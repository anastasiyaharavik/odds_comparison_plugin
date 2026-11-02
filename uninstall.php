<?php

/**
 * Uninstall routine: removes every option, transient and cron event created by the plugin.
 *
 * WordPress runs this file in isolation (the plugin itself is not loaded).
 *
 * @package OddsComparison
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The plugin can never have been active on an unsupported PHP version, so there is nothing to clean up
// (and src/ would not even parse).
if (version_compare(PHP_VERSION, '8.1', '<')) {
    return;
}

require_once __DIR__ . '/autoload.php';

(new \OddsComparison\Lifecycle\Uninstaller())->uninstall();
