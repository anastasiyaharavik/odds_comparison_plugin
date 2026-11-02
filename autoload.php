<?php

/**
 * Class autoloading.
 *
 * Uses Composer's autoloader when `vendor/` is present (development and release zip).
 * Falls back to a minimal PSR-4 loader for `OddsComparison\` => `src/` so the plugin
 * still runs when installed straight from a Git checkout without `composer install`.
 *
 * Shared by odds-comparison.php and uninstall.php.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    return;
}

spl_autoload_register(static function ($class) {
    $prefix = 'OddsComparison\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
