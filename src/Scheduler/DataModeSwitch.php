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
use OddsComparison\Support\StatusRecorder;

defined('ABSPATH') || exit;

/**
 * Listens to `odds_comparison/settings_saved` (Observer). When the data mode changes between
 * Demo and Live it clears the stored odds, the event index and the per-source status, then
 * queues a refresh run, so visitors never see demo odds labelled as live (or the reverse) and
 * the new mode fills the tables within a minute.
 */
final class DataModeSwitch implements HookableInterface
{
    /**
     * @param OddsRepositoryInterface $repository Odds storage.
     * @param EventIndex              $events     Discovered events.
     * @param StatusRecorder          $status     Source status.
     */
    public function __construct(
        private readonly OddsRepositoryInterface $repository,
        private readonly EventIndex $events,
        private readonly StatusRecorder $status
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

        $this->repository->flush();
        $this->events->clear();
        $this->status->resetSources();
        RefreshScheduler::scheduleManualRun();
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
