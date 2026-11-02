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
    /**
     * json_encode() flags for data quoted inside exception messages: the value is shown in
     * quotes with control characters escaped (so it cannot forge log lines), stays readable
     * (no escaped slashes or Unicode), and invalid UTF-8 cannot make the encoding fail.
     */
    public const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

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
            throw new InvalidArgumentException(
                sprintf(
                    '%s must be a kebab-case slug, got %s.',
                    json_encode($field, self::JSON),
                    json_encode($value, self::JSON)
                )
            );
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
            throw new InvalidArgumentException(sprintf('%s must not be empty.', json_encode($field, self::JSON)));
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

        // FILTER_VALIDATE_URL requires a host for http(s); the pattern limits the scheme and
        // rejects an empty host. (parse_url() is avoided: WordPress asks for wp_parse_url(), which
        // this pure-PHP layer cannot call.)
        if (
            filter_var($value, FILTER_VALIDATE_URL) === false
            || preg_match('~^https?://[^/?#\s]+~i', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s must be an http(s) URL, got %s.',
                    json_encode($field, self::JSON),
                    json_encode($value, self::JSON)
                )
            );
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
            throw new InvalidArgumentException(
                sprintf(
                    '%s must be a safe relative path, got %s.',
                    json_encode($field, self::JSON),
                    json_encode($value, self::JSON)
                )
            );
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
                throw new InvalidArgumentException(
                    sprintf('%s must map source ids to strings.', json_encode($field, self::JSON))
                );
            }

            self::slug($sourceId, $field . ' source id');
            self::notBlank($value, $field . '[' . $sourceId . ']');

            if ($valueCheck !== null) {
                $valueCheck($value, $field . '[' . $sourceId . ']');
            }
        }
    }
}
