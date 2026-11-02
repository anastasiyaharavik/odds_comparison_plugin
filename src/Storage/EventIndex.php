<?php

/**
 * Index of discovered events.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OddsComparison\Domain\Event;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Events found by discovery, stored in the non-autoloaded option `odds_comparison_events`.
 * The editor's event picker and render-time validation read it, and nothing
 * here scrapes.
 *
 * Discovery replaces one (source, competition) slice at a time, so upcoming events no longer
 * listed disappear. A match that has started stays for {@see self::STARTED_RETENTION} seconds
 * (even when the source stops listing it), so blocks showing it say "This event has started"
 * instead of going blank; it is never offered as upcoming ({@see self::search()}). The size is
 * bounded by enabled competitions × matches listed or played in the retention window. Per site
 * on multisite.
 */
final class EventIndex
{
    /** Seconds a started match is kept after kick-off (7 days). */
    public const STARTED_RETENTION = 7 * 86400;

    /**
     * Loaded events by id, or null before the first read.
     *
     * @var array<string, Event>|null
     */
    private ?array $events = null;

    /**
     * Replace the events of one competition on one source.
     *
     * Upcoming events of the slice that are no longer listed are dropped. Started matches of the
     * slice are kept until {@see self::STARTED_RETENTION} after kick-off, listed or not.
     *
     * @param string            $sourceId    Source id.
     * @param string            $competition Competition slug.
     * @param list<Event>       $events      Freshly discovered events.
     * @param DateTimeInterface $now         Current time.
     * @return void
     */
    public function replace(string $sourceId, string $competition, array $events, DateTimeInterface $now): void
    {
        $inSlice = static fn (Event $event): bool => $event->sourceId === $sourceId
            && $event->competition === $competition;
        $all     = array_filter(
            $this->all(),
            static fn (Event $event): bool => !$inSlice($event) || $event->hasStarted($now)
        );

        foreach ($events as $event) {
            if ($inSlice($event)) {
                $all[$event->id] = $event;
            }
        }

        $this->store(array_filter($all, static fn (Event $event): bool => !self::expired($event, $now)));
    }

    /**
     * Drop matches that started more than {@see self::STARTED_RETENTION} ago.
     *
     * @param DateTimeInterface $now Current time.
     * @return int Number of events removed.
     */
    public function prune(DateTimeInterface $now): int
    {
        $all  = $this->all();
        $kept = array_filter($all, static fn (Event $event): bool => !self::expired($event, $now));

        if (count($kept) !== count($all)) {
            $this->store($kept);
        }

        return count($all) - count($kept);
    }

    /**
     * Upcoming (not started) matches of one competition on one source.
     *
     * @param string            $sourceId    Source id.
     * @param string            $competition Competition slug.
     * @param DateTimeInterface $now         Current time.
     * @return int
     */
    public function upcomingMatchCount(string $sourceId, string $competition, DateTimeInterface $now): int
    {
        return count(array_filter(
            $this->all(),
            static fn (Event $event): bool => $event->sourceId === $sourceId
                && $event->competition === $competition
                && !$event->isOutright()
                && !$event->hasStarted($now)
        ));
    }

    /**
     * Forget every event (data mode switch); the next discovery fills the index again.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->store([]);
    }

    /**
     * One event by id.
     *
     * @param string $id Event id.
     * @return Event|null
     */
    public function get(string $id): ?Event
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Upcoming events of a source, filtered by a case-insensitive name search, soonest first
     * (outrights last). Started matches are left out before the limit applies.
     *
     * @param string                 $sourceId Source id.
     * @param string                 $search   Search text ("" for all).
     * @param int                    $limit    Maximum results.
     * @param DateTimeInterface|null $now      Current time (null: the real time).
     * @return list<Event>
     */
    public function search(
        string $sourceId,
        string $search = '',
        int $limit = 50,
        ?DateTimeInterface $now = null
    ): array {
        $now   ??= new DateTimeImmutable();
        $needle  = mb_strtolower(trim($search));
        $matches = array_filter(
            $this->all(),
            static fn (Event $event): bool => $event->sourceId === $sourceId
                && !$event->hasStarted($now)
                && ($needle === '' || str_contains(mb_strtolower($event->name), $needle))
        );

        usort($matches, static fn (Event $a, Event $b): int => [$a->startsAt === null, $a->startsAt, $a->name]
            <=> [$b->startsAt === null, $b->startsAt, $b->name]);

        return array_slice($matches, 0, max(1, $limit));
    }

    /**
     * All events by id.
     *
     * @return array<string, Event>
     */
    public function all(): array
    {
        if ($this->events !== null) {
            return $this->events;
        }

        $this->events = [];
        $stored       = get_option(OptionKeys::EVENTS, []);

        foreach (is_array($stored) ? $stored : [] as $row) {
            try {
                $event                    = Event::fromArray(is_array($row) ? $row : []);
                $this->events[$event->id] = $event;
            } catch (InvalidArgumentException) {
                // Skip rows written by an incompatible version; the next discovery rewrites them.
                continue;
            }
        }

        return $this->events;
    }

    /**
     * Whether a match started more than {@see self::STARTED_RETENTION} ago.
     *
     * @param Event             $event Event.
     * @param DateTimeInterface $now   Current time.
     * @return bool
     */
    private static function expired(Event $event, DateTimeInterface $now): bool
    {
        return $event->startsAt !== null
            && $now->getTimestamp() - $event->startsAt->getTimestamp() > self::STARTED_RETENTION;
    }

    /**
     * Persist events.
     *
     * @param array<string, Event> $events Events by id.
     * @return void
     */
    private function store(array $events): void
    {
        $this->events = $events;

        update_option(
            OptionKeys::EVENTS,
            array_values(array_map(static fn (Event $event): array => $event->toArray(), $events)),
            false
        );
    }
}
