<?php

/**
 * Cron-driven refresh run.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Scheduler;

use Closure;
use DateTimeImmutable;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\Event;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Deadline;
use OddsComparison\Support\RobotsMonitor;
use OddsComparison\Support\StatusRecorder;

defined('ABSPATH') || exit;

/**
 * One refresh run on the `odds_comparison_refresh` cron hook .
 *
 * 1. Take the lock; overlapping runs return immediately.
 * 2. Fire `odds_comparison/before_refresh`.
 * 3. Check robots.txt of the active source once a day.
 * 4. Discover events of enabled competitions on the active source.
 * 5. Refresh due tracked pairs of the active source, soonest kick-off first. A pair is due when
 *    it has no odds or they are older than the refresh interval (The Odds API: the API interval,
 *    to protect the credit quota).
 * 6. Stop at `requests_per_run` requests, or when the next step would not fit the time budget
 *    (estimated from the slowest step so far); the rest waits for the next run. The same
 *    deadline caps HTTP timeouts and retries, so a run ends within the budget.
 * 7. Report the source's non-fatal warnings and unknown bookmaker codes to the error log
 *    ({@see OddsRefresher::reportDiagnostics()}).
 * 8. Fire `odds_comparison/after_refresh` with the run summary, then release the lock.
 */
final class RefreshScheduler implements HookableInterface
{
    /** Seconds a run may spend, below PHP's default 30 s max_execution_time. */
    public const TIME_BUDGET = 25.0;

    private readonly Closure $clock;

    /**
     * @param Settings                $settings     Settings.
     * @param CompetitionRegistry     $competitions Competition registry.
     * @param EventIndex              $events       Event index.
     * @param TrackedEventsIndex      $tracked      Tracked pairs.
     * @param OddsRepositoryInterface $repository   Odds storage.
     * @param OddsRefresher           $refresher    Single-pair refresh step.
     * @param RobotsMonitor           $robots       Robots.txt check.
     * @param StatusRecorder          $status       Status (quota pacing).
     * @param Lock                    $lock         Run lock.
     * @param Deadline                $deadline     Run deadline, shared with the HTTP client.
     * @param Closure|null            $clock        fn(): DateTimeImmutable.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CompetitionRegistry $competitions,
        private readonly EventIndex $events,
        private readonly TrackedEventsIndex $tracked,
        private readonly OddsRepositoryInterface $repository,
        private readonly OddsRefresher $refresher,
        private readonly RobotsMonitor $robots,
        private readonly StatusRecorder $status,
        private readonly Lock $lock,
        private readonly Deadline $deadline,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action(CronSchedule::HOOK, [$this, 'run']);
    }

    /**
     * Queue a manual run and nudge WP-Cron (asynchronous; the caller polls the status).
     *
     * @return bool Whether a new run was queued (false when one is already waiting).
     */
    public static function queueManualRun(): bool
    {
        $queued = wp_next_scheduled(CronSchedule::HOOK, ['manual']) === false
            && wp_schedule_single_event(time(), CronSchedule::HOOK, ['manual']) === true;

        spawn_cron();

        return $queued;
    }

    /**
     * Run one refresh.
     *
     * @param string $trigger "cron" or "manual".
     * @return array<string, mixed> Run summary (also passed to `odds_comparison/after_refresh`).
     */
    public function run(string $trigger = 'cron'): array
    {
        $sourceId = $this->settings->activeSource();
        $summary  = [
            'trigger'    => $trigger === 'manual' ? 'manual' : 'cron',
            'source'     => $sourceId,
            'startedAt'  => time(),
            'finishedAt' => 0,
            'discovered' => 0,
            'fetched'    => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'seconds'    => 0.0,
        ];

        if (!$this->lock->acquire()) {
            return ['locked' => true] + $summary;
        }

        $this->deadline->start(self::TIME_BUDGET);

        try {
            /**
             * Fires when a refresh run starts.
             *
             * @param string $sourceId Active source id.
             */
            do_action('odds_comparison/before_refresh', $sourceId);

            $budget   = $this->settings->requestsPerRun();
            $slowest  = 0.0;
            $requests = $this->robots->checkIfDue($sourceId, $this->settings->enabledCompetitions()) ? 1 : 0;

            foreach ($this->competitions->only($this->settings->enabledCompetitions()) as $competition) {
                $covered = $competition->sourcePath($sourceId) !== null;

                if (!$covered || !$this->competitionDue($sourceId, $competition->slug)) {
                    continue;
                }

                if ($requests >= $budget || !$this->deadline->allows($slowest)) {
                    break;
                }

                $stepStart = $this->deadline->elapsed();
                $count     = $this->refresher->discover($sourceId, $competition);
                $slowest   = max($slowest, $this->deadline->elapsed() - $stepStart);
                ++$requests;

                if ($count !== null) {
                    $summary['discovered'] += $count;
                    $this->status->recordCompetitionFetch($sourceId, $competition->slug);
                }
            }

            $this->events->prune(($this->clock)());

            foreach ($this->duePairs($sourceId) as $pair) {
                if ($requests >= $budget || !$this->deadline->allows($slowest)) {
                    ++$summary['skipped'];
                    continue;
                }

                $stepStart = $this->deadline->elapsed();
                $result    = $this->refresher->refreshPair($pair['eventId'], $pair['marketKey']);
                $slowest   = max($slowest, $this->deadline->elapsed() - $stepStart);
                ++$requests;
                ++$summary[$result];
            }
        } finally {
            // Warnings the source collected during this run go to the error log (Status tab).
            $this->refresher->reportDiagnostics($sourceId);

            $summary['finishedAt'] = time();
            $summary['seconds']    = round($this->deadline->elapsed(), 2);
            $this->deadline->clear();
            $this->lock->release();
        }

        /**
         * Fires when a refresh run finished.
         *
         * @param array<string, mixed> $summary Counts: discovered, fetched, failed, skipped; trigger, source,
         *                                      startedAt, finishedAt and seconds (run duration).
         */
        do_action('odds_comparison/after_refresh', $summary);

        return $summary;
    }

    /**
     * Tracked pairs of a source that are due, soonest kick-off first (outrights last).
     *
     * @param string $sourceId Source id.
     * @return list<array{eventId: string, marketKey: string}>
     */
    public function duePairs(string $sourceId): array
    {
        $now     = ($this->clock)();
        $maxAge  = $this->maxAgeSeconds($sourceId);
        $enabled = $this->settings->enabledMarkets();
        $due     = [];

        foreach ($this->tracked->pairs() as $pair) {
            $event = $this->events->get($pair['eventId']);

            if (
                $event === null
                || $event->sourceId !== $sourceId
                || $event->hasStarted($now)
                || !in_array($pair['marketKey'], $enabled, true)
            ) {
                continue;
            }

            $odds = $this->repository->get($pair['eventId'], $pair['marketKey']);

            if ($odds === null || $now->getTimestamp() - $odds->fetchedAt->getTimestamp() >= $maxAge) {
                $due[] = [$event, ['eventId' => $pair['eventId'], 'marketKey' => $pair['marketKey']]];
            }
        }

        usort($due, static fn (array $a, array $b): int => self::sortKey($a[0]) <=> self::sortKey($b[0]));

        return array_column($due, 1);
    }

    /**
     * Whether a competition should be (re)discovered now. The Odds API is paced by its own
     * interval; scraped sources are discovered every run.
     *
     * @param string $sourceId    Source id.
     * @param string $competition Competition slug.
     * @return bool
     */
    private function competitionDue(string $sourceId, string $competition): bool
    {
        if ($sourceId !== TheOddsApiSource::ID) {
            return true;
        }

        $last = $this->status->source($sourceId)['fetchedAt'][$competition] ?? 0;

        return time() - $last >= $this->maxAgeSeconds($sourceId);
    }

    /**
     * Age at which stored odds are re-fetched.
     *
     * @param string $sourceId Source id.
     * @return int Seconds.
     */
    private function maxAgeSeconds(string $sourceId): int
    {
        $minutes = $sourceId === TheOddsApiSource::ID
            ? $this->settings->apiRefreshInterval()
            : $this->settings->cacheTtl()['refresh_interval'];

        return max(1, $minutes) * 60;
    }

    /**
     * Sort key: matches by kick-off, outrights after all matches.
     *
     * @param Event $event Event.
     * @return array{int, int}
     */
    private static function sortKey(Event $event): array
    {
        return $event->startsAt === null ? [1, 0] : [0, $event->startsAt->getTimestamp()];
    }
}
