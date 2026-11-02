<?php

/**
 * Typed reads from untyped definition/storage arrays.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * Extracts typed values for the `fromArray()` named constructors.
 *
 * Registry definitions come from third-party filters and stored arrays come from the
 * database, so neither is trusted: a missing or wrongly typed key throws instead of
 * being coerced.
 *
 * @internal
 */
final class Field
{
    /**
     * Static helper; not instantiable.
     */
    private function __construct()
    {
    }

    /**
     * Read a string value.
     *
     * @param array<mixed, mixed> $data    Source array.
     * @param string              $key     Key to read.
     * @param string|null         $default Value when the key is absent; null makes the key required.
     * @return string
     * @throws InvalidArgumentException When the key is required but missing, or not a string.
     */
    public static function string(array $data, string $key, ?string $default = null): string
    {
        $value = self::read($data, $key, $default);

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * Read an array value.
     *
     * @param array<mixed, mixed>      $data    Source array.
     * @param string                   $key     Key to read.
     * @param array<mixed, mixed>|null $default Value when the key is absent; null makes the key required.
     * @return array<mixed, mixed>
     * @throws InvalidArgumentException When the key is required but missing, or not an array.
     */
    public static function array(array $data, string $key, ?array $default = null): array
    {
        $value = self::read($data, $key, $default);

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * Read a numeric value as float (ints and numeric strings accepted).
     *
     * @param array<mixed, mixed> $data Source array.
     * @param string              $key  Key to read (required).
     * @return float
     * @throws InvalidArgumentException When the key is missing or not numeric.
     */
    public static function float(array $data, string $key): float
    {
        $value = self::read($data, $key, null);

        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be numeric.', $key));
        }

        return (float) $value;
    }

    /**
     * Read an integer value (numeric strings of integers accepted).
     *
     * @param array<mixed, mixed> $data Source array.
     * @param string              $key  Key to read (required).
     * @return int
     * @throws InvalidArgumentException When the key is missing or not an integer.
     */
    public static function int(array $data, string $key): int
    {
        $value = self::read($data, $key, null);

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * Read a raw value or the default.
     *
     * @param array<mixed, mixed> $data    Source array.
     * @param string              $key     Key to read.
     * @param mixed               $default Value when absent; null makes the key required.
     * @return mixed
     * @throws InvalidArgumentException When the key is required but missing.
     */
    private static function read(array $data, string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        if ($default === null) {
            throw new InvalidArgumentException(sprintf('Field "%s" is required.', $key));
        }

        return $default;
    }
}
