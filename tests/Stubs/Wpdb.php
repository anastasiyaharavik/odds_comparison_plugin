<?php

/**
 * Test double for the global $wpdb.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Stubs;

use LogicException;

/**
 * Database stand-in for the few direct queries the plugin makes (the refresh lock).
 *
 * prepare() keeps the statement and its values together; query() and get_var() understand only
 * the statement shapes used by OddsComparison\Scheduler\Lock and run them against the in-memory
 * options store, with the same affected-row counts MySQL reports.
 */
final class Wpdb
{
    public string $options = 'wp_options';

    public function prepare(string $query, mixed ...$args): string
    {
        return (string) json_encode([$query, $args]);
    }

    public function query(string $prepared): int
    {
        [$query, $args] = json_decode($prepared, true);
        $options        = &$GLOBALS['odds_comparison_test_options'];

        if (str_starts_with($query, 'INSERT IGNORE')) {
            [$name, $value] = $args;

            if (array_key_exists($name, $options)) {
                return 0;
            }

            $options[$name] = $value;

            return 1;
        }

        if (str_starts_with($query, 'UPDATE')) {
            [$value, $name, $expected] = $args;

            if (!array_key_exists($name, $options) || (string) $options[$name] !== $expected) {
                return 0;
            }

            $options[$name] = $value;

            return 1;
        }

        if (str_starts_with($query, 'DELETE')) {
            [$name, $expected] = $args;

            if (!array_key_exists($name, $options) || (string) $options[$name] !== $expected) {
                return 0;
            }

            unset($options[$name]);

            return 1;
        }

        throw new LogicException('Unsupported query in test wpdb: ' . $query);
    }

    public function get_var(string $prepared): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        [, $args] = json_decode($prepared, true);
        $value    = $GLOBALS['odds_comparison_test_options'][$args[0]] ?? null;

        return $value === null ? null : (string) $value;
    }
}
