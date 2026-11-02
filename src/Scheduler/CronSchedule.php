<?php

/**
 * WP-Cron schedule registration for the refresh job.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Scheduler;

use OddsComparison\Contracts\HookableInterface;

defined('ABSPATH') || exit;

/**
 * Registers the custom cron intervals and (un)schedules the recurring refresh event.
 *
 * The refresh logic itself attaches to {@see self::HOOK} in RefreshScheduler.
 */
final class CronSchedule implements HookableInterface
{
    /** Cron event hook fired on every refresh tick. */
    public const HOOK = 'odds_comparison_refresh';

    /** Allowed refresh intervals in minutes (mirrors the admin select). */
    public const INTERVALS = [5, 15, 30, 60];

    /** Interval used when an unsupported value is requested. */
    public const DEFAULT_INTERVAL = 15;

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedules']);
        add_action('odds_comparison/settings_saved', [$this, 'onSettingsSaved'], 10, 2);
    }

    /**
     * Reschedule the refresh event when the refresh interval changed.
     *
     * @param array<string, mixed> $new New settings.
     * @param array<string, mixed> $old Previous settings.
     * @return void
     */
    public function onSettingsSaved(array $new, array $old): void
    {
        $interval = (int) ($new['refresh_interval'] ?? self::DEFAULT_INTERVAL);

        if ($interval !== (int) ($old['refresh_interval'] ?? 0)) {
            $this->schedule($interval);
        }
    }

    /**
     * Add one cron schedule per allowed interval.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedules(array $schedules): array
    {
        foreach (self::INTERVALS as $minutes) {
            $schedules[$this->scheduleName($minutes)] = [
                'interval' => $minutes * MINUTE_IN_SECONDS,
                /* translators: %d: number of minutes. */
                'display'  => sprintf(__('Every %d minutes (Odds Comparison)', 'odds-comparison'), $minutes),
            ];
        }

        return $schedules;
    }

    /**
     * Cron schedule name for an interval; unsupported values fall back to the default.
     *
     * @param int $minutes Interval in minutes.
     * @return string
     */
    public function scheduleName(int $minutes): string
    {
        return sprintf('odds_comparison_%dmin', $this->normalize($minutes));
    }

    /**
     * (Re)schedule the recurring refresh event, replacing any existing one.
     *
     * The first run is delayed by one minute so activation stays fast.
     *
     * @param int $minutes Interval in minutes.
     * @return bool Whether the event was scheduled.
     */
    public function schedule(int $minutes): bool
    {
        $this->unschedule();

        return wp_schedule_event(time() + MINUTE_IN_SECONDS, $this->scheduleName($minutes), self::HOOK) === true;
    }

    /**
     * Schedule the event only if it is missing or uses a different interval.
     *
     * @param int $minutes Interval in minutes.
     * @return void
     */
    public function ensureScheduled(int $minutes): void
    {
        $event = wp_get_scheduled_event(self::HOOK);

        if ($event === false || $event->schedule !== $this->scheduleName($minutes)) {
            $this->schedule($minutes);
        }
    }

    /**
     * Remove every scheduled occurrence of the refresh event.
     *
     * @return void
     */
    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Timestamp of the next scheduled run, or null when not scheduled.
     *
     * @return int|null
     */
    public function nextRun(): ?int
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        return $timestamp === false ? null : (int) $timestamp;
    }

    /**
     * Clamp an interval to the allowed list.
     *
     * @param int $minutes Requested interval.
     * @return int
     */
    private function normalize(int $minutes): int
    {
        return in_array($minutes, self::INTERVALS, true) ? $minutes : self::DEFAULT_INTERVAL;
    }
}
