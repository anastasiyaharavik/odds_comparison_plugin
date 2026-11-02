<?php

/**
 * Refreshes single (event, market) pairs and competition event lists.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Scheduler;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Http\TimeBudgetExceeded;
use OddsComparison\Contracts\DiagnosticsInterface;
use OddsComparison\Http\WpHttpClient;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\Logger;
use OddsComparison\Support\OptionKeys;
use RuntimeException;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * One step of the refresh pipeline, used by the cron scheduler
 * and the manual refresh. It fires the pipeline's hooks:
 *
 * - `odds_comparison/market_odds` (filter): validated odds, before saving.
 * - `odds_comparison/odds_updated` (action): after saving, with the post ids showing the pair,
 *   so integrators can purge page caches.
 * - `odds_comparison/source_failed` (action): fetch, parse or validation failure, with a
 *   WP_Error. The previous good data is never touched.
 *
 * It never scrapes on its own initiative; callers decide what is due. At the end of a run,
 * {@see self::reportDiagnostics()} moves the source's non-fatal warnings to the error log.
 */
final class OddsRefresher
{
    public const FETCHED = 'fetched';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** Distinct warnings quoted in one log entry; the rest are counted. */
    private const MAX_QUOTED_WARNINGS = 5;

    /** Unknown bookmaker codes named in one log entry; the rest are counted. */
    private const MAX_QUOTED_CODES = 10;

    /** Unchanged diagnostics are logged again after this many seconds (1 day) as a reminder. */
    private const REMIND_AFTER = 86400;

    private readonly Closure $clock;

    /**
     * @param SourceFactory           $sources    Source factory.
     * @param EventIndex              $events     Event index.
     * @param MarketRegistry          $markets    Market registry.
     * @param OddsRepositoryInterface $repository Odds storage.
     * @param TrackedEventsIndex      $tracked    Tracked pairs (post ids for cache purges).
     * @param Logger                  $logger     Error log.
     * @param Closure|null            $clock      fn(): DateTimeImmutable.
     */
    public function __construct(
        private readonly SourceFactory $sources,
        private readonly EventIndex $events,
        private readonly MarketRegistry $markets,
        private readonly OddsRepositoryInterface $repository,
        private readonly TrackedEventsIndex $tracked,
        private readonly Logger $logger,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Fetch, validate, filter and store one pair.
     *
     * @param string $eventId   Event id (must be in the event index).
     * @param string $marketKey Market key.
     * @return string One of FETCHED, FAILED, SKIPPED.
     */
    public function refreshPair(string $eventId, string $marketKey): string
    {
        $event  = $this->events->get($eventId);
        $market = $this->markets->get($marketKey);

        if ($event === null || $market === null) {
            $this->logger->warning(
                'refresh',
                sprintf('Skipped %s / %s: unknown event or market.', $eventId, $marketKey)
            );

            return self::SKIPPED;
        }

        try {
            $odds = $this->sources->make($event->sourceId)->fetchMarket($event, $market);
        } catch (TimeBudgetExceeded) {
            return self::SKIPPED; // Nothing was requested; the pair stays due for the next run.
        } catch (RuntimeException | InvalidArgumentException $exception) {
            $this->fail($event->sourceId, $exception->getMessage());

            return self::FAILED;
        }

        /**
         * Filters validated odds before they are stored.
         *
         * @param MarketOdds                          $odds   Validated odds.
         * @param \OddsComparison\Domain\Event        $event  Event.
         * @param \OddsComparison\Domain\Market       $market Market.
         */
        $filtered = apply_filters('odds_comparison/market_odds', $odds, $event, $market);

        if (!$filtered instanceof MarketOdds) {
            $this->logger->warning('refresh', 'odds_comparison/market_odds returned a non-MarketOdds value; ignored.');
            $filtered = $odds;
        }

        if (!$this->repository->save($filtered)) {
            $this->logger->error('refresh', sprintf('Could not store odds for %s / %s.', $eventId, $marketKey));

            return self::FAILED;
        }

        /**
         * Fires after fresh odds were stored.
         *
         * @param string    $eventId   Event id.
         * @param string    $marketKey Market key.
         * @param list<int> $postIds   Posts showing this pair (purge their page cache).
         */
        $postIds = $this->tracked->postIdsFor($eventId, $marketKey);

        do_action('odds_comparison/odds_updated', $eventId, $marketKey, $postIds);

        return self::FETCHED;
    }

    /**
     * Rediscover a competition's events on a source and replace them in the index.
     *
     * A listing that yields no matches while upcoming matches of the competition are stored is a
     * failure, not news: a blank, truncated or changed page answered with HTTP 200 must not wipe
     * the events that published tables show. The stored events stay and `source_failed` fires
     * (Status tab, error log). Once the stored matches have kicked off, an empty listing is
     * accepted (end of season, international break).
     *
     * @param string      $sourceId    Source id.
     * @param Competition $competition Competition.
     * @return int|null Number of events found, or null on failure or when the run is out of time
     *                  (index left unchanged).
     */
    public function discover(string $sourceId, Competition $competition): ?int
    {
        try {
            $events = $this->sources->make($sourceId)->discoverEvents($competition);
        } catch (TimeBudgetExceeded) {
            return null; // Nothing was requested; discovery runs again next time.
        } catch (RuntimeException | InvalidArgumentException $exception) {
            $this->fail($sourceId, $exception->getMessage());

            return null;
        }

        $now     = ($this->clock)();
        $matches = count(array_filter($events, static fn (Event $event): bool => !$event->isOutright()));
        $stored  = $this->events->upcomingMatchCount($sourceId, $competition->slug, $now);

        if ($matches === 0 && $stored > 0) {
            $this->fail($sourceId, sprintf(
                '%s listing returned no matches although %d upcoming match(es) are stored; '
                . 'the page may be empty, blocked or changed. Stored events were kept.',
                $competition->name,
                $stored
            ));

            return null;
        }

        $this->events->replace($sourceId, $competition->slug, $events, $now);

        return count($events);
    }

    /**
     * Move a source's collected warnings and unknown bookmaker codes to the error log, then clear them.
     *
     * Called once at the end of every refresh run. To keep the capped log useful for real errors,
     * a run writes at most two warning entries (one for warnings, one for unknown codes), and
     * only when their content changed since the last entry, or a day later if it did not.
     * Unknown codes are expected on a comparison site that lists more bookmakers than the
     * registry; the entry names them so an admin can add one with `odds_comparison/bookmakers`.
     *
     * @param string $sourceId Source id.
     * @return void
     */
    public function reportDiagnostics(string $sourceId): void
    {
        if (!$this->sources->has($sourceId)) {
            return;
        }

        try {
            $source = $this->sources->make($sourceId);
        } catch (InvalidArgumentException) {
            return;
        }

        if (!$source instanceof DiagnosticsInterface) {
            return;
        }

        $warnings = array_values(array_unique($source->warnings()));
        $codes    = $source->unmappedCodes();
        $source->clearDiagnostics();

        if ($warnings !== []) {
            $quoted = array_slice($warnings, 0, self::MAX_QUOTED_WARNINGS);
            $more   = count($warnings) - count($quoted);

            $this->logWhenChanged($sourceId, 'warnings', $warnings, sprintf(
                '%d data warning(s) during the refresh: %s%s',
                count($warnings),
                implode(' | ', $quoted),
                $more > 0 ? sprintf(' (+%d more)', $more) : ''
            ));
        }

        if ($codes !== []) {
            arsort($codes);
            $named = [];

            foreach (array_slice($codes, 0, self::MAX_QUOTED_CODES, true) as $code => $count) {
                $named[] = sprintf('%s (%d)', $code, $count);
            }

            $more = count($codes) - count($named);

            $this->logWhenChanged($sourceId, 'unmapped', array_keys($codes), sprintf(
                'Bookmakers on the source that are not configured, so their prices are ignored: %s%s. '
                . 'Add one with the odds_comparison/bookmakers filter if needed.',
                implode(', ', $named),
                $more > 0 ? sprintf(' and %d more', $more) : ''
            ));
        }
    }

    /**
     * Log a warning unless the same items were logged for this source within the last day.
     *
     * Items are compared without digits and without a trailing ", e.g. …" example, so changing
     * counts or examples alone ("3 prices skipped" vs "4 prices skipped") do not count as a change.
     *
     * @param string       $sourceId Source id.
     * @param string       $kind     Diagnostics kind (`warnings` or `unmapped`).
     * @param list<string> $items    Items the message is built from.
     * @param string       $message  Log message.
     * @return void
     */
    private function logWhenChanged(string $sourceId, string $kind, array $items, string $message): void
    {
        $normalized = array_map(
            static fn (string $item): string => (string) preg_replace(
                ['/, e\.g\. .*$/s', '/\d+/'],
                ['', '#'],
                strtolower($item)
            ),
            $items
        );
        sort($normalized);

        $key         = self::diagnosticsTransient($sourceId, $kind);
        $fingerprint = md5(implode("\n", array_unique($normalized)));

        if (get_transient($key) === $fingerprint) {
            return;
        }

        set_transient($key, $fingerprint, self::REMIND_AFTER);
        $this->logger->warning($sourceId, $message);
    }

    /**
     * Transient remembering the last logged diagnostics of one kind (uninstall deletes it).
     *
     * @param string $sourceId Source id.
     * @param string $kind     `warnings` or `unmapped`.
     * @return string
     */
    public static function diagnosticsTransient(string $sourceId, string $kind): string
    {
        return OptionKeys::TRANSIENT_PREFIX . 'diag_' . md5($sourceId . '|' . $kind);
    }

    /**
     * Log a source failure and notify listeners.
     *
     * @param string $sourceId Source id.
     * @param string $message  Failure message; API keys are redacted before it is stored or passed on.
     * @return void
     */
    private function fail(string $sourceId, string $message): void
    {
        // Redact again here: the message reaches the status option and third-party listeners.
        $message = WpHttpClient::redact($message);

        $this->logger->error($sourceId, $message);

        /**
         * Fires when a source request, parse or validation fails. Cached odds are unchanged.
         *
         * @param string   $sourceId Source id.
         * @param WP_Error $error    Failure.
         */
        do_action('odds_comparison/source_failed', $sourceId, new WP_Error('odds_comparison_source_failed', $message));
    }
}
