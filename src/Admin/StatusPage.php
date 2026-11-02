<?php

/**
 * Status tab and admin warnings.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Plugin;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\Lock;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Logger;
use OddsComparison\Support\StatusRecorder;

defined('ABSPATH') || exit;

/**
 * Status tab: a live strip with "Refresh now", source health, schedule, recent
 * refreshes, actions and recent errors. Also the admin warnings.
 *
 * Read-only: rendering never makes a network request.
 */
final class StatusPage implements HookableInterface
{
    /** Result notices of admin actions (whitelist for the `oc_notice` query arg). */
    private const ACTION_NOTICES = [
        'refresh_queued',
        'refresh_waiting',
        'cache_cleared',
        'breaker_reset',
        'demo_enabled',
        'demo_unavailable',
    ];

    /**
     * @param Settings                $settings   Settings.
     * @param SourceFactory           $sources    Source factory.
     * @param CircuitBreaker          $breaker    Circuit breaker.
     * @param StatusRecorder          $status     Status storage.
     * @param TrackedEventsIndex      $tracked    Tracked pairs.
     * @param EventIndex              $events     Event index.
     * @param OddsRepositoryInterface $repository Odds storage.
     * @param RefreshScheduler        $scheduler  Scheduler (due pairs).
     * @param Lock                    $lock       Run lock.
     * @param Logger                  $logger     Error log.
     * @param CronSchedule            $cron       Cron schedule.
     * @param BookmakerRegistry       $bookmakers Bookmaker registry (coverage names).
     * @param bool                    $demoAvailable Whether Demo mode can be offered (recorded data present).
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly SourceFactory $sources,
        private readonly CircuitBreaker $breaker,
        private readonly StatusRecorder $status,
        private readonly TrackedEventsIndex $tracked,
        private readonly EventIndex $events,
        private readonly OddsRepositoryInterface $repository,
        private readonly RefreshScheduler $scheduler,
        private readonly Lock $lock,
        private readonly Logger $logger,
        private readonly CronSchedule $cron,
        private readonly BookmakerRegistry $bookmakers,
        private readonly bool $demoAvailable = true
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderGlobalNotices']);
    }

    /**
     * Warnings on the Dashboard and Plugins screens (the plugin screen shows them itself).
     *
     * @return void
     */
    public function renderGlobalNotices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        $relevant = $screen !== null && in_array($screen->id, ['dashboard', 'plugins'], true);

        if ($relevant && current_user_can(SettingsPage::CAPABILITY)) {
            $this->renderWarnings();
        }
    }

    /**
     * Action results and warnings for the plugin screen.
     *
     * @return void
     */
    public function renderNotices(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, whitelisted value.
        $notice = isset($_GET['oc_notice']) ? sanitize_key(wp_unslash($_GET['oc_notice'])) : '';

        if (in_array($notice, self::ACTION_NOTICES, true)) {
            $messages = [
                'refresh_queued'  => __(
                    'Refresh queued. It runs in the background within a minute; reload this tab to see the result.',
                    'odds-comparison'
                ),
                'refresh_waiting' => __('A refresh is already queued or running.', 'odds-comparison'),
                'cache_cleared'   => __(
                    'Cached odds were cleared. They are fetched again on the next refresh.',
                    'odds-comparison'
                ),
                'breaker_reset'   => __(
                    'Circuit breakers were reset; requests to the sources are allowed again.',
                    'odds-comparison'
                ),
                'demo_enabled'    => __(
                    'Demo data is on. Odds tables fill within a minute; reload this tab to see the result.',
                    'odds-comparison'
                ),
                'demo_unavailable' => __(
                    'Demo data could not be turned on; see the message above.',
                    'odds-comparison'
                ),
            ];
            $this->notice($notice === 'demo_unavailable' ? 'error' : 'success', $messages[$notice]);
        }

        $this->renderWarnings();
    }

    /**
     * Render the Status tab.
     *
     * @return void
     */
    public function render(): void
    {
        $this->renderLive();
        echo '<section class="oc-panel" aria-labelledby="oc-status-sources">';
        $this->renderSources();
        $this->renderSchedule();
        $this->renderRuns();
        $this->renderActions();
        echo '</section>';
        $this->renderErrors();
    }

    /**
     * How many of the active source's stored markets each bookmaker appears in.
     *
     * @return array{total: int, seen: array<string, int>}
     */
    public function bookmakerCoverage(): array
    {
        $source = $this->settings->activeSource();
        $total  = 0;
        $seen   = [];

        foreach ($this->tracked->pairs() as $pair) {
            $event = $this->events->get($pair['eventId']);
            $odds  = $event !== null && $event->sourceId === $source
                ? $this->repository->get($pair['eventId'], $pair['marketKey'])
                : null;

            if ($odds === null) {
                continue;
            }

            ++$total;

            foreach ($odds->bookmakerSlugs() as $slug) {
                $seen[$slug] = ($seen[$slug] ?? 0) + 1;
            }
        }

        return ['total' => $total, 'seen' => $seen];
    }

    /**
     * Data for the REST status endpoint (polling after "Refresh now").
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'running' => $this->lock->isLocked(),
            'queued'  => wp_next_scheduled(CronSchedule::HOOK, ['manual']) !== false,
            'lastRun' => $this->status->lastRun(),
        ];
    }

    /**
     * Live strip: overall state, active source, last update and "Refresh now".
     *
     * @return void
     */
    private function renderLive(): void
    {
        $source = $this->settings->activeSource();
        $health = $this->status->source($source);
        $state  = $this->breaker->state($this->host($source));
        $fresh  = $health['lastSuccess'] > 0
            && time() - $health['lastSuccess'] <= $this->settings->staleAfterFor($source) * 60;

        [$tone, $label] = match (true) {
            $this->settings->isDemo()                   => ['warn', __('Demo data', 'odds-comparison')],
            $state['state'] === CircuitBreaker::OPEN    => ['bad', __('Paused', 'odds-comparison')],
            $fresh                                      => ['ok', __('Live', 'odds-comparison')],
            $health['lastSuccess'] === 0                => ['warn', __('Waiting for first fetch', 'odds-comparison')],
            default                                     => ['warn', __('Not updating', 'odds-comparison')],
        };

        $details = [
            SourceLabels::short($source),
            /* translators: %s: "5 minutes ago" or "never". */
            sprintf(__('updated %s', 'odds-comparison'), $this->ago($health['lastSuccess'])),
        ];

        if ($source === TheOddsApiSource::ID) {
            $details[] = $this->quotaText();
        }

        echo '<div class="oc-live">';
        printf(
            '<p class="oc-live__state"><span class="oc-dot is-%1$s" aria-hidden="true"></span>'
            . '<strong>%2$s</strong> · %3$s</p>',
            esc_attr($tone),
            esc_html($label),
            esc_html(implode(' · ', $details))
        );
        printf(
            '<form method="post" action="%s" id="oc-refresh-form"><input type="hidden" name="action" value="%s" />',
            esc_url(admin_url('admin-post.php')),
            esc_attr(AdminActions::REFRESH)
        );
        wp_nonce_field(AdminActions::REFRESH);
        printf(
            '<span class="spinner oc-refresh-spinner"></span>'
            . '<button type="submit" class="button button-primary">%s</button>',
            esc_html__('Refresh now', 'odds-comparison')
        );
        echo '</form></div>';
        echo '<div class="oc-refresh-result" role="status" aria-live="polite"></div>';
    }

    /**
     * One card per source.
     *
     * @return void
     */
    private function renderSources(): void
    {
        $active = $this->settings->activeSource();

        printf('<h2 id="oc-status-sources">%s</h2>', esc_html__('Sources', 'odds-comparison'));
        printf(
            '<p class="oc-muted">%s</p>',
            esc_html__('Where odds come from and whether requests are getting through.', 'odds-comparison')
        );
        echo '<div class="oc-sources">';

        foreach ($this->sources->ids() as $id) {
            $health = $this->status->source($id);
            $state  = $this->breaker->state($this->host($id));

            [$tone, $pill] = match (true) {
                $state['state'] === CircuitBreaker::OPEN => ['bad', sprintf(
                    /* translators: %s: human time difference. */
                    __('Paused for %s', 'odds-comparison'),
                    human_time_diff(time(), max(time() + 1, $state['openUntil']))
                )],
                $id !== $active                          => ['off', __('Not in use', 'odds-comparison')],
                $state['failures'] > 0,
                $health['lastErrorAt'] > $health['lastSuccess'] => ['warn', __('Active · failing', 'odds-comparison')],
                default                                  => ['ok', __('Active · working', 'odds-comparison')],
            };

            $facts = [
                __('Last successful fetch', 'odds-comparison') => $this->ago($health['lastSuccess']),
                __('Last error', 'odds-comparison')            => $health['lastError'] === ''
                    ? __('none', 'odds-comparison')
                    : $health['lastError'] . ' (' . $this->ago($health['lastErrorAt']) . ')',
                __('Requests', 'odds-comparison')              => $this->breakerText($state),
                __('robots.txt', 'odds-comparison')            => $this->robotsText($health['robots']),
            ];

            if ($id === TheOddsApiSource::ID) {
                $facts[__('API credits', 'odds-comparison')] = $this->quotaText();
            }

            echo '<div class="oc-source">';
            printf(
                '<h3>%1$s <span class="oc-pill is-%2$s">%3$s</span></h3><dl>',
                esc_html(SourceLabels::short($id)),
                esc_attr($tone),
                esc_html($pill)
            );

            foreach ($facts as $term => $value) {
                printf('<dt>%s</dt><dd>%s</dd>', esc_html($term), esc_html($value));
            }

            echo '</dl></div>';
        }

        echo '</div>';
    }

    /**
     * Schedule, queue and bookmaker coverage facts.
     *
     * @return void
     */
    private function renderSchedule(): void
    {
        $next     = $this->cron->nextRun();
        $due      = $this->scheduler->duePairs($this->settings->activeSource());
        $coverage = $this->bookmakerCoverage();
        $missing  = array_values(array_filter(
            $this->settings->enabledBookmakers($this->bookmakers->keys()),
            static fn (string $slug): bool => ($coverage['seen'][$slug] ?? 0) === 0
        ));
        $names    = array_map(
            fn (string $slug): string => $this->bookmakers->get($slug)?->name ?? $slug,
            $missing
        );
        $facts    = [
            __('Next scheduled refresh', 'odds-comparison')    => $next === null
                ? __('not scheduled', 'odds-comparison')
                : wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $next),
            __('Refresh running', 'odds-comparison')           => $this->lock->isLocked()
                ? __('yes', 'odds-comparison')
                : __('no', 'odds-comparison'),
            __('Tracked event/market pairs', 'odds-comparison') => (string) count($this->tracked->pairs()),
            __('Due now (active source)', 'odds-comparison')    => (string) count($due),
            __('Bookmakers in stored odds', 'odds-comparison')  => match (true) {
                $coverage['total'] === 0 => __('no data yet', 'odds-comparison'),
                $missing === []          => __('all enabled bookmakers found', 'odds-comparison'),
                /* translators: %s: comma-separated bookmaker names. */
                default                  => sprintf(__('not found: %s', 'odds-comparison'), implode(', ', $names)),
            },
        ];

        printf('<h2>%s</h2><dl class="oc-facts">', esc_html__('Schedule', 'odds-comparison'));

        foreach ($facts as $term => $value) {
            printf('<div><dt>%s</dt><dd>%s</dd></div>', esc_html($term), esc_html($value));
        }

        echo '</dl>';

        printf(
            '<p class="description">%s</p>',
            defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
                // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
                ? esc_html__('WP-Cron is disabled: make sure a system cron runs "wp cron event run --due-now" every few minutes.', 'odds-comparison')
                // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
                : esc_html__('WP-Cron runs on page visits. For reliable timing, set DISABLE_WP_CRON and use a system cron (see README).', 'odds-comparison')
        );
    }

    /**
     * Recent refresh runs, newest first.
     *
     * @return void
     */
    private function renderRuns(): void
    {
        $runs = $this->status->recentRuns();

        printf('<h2>%s</h2>', esc_html__('Recent refreshes', 'odds-comparison'));

        if ($runs === []) {
            printf('<p>%s</p>', esc_html__('No refresh has run yet.', 'odds-comparison'));

            return;
        }

        echo '<table class="oc-runs"><thead><tr>';

        $headings = [
            __('When', 'odds-comparison'),
            __('Trigger', 'odds-comparison'),
            __('Source', 'odds-comparison'),
            __('Fetched', 'odds-comparison'),
            __('Failed', 'odds-comparison'),
            __('Waiting', 'odds-comparison'),
            __('Events found', 'odds-comparison'),
        ];

        foreach ($headings as $heading) {
            printf('<th scope="col">%s</th>', esc_html($heading));
        }

        echo '</tr></thead><tbody>';

        foreach ($runs as $run) {
            $failed = (int) ($run['failed'] ?? 0);

            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$d</td>'
                . '<td%5$s>%6$d</td><td>%7$d</td><td>%8$d</td></tr>',
                esc_html($this->ago((int) ($run['finishedAt'] ?? 0))),
                esc_html(($run['trigger'] ?? '') === 'manual'
                    ? __('Refresh now', 'odds-comparison')
                    : __('Schedule', 'odds-comparison')),
                esc_html(SourceLabels::short((string) ($run['source'] ?? ''))),
                (int) ($run['fetched'] ?? 0),
                $failed > 0 ? ' class="oc-fail"' : '',
                (int) $failed,
                (int) ($run['skipped'] ?? 0),
                (int) ($run['discovered'] ?? 0)
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Maintenance buttons (admin-post forms with their own nonces).
     *
     * @return void
     */
    private function renderActions(): void
    {
        echo '<div class="oc-actions">';

        foreach (
            [
            AdminActions::CLEAR_CACHE   => __('Clear cached odds', 'odds-comparison'),
            AdminActions::RESET_BREAKER => __('Allow paused sources again', 'odds-comparison'),
            ] as $action => $label
        ) {
            printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
            printf('<input type="hidden" name="action" value="%s" />', esc_attr($action));
            wp_nonce_field($action);
            printf('<button type="submit" class="button">%s</button>', esc_html($label));
            echo '</form>';
        }

        echo '</div>';
    }

    /**
     * Recent errors (escaped; messages may contain scraped text).
     *
     * @return void
     */
    private function renderErrors(): void
    {
        $entries = $this->logger->entries();

        printf('<section class="oc-panel"><h2>%s</h2>', esc_html__('Recent errors', 'odds-comparison'));

        if ($entries === []) {
            printf('<p>%s</p></section>', esc_html__('No errors recorded.', 'odds-comparison'));

            return;
        }

        echo '<table class="oc-runs"><thead><tr>';

        $headings = [
            __('When', 'odds-comparison'),
            __('Level', 'odds-comparison'),
            __('Source', 'odds-comparison'),
            __('Message', 'odds-comparison'),
        ];

        foreach ($headings as $heading) {
            printf('<th scope="col">%s</th>', esc_html($heading));
        }

        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $count   = (int) ($entry['count'] ?? 1);
            $message = (string) ($entry['message'] ?? '');

            if ($count > 1) {
                /* translators: 1: log message, 2: how many times it occurred in a row. */
                $message = sprintf(__('%1$s (×%2$d)', 'odds-comparison'), $message, $count);
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($this->ago((int) ($entry['time'] ?? 0))),
                esc_html((string) ($entry['level'] ?? '')),
                esc_html((string) ($entry['source'] ?? '')),
                esc_html($message)
            );
        }

        echo '</tbody></table></section>';
    }

    /**
     * Warnings: Demo mode on (with how to go live), active source failing (with a "Use demo
     * data" button), paused, robots disallow, no recent success.
     *
     * @return void
     */
    private function renderWarnings(): void
    {
        $source = $this->settings->activeSource();
        $health = $this->status->source($source);
        $state  = $this->breaker->state($this->host($source));

        if ($this->settings->isDemo()) {
            $this->notice('info', implode(' ', [
                __('Odds Comparison shows demo data: real odds recorded from the data source, with kick-off dates moved to the coming days.', 'odds-comparison'), // phpcs:ignore Generic.Files.LineLength.TooLong
                self::goLiveHint(),
            ]));

            return;
        }

        $failing = $state['state'] === CircuitBreaker::OPEN
            || ($health['lastErrorAt'] > 0 && $health['lastErrorAt'] >= $health['lastSuccess']);

        if ($failing && $this->demoAvailable) {
            $this->demoOffer(
                $source,
                $state['state'] === CircuitBreaker::OPEN ? $state['lastError'] : $health['lastError']
            );
        } elseif ($state['state'] === CircuitBreaker::OPEN) {
            $this->notice('error', sprintf(
                /* translators: 1: source id, 2: last error. */
                __('Odds Comparison: requests to %1$s are paused after repeated failures (%2$s). Cached odds are still shown.', 'odds-comparison'), // phpcs:ignore Generic.Files.LineLength.TooLong
                $source,
                $state['lastError']
            ));
        }

        if ($health['robots']['allowed'] === false) {
            $this->notice('warning', sprintf(
                /* translators: 1: source id, 2: details. */
                __('Odds Comparison: robots.txt of %1$s disallows paths the plugin uses; they are not requested and their odds are not updated. %2$s', 'odds-comparison'), // phpcs:ignore Generic.Files.LineLength.TooLong
                $source,
                $health['robots']['detail']
            ));
        }

        $staleSeconds = $this->settings->staleAfterFor($source) * 60;

        if (!$failing && $this->tracked->pairs() !== [] && time() - $health['lastSuccess'] > $staleSeconds) {
            $this->notice('warning', sprintf(
                /* translators: %s: source id. */
                __('Odds Comparison: no successful fetch from %s recently. Check the Status tab.', 'odds-comparison'),
                $source
            ));
        }
    }

    /**
     * Error notice for a data source that returns no odds, with what to do and a button that
     * turns on demo data.
     *
     * @param string $source    Active source id.
     * @param string $lastError Last error message (already redacted).
     * @return void
     */
    private function demoOffer(string $source, string $lastError): void
    {
        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p>'
            . '<form method="post" action="%4$s"><input type="hidden" name="action" value="%5$s" />',
            esc_html(sprintf(
                /* translators: %s: data source name. */
                __('Odds Comparison: no odds can be fetched from %s, so there are none to show.', 'odds-comparison'),
                SourceLabels::short($source)
            )),
            esc_html($lastError),
            esc_html(implode(' ', [
                self::goLiveHint(),
                __('Until then you can show demo data: real recorded odds with kick-off dates moved to the coming days, marked "Demo data".', 'odds-comparison'), // phpcs:ignore Generic.Files.LineLength.TooLong
            ])),
            esc_url(admin_url('admin-post.php')),
            esc_attr(AdminActions::USE_DEMO)
        );
        wp_nonce_field(AdminActions::USE_DEMO);
        printf(
            '<p><button type="submit" class="button button-primary">%s</button></p></form></div>',
            esc_html__('Use demo data', 'odds-comparison')
        );
    }

    /**
     * What it takes to show live odds (same text in the demo notice and the failure notice).
     *
     * @return string Plain text.
     */
    private static function goLiveHint(): string
    {
        return Plugin::theOddsApiKey() !== ''
            ? __('For live odds, choose The Odds API as the data source and set Data mode to Live (Odds Comparison → General).', 'odds-comparison') // phpcs:ignore Generic.Files.LineLength.TooLong
            : __('For live odds, get a free key from the-odds-api.com, add define( \'ODDS_COMPARISON_THEODDSAPI_KEY\', \'your-key\' ); to wp-config.php, then choose The Odds API as the data source and set Data mode to Live (Odds Comparison → General). BettingOdds.com blocks requests from most web servers.', 'odds-comparison'); // phpcs:ignore Generic.Files.LineLength.TooLong
    }

    /**
     * Print an admin notice.
     *
     * @param string $type    success|warning|error.
     * @param string $message Plain text.
     * @return void
     */
    private function notice(string $type, string $message): void
    {
        printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($message));
    }

    /**
     * Host of a source (circuit breaker key).
     *
     * @param string $sourceId Source id.
     * @return string
     */
    private function host(string $sourceId): string
    {
        return strtolower((string) wp_parse_url($this->sources->make($sourceId)->baseUrl(), PHP_URL_HOST));
    }

    /**
     * "5 minutes ago" or "never".
     *
     * @param int $timestamp Unix time (0 = never).
     * @return string
     */
    private function ago(int $timestamp): string
    {
        return $timestamp <= 0
            ? __('never', 'odds-comparison')
            /* translators: %s: human time difference. */
            : sprintf(__('%s ago', 'odds-comparison'), human_time_diff($timestamp));
    }

    /**
     * Circuit breaker state text.
     *
     * @param array{state: string, failures: int, openUntil: int, lastError: string} $state State.
     * @return string
     */
    private function breakerText(array $state): string
    {
        return match ($state['state']) {
            CircuitBreaker::OPEN      => sprintf(
                /* translators: %s: human time difference. */
                __('paused, retry in %s', 'odds-comparison'),
                human_time_diff(time(), max(time() + 1, $state['openUntil']))
            ),
            CircuitBreaker::HALF_OPEN => __('testing (next request decides)', 'odds-comparison'),
            default                   => $state['failures'] > 0
                ? sprintf(
                    /* translators: %d: consecutive failures. */
                    __('allowed (%d recent failures)', 'odds-comparison'),
                    $state['failures']
                )
                : __('allowed', 'odds-comparison'),
        };
    }

    /**
     * robots.txt result text.
     *
     * @param array{checkedAt: int, allowed: ?bool, detail: string} $robots Result.
     * @return string
     */
    private function robotsText(array $robots): string
    {
        if ($robots['checkedAt'] === 0) {
            return __('not checked yet (checked daily during refresh)', 'odds-comparison');
        }

        $verdict = match ($robots['allowed']) {
            true    => __('allowed', 'odds-comparison'),
            false   => __('disallowed', 'odds-comparison'),
            default => __('check failed', 'odds-comparison'),
        };

        return $verdict . ': ' . $robots['detail'] . ' (' . $this->ago($robots['checkedAt']) . ')';
    }

    /**
     * The Odds API key and quota text.
     *
     * @return string
     */
    private function quotaText(): string
    {
        if (Plugin::theOddsApiKey() === '') {
            return __('API key not configured', 'odds-comparison');
        }

        $quota = TheOddsApiSource::quota();

        return $quota === null
            ? __('API key configured', 'odds-comparison')
            /* translators: %d: remaining credits. */
            : sprintf(__('%d credits left', 'odds-comparison'), $quota['remaining']);
    }
}
