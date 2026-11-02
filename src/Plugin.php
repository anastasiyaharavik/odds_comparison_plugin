<?php

/**
 * Plugin composition root.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use OddsComparison\Admin\AdminActions;
use OddsComparison\Admin\SettingsPage;
use OddsComparison\Admin\SourceProbe;
use OddsComparison\Admin\StatusPage;
use OddsComparison\Block\BlockRegistrar;
use OddsComparison\Block\TableRenderer;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\RateLimiter;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Http\WpHttpClient;
use OddsComparison\Lifecycle\Installer;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Rest\EditorController;
use OddsComparison\Rest\StatusController;
use OddsComparison\Scheduler\Lock;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\SettingsEvents;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\OddsRepository;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Deadline;
use OddsComparison\Support\Logger;
use OddsComparison\Support\RobotsMonitor;
use OddsComparison\Support\StatusRecorder;

defined('ABSPATH') || exit;

/**
 * Plugin entry point (Singleton) and composition root.
 *
 * This is the only singleton in the plugin. It builds every service once, injects
 * dependencies through constructors and asks each HookableInterface service to register
 * its hooks. Services never call Plugin::instance() themselves (Dependency Inversion).
 */
final class Plugin
{
    /** Plugin version; keep in sync with the header in odds-comparison.php. */
    public const VERSION = '0.1.0';

    /** Text domain for all translatable strings. */
    public const TEXT_DOMAIN = 'odds-comparison';

    /** The single instance. */
    private static ?self $instance = null;

    /** Whether boot() already ran. */
    private bool $booted = false;

    /** Settings accessor. */
    private readonly Settings $settings;

    /** Cron scheduling helper. */
    private readonly CronSchedule $cronSchedule;

    /** Activation/upgrade routines. */
    private readonly Installer $installer;

    /** Bookmaker registry. */
    private readonly BookmakerRegistry $bookmakers;

    /** Market registry. */
    private readonly MarketRegistry $markets;

    /** Competition registry. */
    private readonly CompetitionRegistry $competitions;

    /** Source adapter factory. */
    private readonly SourceFactory $sources;

    /** Odds format converter (single source of truth). */
    private readonly OddsConverter $converter;

    /** Circuit breaker shared by all sources (keyed by host). */
    private readonly CircuitBreaker $circuitBreaker;

    /** Capped error log. */
    private readonly Logger $logger;

    /** Odds storage. */
    private readonly OddsRepository $repository;

    /** Discovered events. */
    private readonly EventIndex $events;

    /** Pairs used by blocks. */
    private readonly TrackedEventsIndex $tracked;

    /** Single-pair refresh step (fires market_odds / odds_updated / source_failed). */
    private readonly OddsRefresher $refresher;

    /** Cron refresh run. */
    private readonly RefreshScheduler $scheduler;

    /** Odds table renderer (block render.php). */
    private readonly TableRenderer $tableRenderer;

    /** Source health and run summaries (Observer). */
    private readonly StatusRecorder $statusRecorder;

    /**
     * Services that attach hooks (admin, REST, settings events), in registration order.
     *
     * @var list<HookableInterface>
     */
    private readonly array $hookables;

    /**
     * Build the service graph. Private: use {@see self::instance()}.
     *
     * @param string $file Absolute path of the main plugin file.
     */
    private function __construct(private readonly string $file)
    {
        $configDir = dirname($file) . '/config/';

        $this->settings     = new Settings();
        $this->cronSchedule = new CronSchedule();
        $this->installer    = new Installer($this->settings, $this->cronSchedule, self::VERSION, $file);
        $this->bookmakers   = new BookmakerRegistry($configDir . 'bookmakers.php');
        $this->markets      = new MarketRegistry($configDir . 'markets.php');
        $this->competitions = new CompetitionRegistry($configDir . 'competitions.php');
        $this->converter    = new OddsConverter();

        /**
         * Filters how long a source's circuit stays open after repeated failures.
         *
         * @param int $seconds Open period in seconds (default 1 hour).
         */
        $openSeconds          = (int) apply_filters('odds_comparison/circuit_breaker_seconds', HOUR_IN_SECONDS);
        $this->circuitBreaker = new CircuitBreaker(3, max(60, $openSeconds));

        $deadline       = new Deadline();
        [$http, $clock] = $this->transport(dirname($file) . '/fixtures', $deadline);
        $validator      = new OddsValidator();
        $apiKey         = self::theOddsApiKey();
        $this->sources  = new SourceFactory(
            [
                BettingOddsSource::ID => BettingOddsSource::class,
                TheOddsApiSource::ID  => fn (): SourceInterface => new TheOddsApiSource(
                    $http,
                    $this->bookmakers,
                    $this->competitions,
                    $validator,
                    $apiKey,
                    $clock
                ),
            ],
            [$http, $this->bookmakers, $this->competitions, $validator, $clock]
        );

        $this->logger     = new Logger();
        $this->events     = new EventIndex();
        $this->tracked    = new TrackedEventsIndex();
        $this->repository = new OddsRepository(
            fn (): int => $this->settings->cacheTtl()['stale_after'],
            static fn (): int => $clock()->getTimestamp()
        );
        $this->refresher  = new OddsRefresher(
            $this->sources,
            $this->events,
            $this->markets,
            $this->repository,
            $this->tracked,
            $this->logger,
            $clock
        );

        $this->statusRecorder = new StatusRecorder();
        $lock                 = new Lock();
        $this->scheduler      = new RefreshScheduler(
            $this->settings,
            $this->competitions,
            $this->events,
            $this->tracked,
            $this->repository,
            $this->refresher,
            new RobotsMonitor($http, $this->sources, $this->competitions, $this->statusRecorder),
            $this->statusRecorder,
            $lock,
            $deadline,
            $clock
        );

        $statusPage      = new StatusPage(
            $this->settings,
            $this->sources,
            $this->circuitBreaker,
            $this->statusRecorder,
            $this->tracked,
            $this->events,
            $this->repository,
            $this->scheduler,
            $lock,
            $this->logger,
            $this->cronSchedule,
            $this->bookmakers
        );
        $this->tableRenderer = new TableRenderer(
            $this->settings,
            $this->bookmakers,
            $this->markets,
            $this->competitions,
            $this->events,
            $this->repository,
            $this->converter,
            dirname($file) . '/templates',
            $clock
        );

        $this->hookables = [
            new BlockRegistrar(dirname($file) . '/build/odds-table'),
            new EditorController(
                $this->settings,
                $this->events,
                $this->markets,
                $this->bookmakers,
                $this->competitions,
                $this->repository,
                $this->tracked,
                $clock
            ),
            new SettingsEvents(),
            $this->statusRecorder,
            $this->scheduler,
            new SettingsPage(
                $this->settings,
                $this->bookmakers,
                $this->markets,
                $this->competitions,
                $this->sources,
                new SourceProbe($this->sources, $this->competitions, $this->settings),
                $statusPage,
                $file,
                is_dir(dirname($file) . '/fixtures')
            ),
            $statusPage,
            new AdminActions($this->repository, $this->circuitBreaker, $this->sources),
            new StatusController($statusPage),
        ];
    }

    /**
     * Get the plugin instance, creating it on first call.
     *
     * @param string|null $file Main plugin file; required on the first call only.
     * @return self
     * @throws LogicException When called for the first time without the plugin file.
     */
    public static function instance(?string $file = null): self
    {
        if (self::$instance === null) {
            if ($file === null) {
                throw new LogicException('Plugin::instance() must receive the main plugin file on first call.');
            }

            self::$instance = new self($file);
        }

        return self::$instance;
    }

    /**
     * Register all hooks. Idempotent: safe to call from both plugins_loaded and activation.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ($this->services() as $service) {
            $service->register();
        }

        /**
         * Fires once the plugin has registered all of its hooks.
         *
         * @param Plugin $plugin The plugin instance.
         */
        do_action('odds_comparison/loaded', $this);
    }

    /**
     * Activation handler (wired in odds-comparison.php).
     *
     * On the activation request plugins_loaded has already fired before this plugin was
     * loaded, so boot() is called here to make sure e.g. the custom cron schedules exist.
     *
     * @param bool $networkWide Whether the plugin is network-activated.
     * @return void
     */
    public function activate(bool $networkWide): void
    {
        $this->boot();
        $this->installer->activate($networkWide);
    }

    /**
     * Deactivation handler (wired in odds-comparison.php).
     *
     * @param bool $networkWide Whether the plugin is network-deactivated.
     * @return void
     */
    public function deactivate(bool $networkWide): void
    {
        $this->boot();
        $this->installer->deactivate($networkWide);
    }

    /**
     * Absolute path of the main plugin file.
     *
     * @return string
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * Absolute filesystem path inside the plugin directory.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    public function path(string $relative = ''): string
    {
        return plugin_dir_path($this->file) . ltrim($relative, '/');
    }

    /**
     * Public URL inside the plugin directory.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    public function url(string $relative = ''): string
    {
        return plugin_dir_url($this->file) . ltrim($relative, '/');
    }

    /**
     * Bookmaker registry (for integrations; plugin services receive it by injection).
     *
     * @return BookmakerRegistry
     */
    public function bookmakers(): BookmakerRegistry
    {
        return $this->bookmakers;
    }

    /**
     * Market registry.
     *
     * @return MarketRegistry
     */
    public function markets(): MarketRegistry
    {
        return $this->markets;
    }

    /**
     * Competition registry.
     *
     * @return CompetitionRegistry
     */
    public function competitions(): CompetitionRegistry
    {
        return $this->competitions;
    }

    /**
     * Source adapter factory.
     *
     * @return SourceFactory
     */
    public function sources(): SourceFactory
    {
        return $this->sources;
    }

    /**
     * Odds storage.
     *
     * @return OddsRepository
     */
    public function repository(): OddsRepository
    {
        return $this->repository;
    }

    /**
     * Discovered events.
     *
     * @return EventIndex
     */
    public function events(): EventIndex
    {
        return $this->events;
    }

    /**
     * Pairs used by blocks.
     *
     * @return TrackedEventsIndex
     */
    public function tracked(): TrackedEventsIndex
    {
        return $this->tracked;
    }

    /**
     * Single-pair refresh step.
     *
     * @return OddsRefresher
     */
    public function refresher(): OddsRefresher
    {
        return $this->refresher;
    }

    /**
     * Odds table renderer.
     *
     * @return TableRenderer
     */
    public function tableRenderer(): TableRenderer
    {
        return $this->tableRenderer;
    }

    /**
     * Cron refresh run.
     *
     * @return RefreshScheduler
     */
    public function scheduler(): RefreshScheduler
    {
        return $this->scheduler;
    }

    /**
     * Source health and run summaries.
     *
     * @return StatusRecorder
     */
    public function statusRecorder(): StatusRecorder
    {
        return $this->statusRecorder;
    }

    /**
     * Error log.
     *
     * @return Logger
     */
    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * Odds format converter.
     *
     * @return OddsConverter
     */
    public function converter(): OddsConverter
    {
        return $this->converter;
    }

    /**
     * Circuit breaker (Status tab, "Reset circuit breaker").
     *
     * @return CircuitBreaker
     */
    public function circuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * The Odds API key from the `ODDS_COMPARISON_THEODDSAPI_KEY` constant (wp-config.php) or the
     * environment variable of the same name. Never stored in options.
     *
     * @return string Key, or "" when not configured.
     */
    public static function theOddsApiKey(): string
    {
        $name  = 'ODDS_COMPARISON_THEODDSAPI_KEY';
        $value = defined($name) ? constant($name) : getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * HTTP transport and clock for the current data mode.
     *
     * Live: WordPress HTTP API with throttling and the circuit breaker; "now" is the real time.
     * Recorded: real captured responses only, and "now" is the capture time so recorded matches
     * are not treated as started. Recorded mode needs the dev-only fixtures/ folder.
     *
     * @param string   $fixturesDir Absolute fixtures/ path.
     * @param Deadline $deadline    Refresh-run deadline for live requests.
     * @return array{HttpClientInterface, Closure(): DateTimeImmutable}
     */
    private function transport(string $fixturesDir, Deadline $deadline): array
    {
        if ($this->settings->dataMode() === 'recorded' && is_dir($fixturesDir)) {
            $recorded   = new RecordedHttpClient($fixturesDir);
            $capturedAt = $recorded->firstCapturedAt() ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

            return [$recorded, static fn (): DateTimeImmutable => $capturedAt];
        }

        $userAgent = sprintf('OddsComparisonPlugin/%s (+%s)', self::VERSION, home_url('/'));
        $http      = new WpHttpClient(
            new RateLimiter((float) $this->settings->minRequestInterval()),
            $this->circuitBreaker,
            $userAgent,
            null,
            $deadline
        );

        return [$http, static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))];
    }

    /**
     * Services that attach WordPress hooks, in registration order.
     *
     * @return list<HookableInterface>
     */
    private function services(): array
    {
        return [
            $this->cronSchedule,
            $this->installer,
            $this->tracked,
            ...$this->hookables,
        ];
    }

    /**
     * Singletons must not be cloned.
     */
    private function __clone()
    {
    }

    /**
     * Singletons must not be unserialized.
     *
     * @return void
     * @throws LogicException Always.
     */
    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize a singleton.');
    }
}
