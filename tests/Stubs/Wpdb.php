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

    public function esc_like(string $text): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return addcslashes($text, '_%\\');
    }

    /** @var array<string, true> MySQL named locks held (GET_LOCK / RELEASE_LOCK). */
    public array $namedLocks = [];

    /** Number of GET_LOCK calls. */
    public int $lockCalls = 0;

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

        if (str_starts_with($query, 'SELECT RELEASE_LOCK')) {
            $released = isset($this->namedLocks[$args[0]]);
            unset($this->namedLocks[$args[0]]);

            return $released ? 1 : 0;
        }

        if (str_starts_with($query, 'DELETE') && str_contains($query, 'LIKE')) {
            $deleted = 0;

            foreach (array_keys($options) as $name) {
                foreach ($args as $pattern) {
                    if (str_starts_with((string) $name, rtrim(stripslashes($pattern), '%'))) {
                        unset($options[$name]);
                        ++$deleted;
                        break;
                    }
                }
            }

            return $deleted;
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
        [$query, $args] = json_decode($prepared, true);

        if (str_starts_with($query, 'SELECT GET_LOCK')) {
            ++$this->lockCalls;
            $this->namedLocks[$args[0]] = true;

            return '1';
        }

        $value = $GLOBALS['odds_comparison_test_options'][$args[0]] ?? null;

        return $value === null ? null : (string) $value;
    }
}
