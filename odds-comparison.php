<?php

/**
 * Plugin Name:       Odds Comparison
 * Description:       Compares live odds from multiple bookmakers in a cached Gutenberg block.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Anastasiya Haravik
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odds-comparison
 *
 * This file is intentionally written in conservative PHP syntax (no arrow functions,
 * typed properties, etc.) so that it parses on old PHP versions and can show a
 * friendly notice instead of a fatal error. All modern code lives in src/.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

define('ODDS_COMPARISON_FILE', __FILE__);
define('ODDS_COMPARISON_MIN_PHP', '8.1');
define('ODDS_COMPARISON_MIN_WP', '6.5');

/**
 * Whether the current environment satisfies the plugin's minimum requirements.
 *
 * WordPress already refuses to activate a plugin whose "Requires PHP" / "Requires at least"
 * headers are not met; this check additionally covers environments that were downgraded
 * after activation.
 *
 * @return bool
 */
function odds_comparison_requirements_met()
{
    return version_compare(PHP_VERSION, ODDS_COMPARISON_MIN_PHP, '>=')
        && version_compare($GLOBALS['wp_version'], ODDS_COMPARISON_MIN_WP, '>=');
}

/**
 * Admin notice shown when requirements are not met (the plugin stays dormant).
 *
 * @return void
 */
function odds_comparison_requirements_notice()
{
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html(
            sprintf(
                /* translators: 1: required PHP version, 2: required WordPress version. */
                __('Odds Comparison needs PHP %1$s+ and WordPress %2$s+; it is inactive.', 'odds-comparison'),
                ODDS_COMPARISON_MIN_PHP,
                ODDS_COMPARISON_MIN_WP
            )
        )
    );
}

if (!odds_comparison_requirements_met()) {
    add_action('admin_notices', 'odds_comparison_requirements_notice');
    return;
}

require_once __DIR__ . '/autoload.php';

register_activation_hook(__FILE__, static function ($network_wide) {
    \OddsComparison\Plugin::instance(ODDS_COMPARISON_FILE)->activate((bool) $network_wide);
});

register_deactivation_hook(__FILE__, static function ($network_wide) {
    \OddsComparison\Plugin::instance(ODDS_COMPARISON_FILE)->deactivate((bool) $network_wide);
});

// Boot on plugins_loaded so other plugins can register filters (bookmakers, sources, ...) first.
add_action('plugins_loaded', static function () {
    \OddsComparison\Plugin::instance(ODDS_COMPARISON_FILE)->boot();
});
