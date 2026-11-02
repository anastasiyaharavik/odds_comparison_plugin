<?php

/**
 * Records source health and refresh runs for the Status tab.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use Closure;
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
 *     robots: array{checkedAt: int, allowed: ?bool, detail: string, disallowed: list<string>},
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
        $this->modify(static function (array $status) use ($summary): array {
            $runs              = is_array($status['runs'] ?? null) ? $status['runs'] : [];
            $runs              = array_values(array_filter($runs, 'is_array'));
            $status['lastRun'] = $summary;
            $status['runs']    = array_slice([$summary, ...$runs], 0, self::MAX_RUNS);

            return $status;
        });
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
        $now = time();

        $this->updateSource($sourceId, static fn (array $current): array => [
            'fetchedAt' => array_replace($current['fetchedAt'], [$competition => $now]),
        ]);
    }

    /**
     * Record a robots.txt check.
     *
     * @param string    $sourceId Source id.
     * @param bool|null $allowed  Whether all used paths are allowed; null when the check failed.
     * @param string       $detail     Plain-text detail.
     * @param list<string> $disallowed Host-relative paths the plugin uses that robots.txt disallows
     *                                 (refreshes skip them until the next check).
     * @return void
     */
    public function recordRobots(string $sourceId, ?bool $allowed, string $detail, array $disallowed = []): void
    {
        $robots = [
            'checkedAt'  => time(),
            'allowed'    => $allowed,
            'detail'     => $detail,
            'disallowed' => array_values(array_filter($disallowed, 'is_string')),
        ];

        $this->updateSource($sourceId, static fn (): array => ['robots' => $robots]);
    }

    /**
     * Forget every source's health, fetch times and robots.txt result (data mode switch: they
     * describe the other mode). Run summaries are kept.
     *
     * @return void
     */
    public function resetSources(): void
    {
        $this->modify(static function (array $status): array {
            unset($status['sources']);

            return $status;
        });
    }

    /**
     * Status of one source (defaults filled in).
     *
     * @param string $sourceId Source id.
     * @return SourceStatus
     */
    public function source(string $sourceId): array
    {
        return self::sourceFrom($this->all(), $sourceId);
    }

    /**
     * One source's status out of a status array (defaults filled in).
     *
     * @param array<string, mixed> $status   Whole status option.
     * @param string               $sourceId Source id.
     * @return SourceStatus
     */
    private static function sourceFrom(array $status, string $sourceId): array
    {
        $stored = is_array($status['sources'] ?? null) ? ($status['sources'][$sourceId] ?? []) : [];
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
                'disallowed' => is_array($robots['disallowed'] ?? null)
                    ? array_values(array_filter($robots['disallowed'], 'is_string'))
                    : [],
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
     * @param string                                              $sourceId Source id.
     * @param array<string, mixed>|Closure(SourceStatus): array<string, mixed> $fields Fields, or a
     *        function of the source's current status returning them (read inside the lock).
     * @return void
     */
    private function updateSource(string $sourceId, array|Closure $fields): void
    {
        $this->modify(static function (array $status) use ($sourceId, $fields): array {
            $current                      = self::sourceFrom($status, $sourceId);
            $status['sources']            = is_array($status['sources'] ?? null) ? $status['sources'] : [];
            $changes                      = $fields instanceof Closure ? $fields($current) : $fields;
            $status['sources'][$sourceId] = array_replace($current, $changes);

            return $status;
        });
    }

    /**
     * Change the status option under its lock, starting from the value other requests stored.
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $change Change.
     * @return void
     */
    private function modify(Closure $change): void
    {
        OptionMutex::update(
            OptionKeys::STATUS,
            static fn (mixed $stored): array => $change(is_array($stored) ? $stored : [])
        );
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
}
