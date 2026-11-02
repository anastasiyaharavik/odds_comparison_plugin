<?php

/**
 * Status tab actions (admin-post.php handlers).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Refresh now / Clear cache / Reset circuit breaker / Use demo data.
 *
 * Each handler checks `manage_options` and its own nonce (both: a nonce is not a permission),
 * then redirects back to the Status tab with a whitelisted result code. "Refresh now" is
 * asynchronous: it queues a cron run; admin.js uses the REST equivalent and polls the status.
 */
final class AdminActions implements HookableInterface
{
    public const REFRESH = 'odds_comparison_refresh';

    public const CLEAR_CACHE = 'odds_comparison_clear_cache';

    public const RESET_BREAKER = 'odds_comparison_reset_breaker';

    public const USE_DEMO = 'odds_comparison_use_demo';

    /**
     * @param OddsRepositoryInterface $repository Odds storage.
     * @param CircuitBreaker          $breaker    Circuit breaker.
     * @param SourceFactory           $sources    Source factory (hosts).
     * @param Settings                $settings   Settings (current values).
     * @param SettingsPage            $page       Settings screen (its sanitize callback validates the switch).
     */
    public function __construct(
        private readonly OddsRepositoryInterface $repository,
        private readonly CircuitBreaker $breaker,
        private readonly SourceFactory $sources,
        private readonly Settings $settings,
        private readonly SettingsPage $page
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('admin_post_' . self::REFRESH, [$this, 'refresh']);
        add_action('admin_post_' . self::CLEAR_CACHE, [$this, 'clearCache']);
        add_action('admin_post_' . self::RESET_BREAKER, [$this, 'resetBreaker']);
        add_action('admin_post_' . self::USE_DEMO, [$this, 'useDemo']);
    }

    /**
     * Queue a refresh run.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->authorize(self::REFRESH);
        $this->back(RefreshScheduler::queueManualRun() ? 'refresh_queued' : 'refresh_waiting');
    }

    /**
     * Remove all cached odds.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->authorize(self::CLEAR_CACHE);
        $this->repository->flush();
        $this->back('cache_cleared');
    }

    /**
     * Close every source's circuit breaker.
     *
     * @return void
     */
    public function resetBreaker(): void
    {
        $this->authorize(self::RESET_BREAKER);
        $this->resetAllBreakers();
        $this->back('breaker_reset');
    }

    /**
     * Switch the data mode to Demo (offered when the data source does not respond).
     *
     * The change goes through the settings form's own validation, as if the General tab had been
     * saved with only the data mode changed. Saving fires the data mode switch, which clears the
     * old data and queues a refresh.
     *
     * @return void
     */
    public function useDemo(): void
    {
        $this->authorize(self::USE_DEMO);

        $current = $this->settings->all();
        $clean   = $this->page->sanitize([
            '_tab'                 => 'general',
            'active_source'        => $current['active_source'],
            'data_mode'            => 'demo',
            'default_odds_format'  => $current['default_odds_format'],
            'enabled_competitions' => $current['enabled_competitions'],
        ]);

        update_option(OptionKeys::SETTINGS, $clean, true);
        $this->back($this->settings->isDemo() ? 'demo_enabled' : 'demo_unavailable');
    }

    /**
     * Reset the breakers of all registered sources.
     *
     * @return void
     */
    public function resetAllBreakers(): void
    {
        foreach ($this->sources->ids() as $id) {
            $host = (string) wp_parse_url($this->sources->make($id)->baseUrl(), PHP_URL_HOST);
            $this->breaker->reset(strtolower($host));
        }
    }

    /**
     * Capability and nonce check; dies on failure.
     *
     * @param string $action Nonce action.
     * @return void
     */
    private function authorize(string $action): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'odds-comparison'), 403);
        }

        check_admin_referer($action);
    }

    /**
     * Redirect to the Status tab with a result code.
     *
     * @param string $notice Result code.
     * @return never
     */
    private function back(string $notice): never
    {
        wp_safe_redirect(add_query_arg(
            ['page' => SettingsPage::SLUG, 'tab' => 'status', 'oc_notice' => $notice],
            admin_url('admin.php')
        ));
        exit;
    }
}
