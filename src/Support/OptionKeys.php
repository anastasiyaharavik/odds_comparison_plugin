<?php

/**
 * Names of every option and transient prefix the plugin owns.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

defined('ABSPATH') || exit;

/**
 * Single source of truth for storage keys, so uninstall can reliably remove everything.
 */
final class OptionKeys
{
    /** Plugin settings (autoloaded, small). */
    public const SETTINGS = 'odds_comparison_settings';

    /** Installed plugin version, used for upgrade routines (autoloaded). */
    public const VERSION = 'odds_comparison_version';

    /** Discovered events index (autoload: no). */
    public const EVENTS = 'odds_comparison_events';

    /** Event/market pairs referenced by blocks (autoload: no). */
    public const TRACKED = 'odds_comparison_tracked';

    /** Capped error log (autoload: no). */
    public const ERRORS = 'odds_comparison_errors';

    /** Source health / last run summary (autoload: no). */
    public const STATUS = 'odds_comparison_status';

    /** Refresh-run lock (autoload: no). */
    public const LOCK = 'odds_comparison_lock';

    /** Transient names of stored odds, so the cache can be flushed without SQL (autoload: no). */
    public const ODDS_KEYS = 'odds_comparison_odds_keys';

    /**
     * Prefix of every transient the plugin creates (odds payloads, rate limiter, circuit breaker, ...).
     * Deliberately long and specific so uninstall never matches another plugin's transients.
     */
    public const TRANSIENT_PREFIX = 'odds_comparison_';

    /**
     * Static holder; not instantiable.
     */
    private function __construct()
    {
    }

    /**
     * All option names owned by the plugin.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SETTINGS,
            self::VERSION,
            self::EVENTS,
            self::TRACKED,
            self::ERRORS,
            self::STATUS,
            self::LOCK,
            self::ODDS_KEYS,
        ];
    }
}
