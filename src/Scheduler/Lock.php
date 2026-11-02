<?php

/**
 * Refresh-run lock.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Scheduler;

use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Prevents overlapping refresh runs (cron + cron, or cron + manual refresh).
 *
 * The lock is a row in the options table whose value is `{unix time}:{random token}`. Every step
 * is a single atomic SQL statement, the same approach as WordPress core's upgrader lock:
 *
 * - Acquire: `INSERT IGNORE` succeeds for exactly one process (`add_option()` is not enough: it
 *   ends in `ON DUPLICATE KEY UPDATE`, so two racing calls can both report success).
 * - Take over: a lock older than {@see self::STALE_SECONDS} was left by a crashed run. It is
 *   replaced with a compare-and-swap `UPDATE ... WHERE option_value = <stale value>`, so when
 *   several processes see the same stale lock only one of them wins.
 * - Release: deletes the row only while it still holds this process's token, so a run that was
 *   taken over cannot remove its successor's lock.
 *
 * The row is read and written only here, with direct queries, and is never autoloaded; the
 * options cache is cleared after each write so get_option() elsewhere never sees an old value.
 */
final class Lock
{
    /** Age after which a lock is considered stale. */
    public const STALE_SECONDS = 600;

    /** Value written by this instance while it holds the lock, else null. */
    private ?string $held = null;

    /**
     * Try to take the lock.
     *
     * @return bool Whether this process now holds it.
     */
    public function acquire(): bool
    {
        global $wpdb;

        $value = time() . ':' . bin2hex(random_bytes(8));

        // Two attempts: the row may be released between the failed insert and the read.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    OptionKeys::LOCK,
                    $value
                )
            );

            if ($inserted === 1) {
                return $this->hold($value);
            }

            $current = $this->current();

            if ($current === null) {
                continue;
            }

            if (!self::isStale($current)) {
                return false;
            }

            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $value,
                    OptionKeys::LOCK,
                    $current
                )
            );

            return $updated === 1 ? $this->hold($value) : false;
        }

        return false;
    }

    /**
     * Release the lock if this instance still holds it.
     *
     * @return void
     */
    public function release(): void
    {
        global $wpdb;

        if ($this->held === null) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                OptionKeys::LOCK,
                $this->held
            )
        );

        $this->held = null;
        self::clearCache();
    }

    /**
     * Whether a (non-stale) run is in progress.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        $current = $this->current();

        return $current !== null && !self::isStale($current);
    }

    /**
     * Current lock value, read from the database (never from a possibly stale cache).
     *
     * @return string|null
     */
    private function current(): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", OptionKeys::LOCK)
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Remember the written value and clear the options cache.
     *
     * @param string $value Lock value written by this instance.
     * @return bool Always true.
     */
    private function hold(string $value): bool
    {
        $this->held = $value;
        self::clearCache();

        return true;
    }

    /**
     * Whether a lock value is older than {@see self::STALE_SECONDS}. Values without a valid time
     * (e.g. written by hand) count as stale, so they can never block refreshes forever.
     *
     * @param string $value Lock value (`{time}:{token}`; a bare time from earlier versions also works).
     * @return bool
     */
    private static function isStale(string $value): bool
    {
        $since = (int) $value;

        return $since <= 0 || time() - $since > self::STALE_SECONDS;
    }

    /**
     * Drop the lock row from the options cache after a direct write.
     *
     * @return void
     */
    private static function clearCache(): void
    {
        wp_cache_delete(OptionKeys::LOCK, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
