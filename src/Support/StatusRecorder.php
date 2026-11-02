<?php

/**
 * Records source health and refresh runs for the Status tab.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use OddsComparison\Contracts\HookableInterface;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Observer of the refresh pipeline: listens to `odds_comparison/odds_updated`,
 * `odds_comparison/source_failed` and `odds_comparison/after_refresh`, and keeps a small
 * summary in the non-autoloaded option `odds_comparison_status`.
 *
 * @phpstan-type SourceStatus array{
 *     lastSuccess: int, lastError: string, lastErrorAt: int,
 *     robots: array{checkedAt: int, allowed: ?bool, detail: string},
 *     fetchedAt: array<string, int>
 * }
 */
final class StatusRecorder implements HookableInterface
{
    /** Runs kept for the "Recent refreshes" list. */
    public const MAX_RUNS = 10;

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('odds_comparison/odds_updated', [$this, 'onOddsUpdated']);
        add_action('odds_comparison/source_failed', [$this, 'onSourceFailed'], 10, 2);
        add_action('odds_comparison/after_refresh', [$this, 'onAfterRefresh']);
    }

    /**
     * Record a successful fetch (the source id is the event id prefix).
     *
     * @param string $eventId Event id.
     * @return void
     */
    public function onOddsUpdated(string $eventId): void
    {
        $this->updateSource(strstr($eventId, ':', true) ?: $eventId, ['lastSuccess' => time()]);
    }

    /**
     * Record a failure.
     *
     * @param string   $sourceId Source id.
     * @param WP_Error $error    Failure.
     * @return void
     */
    public function onSourceFailed(string $sourceId, WP_Error $error): void
    {
        $this->updateSource($sourceId, ['lastError' => $error->get_error_message(), 'lastErrorAt' => time()]);
    }

    /**
     * Record a finished run.
     *
     * @param array<string, mixed> $summary Run summary.
     * @return void
     */
    public function onAfterRefresh(array $summary): void
    {
        $status            = $this->all();
        $status['lastRun'] = $summary;
        $status['runs']    = array_slice([$summary, ...$this->recentRuns()], 0, self::MAX_RUNS);
        $this->store($status);
    }

    /**
     * Record when a competition was last fetched from a source (quota pacing).
     *
     * @param string $sourceId    Source id.
     * @param string $competition Competition slug.
     * @return void
     */
    public function recordCompetitionFetch(string $sourceId, string $competition): void
    {
        $fetchedAt               = $this->source($sourceId)['fetchedAt'];
        $fetchedAt[$competition] = time();
        $this->updateSource($sourceId, ['fetchedAt' => $fetchedAt]);
    }

    /**
     * Record a robots.txt check.
     *
     * @param string    $sourceId Source id.
     * @param bool|null $allowed  Whether all used paths are allowed; null when the check failed.
     * @param string    $detail   Plain-text detail.
     * @return void
     */
    public function recordRobots(string $sourceId, ?bool $allowed, string $detail): void
    {
        $this->updateSource(
            $sourceId,
            ['robots' => ['checkedAt' => time(), 'allowed' => $allowed, 'detail' => $detail]]
        );
    }

    /**
     * Status of one source (defaults filled in).
     *
     * @param string $sourceId Source id.
     * @return SourceStatus
     */
    public function source(string $sourceId): array
    {
        $stored = $this->all()['sources'][$sourceId] ?? [];
        $stored = is_array($stored) ? $stored : [];
        $robots = is_array($stored['robots'] ?? null) ? $stored['robots'] : [];

        return [
            'lastSuccess' => (int) ($stored['lastSuccess'] ?? 0),
            'lastError'   => (string) ($stored['lastError'] ?? ''),
            'lastErrorAt' => (int) ($stored['lastErrorAt'] ?? 0),
            'robots'      => [
                'checkedAt' => (int) ($robots['checkedAt'] ?? 0),
                'allowed'   => isset($robots['allowed']) ? (bool) $robots['allowed'] : null,
                'detail'    => (string) ($robots['detail'] ?? ''),
            ],
            'fetchedAt'   => array_map('intval', is_array($stored['fetchedAt'] ?? null) ? $stored['fetchedAt'] : []),
        ];
    }

    /**
     * Last run summary, or null before the first run.
     *
     * @return array<string, mixed>|null
     */
    public function lastRun(): ?array
    {
        $run = $this->all()['lastRun'] ?? null;

        return is_array($run) ? $run : null;
    }

    /**
     * Recent run summaries, newest first (at most MAX_RUNS).
     *
     * @return list<array<string, mixed>>
     */
    public function recentRuns(): array
    {
        $runs = $this->all()['runs'] ?? [];

        return is_array($runs) ? array_values(array_filter($runs, 'is_array')) : [];
    }

    /**
     * Merge fields into one source's status.
     *
     * @param string               $sourceId Source id.
     * @param array<string, mixed> $fields   Fields.
     * @return void
     */
    private function updateSource(string $sourceId, array $fields): void
    {
        $status                       = $this->all();
        $status['sources'][$sourceId] = array_replace($this->source($sourceId), $fields);
        $this->store($status);
    }

    /**
     * Whole status option.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $status = get_option(OptionKeys::STATUS, []);

        return is_array($status) ? $status : [];
    }

    /**
     * Persist the status option (not autoloaded).
     *
     * @param array<string, mixed> $status Status.
     * @return void
     */
    private function store(array $status): void
    {
        update_option(OptionKeys::STATUS, $status, false);
    }
}
