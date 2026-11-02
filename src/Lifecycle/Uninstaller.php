<?php

/**
 * Full data removal on plugin uninstall.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Lifecycle;

use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Deletes all options, transients and cron events owned by the plugin (on every site in multisite).
 */
final class Uninstaller
{
    /**
     * Remove all plugin data.
     *
     * @return void
     */
    public function uninstall(): void
    {
        if (!is_multisite()) {
            $this->uninstallSite();
            return;
        }

        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            $this->uninstallSite();
            restore_current_blog();
        }
    }

    /**
     * Remove plugin data from the current site.
     *
     * @return void
     */
    private function uninstallSite(): void
    {
        foreach (OptionKeys::all() as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook(CronSchedule::HOOK);
        $this->deleteTransients();
    }

    /**
     * Delete all prefixed transients stored in the options table.
     *
     * The Transients API cannot delete by prefix, so this is the one place that uses a
     * (prepared) query. With a persistent object cache, transients live in the cache and
     * expire on their own (max 7 days).
     *
     * @return void
     */
    private function deleteTransients(): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . OptionKeys::TRANSIENT_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . OptionKeys::TRANSIENT_PREFIX) . '%'
            )
        );
    }
}
