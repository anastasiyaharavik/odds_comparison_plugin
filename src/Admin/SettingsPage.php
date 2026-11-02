<?php

/**
 * Admin settings screen.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\OddsFormat;
use OddsComparison\Plugin;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Scheduler\RefreshScheduler;
use OddsComparison\Settings\Settings;
use OddsComparison\Settings\SettingsSanitizer;
use OddsComparison\Source\SourceFactory;
use OddsComparison\Support\OptionKeys;

defined('ABSPATH') || exit;

/**
 * "Odds Comparison" top-level screen with tabs.
 *
 * Settings API: one option, one option group. Each tab is its own form with a hidden `_tab`
 * field, and {@see SettingsSanitizer} merges only that tab's keys. Saving goes through
 * options.php, which checks the nonce (`settings_fields()`) and `manage_options`. Each setting
 * is a numbered card ({@see cards()}). The Status tab is rendered by {@see StatusPage} and has
 * no settings form.
 */
final class SettingsPage implements HookableInterface
{
    /** Menu and page slug. */
    public const SLUG = 'odds-comparison';

    /** Settings API option group. */
    public const OPTION_GROUP = 'odds_comparison';

    /** Capability for the screen and every action. */
    public const CAPABILITY = 'manage_options';

    /** Input name prefix of every field. */
    private const NAME = OptionKeys::SETTINGS;

    /** Hook suffix returned by add_menu_page(); assets load only there. */
    private string $hookSuffix = '';

    /**
     * Per-request sanitize memo: [input fingerprint, output]. WordPress calls the sanitize
     * callback a second time, with the first call's output, when update_option() falls back to
     * add_option() (option missing).
     *
     * @var array{string, array<string, mixed>}|null
     */
    private ?array $sanitized = null;

    /**
     * @param Settings            $settings     Settings accessor.
     * @param BookmakerRegistry   $bookmakers   Bookmaker registry.
     * @param MarketRegistry      $markets      Market registry.
     * @param CompetitionRegistry $competitions Competition registry.
     * @param SourceFactory       $sources      Source factory.
     * @param SourceProbe         $probe        Source switch probe.
     * @param StatusPage          $status       Status tab renderer and bookmaker coverage.
     * @param string              $pluginFile   Main plugin file (asset URLs).
     * @param bool                $demoAvailable Whether Demo mode can be chosen (recorded data present).
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly BookmakerRegistry $bookmakers,
        private readonly MarketRegistry $markets,
        private readonly CompetitionRegistry $competitions,
        private readonly SourceFactory $sources,
        private readonly SourceProbe $probe,
        private readonly StatusPage $status,
        private readonly string $pluginFile,
        private readonly bool $demoAvailable
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Add the top-level menu.
     *
     * @return void
     */
    public function addMenu(): void
    {
        $this->hookSuffix = (string) add_menu_page(
            __('Odds Comparison', 'odds-comparison'),
            __('Odds Comparison', 'odds-comparison'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-chart-bar',
            80
        );
    }

    /**
     * Register the option, sections and fields.
     *
     * @return void
     */
    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, OptionKeys::SETTINGS, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            // No 'default': WordPress would treat a stored value equal to it as a missing option
            // and re-sanitize on add_option(). Settings::all() merges defaults on every read.
            'show_in_rest'      => false,
        ]);
    }

    /**
     * Settings API sanitize callback.
     *
     * @param mixed $input Submitted values (already unslashed by WordPress).
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        $current = $this->settings->all();

        if (!current_user_can(self::CAPABILITY)) {
            return $current;
        }

        $fingerprint = md5((string) wp_json_encode($input));

        // Same submission again, or our own output passed back by add_option(): already clean.
        if ($this->sanitized !== null && ($this->sanitized[0] === $fingerprint || $input === $this->sanitized[1])) {
            return $this->sanitized[1];
        }

        $sanitizer = new SettingsSanitizer(
            $this->competitions->keys(),
            $this->markets->keys(),
            $this->bookmakers->keys(),
            $this->sources->ids(),
            $this->demoAvailable,
            fn (string $sourceId): ?string => $this->probe->problem($sourceId),
            $this->sourceLabels()
        );
        $result    = $sanitizer->sanitize($input, $current);

        foreach ($result['errors'] as $error) {
            // settings_errors() prints messages unescaped, so escape them here.
            add_settings_error(self::OPTION_GROUP, 'odds_comparison_' . $error['code'], esc_html($error['message']));
        }

        $this->sanitized = [$fingerprint, $result['settings']];

        return $result['settings'];
    }

    /**
     * Load admin assets on this screen only.
     *
     * @param string $hookSuffix Current admin page hook suffix.
     * @return void
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($this->hookSuffix === '' || $hookSuffix !== $this->hookSuffix) {
            return;
        }

        $base = plugin_dir_url($this->pluginFile) . 'assets/admin/';

        wp_enqueue_style('odds-comparison-admin', $base . 'admin.css', [], Plugin::VERSION);
        wp_enqueue_script(
            'odds-comparison-admin',
            $base . 'admin.js',
            ['wp-api-fetch', 'wp-i18n'],
            Plugin::VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_set_script_translations('odds-comparison-admin', 'odds-comparison');
    }

    /**
     * Render the screen.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage Odds Comparison.', 'odds-comparison'), 403);
        }

        $tab = $this->currentTab();

        echo '<div class="wrap oc-admin">';
        printf('<h1>%s</h1>', esc_html__('Odds Comparison', 'odds-comparison'));
        $this->status->renderNotices();
        settings_errors();

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Settings sections', 'odds-comparison') . '">';

        foreach ($this->tabs() as $slug => $label) {
            printf(
                '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
                esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('admin.php'))),
                $slug === $tab ? ' nav-tab-active' : '',
                $slug === $tab ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }

        echo '</nav>';

        if ($tab === 'status') {
            $this->status->render();
        } else {
            echo '<form method="post" action="options.php" class="oc-settings-form">';
            settings_fields(self::OPTION_GROUP);
            printf('<input type="hidden" name="%s[_tab]" value="%s" />', esc_attr(self::NAME), esc_attr($tab));
            echo '<div class="oc-cards">';

            foreach ($this->cards()[$tab] ?? [] as $number => $card) {
                $this->card($number + 1, $card);
            }

            echo '</div><div class="oc-save">';
            submit_button(null, 'primary', 'submit', false);
            echo '</div></form>';
        }

        echo '</div>';
    }

    /**
     * Data source radios with key status.
     *
     * @return void
     */
    public function fieldActiveSource(): void
    {
        $current = $this->settings->activeSource();

        echo '<fieldset aria-describedby="oc-active-source-help"><legend class="screen-reader-text">'
            . esc_html__('Data source', 'odds-comparison') . '</legend>';

        foreach ($this->sourceLabels() as $id => $label) {
            printf(
                '<label class="oc-choice">'
                . '<input type="radio" name="%1$s[active_source]" value="%2$s"%3$s /> %4$s</label>',
                esc_attr(self::NAME),
                esc_attr($id),
                checked($id, $current, false),
                esc_html($label)
            );
        }

        echo '</fieldset>';
        printf(
            '<p class="description" id="oc-active-source-help">%s %s</p>',
            esc_html__(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
                'Where odds are fetched from. Switching makes one test request first and only switches when it returns odds.',
                'odds-comparison'
            ),
            esc_html(
                Plugin::theOddsApiKey() !== ''
                    ? __('The Odds API key is configured.', 'odds-comparison')
                    : __(
                        'The Odds API key is not configured: define ODDS_COMPARISON_THEODDSAPI_KEY in wp-config.php.',
                        'odds-comparison'
                    )
            )
        );
    }

    /**
     * Competition checkboxes.
     *
     * @return void
     */
    public function fieldCompetitions(): void
    {
        $enabled = $this->settings->enabledCompetitions();
        $source  = $this->settings->activeSource();

        echo '<fieldset aria-describedby="oc-competitions-help"><legend class="screen-reader-text">'
            . esc_html__('Competitions', 'odds-comparison') . '</legend>';

        foreach ($this->competitions->all() as $competition) {
            $covered = $competition->sourcePath($source) !== null;

            printf(
                '<label class="oc-choice">'
                . '<input type="checkbox" name="%1$s[enabled_competitions][]" value="%2$s"%3$s /> %4$s%5$s</label>',
                esc_attr(self::NAME),
                esc_attr($competition->slug),
                checked(in_array($competition->slug, $enabled, true), true, false),
                esc_html($competition->name),
                $covered
                    ? ''
                    : ' <span class="oc-muted">'
                        . esc_html__('(not covered by the current source)', 'odds-comparison') . '</span>'
            );
        }

        echo '</fieldset>';
        $this->help(
            'oc-competitions-help',
            __('Events of enabled competitions are discovered and offered in the block editor.', 'odds-comparison')
        );
    }

    /**
     * Default odds format select.
     *
     * @return void
     */
    public function fieldOddsFormat(): void
    {
        $labels = [
            OddsFormat::Fractional->value => __('Fractional (5/2)', 'odds-comparison'),
            OddsFormat::Decimal->value    => __('Decimal (3.50)', 'odds-comparison'),
            OddsFormat::American->value   => __('American (+250)', 'odds-comparison'),
        ];

        $this->select(
            'oc-default-odds-format',
            'default_odds_format',
            $labels,
            $this->settings->defaultOddsFormat()->value
        );
        $this->help(
            'oc-default-odds-format-help',
            __('Used by blocks set to "Use site default". Visitors can switch formats on the page.', 'odds-comparison')
        );
    }

    /**
     * Data mode radios.
     *
     * @return void
     */
    public function fieldDataMode(): void
    {
        $mode = $this->settings->dataMode();

        echo '<fieldset aria-describedby="oc-data-mode-help"><legend class="screen-reader-text">'
            . esc_html__('Data mode', 'odds-comparison') . '</legend>';
        printf(
            '<label class="oc-choice">'
            . '<input type="radio" name="%1$s[data_mode]" value="demo"%2$s%3$s /> %4$s</label>',
            esc_attr(self::NAME),
            checked('demo', $mode, false),
            disabled($this->demoAvailable, false, false),
            esc_html__('Demo data', 'odds-comparison')
        );
        printf(
            '<label class="oc-choice"><input type="radio" name="%1$s[data_mode]" value="live"%2$s /> %3$s</label>',
            esc_attr(self::NAME),
            checked('live', $mode, false),
            esc_html__('Live odds from the data source', 'odds-comparison')
        );
        echo '</fieldset>';
        $this->help(
            'oc-data-mode-help',
            // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
            __('Demo data shows real odds recorded from the data source, with kick-off dates moved to the coming days, and makes no outside requests. Tables show a "Demo data" badge. Switch to Live once the data source works (see the Status tab).', 'odds-comparison')
        );
    }

    /**
     * Bookmaker tiles: show checkbox and affiliate link. The site-wide order and label overrides
     * are kept as stored but not edited here; editors order bookmakers per block.
     *
     * @return void
     */
    public function fieldBookmakers(): void
    {
        $this->help(
            'oc-bookmakers-help',
            // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
            __('Tick the bookmakers whose odds appear on your site. Add your affiliate link (http or https), or leave it empty to link to the bookmaker\'s website.', 'odds-comparison')
        );

        $custom = __('The name in odds tables links to your link', 'odds-comparison');

        echo '<ul class="oc-tiles oc-bookmaker-tiles">';

        foreach ($this->settings->orderedBookmakers($this->bookmakers->keys()) as $slug) {
            $bookmaker = $this->bookmakers->get($slug);

            if ($bookmaker === null) {
                continue;
            }

            $row     = $this->settings->bookmaker($slug);
            $field   = sprintf('%s[bookmakers][%s]', self::NAME, $slug);
            $id      = 'oc-bm-' . $slug;
            $website = sprintf(
                /* translators: %s: bookmaker website address. */
                __('The name in odds tables links to %s', 'odds-comparison'),
                untrailingslashit((string) preg_replace('#^https?://#', '', $bookmaker->defaultUrl))
            );

            printf('<li class="oc-tile oc-bookmaker%s">', $row['enabled'] ? '' : ' is-off');
            printf(
                '<label class="oc-tile__name"><input type="checkbox" name="%1$s[enabled]" value="1"%2$s'
                . ' data-oc-bookmaker /> %3$s</label>',
                esc_attr($field),
                checked($row['enabled'], true, false),
                esc_html($bookmaker->name)
            );
            printf(
                '<label class="oc-tile__label" for="%1$s-link">%2$s'
                . '<span class="screen-reader-text"> %3$s</span></label>'
                . '<input type="url" id="%1$s-link" name="%4$s[link]" value="%5$s" placeholder="%6$s"'
                . ' aria-describedby="%1$s-target" data-oc-link />',
                esc_attr($id),
                esc_html__('Affiliate link', 'odds-comparison'),
                /* translators: %s: bookmaker name. */
                esc_html(sprintf(__('for %s', 'odds-comparison'), $bookmaker->name)),
                esc_attr($field),
                esc_attr($row['link']),
                esc_attr($bookmaker->defaultUrl)
            );
            printf(
                '<p class="oc-tile__target%1$s" id="%2$s-target" data-custom="%3$s" data-website="%4$s">%5$s</p>',
                $row['link'] !== '' ? ' is-custom' : '',
                esc_attr($id),
                esc_attr($custom),
                esc_attr($website),
                esc_html($row['link'] !== '' ? $custom : $website)
            );
            echo '</li>';
        }

        echo '</ul>';
    }

    /**
     * Market tiles with a short description and the sources that offer them.
     *
     * @return void
     */
    public function fieldMarkets(): void
    {
        $enabled = $this->settings->enabledMarkets();
        $labels  = $this->sourceLabels();

        $this->help(
            'oc-markets-help',
            __('Enabled markets can be chosen in the block. At least one is required.', 'odds-comparison')
        );
        // Marks a submission whose market boxes may all be unticked (see SettingsSanitizer).
        printf('<input type="hidden" name="%s[_markets]" value="1" />', esc_attr(self::NAME));
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Markets', 'odds-comparison') . '</legend>';
        echo '<ul class="oc-tiles oc-market-tiles">';

        foreach ($this->markets->all() as $market) {
            $offeredBy = array_map(
                static fn (string $id): string => SourceLabels::short($id),
                array_keys(array_intersect_key($market->sourceNames, $labels))
            );

            printf(
                '<li><label class="oc-tile oc-market">'
                . '<input type="checkbox" name="%1$s[enabled_markets][]" value="%2$s"%3$s data-oc-market />'
                . '<span class="oc-tile__name">%4$s</span><span class="oc-tile__meta">%5$s</span>'
                . '<span class="oc-tile__meta">%6$s</span></label></li>',
                esc_attr(self::NAME),
                esc_attr($market->key),
                checked(in_array($market->key, $enabled, true), true, false),
                esc_html($market->label),
                esc_html($this->marketDescription($market)),
                esc_html(
                    sprintf(
                        /* translators: 1: market type, 2: comma-separated source names. */
                        __('%1$s · from %2$s', 'odds-comparison'),
                        $this->marketType($market),
                        implode(', ', $offeredBy)
                    )
                )
            );
        }

        echo '</ul></fieldset>';
    }

    /**
     * Refresh interval select.
     *
     * @return void
     */
    public function fieldRefreshInterval(): void
    {
        $this->minutesSelect(
            'oc-refresh-interval',
            'refresh_interval',
            SettingsSanitizer::REFRESH_INTERVALS,
            (int) $this->settings->get('refresh_interval')
        );
        $this->help(
            'oc-refresh-interval-help',
            __('How often due odds are fetched. A system cron is recommended for exact timing.', 'odds-comparison')
        );
    }

    /**
     * Stale-after number.
     *
     * @return void
     */
    public function fieldStaleAfter(): void
    {
        $this->number('oc-stale-after', 'stale_after', (int) $this->settings->get('stale_after'), 10, 1440);
        $this->help(
            'oc-stale-after-help',
            // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
            __('Minutes after which a table says "Odds may be out of date". At least twice the refresh interval.', 'odds-comparison')
        );
    }

    /**
     * Requests per run number.
     *
     * @return void
     */
    public function fieldRequestsPerRun(): void
    {
        $this->number('oc-requests-per-run', 'requests_per_run', (int) $this->settings->get('requests_per_run'), 1, 50);
        $this->help(
            'oc-requests-per-run-help',
            __('Upper limit of source requests per refresh run; the rest continues on the next run.', 'odds-comparison')
        );
    }

    /**
     * Minimum request interval number.
     *
     * @return void
     */
    public function fieldMinRequestInterval(): void
    {
        $this->number(
            'oc-min-request-interval',
            'min_request_interval',
            $this->settings->minRequestInterval(),
            2,
            Settings::MAX_REQUEST_INTERVAL
        );
        $interval = $this->settings->minRequestInterval();
        $this->help(
            'oc-min-request-interval-help',
            sprintf(
                /* translators: 1: maximum seconds, 2: seconds per run, 3: requests per run. */
                __('Seconds between two requests to the same site (plus a little random delay), at most %1$d. A refresh run lasts up to %2$d seconds, so at this value it can make about %3$d requests to the site.', 'odds-comparison'), // phpcs:ignore Generic.Files.LineLength.TooLong
                Settings::MAX_REQUEST_INTERVAL,
                (int) RefreshScheduler::TIME_BUDGET,
                (int) floor(RefreshScheduler::TIME_BUDGET / max(1, $interval)) + 1
            )
        );
    }

    /**
     * The Odds API interval select.
     *
     * @return void
     */
    public function fieldApiRefreshInterval(): void
    {
        $this->minutesSelect(
            'oc-api-refresh-interval',
            'api_refresh_interval',
            SettingsSanitizer::API_REFRESH_INTERVALS,
            (int) $this->settings->get('api_refresh_interval')
        );
        $this->help(
            'oc-api-refresh-interval-help',
            // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
            __('How often The Odds API is asked for a competition. Each request uses one credit of your plan.', 'odds-comparison')
        );
    }

    /**
     * Source labels for the UI.
     *
     * @return array<string, string>
     */
    public function sourceLabels(): array
    {
        $labels = [];

        foreach ($this->sources->ids() as $id) {
            $labels[$id] = SourceLabels::long($id);
        }

        return $labels;
    }

    /**
     * Tab slugs and labels.
     *
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            'general'    => __('General', 'odds-comparison'),
            'bookmakers' => __('Bookmakers & markets', 'odds-comparison'),
            'cache'      => __('Cache & Schedule', 'odds-comparison'),
            'status'     => __('Status', 'odds-comparison'),
        ];
    }

    /**
     * Current tab from the query string (whitelisted).
     *
     * @return string
     */
    private function currentTab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $tab = $tab === 'markets' ? 'bookmakers' : $tab; // Markets used to be a tab of its own.

        return array_key_exists($tab, $this->tabs()) ? $tab : 'general';
    }

    /**
     * Settings cards per tab, in order: title, field callback, optional id of the field's single
     * control (the title becomes its label) and optional count callback for the card header.
     *
     * @return array<string, list<array{0: string, 1: string, 2?: string, 3?: string}>>
     */
    private function cards(): array
    {
        return [
            'general'    => [
                [__('Data mode', 'odds-comparison'), 'fieldDataMode'],
                [__('Data source', 'odds-comparison'), 'fieldActiveSource'],
                [__('Competitions', 'odds-comparison'), 'fieldCompetitions'],
                [__('Default odds format', 'odds-comparison'), 'fieldOddsFormat', 'oc-default-odds-format'],
            ],
            'bookmakers' => [
                [__('Bookmakers and links', 'odds-comparison'), 'fieldBookmakers', '', 'bookmakerCount'],
                [__('Markets', 'odds-comparison'), 'fieldMarkets', '', 'marketCount'],
            ],
            'cache'      => [
                [__('Refresh interval', 'odds-comparison'), 'fieldRefreshInterval', 'oc-refresh-interval'],
                [__('Stale after', 'odds-comparison'), 'fieldStaleAfter', 'oc-stale-after'],
                [__('Requests per run', 'odds-comparison'), 'fieldRequestsPerRun', 'oc-requests-per-run'],
                [
                    __('Minimum request interval', 'odds-comparison'),
                    'fieldMinRequestInterval',
                    'oc-min-request-interval',
                ],
                [__('The Odds API interval', 'odds-comparison'), 'fieldApiRefreshInterval', 'oc-api-refresh-interval'],
            ],
        ];
    }

    /**
     * One numbered settings card.
     *
     * @param int                                           $number Position on the tab.
     * @param array{0: string, 1: string, 2?: string, 3?: string} $card   Card definition.
     * @return void
     */
    private function card(int $number, array $card): void
    {
        $id       = 'oc-card-' . $number;
        $labelFor = $card[2] ?? '';
        $title    = $labelFor !== ''
            ? sprintf('<label for="%s">%s</label>', esc_attr($labelFor), esc_html($card[0]))
            : esc_html($card[0]);

        printf('<section class="oc-card" aria-labelledby="%s"><header class="oc-card__head">', esc_attr($id));
        printf('<span class="oc-card__num" aria-hidden="true">%d</span>', (int) $number);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title is escaped above.
        printf('<h2 class="oc-card__title" id="%s">%s</h2>', esc_attr($id), $title);

        if (($card[3] ?? '') !== '') {
            printf('<span class="oc-card__count" data-oc-count>%s</span>', esc_html($this->{$card[3]}()));
        }

        echo '</header><div class="oc-card__body">';
        $this->{$card[1]}();
        echo '</div></section>';
    }

    /**
     * "9 of 10 shown".
     *
     * @return string
     */
    private function bookmakerCount(): string
    {
        $registered = $this->bookmakers->keys();

        return sprintf(
            /* translators: 1: bookmakers shown, 2: all bookmakers. */
            __('%1$d of %2$d shown', 'odds-comparison'),
            count($this->settings->enabledBookmakers($registered)),
            count($registered)
        );
    }

    /**
     * "2 of 5 on".
     *
     * @return string
     */
    private function marketCount(): string
    {
        return sprintf(
            /* translators: 1: enabled markets, 2: all markets. */
            __('%1$d of %2$d on', 'odds-comparison'),
            count($this->settings->enabledMarkets()),
            count($this->markets->all())
        );
    }

    /**
     * A select of values.
     *
     * @param string                $id      Element id.
     * @param string                $key     Setting key.
     * @param array<string, string> $options Value => label.
     * @param string                $current Current value.
     * @return void
     */
    private function select(string $id, string $key, array $options, string $current): void
    {
        printf(
            '<select id="%1$s" name="%2$s[%3$s]" aria-describedby="%1$s-help">',
            esc_attr($id),
            esc_attr(self::NAME),
            esc_attr($key)
        );

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($value, $current, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    /**
     * A select of minute values.
     *
     * @param string    $id      Element id.
     * @param string    $key     Setting key.
     * @param list<int> $minutes Allowed minutes.
     * @param int       $current Current value.
     * @return void
     */
    private function minutesSelect(string $id, string $key, array $minutes, int $current): void
    {
        $options = [];

        foreach ($minutes as $value) {
            $options[(string) $value] = $value >= 60 && $value % 60 === 0
                /* translators: %d: number of hours. */
                ? sprintf(_n('%d hour', '%d hours', intdiv($value, 60), 'odds-comparison'), intdiv($value, 60))
                /* translators: %d: number of minutes. */
                : sprintf(_n('%d minute', '%d minutes', $value, 'odds-comparison'), $value);
        }

        $this->select($id, $key, $options, (string) $current);
    }

    /**
     * A number input.
     *
     * @param string $id    Element id.
     * @param string $key   Setting key.
     * @param int    $value Current value.
     * @param int    $min   Minimum.
     * @param int    $max   Maximum.
     * @return void
     */
    private function number(string $id, string $key, int $value, int $min, int $max): void
    {
        printf(
            '<input type="number" class="small-text" id="%1$s" name="%2$s[%3$s]" value="%4$d"'
            . ' min="%5$d" max="%6$d" step="1" required aria-describedby="%1$s-help" />',
            esc_attr($id),
            esc_attr(self::NAME),
            esc_attr($key),
            (int) $value,
            (int) $min,
            (int) $max
        );
    }

    /**
     * Help text paragraph.
     *
     * @param string $id   Element id (target of aria-describedby).
     * @param string $text Help text.
     * @return void
     */
    private function help(string $id, string $text): void
    {
        printf('<p class="description" id="%s">%s</p>', esc_attr($id), esc_html($text));
    }

    /**
     * What a market is about, for the Markets card.
     *
     * @param Market $market Market.
     * @return string
     */
    private function marketDescription(Market $market): string
    {
        return match ($market->key) {
            'match-result'    => __('Home win, draw or away win in one match.', 'odds-comparison'),
            'outright-winner' => __('Which team wins the competition.', 'odds-comparison'),
            'top-goalscorer'  => __('Which player scores the most goals in the season.', 'odds-comparison'),
            'relegation'      => __('Which teams go down at the end of the season.', 'odds-comparison'),
            'top-four-finish' => __('Which teams finish in the top four.', 'odds-comparison'),
            default           => $market->label,
        };
    }

    /**
     * Human market type.
     *
     * @param Market $market Market.
     * @return string
     */
    private function marketType(Market $market): string
    {
        return $market->isOutright()
            ? __('Season-long', 'odds-comparison')
            : __('Per match', 'odds-comparison');
    }
}
