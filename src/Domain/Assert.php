<?php

/**
 * Shared input guards for domain value objects.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * Pure-PHP validation helpers (no WordPress calls) used by value-object constructors.
 *
 * Every method throws InvalidArgumentException so value objects can never hold invalid state.
 * Kept internal to the Domain namespace to avoid duplicating rules across Bookmaker, Market,
 * Competition, Event, Price and MarketOdds.
 *
 * @internal
 */
final class Assert
{
    /** Lower-case kebab-case identifier, e.g. `bet365`, `888sport`, `match-result`. */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Characters allowed in a source-relative path. Deliberately excludes `:`, `\`, whitespace and
     * control characters, so a path can never smuggle in a scheme or another host (SSRF guard).
     */
    private const PATH_PATTERN = '#^[A-Za-z0-9_.~%/?=&+-]+$#';

    /**
     * Static helper; not instantiable.
     */
    private function __construct()
    {
    }

    /**
     * Assert a kebab-case slug.
     *
     * @param string $value Value to check.
     * @param string $field Field name for the error message.
     * @return void
     * @throws InvalidArgumentException When the value is not a slug.
     */
    public static function slug(string $value, string $field): void
    {
        if (preg_match(self::SLUG_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be a kebab-case slug, got "%s".', $field, $value));
        }
    }

    /**
     * Assert a string that is not empty after trimming.
     *
     * @param string $value Value to check.
     * @param string $field Field name for the error message.
     * @return void
     * @throws InvalidArgumentException When the value is blank.
     */
    public static function notBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
        }
    }

    /**
     * Assert an absolute http(s) URL, or an empty string when $allowEmpty is true.
     *
     * @param string $value      Value to check.
     * @param string $field      Field name for the error message.
     * @param bool   $allowEmpty Whether "" is accepted.
     * @return void
     * @throws InvalidArgumentException When the value is not an http(s) URL.
     */
    public static function httpUrl(string $value, string $field, bool $allowEmpty = true): void
    {
        if ($value === '' && $allowEmpty) {
            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host   = (string) parse_url($value, PHP_URL_HOST);

        if (
            filter_var($value, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
            || $host === ''
        ) {
            throw new InvalidArgumentException(sprintf('%s must be an http(s) URL, got "%s".', $field, $value));
        }
    }

    /**
     * Assert a path relative to a source host (never a full URL or protocol-relative URL).
     *
     * @param string $value        Value to check.
     * @param string $field        Field name for the error message.
     * @param bool   $leadingSlash Whether the path must start with "/" (host-relative) or be a bare segment.
     * @return void
     * @throws InvalidArgumentException When the value is not a safe relative path.
     */
    public static function relativePath(string $value, string $field, bool $leadingSlash): void
    {
        $valid = preg_match(self::PATH_PATTERN, $value) === 1
            && !str_starts_with($value, '//')
            && !in_array('..', explode('/', $value), true)
            && str_starts_with($value, '/') === $leadingSlash;

        if (!$valid) {
            throw new InvalidArgumentException(sprintf('%s must be a safe relative path, got "%s".', $field, $value));
        }
    }

    /**
     * Assert a map of source id => non-empty string (bookmaker codes, registry paths).
     *
     * @param array<mixed, mixed> $map         Map to check.
     * @param string              $field       Field name for the error message.
     * @param callable|null       $valueCheck  Extra per-value check, called as fn(string $value, string $field).
     * @return void
     * @throws InvalidArgumentException When a key is not a slug or a value is not a non-empty string.
     */
    public static function sourceMap(array $map, string $field, ?callable $valueCheck = null): void
    {
        foreach ($map as $sourceId => $value) {
            if (!is_string($sourceId) || !is_string($value)) {
                throw new InvalidArgumentException(sprintf('%s must map source ids to strings.', $field));
            }

            self::slug($sourceId, $field . ' source id');
            self::notBlank($value, $field . '[' . $sourceId . ']');

            if ($valueCheck !== null) {
                $valueCheck($value, $field . '[' . $sourceId . ']');
            }
        }
    }
}
