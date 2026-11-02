<?php

/**
 * Activation, deactivation and upgrade routines.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Lifecycle;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Support\OptionKeys;
use WP_Site;

defined('ABSPATH') || exit;

/**
 * Prepares and tears down per-site state (settings defaults, cron event, version marker).
 *
 * Multisite aware: network activation provisions every existing site and any site created later.
 */
final class Installer implements HookableInterface
{
    /**
     * @param Settings     $settings     Settings accessor.
     * @param CronSchedule $cronSchedule Cron scheduling helper.
     * @param string       $version      Current plugin version.
     * @param string       $pluginFile   Absolute path of the main plugin file.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CronSchedule $cronSchedule,
        private readonly string $version,
        private readonly string $pluginFile
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('init', [$this, 'maybeUpgrade']);
        add_action('wp_initialize_site', [$this, 'onSiteInitialized'], 200);
    }

    /**
     * Activation handler.
     *
     * @param bool $networkWide Whether the plugin is being network-activated.
     * @return void
     */
    public function activate(bool $networkWide): void
    {
        $this->forEachSite($networkWide, function (): void {
            $this->settings->installDefaults();
            $this->cronSchedule->schedule($this->settings->refreshInterval());
            update_option(OptionKeys::VERSION, $this->version, true);
            // First refresh right away, so a new install (Demo mode) has odds within a minute.
            RefreshScheduler::scheduleManualRun();
        });
    }

    /**
     * Deactivation handler. Keeps settings and cached data (removed only on uninstall).
     *
     * @param bool $networkWide Whether the plugin is being network-deactivated.
     * @return void
     */
    public function deactivate(bool $networkWide): void
    {
        $this->forEachSite($networkWide, function (): void {
            $this->cronSchedule->unscheduleAll();
            delete_option(OptionKeys::LOCK);
        });
    }

    /**
     * Run upgrade steps once after the plugin files were updated (version marker differs).
     *
     * Also self-heals a missing cron event (e.g. cleared by another tool).
     *
     * @return void
     */
    public function maybeUpgrade(): void
    {
        if (get_option(OptionKeys::VERSION) !== $this->version) {
            $this->settings->installDefaults();
            update_option(OptionKeys::VERSION, $this->version, true);
        }

        $this->cronSchedule->ensureScheduled($this->settings->refreshInterval());
    }

    /**
     * Provision a site created after the plugin was network-activated.
     *
     * @param WP_Site $site The new site.
     * @return void
     */
    public function onSiteInitialized(WP_Site $site): void
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active_for_network(plugin_basename($this->pluginFile))) {
            return;
        }

        switch_to_blog((int) $site->blog_id);
        $this->activate(false);
        restore_current_blog();
    }

    /**
     * Run a callback on the current site, or on every site of the network.
     *
     * @param bool     $networkWide Whether to iterate all sites.
     * @param callable $callback    Per-site routine.
     * @return void
     */
    private function forEachSite(bool $networkWide, callable $callback): void
    {
        if (!$networkWide || !is_multisite()) {
            $callback();
            return;
        }

        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            $callback();
            restore_current_blog();
        }
    }
}
