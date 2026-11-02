<?php

/**
 * Plugin composition root.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use OddsComparison\Admin\AdminActions;
use OddsComparison\Admin\SettingsPage;
use OddsComparison\Admin\SourceProbe;
use OddsComparison\Admin\StatusPage;
use OddsComparison\Block\BlockRegistrar;
use OddsComparison\Block\PreviewContext;
use OddsComparison\Block\TableRenderer;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\CountingHttpClient;
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
use OddsComparison\Scheduler\DataModeSwitch;
use OddsComparison\Rest\EditorController;
use OddsComparison\Rest\StatusController;
use OddsComparison\Scheduler\Lock;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\SettingsEvents;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\DemoSource;
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

    /** Whether the block renders for the editor preview. */
    private readonly PreviewContext $previewContext;

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

        $fixturesDir    = dirname($file) . '/fixtures';
        $deadline       = new Deadline();
        $clock          = static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $recorded       = $this->settings->dataMode() === 'demo' && is_dir($fixturesDir)
            ? new RecordedHttpClient($fixturesDir)
            : null;
        $http           = new CountingHttpClient($recorded ?? $this->liveTransport($deadline));
        $capturedAt     = $recorded?->firstCapturedAt() ?? $clock();
        $sourceClock    = $recorded === null ? $clock : static fn (): DateTimeImmutable => $capturedAt;
        $validator      = new OddsValidator();
        // Demo requests never leave the site, so The Odds API needs no real key there.
        $apiKey         = $recorded === null ? self::theOddsApiKey() : 'demo';
        $demo           = static fn (SourceInterface $source): SourceInterface => $recorded === null
            ? $source
            : new DemoSource($source, $capturedAt, $clock);
        $this->sources  = new SourceFactory(
            [
                BettingOddsSource::ID => fn (): SourceInterface => $demo(new BettingOddsSource(
                    $http,
                    $this->bookmakers,
                    $this->competitions,
                    $validator,
                    $sourceClock
                )),
                TheOddsApiSource::ID  => fn (): SourceInterface => $demo(new TheOddsApiSource(
                    $http,
                    $this->bookmakers,
                    $this->competitions,
                    $validator,
                    $apiKey,
                    $sourceClock
                )),
            ],
            [$http, $this->bookmakers, $this->competitions, $validator, $sourceClock]
        );

        $this->logger     = new Logger();
        $this->events     = new EventIndex();
        $this->tracked    = new TrackedEventsIndex();
        $this->repository = new OddsRepository(
            fn (string $sourceId): int => $this->settings->staleAfterFor($sourceId),
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
            $clock,
            $http
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
            $this->bookmakers,
            is_dir($fixturesDir)
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

        $settingsPage = new SettingsPage(
            $this->settings,
            $this->bookmakers,
            $this->markets,
            $this->competitions,
            $this->sources,
            new SourceProbe($this->sources, $this->competitions, $this->settings),
            $statusPage,
            $file,
            is_dir($fixturesDir)
        );

        $this->previewContext = new PreviewContext();

        $this->hookables = [
            new BlockRegistrar(dirname($file) . '/build/odds-table'),
            $this->previewContext,
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
            new DataModeSwitch($this->repository, $this->events, $this->statusRecorder),
            $this->statusRecorder,
            $this->scheduler,
            $settingsPage,
            $statusPage,
            new AdminActions($this->repository, $this->circuitBreaker, $this->sources, $this->settings, $settingsPage),
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
     * Editor preview detection (block render.php).
     *
     * @return PreviewContext
     */
    public function previewContext(): PreviewContext
    {
        return $this->previewContext;
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
     * Live transport: WordPress HTTP API with throttling and the circuit breaker.
     *
     * Demo mode uses {@see RecordedHttpClient} instead: real responses recorded from the sources
     * and shipped in fixtures/, never the network. {@see DemoSource} moves their kick-off dates
     * into the coming days.
     *
     * @param Deadline $deadline Refresh-run deadline for live requests.
     * @return HttpClientInterface
     */
    private function liveTransport(Deadline $deadline): HttpClientInterface
    {
        $userAgent = sprintf('OddsComparisonPlugin/%s (+%s)', self::VERSION, home_url('/'));

        return new WpHttpClient(
            new RateLimiter((float) $this->settings->minRequestInterval()),
            $this->circuitBreaker,
            $userAgent,
            null,
            $deadline
        );
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
