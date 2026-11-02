<?php

/**
 * Validation and merge-on-save for the settings form.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Settings;

use Closure;
use OddsComparison\Domain\OddsFormat;

defined('ABSPATH') || exit;

/**
 * Turns one submitted settings tab into the new settings array.
 *
 * - **Merge on save.** The form posts only the current tab (`_tab`), so only that tab's keys
 *   change; every other tab keeps its stored values. Bookmaker labels and the site-wide order
 *   are no longer edited on the screen and keep their stored values unless posted.
 * - **Reject, don't coerce.** An invalid value keeps the previous one and produces an error
 *   for the admin notice. Unknown registry entries in lists are dropped silently.
 * - **Source switch probe.** Changing `active_source` calls the probe; the switch only
 *   happens when the probe returns no error.
 *
 * Holds no state and reads no options; the caller passes the current settings and the
 * registry keys, so it can be unit-tested.
 *
 * @phpstan-type Error array{code: string, message: string}
 */
final class SettingsSanitizer
{
    /** Keys owned by each tab. */
    public const TABS = [
        'general'    => ['active_source', 'data_mode', 'default_odds_format', 'enabled_competitions'],
        'bookmakers' => ['bookmakers', 'bookmaker_order', 'enabled_markets'],
        'markets'    => ['enabled_markets'],
        'cache'      => [
            'refresh_interval',
            'stale_after',
            'requests_per_run',
            'min_request_interval',
            'api_refresh_interval',
        ],
    ];

    /** Allowed refresh intervals (minutes); mirrors CronSchedule::INTERVALS. */
    public const REFRESH_INTERVALS = [5, 15, 30, 60];

    /** Allowed The Odds API fetch intervals (minutes). */
    public const API_REFRESH_INTERVALS = [60, 180, 360, 720];

    /** Longest bookmaker label override. */
    private const MAX_LABEL_LENGTH = 100;

    /**
     * Collected errors of the current call.
     *
     * @var list<Error>
     */
    private array $errors = [];

    /**
     * @param list<string>  $competitions      Registered competition slugs (registry order).
     * @param list<string>  $markets           Registered market keys (registry order).
     * @param list<string>  $bookmakers        Registered bookmaker slugs (registry order).
     * @param list<string>  $sources           Registered source ids.
     * @param bool          $recordedAvailable Whether Recorded mode can be used (fixtures present).
     * @param Closure|null  $probe             fn(string $sourceId): ?string; error message or null when usable.
     * @param array<string, string> $sourceLabels Source id => display name (for messages).
     */
    public function __construct(
        private readonly array $competitions,
        private readonly array $markets,
        private readonly array $bookmakers,
        private readonly array $sources,
        private readonly bool $recordedAvailable,
        private readonly ?Closure $probe = null,
        private readonly array $sourceLabels = []
    ) {
    }

    /**
     * Sanitize a submitted tab against the current settings.
     *
     * @param mixed                $input   Raw `$_POST['odds_comparison_settings']` (already unslashed by WP).
     * @param array<string, mixed> $current Current settings (defaults merged).
     * @return array{settings: array<string, mixed>, errors: list<Error>}
     */
    public function sanitize(mixed $input, array $current): array
    {
        $this->errors = [];
        $settings     = $current;
        $tab          = is_array($input) && is_string($input['_tab'] ?? null) ? $input['_tab'] : '';

        if (!is_array($input) || !isset(self::TABS[$tab])) {
            $this->error(
                'invalid_form',
                __('The settings form was incomplete; nothing was changed.', 'odds-comparison')
            );

            return ['settings' => $current, 'errors' => $this->errors];
        }

        $settings = match ($tab) {
            'general'    => $this->general($input, $settings),
            'bookmakers' => $this->bookmakersAndMarkets($input, $settings),
            'markets'    => $this->marketTab($input, $settings),
            'cache'      => $this->cacheTab($input, $settings),
        };

        return ['settings' => $settings, 'errors' => $this->errors];
    }

    /**
     * General tab.
     *
     * @param array<string, mixed> $input    Input.
     * @param array<string, mixed> $settings Settings.
     * @return array<string, mixed>
     */
    private function general(array $input, array $settings): array
    {
        $mode = $this->key($input['data_mode'] ?? '');

        if ($mode === 'live' || ($mode === 'recorded' && $this->recordedAvailable)) {
            $settings['data_mode'] = $mode;
        } else {
            $this->error(
                'data_mode',
                __('Recorded mode is only available in development checkouts with recorded data.', 'odds-comparison')
            );
        }

        $format = OddsFormat::tryFrom($this->key($input['default_odds_format'] ?? ''));

        if ($format !== null) {
            $settings['default_odds_format'] = $format->value;
        } else {
            $this->error('default_odds_format', __('Choose a valid default odds format.', 'odds-comparison'));
        }

        $settings['enabled_competitions'] = $this->subset($input['enabled_competitions'] ?? [], $this->competitions);
        $settings['active_source']        = $this->activeSource(
            $input['active_source'] ?? '',
            (string) $settings['active_source']
        );

        return $settings;
    }

    /**
     * Validate a source switch with the probe.
     *
     * @param mixed  $value   Submitted source id.
     * @param string $current Current source id.
     * @return string The source id to store.
     */
    private function activeSource(mixed $value, string $current): string
    {
        $source = $this->key($value);

        if (!in_array($source, $this->sources, true)) {
            $this->error('active_source', __('Choose a registered data source.', 'odds-comparison'));

            return $current;
        }

        if ($source === $current || $this->probe === null) {
            return $source;
        }

        $problem = ($this->probe)($source);

        if ($problem === null) {
            return $source;
        }

        $this->error('active_source', sprintf(
            /* translators: 1: data source name, 2: reason, 3: current source name. */
            __('Could not switch to %1$s: %2$s. %3$s stays the data source.', 'odds-comparison'),
            $this->sourceLabels[$source] ?? $source,
            $problem,
            $this->sourceLabels[$current] ?? $current
        ));

        return $current;
    }

    /**
     * Bookmakers tab: enable flags, labels, affiliate links and order.
     *
     * @param array<string, mixed> $input    Input.
     * @param array<string, mixed> $settings Settings.
     * @return array<string, mixed>
     */
    private function bookmakerTab(array $input, array $settings): array
    {
        $rows     = is_array($input['bookmakers'] ?? null) ? $input['bookmakers'] : [];
        $stored   = is_array($settings['bookmakers'] ?? null) ? $settings['bookmakers'] : [];
        $entries  = $stored; // Entries of bookmakers no longer registered are kept for re-registration.

        foreach ($this->bookmakers as $slug) {
            $row      = is_array($rows[$slug] ?? null) ? $rows[$slug] : [];
            $previous = is_array($stored[$slug] ?? null) ? $stored[$slug] : [];

            $label = array_key_exists('label', $row) ? (string) $row['label'] : (string) ($previous['label'] ?? '');

            $entries[$slug] = [
                'enabled' => !empty($row['enabled']),
                'label'   => mb_substr(sanitize_text_field($label), 0, self::MAX_LABEL_LENGTH),
                'link'    => $this->link($slug, $row['link'] ?? '', (string) ($previous['link'] ?? '')),
            ];
        }

        $settings['bookmakers'] = $entries;

        if (isset($input['bookmaker_order']) || isset($input['_move'])) {
            $settings['bookmaker_order'] = $this->order($input['bookmaker_order'] ?? [], $input['_move'] ?? '');
        }

        return $settings;
    }

    /**
     * "Bookmakers & markets" tab: the bookmaker fields, plus the market checkboxes when the form
     * has them (`_markets` marks a form whose market boxes may all be unticked).
     *
     * @param array<string, mixed> $input    Input.
     * @param array<string, mixed> $settings Settings.
     * @return array<string, mixed>
     */
    private function bookmakersAndMarkets(array $input, array $settings): array
    {
        $settings = $this->bookmakerTab($input, $settings);

        return isset($input['enabled_markets']) || !empty($input['_markets'])
            ? $this->marketTab($input, $settings)
            : $settings;
    }

    /**
     * Validate an affiliate link: empty, or an absolute http(s) URL with a host.
     *
     * @param string $slug     Bookmaker slug (for the message).
     * @param mixed  $value    Submitted link.
     * @param string $previous Stored link, kept when the new one is invalid.
     * @return string
     */
    private function link(string $slug, mixed $value, string $previous): string
    {
        $raw = trim(is_string($value) ? $value : '');

        if ($raw === '') {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($raw, PHP_URL_SCHEME));
        $host   = (string) wp_parse_url($raw, PHP_URL_HOST);
        $clean  = esc_url_raw($raw, ['http', 'https']);

        $valid = in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && $clean !== ''
            && filter_var($clean, FILTER_VALIDATE_URL) !== false;

        if ($valid) {
            return $clean;
        }

        $this->error('link_' . $slug, sprintf(
            /* translators: %s: bookmaker slug. */
            __(
                'The link for %s must be a full http:// or https:// address; the previous link was kept.',
                'odds-comparison'
            ),
            $slug
        ));

        return $previous;
    }

    /**
     * Bookmaker order: submitted slugs that exist, then the rest in registry order; then apply a move.
     *
     * @param mixed $value Submitted order.
     * @param mixed $move  Optional "slug:up" / "slug:down" from the no-JS move buttons.
     * @return list<string>
     */
    private function order(mixed $value, mixed $move): array
    {
        $order = array_values(array_unique([...$this->subset($value, $this->bookmakers, false), ...$this->bookmakers]));

        if (is_string($move) && preg_match('/^([a-z0-9-]+):(up|down)$/', $move, $parts) === 1) {
            $index  = array_search($parts[1], $order, true);
            $target = $parts[2] === 'up' ? $index - 1 : $index + 1;

            if ($index !== false && isset($order[$target])) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
            }
        }

        return $order;
    }

    /**
     * Markets tab.
     *
     * @param array<string, mixed> $input    Input.
     * @param array<string, mixed> $settings Settings.
     * @return array<string, mixed>
     */
    private function marketTab(array $input, array $settings): array
    {
        $markets = $this->subset($input['enabled_markets'] ?? [], $this->markets);

        if ($markets === []) {
            $this->error(
                'enabled_markets',
                __('Enable at least one market; the previous selection was kept.', 'odds-comparison')
            );
        } else {
            $settings['enabled_markets'] = $markets;
        }

        return $settings;
    }

    /**
     * Cache & Schedule tab.
     *
     * @param array<string, mixed> $input    Input.
     * @param array<string, mixed> $settings Settings.
     * @return array<string, mixed>
     */
    private function cacheTab(array $input, array $settings): array
    {
        $refresh = $this->choice($input['refresh_interval'] ?? null, self::REFRESH_INTERVALS);
        $stale   = $this->integer($input['stale_after'] ?? null, 10, 1440);

        if ($refresh === null) {
            $this->error('refresh_interval', __('Choose a refresh interval from the list.', 'odds-comparison'));
        }

        if ($stale === null) {
            $this->error(
                'stale_after',
                __('"Stale after" must be a whole number of minutes between 10 and 1440.', 'odds-comparison')
            );
        }

        $newRefresh = $refresh ?? (int) $settings['refresh_interval'];
        $newStale   = $stale ?? (int) $settings['stale_after'];

        if ($newStale < 2 * $newRefresh) {
            $this->error('stale_after', sprintf(
                /* translators: %d: minimum minutes. */
                __(
                    '"Stale after" must be at least twice the refresh interval (%d minutes); both were kept unchanged.',
                    'odds-comparison'
                ),
                2 * $newRefresh
            ));
        } else {
            $settings['refresh_interval'] = $newRefresh;
            $settings['stale_after']      = $newStale;
        }

        $settings = $this->setInteger(
            $settings,
            $input,
            'requests_per_run',
            1,
            50,
            __('"Requests per run" must be between 1 and 50.', 'odds-comparison')
        );
        $settings = $this->setInteger(
            $settings,
            $input,
            'min_request_interval',
            2,
            30,
            __('"Minimum request interval" must be between 2 and 30 seconds.', 'odds-comparison')
        );

        $api = $this->choice($input['api_refresh_interval'] ?? null, self::API_REFRESH_INTERVALS);

        if ($api === null) {
            $this->error(
                'api_refresh_interval',
                __('Choose a The Odds API interval from the list.', 'odds-comparison')
            );
        } else {
            $settings['api_refresh_interval'] = $api;
        }

        return $settings;
    }

    /**
     * Store a bounded integer or record an error and keep the old value.
     *
     * @param array<string, mixed> $settings Settings.
     * @param array<string, mixed> $input    Input.
     * @param string               $key      Setting key.
     * @param int                  $min      Minimum.
     * @param int                  $max      Maximum.
     * @param string               $message  Error message.
     * @return array<string, mixed>
     */
    private function setInteger(array $settings, array $input, string $key, int $min, int $max, string $message): array
    {
        $value = $this->integer($input[$key] ?? null, $min, $max);

        if ($value === null) {
            $this->error($key, $message);
        } else {
            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * A whole number within bounds, or null.
     *
     * @param mixed $value Value.
     * @param int   $min   Minimum.
     * @param int   $max   Maximum.
     * @return int|null
     */
    private function integer(mixed $value, int $min, int $max): ?int
    {
        $int = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);

        return is_int($int) ? $int : null;
    }

    /**
     * One of the allowed integers, or null.
     *
     * @param mixed     $value   Value.
     * @param list<int> $allowed Allowed values.
     * @return int|null
     */
    private function choice(mixed $value, array $allowed): ?int
    {
        $int = $this->integer($value, PHP_INT_MIN, PHP_INT_MAX);

        return $int !== null && in_array($int, $allowed, true) ? $int : null;
    }

    /**
     * Allowed keys from a submitted list.
     *
     * @param mixed        $value         Submitted list.
     * @param list<string> $allowed       Allowed keys.
     * @param bool         $registryOrder Return in registry order (true) or submitted order (false).
     * @return list<string>
     */
    private function subset(mixed $value, array $allowed, bool $registryOrder = true): array
    {
        $submitted = array_map([$this, 'key'], is_array($value) ? $value : []);

        return $registryOrder
            ? array_values(array_intersect($allowed, $submitted))
            : array_values(array_unique(array_intersect($submitted, $allowed)));
    }

    /**
     * Sanitized key (lower-case slug characters only).
     *
     * @param mixed $value Value.
     * @return string
     */
    private function key(mixed $value): string
    {
        return is_string($value) ? sanitize_key($value) : '';
    }

    /**
     * Record an error.
     *
     * @param string $code    Error code (unique per field).
     * @param string $message Message (plain text; escaped when displayed).
     * @return void
     */
    private function error(string $code, string $message): void
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
    }
}
