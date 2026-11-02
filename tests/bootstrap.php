<?php

/**
 * PHPUnit bootstrap for pure unit tests (WordPress is not loaded).
 *
 * Only classes without WordPress calls at load/construct time are unit-tested
 * (domain, converter, parser, validator). Registries and the source factory
 * only need the hook functions, which tests/Stubs/wordpress.php provides.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

// Satisfy the ABSPATH guard at the top of every plugin file.
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Stubs/wordpress.php';
