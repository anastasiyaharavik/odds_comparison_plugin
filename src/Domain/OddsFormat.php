<?php

/**
 * Supported odds display formats.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

defined('ABSPATH') || exit;

/**
 * Odds format (backed by the values stored in settings and block attributes).
 *
 * The block attribute additionally accepts {@see self::INHERIT}, which resolves to the
 * site default via {@see self::resolve()}.
 */
enum OddsFormat: string
{
    case Fractional = 'fractional';
    case Decimal    = 'decimal';
    case American   = 'american';

    /** Block attribute value meaning "use the site default format". */
    public const INHERIT = 'inherit';

    /**
     * Resolve a stored/attribute value to a format, whitelisting it.
     *
     * `inherit`, unknown and empty values resolve to $default.
     *
     * @param string $value   Raw value (block attribute or setting).
     * @param self   $default Fallback format (usually the site default).
     * @return self
     */
    public static function resolve(string $value, self $default): self
    {
        return self::tryFrom($value) ?? $default;
    }

    /**
     * All backing values, e.g. for enum validation in settings or REST schemas.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
