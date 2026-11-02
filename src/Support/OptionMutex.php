<?php

/**
 * Serialized read-modify-write of one option.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use Closure;

defined('ABSPATH') || exit;

/**
 * Changes an option under a MySQL named lock (`GET_LOCK`), so two requests that update it at
 * the same time (a cron run logging errors while an editor previews a block, two cron runs on
 * different servers) cannot overwrite each other's changes.
 *
 * Inside the lock the option is read from the database, not from this request's cache, then
 * the change is applied and written. The lock is per site (the options table name is part of
 * its name) and is released at the end, or by MySQL when the connection closes. When the lock
 * cannot be taken within {@see self::WAIT_SECONDS} (or the database has no named locks), the
 * change is still applied: losing the race in a rare case is better than losing the write.
 */
final class OptionMutex
{
    /** Longest wait for the lock, in seconds. */
    private const WAIT_SECONDS = 3;

    /**
     * Apply a change to an option's stored value.
     *
     * @param string                       $option Option name (not autoloaded).
     * @param Closure(mixed): array<mixed> $change Receives the stored value (false/absent: []), returns the new value.
     * @return void
     */
    public static function update(string $option, Closure $change): void
    {
        global $wpdb;

        $name   = 'odds_comparison_' . md5($wpdb->options . '|' . $option);
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::WAIT_SECONDS)) === '1';

        try {
            // Read what other requests committed, not this request's cached copy.
            wp_cache_delete($option, 'options');
            wp_cache_delete('notoptions', 'options');

            $stored = get_option($option, []);
            $value  = $change($stored === false ? [] : $stored);

            if ($value !== $stored) {
                update_option($option, $value, false);
            }
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }
}
