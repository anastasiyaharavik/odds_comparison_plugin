<?php

/**
 * Resets fetched data when the data mode changes.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Scheduler;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Storage\EventIndex;
use OddsComparison\Storage\TrackedEventsIndex;
use OddsComparison\Support\OptionKeys;
use OddsComparison\Support\StatusRecorder;

defined('ABSPATH') || exit;

/**
 * Listens to `odds_comparison/settings_saved` (Observer). When the data mode changes between
 * Demo and Live it clears the stored odds, the event index and the per-source status, then
 * queues a refresh run, so visitors never see demo odds labelled as live (or the reverse) and
 * the new mode fills the tables within a minute.
 *
 * Demo matches are not in a live feed, so blocks that showed one have nothing to show after a
 * switch to Live. The switch records the events published blocks used
 * ({@see OptionKeys::DEMO_EVENTS}); {@see self::postsShowingDemoEvents()} then lists the posts
 * whose blocks still point at one of them, for the Status tab and Dashboard warning.
 */
final class DataModeSwitch implements HookableInterface
{
    /**
     * @param OddsRepositoryInterface $repository Odds storage.
     * @param EventIndex              $events     Discovered events.
     * @param StatusRecorder          $status     Source status.
     * @param TrackedEventsIndex      $tracked    Pairs used by blocks.
     */
    public function __construct(
        private readonly OddsRepositoryInterface $repository,
        private readonly EventIndex $events,
        private readonly StatusRecorder $status,
        private readonly TrackedEventsIndex $tracked
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('odds_comparison/settings_saved', [$this, 'onSettingsSaved'], 10, 2);
    }

    /**
     * Reset and refresh when the data mode changed.
     *
     * @param array<string, mixed> $new New settings (defaults merged).
     * @param array<string, mixed> $old Previous settings (defaults merged).
     * @return void
     */
    public function onSettingsSaved(array $new, array $old): void
    {
        if (self::isDemo($new['data_mode'] ?? null) === self::isDemo($old['data_mode'] ?? null)) {
            return;
        }

        if (self::isDemo($new['data_mode'] ?? null)) {
            delete_option(OptionKeys::DEMO_EVENTS);
        } else {
            update_option(OptionKeys::DEMO_EVENTS, $this->publishedEventIds(), false);
        }

        $this->repository->flush();
        $this->events->clear();
        $this->status->resetSources();
        RefreshScheduler::scheduleManualRun();
    }

    /**
     * Published posts whose blocks still show an event from before the switch to Live that the
     * live source does not list (a demo match). Empty until the first live discovery has stored
     * events, so the list never shows every post just because discovery has not run yet.
     *
     * @return array<int, list<string>> Post id => event ids.
     */
    public function postsShowingDemoEvents(): array
    {
        $demo = get_option(OptionKeys::DEMO_EVENTS, []);

        if (!is_array($demo) || $demo === [] || $this->events->all() === []) {
            return [];
        }

        $posts = [];

        foreach ($this->tracked->pairs() as $pair) {
            if (!in_array($pair['eventId'], $demo, true) || $this->events->get($pair['eventId']) !== null) {
                continue;
            }

            foreach ($pair['postIds'] as $postId) {
                $posts[$postId][] = $pair['eventId'];
            }
        }

        ksort($posts);

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $posts);
    }

    /**
     * Ids of the events that published blocks show.
     *
     * @return list<string>
     */
    private function publishedEventIds(): array
    {
        $ids = [];

        foreach ($this->tracked->pairs() as $pair) {
            if ($pair['postIds'] !== []) {
                $ids[] = $pair['eventId'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a stored data mode value means Demo (see Settings::dataMode()).
     *
     * @param mixed $mode Stored value.
     * @return bool
     */
    private static function isDemo(mixed $mode): bool
    {
        return in_array($mode, ['demo', 'recorded'], true);
    }
}
