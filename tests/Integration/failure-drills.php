<?php

/**
 * Failure drills and an output-escaping check against a running WordPress.
 *
 *     wp eval-file wp-content/plugins/odds-comparison/tests/Integration/failure-drills.php
 *
 * 1. Seeds the cache from real recorded BettingOdds.com pages (fixtures/), no network.
 * 2. Drills, each simulated at the HTTP level with `pre_http_request` (recorded or empty bodies,
 *    never made-up odds): a 403 bot challenge, a real page without odds, and a timeout.
 *    Each drill checks that the stored odds are unchanged, that the block still renders them, and
 *    that the Status tab tells the admin (circuit state, last error, error log).
 * 3. Renders real recorded odds with hostile strings (a selection label, a caption and an
 *    affiliate link) and checks the HTML is escaped.
 *
 * The plugin settings are not changed. The event index, tracked pairs, status and error log are
 * restored, and the cached odds and circuit state of the drilled host are cleared at the end.
 * Prints PASS/FAIL per check; exit status 1 on any failure.
 *
 * @package OddsComparison
 */

use OddsComparison\Admin\StatusPage;
use OddsComparison\Block\TableRenderer;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\RateLimiter;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Http\WpHttpClient;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Odds\OddsValidator;
use OddsComparison\Plugin;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\Lock;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

const OC_DRILL_HOST = 'www.bettingodds.com';
const OC_DRILL_OUTRIGHTS = 'bettingodds:premier-league/outrights';

$plugin   = Plugin::instance();
$root     = $plugin->path();
$failed   = 0;
$options  = [OptionKeys::EVENTS, OptionKeys::TRACKED, OptionKeys::STATUS, OptionKeys::ERRORS];
$saved    = [];
$check    = static function (string $label, bool $ok) use (&$failed): void {
    WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $label);
    $failed += $ok ? 0 : 1;
};

if (!is_file($root . 'fixtures/bettingodds/manifest.json')) {
    WP_CLI::error('No recorded BettingOdds responses (fixtures/ is dev-only).');
}

foreach ($options as $option) {
    $saved[$option] = get_option($option, null);
}

// The capture time is "now" for every check, so recorded matches have not started.
$capturedAt = (new RecordedHttpClient($root . 'fixtures'))->firstCapturedAt() ?? new DateTimeImmutable();
$clock      = static fn (): DateTimeImmutable => $capturedAt;
$breaker    = $plugin->circuitBreaker();
$validator  = new OddsValidator();

/**
 * A refresher on the given transport that stores into the plugin's own repository and index.
 */
$refresherOn = static function ($http) use ($plugin, $validator, $clock): OddsRefresher {
    $sources = new SourceFactory(
        [BettingOddsSource::ID => BettingOddsSource::class],
        [$http, $plugin->bookmakers(), $plugin->competitions(), $validator, $clock]
    );

    return new OddsRefresher(
        $sources,
        $plugin->events(),
        $plugin->markets(),
        $plugin->repository(),
        $plugin->tracked(),
        $plugin->logger(),
        $clock
    );
};

// Live transport without throttle waits or retry pauses, so the drills run in seconds.
$live = new WpHttpClient(new RateLimiter(0.0, 0.0), $breaker, 'OddsComparisonPlugin/drill', static function (): void {
});

$renderer = new TableRenderer(
    new Settings(),
    $plugin->bookmakers(),
    $plugin->markets(),
    $plugin->competitions(),
    $plugin->events(),
    $plugin->repository(),
    new OddsConverter(),
    $root . 'templates',
    $clock
);
$bookmakers = (new Settings())->enabledBookmakers($plugin->bookmakers()->keys());

$statusHtml = static function () use ($plugin, $breaker): string {
    $page = new StatusPage(
        new Settings(),
        $plugin->sources(),
        $breaker,
        $plugin->statusRecorder(),
        $plugin->tracked(),
        $plugin->events(),
        $plugin->repository(),
        $plugin->scheduler(),
        new Lock(),
        $plugin->logger(),
        new CronSchedule(),
        $plugin->bookmakers()
    );
    ob_start();
    $page->render();

    return (string) ob_get_clean();
};

$http = static function (callable $answer): void {
    remove_all_filters('pre_http_request');
    add_filter('pre_http_request', static fn ($pre, array $args, string $url) => $answer($url), 10, 3);
};

try {
    // --- Seed: real recorded pages -------------------------------------------------------------
    $breaker->reset(OC_DRILL_HOST);
    $plugin->logger()->clear();
    $seed = $refresherOn(new RecordedHttpClient($root . 'fixtures'));
    $seed->discover('bettingodds', $plugin->competitions()->get('premier-league'));

    $match = null;
    foreach ($plugin->events()->search('bettingodds', '', 100) as $event) {
        if (!$event->isOutright() && !$event->hasStarted($capturedAt)) {
            $match = $event;
            break;
        }
    }

    $pairs = [[$match?->id ?? '', 'match-result'], [OC_DRILL_OUTRIGHTS, 'outright-winner']];
    foreach ($pairs as [$eventId, $market]) {
        $seed->refreshPair($eventId, $market);
    }

    $stored = static fn (): array => array_map(
        static fn (array $pair): ?array => $plugin->repository()->get($pair[0], $pair[1])?->toArray(),
        $pairs
    );
    $before = $stored();
    $check('seeded from recorded pages', $match !== null && !in_array(null, $before, true));

    // Blocks of markets the site has disabled show "unavailable" by design; check the others.
    $shown = array_values(array_filter(
        $pairs,
        static fn (array $pair): bool => in_array($pair[1], (new Settings())->enabledMarkets(), true)
    ));
    $check('at least one drilled market is enabled on this site', $shown !== []);

    $renders = static function () use ($renderer, $shown, $bookmakers): bool {
        foreach ($shown as [$eventId, $market]) {
            $html = $renderer->render(['eventId' => $eventId, 'marketKey' => $market, 'bookmakers' => $bookmakers]);

            if (substr_count($html, 'class="oc-odds__price') < 10) {
                return false;
            }
        }

        return true;
    };

    // --- Drills ---------------------------------------------------------------------------------
    $noOddsPage = $root . 'fixtures/bettingodds/premier-league-stats-no-odds.html';
    $drills     = [
        '403 bot challenge'      => [
            static fn (): array => [
                'response' => ['code' => 403, 'message' => 'Forbidden'],
                'headers'  => ['cf-mitigated' => 'challenge'],
                'body'     => '',
            ],
            true,
        ],
        'real page without odds' => [
            static fn (): array => [
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers'  => [],
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                'body'     => (string) file_get_contents($noOddsPage),
            ],
            false,
        ],
        'timeout'                => [
            static fn (): WP_Error => new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
            true,
        ],
    ];

    foreach ($drills as $name => [$answer, $opensBreaker]) {
        $breaker->reset(OC_DRILL_HOST);
        $plugin->logger()->clear();
        $http($answer);
        $refresher = $refresherOn($live);
        $results   = [];

        foreach (range(1, 3) as $attempt) {
            foreach ($pairs as [$eventId, $market]) {
                $results[] = $refresher->refreshPair($eventId, $market);
            }
        }

        $state  = $breaker->state(OC_DRILL_HOST);
        $health = $plugin->statusRecorder()->source('bettingodds');
        $status = $statusHtml();

        $check("$name: every refresh fails", array_unique($results) === [OddsRefresher::FAILED]);
        $check("$name: stored odds unchanged", $stored() === $before);
        $check("$name: blocks still show the stored prices", $renders());
        $check(
            "$name: circuit " . ($opensBreaker ? 'opens' : 'stays closed (page problem, not an outage)'),
            ($state['state'] === CircuitBreaker::OPEN) === $opensBreaker
        );
        $check("$name: error log has the failure", $plugin->logger()->entries() !== []);
        $check("$name: status records the last error", $health['lastError'] !== '' && $health['lastErrorAt'] > 0);
        $check(
            "$name: Status tab shows it",
            str_contains($status, 'Recent errors')
                && !str_contains($status, 'No errors recorded.')
                && (!$opensBreaker || str_contains($status, 'Paused'))
        );

        if ($opensBreaker) {
            $refresher->refreshPair($pairs[0][0], $pairs[0][1]);
            $check("$name: open circuit sends no request", str_contains(
                (string) ($plugin->logger()->entries()[0]['message'] ?? ''),
                'paused (circuit open)'
            ));
        }
    }

    remove_all_filters('pre_http_request');

    // --- Escaping: real recorded odds with hostile strings ----------------------------------------
    $real    = $plugin->repository()->get($pairs[0][0], 'match-result');
    $hostile = '<script>alert(1)</script>"><img src=x onerror=alert(1)>';

    if ($real !== null) {
        $selections                  = $real->selections;
        $selections['home']['label'] = $hostile;
        $real                        = $real->withSelections($selections);
    }

    // In memory only: the hostile copy is never stored.
    $memory = new class ($real) implements OddsRepositoryInterface {
        public function __construct(private readonly ?MarketOdds $odds)
        {
        }

        public function get(string $eventId, string $marketKey): ?MarketOdds
        {
            return $this->odds;
        }

        public function save(MarketOdds $odds): bool
        {
            return true;
        }

        public function delete(string $eventId, string $marketKey): bool
        {
            return true;
        }

        public function isStale(MarketOdds $odds): bool
        {
            return false;
        }

        public function flush(): void
        {
        }
    };

    add_filter('odds_comparison/bookmaker_link', static fn (): string => 'javascript:alert(1)');
    $xss = (new TableRenderer(
        new Settings(),
        $plugin->bookmakers(),
        $plugin->markets(),
        $plugin->competitions(),
        $plugin->events(),
        $memory,
        new OddsConverter(),
        $root . 'templates',
        $clock
    ))->render([
        'eventId'    => $pairs[0][0],
        'marketKey'  => 'match-result',
        'bookmakers' => $bookmakers,
        'caption'    => $hostile,
    ]);
    remove_all_filters('odds_comparison/bookmaker_link');

    $check('hostile strings are rendered (as text)', str_contains($xss, '&lt;script&gt;'));
    $check('no script or event-handler markup', !preg_match('/<script|<img[^>]*onerror/i', $xss));
    $check('javascript: links become plain prices', !str_contains($xss, 'javascript:') && !str_contains($xss, '<a '));
} finally {
    remove_all_filters('pre_http_request');
    $plugin->repository()->flush();
    $breaker->reset(OC_DRILL_HOST);

    foreach ($saved as $option => $value) {
        $value === null ? delete_option($option) : update_option($option, $value, false);
    }
}

$failed === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error(sprintf('%d check(s) failed.', $failed));
