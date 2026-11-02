<?php

/**
 * Typed accessor for the plugin settings option.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Settings;

use InvalidArgumentException;
use OddsComparison\Domain\Bookmaker;
use OddsComparison\Domain\OddsFormat;
use OddsComparison\Source\TheOddsApi\TheOddsApiSource;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * Read access to `odds_comparison_settings`, always merged with defaults.
 *
 * No other class reads the option directly. Writes go through the Settings API with
 * {@see SettingsSanitizer} (registered by Admin\SettingsPage).
 *
 * Per site on multisite (a normal option).
 */
final class Settings
{
    /**
     * Longest pause between requests to one host (seconds). A refresh run has a 25-second budget,
     * so 10 s still allows three requests per run; longer pauses would allow only one.
     */
    public const MAX_REQUEST_INTERVAL = 10;

    /**
     * Default values. Stored as a single autoloaded option.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'active_source'        => 'bettingodds',
        'data_mode'            => 'demo',
        'default_odds_format'  => 'fractional',
        'enabled_competitions' => ['premier-league'],
        'bookmakers'           => [],
        'bookmaker_order'      => [],
        'enabled_markets'      => ['match-result'],
        'refresh_interval'     => 15,
        'stale_after'          => 60,
        'requests_per_run'     => 20,
        'min_request_interval' => 3,
        'api_refresh_interval' => 180,
    ];

    /**
     * All settings, stored values overriding defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(OptionKeys::SETTINGS, []);

        return array_replace(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /**
     * A single setting.
     *
     * @param string $key Setting key (must exist in DEFAULTS).
     * @return mixed
     * @throws InvalidArgumentException When the key is unknown.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException(sprintf('Unknown setting "%s".', $key));
        }

        return $this->all()[$key];
    }

    /**
     * Refresh interval in minutes.
     *
     * @return int
     */
    public function refreshInterval(): int
    {
        return (int) $this->get('refresh_interval');
    }

    /**
     * Source id used for discovery and refreshes.
     *
     * @return string
     */
    public function activeSource(): string
    {
        return (string) $this->get('active_source');
    }

    /**
     * Site default odds format.
     *
     * @return OddsFormat
     */
    public function defaultOddsFormat(): OddsFormat
    {
        return OddsFormat::resolve((string) $this->get('default_odds_format'), OddsFormat::Fractional);
    }

    /**
     * Enabled competition slugs.
     *
     * @return list<string>
     */
    public function enabledCompetitions(): array
    {
        return self::stringList($this->get('enabled_competitions'));
    }

    /**
     * Enabled market keys.
     *
     * @return list<string>
     */
    public function enabledMarkets(): array
    {
        return self::stringList($this->get('enabled_markets'));
    }

    /**
     * Maximum source requests per refresh run, clamped to 1–50.
     *
     * @return int
     */
    public function requestsPerRun(): int
    {
        return max(1, min(50, (int) $this->get('requests_per_run')));
    }

    /**
     * Minutes between The Odds API fetches of one competition (quota protection).
     *
     * @return int
     */
    public function apiRefreshInterval(): int
    {
        return max(60, (int) $this->get('api_refresh_interval'));
    }

    /**
     * Admin overrides for one bookmaker. Bookmakers are enabled unless switched off.
     *
     * @param string $slug Bookmaker slug.
     * @return array{enabled: bool, link: string, label: string}
     */
    public function bookmaker(string $slug): array
    {
        $all   = $this->get('bookmakers');
        $entry = is_array($all) && is_array($all[$slug] ?? null) ? $all[$slug] : [];

        return [
            'enabled' => (bool) ($entry['enabled'] ?? true),
            'link'    => is_string($entry['link'] ?? null) ? $entry['link'] : '',
            'label'   => is_string($entry['label'] ?? null) ? $entry['label'] : '',
        ];
    }

    /**
     * Registered bookmaker slugs in admin order: saved order first, then new ones in registry order.
     *
     * @param list<string> $registered Registered slugs (registry order).
     * @return list<string>
     */
    public function orderedBookmakers(array $registered): array
    {
        $saved = array_values(array_intersect(self::stringList($this->get('bookmaker_order')), $registered));

        return array_values(array_unique([...$saved, ...$registered]));
    }

    /**
     * Enabled bookmaker slugs in admin order.
     *
     * @param list<string> $registered Registered slugs (registry order).
     * @return list<string>
     */
    public function enabledBookmakers(array $registered): array
    {
        return array_values(array_filter(
            $this->orderedBookmakers($registered),
            fn (string $slug): bool => $this->bookmaker($slug)['enabled']
        ));
    }

    /**
     * Display name: admin label override, else the registry name.
     *
     * @param Bookmaker $bookmaker Bookmaker.
     * @return string
     */
    public function bookmakerLabel(Bookmaker $bookmaker): string
    {
        $label = $this->bookmaker($bookmaker->slug)['label'];

        return $label !== '' ? $label : $bookmaker->name;
    }

    /**
     * Outbound link: admin affiliate link, else the registry default URL ("" for none).
     *
     * @param Bookmaker $bookmaker Bookmaker.
     * @return string
     */
    public function bookmakerLink(Bookmaker $bookmaker): string
    {
        $link = $this->bookmaker($bookmaker->slug)['link'];

        return $link !== '' ? $link : $bookmaker->defaultUrl;
    }

    /**
     * Freshness settings in minutes, filterable via `odds_comparison/cache_ttl`.
     *
     * @return array{refresh_interval: int, stale_after: int}
     */
    public function cacheTtl(): array
    {
        $defaults = ['refresh_interval' => $this->refreshInterval(), 'stale_after' => $this->staleAfter()];

        /**
         * Filters the freshness settings.
         *
         * @param array{refresh_interval: int, stale_after: int} $ttl Minutes.
         */
        $ttl = apply_filters('odds_comparison/cache_ttl', $defaults);
        $ttl = is_array($ttl) ? $ttl + $defaults : $defaults;

        return [
            'refresh_interval' => max(1, (int) $ttl['refresh_interval']),
            'stale_after'      => max(1, (int) $ttl['stale_after']),
        ];
    }

    /**
     * Minutes after which a source's stored odds are fetched again: The Odds API interval for
     * The Odds API (credit quota), the refresh interval otherwise.
     *
     * @param string $sourceId Source id.
     * @return int
     */
    public function refreshAgeFor(string $sourceId): int
    {
        return $sourceId === TheOddsApiSource::ID
            ? $this->apiRefreshInterval()
            : $this->cacheTtl()['refresh_interval'];
    }

    /**
     * Minutes after which a source's odds count as stale ("Odds may be out of date").
     *
     * The "Stale after" setting, but never less than the source's normal refresh age plus one
     * refresh interval: odds fetched every 3 hours (The Odds API) are not out of date after one
     * hour, they are on schedule.
     *
     * @param string $sourceId Source id.
     * @return int
     */
    public function staleAfterFor(string $sourceId): int
    {
        $ttl = $this->cacheTtl();

        return max($ttl['stale_after'], $this->refreshAgeFor($sourceId) + $ttl['refresh_interval']);
    }

    /**
     * Minutes after which odds count as stale, clamped to 10–1440.
     *
     * @return int
     */
    public function staleAfter(): int
    {
        return max(10, min(1440, (int) $this->get('stale_after')));
    }

    /**
     * Data mode: `demo` (recorded odds with kick-offs moved into the coming days; the default, so
     * a new install shows odds at once) or `live`. `recorded`, the dev-only mode of earlier
     * builds, reads as `demo`; unknown values fall back to `live`.
     *
     * @return string
     */
    public function dataMode(): string
    {
        return in_array($this->get('data_mode'), ['demo', 'recorded'], true) ? 'demo' : 'live';
    }

    /**
     * Whether visitors see demo data.
     *
     * @return bool
     */
    public function isDemo(): bool
    {
        return $this->dataMode() === 'demo';
    }

    /**
     * Minimum seconds between requests to one host, clamped to 2–{@see self::MAX_REQUEST_INTERVAL}
     * (values saved by earlier versions, up to 30, are capped).
     *
     * @return int
     */
    public function minRequestInterval(): int
    {
        return max(2, min(self::MAX_REQUEST_INTERVAL, (int) $this->get('min_request_interval')));
    }

    /**
     * Strings from a stored list value.
     *
     * @param mixed $value Stored value.
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Create the option with defaults, or add keys introduced by a newer plugin version
     * while preserving every value the admin already saved.
     *
     * @return void
     */
    public function installDefaults(): void
    {
        $stored = get_option(OptionKeys::SETTINGS, null);
        $stored = is_array($stored) ? $stored : [];

        update_option(OptionKeys::SETTINGS, array_replace(self::DEFAULTS, $stored), true);
    }
}
