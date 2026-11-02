<?php

/**
 * Index of discovered events.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Storage;

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
 * Discovery replaces one (source, competition) slice at a time, so events no longer listed
 * disappear. Matches that have started are pruned. The size is bounded by enabled competitions
 * × listed matches. Per site on multisite.
 */
final class EventIndex
{
    /**
     * Loaded events by id, or null before the first read.
     *
     * @var array<string, Event>|null
     */
    private ?array $events = null;

    /**
     * Replace the events of one competition on one source, dropping started matches.
     *
     * @param string            $sourceId    Source id.
     * @param string            $competition Competition slug.
     * @param list<Event>       $events      Freshly discovered events.
     * @param DateTimeInterface $now         Current time.
     * @return void
     */
    public function replace(string $sourceId, string $competition, array $events, DateTimeInterface $now): void
    {
        $all = array_filter(
            $this->all(),
            static fn (Event $event): bool => $event->sourceId !== $sourceId || $event->competition !== $competition
        );

        foreach ($events as $event) {
            if ($event->sourceId === $sourceId && $event->competition === $competition && !$event->hasStarted($now)) {
                $all[$event->id] = $event;
            }
        }

        $this->store($all);
    }

    /**
     * Drop matches that have started.
     *
     * @param DateTimeInterface $now Current time.
     * @return int Number of events removed.
     */
    public function prune(DateTimeInterface $now): int
    {
        $all  = $this->all();
        $kept = array_filter($all, static fn (Event $event): bool => !$event->hasStarted($now));

        if (count($kept) !== count($all)) {
            $this->store($kept);
        }

        return count($all) - count($kept);
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
     * (outrights last).
     *
     * @param string $sourceId Source id.
     * @param string $search   Search text ("" for all).
     * @param int    $limit    Maximum results.
     * @return list<Event>
     */
    public function search(string $sourceId, string $search = '', int $limit = 50): array
    {
        $needle  = mb_strtolower(trim($search));
        $matches = array_filter(
            $this->all(),
            static fn (Event $event): bool => $event->sourceId === $sourceId
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
