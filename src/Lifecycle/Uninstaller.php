<?php

/**
 * Full data removal on plugin uninstall.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Lifecycle;

use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\RateLimiter;
use OddsComparison\Scheduler\CronSchedule;
use OddsComparison\Scheduler\OddsRefresher;
use OddsComparison\Source\BettingOdds\BettingOddsSource;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Storage\OddsRepository;
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
     * Remove one site's options, transients and cron events.
     *
     * @return void
     */
    private function uninstallSite(): void
    {
        // Odds transients are found through the stored key list, so this runs before the options go.
        (new OddsRepository(static fn (): int => 60))->flush();

        foreach (OptionKeys::all() as $option) {
            delete_option($option);
        }

        wp_unschedule_hook(CronSchedule::HOOK);
        $this->deleteKnownTransients();
        $this->deleteStoredTransients();
    }

    /**
     * Delete the transients whose names are known, through the Transients API, so they also leave
     * a persistent object cache (where transients never reach the options table): API quota
     * (kept 31 days), circuit breaker and rate limiter state per host, and diagnostics reminders.
     *
     * @return void
     */
    private function deleteKnownTransients(): void
    {
        delete_transient(TheOddsApiSource::QUOTA_TRANSIENT);

        foreach ([BettingOddsSource::BASE_URL, TheOddsApiSource::BASE_URL] as $baseUrl) {
            $host = strtolower((string) wp_parse_url($baseUrl, PHP_URL_HOST));

            delete_transient(CircuitBreaker::transientName($host));
            delete_transient(RateLimiter::transientName($host));
        }

        foreach ([BettingOddsSource::ID, TheOddsApiSource::ID] as $sourceId) {
            foreach (['warnings', 'unmapped'] as $kind) {
                delete_transient(OddsRefresher::diagnosticsTransient($sourceId, $kind));
            }
        }
    }

    /**
     * Delete any other prefixed transient stored in the options table (e.g. of a source added
     * through `odds_comparison/sources`).
     *
     * The Transients API cannot delete by prefix, so this is the one place that uses a (prepared)
     * query. With a persistent object cache such leftovers live only in the cache and expire on
     * their own: odds after 7 days, everything else sooner.
     *
     * @return void
     */
    private function deleteStoredTransients(): void
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
