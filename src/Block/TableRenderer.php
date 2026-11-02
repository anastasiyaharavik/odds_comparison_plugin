<?php

/**
 * Server-side renderer of the odds table block.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

use Closure;
use DateTimeImmutable;
use OddsComparison\Contracts\OddsRepositoryInterface;
use OddsComparison\Domain\Bookmaker;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use OddsComparison\Domain\OddsFormat;
use OddsComparison\Domain\Price;
use OddsComparison\Odds\OddsConverter;
use OddsComparison\Registry\BookmakerRegistry;
use OddsComparison\Registry\CompetitionRegistry;
use OddsComparison\Registry\MarketRegistry;
use OddsComparison\Settings\Settings;
use OddsComparison\Storage\EventIndex;

defined('ABSPATH') || exit;

/**
 * Renders `odds-comparison/odds-table` from stored odds only; it never scrapes.
 *
 * The editor preview uses the same renderer through ServerSideRender, so editor and frontend
 * cannot drift. Flow:
 *
 *     attributes → validation → view model → `odds_comparison/render_args`
 *       → template (theme-overridable) → `odds_comparison/table_html`
 *
 * Fallback states: (a) no event / no bookmakers → nothing (preview: a hint); (b) an event that is
 * no longer indexed (a demo match after the switch to Live, or a finished event) → nothing for
 * visitors, an explanation for the editor preview and for users who can edit the post; (c) no
 * stored odds → "Odds temporarily unavailable" (preview: "being fetched"); (d) stale → marked;
 * (e) match started → notice without prices.
 *
 * Output is cached per attributes, event, fetch time and stale flag, filtered bookmaker links,
 * settings, locale and date (see {@see self::cacheKey()}); the footer shows the fetch time, not
 * "N min ago", so nothing in the key changes by the minute. The cache is checked before the view
 * model is built, so a hit skips the price conversions too. Without a persistent object cache, the
 * cache only spans the request.
 */
final class TableRenderer
{
    /** Rows shown for an outright market when the block selects no selections. */
    public const DEFAULT_OUTRIGHT_ROWS = 10;

    /** Object cache group. */
    private const CACHE_GROUP = 'odds_comparison';

    /** Fragment cache lifetime (seconds). */
    private const CACHE_TTL = 300;

    /** Tables rendered in this request (unique ids for repeated blocks). */
    private int $instances = 0;

    private readonly Closure $clock;

    /**
     * @param Settings                $settings     Settings.
     * @param BookmakerRegistry       $bookmakers   Bookmaker registry.
     * @param MarketRegistry          $markets      Market registry.
     * @param CompetitionRegistry     $competitions Competition registry.
     * @param EventIndex              $events       Event index.
     * @param OddsRepositoryInterface $repository   Odds storage.
     * @param OddsConverter           $converter    Odds converter (all three formats per price).
     * @param string                  $templateDir  Plugin templates directory.
     * @param Closure|null            $clock        fn(): DateTimeImmutable ("now").
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly BookmakerRegistry $bookmakers,
        private readonly MarketRegistry $markets,
        private readonly CompetitionRegistry $competitions,
        private readonly EventIndex $events,
        private readonly OddsRepositoryInterface $repository,
        private readonly OddsConverter $converter,
        private readonly string $templateDir,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Block HTML.
     *
     * @param array<string, mixed> $attributes        Block attributes.
     * @param bool                 $preview           Whether this is the editor preview.
     * @param string               $wrapperAttributes Output of get_block_wrapper_attributes().
     * @return string
     */
    public function render(array $attributes, bool $preview = false, string $wrapperAttributes = ''): string
    {
        $instance = ++$this->instances;
        $cacheKey = $this->cacheKey($attributes, $preview, $wrapperAttributes, $instance);
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if (is_string($cached)) {
            return $cached;
        }

        $model                      = $this->viewModel($attributes, $preview);
        $model['uid']              = 'oc-odds-' . substr(md5($cacheKey), 0, 8);
        $model['wrapperAttributes'] = $wrapperAttributes;

        /**
         * Filters the data passed to the odds table template.
         *
         * @param array<string, mixed> $args       View model (see TableRenderer::viewModel()).
         * @param array<string, mixed> $attributes Block attributes.
         */
        $args = apply_filters('odds_comparison/render_args', $model, $attributes);
        $html = $this->template(is_array($args) ? $args : $model);

        /**
         * Filters the final odds table HTML.
         *
         * @param string               $html Rendered HTML.
         * @param array<string, mixed> $args Template data.
         */
        $html = (string) apply_filters('odds_comparison/table_html', $html, $args);

        wp_cache_set($cacheKey, $html, self::CACHE_GROUP, self::CACHE_TTL);

        return $html;
    }

    /**
     * Validated data for the template.
     *
     * @param array<string, mixed> $attributes Block attributes.
     * @param bool                 $preview    Whether this is the editor preview.
     * @return array<string, mixed> `state` is one of: empty, unavailable, fetching, started, table.
     */
    public function viewModel(array $attributes, bool $preview = false): array
    {
        $eventId = is_string($attributes['eventId'] ?? null) ? $attributes['eventId'] : '';
        $event   = $eventId === '' ? null : $this->events->get($eventId);

        if ($event === null && $eventId !== '') {
            return $this->message(
                'missing',
                $preview || $this->editorViewing()
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
                    ? __('This odds table is hidden from visitors: its event is no longer available (a demo match after switching to live odds, or a finished event). Choose another event in the block settings.', 'odds-comparison')
                    : ''
            );
        }

        if ($event === null) {
            return $this->message(
                'empty',
                $preview ? __('Choose an event in the block settings.', 'odds-comparison') : ''
            );
        }

        $market = $this->enabledMarket($attributes['marketKey'] ?? 'match-result');

        if ($market === null || $market->isOutright() !== $event->isOutright()) {
            return $this->message('unavailable', __('Odds temporarily unavailable.', 'odds-comparison'));
        }

        $bookmakers = $this->bookmakersFor($attributes['bookmakers'] ?? []);

        if ($bookmakers === []) {
            return $this->message(
                'empty',
                $preview ? __('No bookmakers selected: this block shows nothing on the site.', 'odds-comparison') : ''
            );
        }

        $now = ($this->clock)();

        if ($event->hasStarted($now)) {
            return $this->message(
                'started',
                __('This event has started — odds are no longer available.', 'odds-comparison')
            );
        }

        $odds = $this->repository->get($event->id, $market->key);

        if ($odds !== null) {
            return $this->table($attributes, $event, $market, $odds, $bookmakers, $now);
        }

        return $preview
            // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable sentence must stay one literal.
            ? $this->message('fetching', __('Odds are being fetched — they will appear after the next refresh (about a minute).', 'odds-comparison'))
            : $this->message('unavailable', __('Odds temporarily unavailable.', 'odds-comparison'));
    }

    /**
     * Table view model.
     *
     * Match markets: rows = bookmakers, columns = selections. Outright markets (many
     * selections): rows = selections, columns = bookmakers, favourites first, limited to
     * `maxRows` unless the block picked selections.
     *
     * @param array<string, mixed>     $attributes Attributes.
     * @param Event                    $event      Event.
     * @param Market                   $market     Market.
     * @param MarketOdds               $odds       Stored odds.
     * @param array<string, Bookmaker> $bookmakers Displayed bookmakers in block order.
     * @param DateTimeImmutable        $now        Current time.
     * @return array<string, mixed>
     */
    private function table(
        array $attributes,
        Event $event,
        Market $market,
        MarketOdds $odds,
        array $bookmakers,
        DateTimeImmutable $now
    ): array {
        $format = OddsFormat::resolve(
            (string) ($attributes['oddsFormat'] ?? OddsFormat::INHERIT),
            $this->settings->defaultOddsFormat()
        );
        $wanted     = array_values(array_filter((array) ($attributes['selections'] ?? []), 'is_string'));
        $selections = $wanted === []
            ? $odds->selections
            : array_intersect_key($odds->selections, array_flip($wanted));
        $best       = $this->bestPrices($selections, $bookmakers);
        $outright   = $market->isOutright();
        $limited    = $outright && $wanted === [];

        if ($limited) {
            // Only the favourites are shown; skip building rows nobody sees.
            $selections = array_slice($this->favouritesFirst($selections, $best), 0, self::DEFAULT_OUTRIGHT_ROWS, true);
        }

        $links = [];

        foreach ($bookmakers as $slug => $bookmaker) {
            $links[$slug] = $this->link($bookmaker, $event, $market);
        }

        $context = [
            'odds'      => $odds,
            'best'      => $best,
            'format'    => $format,
            'highlight' => (bool) ($attributes['highlightBest'] ?? true),
            'links'     => $links,
        ];
        $bookmakerColumns = [];

        foreach ($bookmakers as $slug => $bookmaker) {
            $bookmakerColumns[] = [
                'key'   => $slug,
                'label' => $this->settings->bookmakerLabel($bookmaker),
                'logo'  => $bookmaker->logoUrl,
                'link'  => $links[$slug],
            ];
        }

        $selectionColumns = [];

        foreach ($selections as $key => $selection) {
            $selectionColumns[] = ['key' => (string) $key, 'label' => $selection['label']];
        }

        $rows = $outright
            ? $this->rows($selectionColumns, $bookmakerColumns, $context, true)
            : $this->rows($bookmakerColumns, $selectionColumns, $context, false);

        $caption     = is_string($attributes['caption'] ?? null) ? trim($attributes['caption']) : '';
        $competition = $this->competitions->get($event->competition)?->name ?? '';

        /**
         * Filters the responsible-gambling text under every odds table.
         *
         * @param string $disclaimer Plain text.
         */
        $disclaimer = apply_filters(
            'odds_comparison/disclaimer',
            __('18+ | Odds subject to change. Gamble responsibly.', 'odds-comparison')
        );

        return [
            'state'        => 'table',
            'layout'       => $outright ? 'selection-rows' : 'bookmaker-rows',
            'caption'      => $caption !== '' ? $caption : $event->name . ' — ' . $market->label,
            'rowHeader'    => $outright ? __('Selection', 'odds-comparison') : __('Bookmaker', 'odds-comparison'),
            'columns'      => $outright ? $bookmakerColumns : $selectionColumns,
            'rows'         => $rows,
            'maxRows'      => $limited ? self::DEFAULT_OUTRIGHT_ROWS : count($rows),
            'format'       => $format->value,
            'showSwitcher' => (bool) ($attributes['showFormatSwitcher'] ?? true),
            'fetchedAt'    => $odds->fetchedAt,
            'updated'      => $this->updatedText($odds->fetchedAt, $now),
            'stale'        => $this->repository->isStale($odds),
            'demo'         => $this->settings->isDemo(),
            'disclaimer'   => is_string($disclaimer) ? $disclaimer : '',
            'eventId'      => $event->id,
            'marketKey'    => $market->key,
            'competition'  => $competition,
            'eventName'    => $event->name,
            'home'         => $event->home ?? '',
            'away'         => $event->away ?? '',
            'kickoff'      => $event->startsAt === null ? null : [
                'iso'  => $event->startsAt->format(DATE_ATOM),
                /* translators: kick-off date and time format, see https://www.php.net/manual/datetime.format.php */
                'text' => wp_date(__('D j M, H:i', 'odds-comparison'), $event->startsAt->getTimestamp()),
            ],
            'subtitle'     => $caption !== '' ? $caption : implode(' · ', array_filter([$competition, $market->label])),
            'bestOffers'   => $this->bestOffers(
                array_slice($rows, 0, $limited ? self::DEFAULT_OUTRIGHT_ROWS : count($rows)),
                $outright ? $bookmakerColumns : $selectionColumns,
                $outright
            ),
            'bookmakerCount' => count($bookmakers),
        ];
    }

    /**
     * Table rows: one per row item, one cell per column item.
     *
     * @param list<array<string, string>> $rowItems    Row items (bookmakers or selections).
     * @param list<array<string, string>> $columnItems Column items (selections or bookmakers).
     * @param array<string, mixed>        $context     Odds, best prices, format, highlight flag, links.
     * @param bool                        $selectionRows Whether rows are selections (outright layout).
     * @return list<array<string, mixed>>
     */
    private function rows(array $rowItems, array $columnItems, array $context, bool $selectionRows): array
    {
        $rows = [];

        foreach ($rowItems as $row) {
            $cells = [];

            foreach ($columnItems as $column) {
                [$selectionKey, $slug] = $selectionRows ? [$row['key'], $column['key']] : [$column['key'], $row['key']];
                $cells[]               = $this->cell($selectionKey, $slug, $context) + ['column' => $column['label']];
            }

            $rows[] = $row + ['cells' => $cells];
        }

        return $rows;
    }

    /**
     * One price cell with all three formats (for the visitor switcher).
     *
     * @param string               $selectionKey Selection key.
     * @param string               $slug         Bookmaker slug.
     * @param array<string, mixed> $context      Odds, best prices, format, highlight flag, links.
     * @return array<string, mixed>
     */
    private function cell(string $selectionKey, string $slug, array $context): array
    {
        $price = $context['odds']->price($selectionKey, $slug);

        if ($price === null) {
            return ['price' => false];
        }

        $best = $context['best'][$selectionKey] ?? null;
        $text = [
            OddsFormat::Decimal->value    => $this->converter->format($price, OddsFormat::Decimal),
            OddsFormat::Fractional->value => $this->converter->format($price, OddsFormat::Fractional),
            OddsFormat::American->value   => $this->converter->format($price, OddsFormat::American),
        ];

        return [
            'price' => true,
            'text'  => $text[$context['format']->value],
            'dec'   => $text[OddsFormat::Decimal->value],
            'frac'  => $text[OddsFormat::Fractional->value],
            'us'    => $text[OddsFormat::American->value],
            'best'  => $context['highlight'] && $best !== null && $price->decimal >= $best,
            'link'  => $context['links'][$slug] ?? '',
        ];
    }

    /**
     * Best price of each selection and where to get it (the phone summary). The first displayed
     * bookmaker wins a tie. Empty when best-price highlighting is off.
     *
     * @param list<array<string, mixed>>  $rows          Table rows.
     * @param list<array<string, string>> $columns       Table columns.
     * @param bool                        $selectionRows Whether rows are selections (outright layout).
     * @return list<array{selection: string, bookmaker: string, cell: array<string, mixed>}>
     */
    private function bestOffers(array $rows, array $columns, bool $selectionRows): array
    {
        $offers = [];
        $count  = $selectionRows ? count($rows) : count($columns);

        for ($i = 0; $i < $count; ++$i) {
            $candidates = $selectionRows ? $rows[$i]['cells'] : array_column(array_column($rows, 'cells'), $i);

            foreach ($candidates as $j => $cell) {
                if (!empty($cell['best'])) {
                    $offers[] = [
                        'selection' => $selectionRows ? $rows[$i]['label'] : $columns[$i]['label'],
                        'bookmaker' => $selectionRows ? $columns[$j]['label'] : $rows[$j]['label'],
                        'cell'      => $cell,
                    ];
                    break;
                }
            }
        }

        return $offers;
    }

    /**
     * Highest decimal price per selection among the displayed bookmakers (null: none priced).
     *
     * @param array<string, array{label: string, prices: array<string, Price>}> $selections Selections.
     * @param array<string, Bookmaker>                                           $bookmakers Displayed bookmakers.
     * @return array<string, float|null>
     */
    private function bestPrices(array $selections, array $bookmakers): array
    {
        $best = [];

        foreach ($selections as $key => $selection) {
            $decimals   = array_map(
                static fn (Price $price): float => $price->decimal,
                array_intersect_key($selection['prices'], $bookmakers)
            );
            $best[$key] = $decimals === [] ? null : max($decimals);
        }

        return $best;
    }

    /**
     * Shortest-priced (most likely) selections first; unpriced ones last.
     *
     * @param array<string, array{label: string, prices: array<string, Price>}> $selections Selections.
     * @param array<string, float|null>                                          $best       Best prices.
     * @return array<string, array{label: string, prices: array<string, Price>}>
     */
    private function favouritesFirst(array $selections, array $best): array
    {
        uksort(
            $selections,
            static fn (string $a, string $b): int => [$best[$a] === null, $best[$a]] <=> [$best[$b] === null, $best[$b]]
        );

        return $selections;
    }

    /**
     * An admin-enabled market, or null.
     *
     * @param mixed $key Market key attribute.
     * @return Market|null
     */
    private function enabledMarket(mixed $key): ?Market
    {
        return is_string($key) && in_array($key, $this->settings->enabledMarkets(), true)
            ? $this->markets->get($key)
            : null;
    }

    /**
     * Displayed bookmakers: block selection ∩ admin-enabled, block order kept.
     *
     * @param mixed $requested Block attribute.
     * @return array<string, Bookmaker>
     */
    private function bookmakersFor(mixed $requested): array
    {
        $enabled = $this->settings->enabledBookmakers($this->bookmakers->keys());
        $slugs   = array_values(array_intersect(array_filter((array) $requested, 'is_string'), $enabled));

        return $this->bookmakers->only($slugs);
    }

    /**
     * Outbound link of a bookmaker, filterable per event and market. A filtered value that is not
     * an http(s) URL is dropped, so the price is shown without a link.
     *
     * @param Bookmaker|null $bookmaker Bookmaker.
     * @param Event          $event     Event.
     * @param Market         $market    Market.
     * @return string URL or "" for none.
     */
    private function link(?Bookmaker $bookmaker, Event $event, Market $market): string
    {
        if ($bookmaker === null) {
            return '';
        }

        /**
         * Filters a bookmaker's outbound (affiliate) link.
         *
         * @param string    $link      Saved affiliate link or the bookmaker's default URL.
         * @param Bookmaker $bookmaker Bookmaker.
         * @param Event     $event     Event shown.
         * @param Market    $market    Market shown.
         */
        $link = apply_filters(
            'odds_comparison/bookmaker_link',
            $this->settings->bookmakerLink($bookmaker),
            $bookmaker,
            $event,
            $market
        );

        // Filtered links get the same scheme rule as saved ones: http(s) only, else plain text.
        return is_string($link) ? esc_url_raw($link, ['http', 'https']) : '';
    }

    /**
     * When the odds were fetched, in the site's time zone: "Updated 14:05", or "Updated 1 Nov,
     * 14:05" when not today. An absolute time (unlike "5 minutes ago") stays true while the HTML
     * is cached, so the cache key does not have to change every minute.
     *
     * @param DateTimeImmutable $fetchedAt Fetch time.
     * @param DateTimeImmutable $now       Now.
     * @return string
     */
    private function updatedText(DateTimeImmutable $fetchedAt, DateTimeImmutable $now): string
    {
        $time  = $fetchedAt->getTimestamp();
        $today = wp_date('Y-m-d', $time) === wp_date('Y-m-d', $now->getTimestamp());

        return sprintf(
            /* translators: %s: time (or date and time) the odds were fetched. */
            __('Updated %s', 'odds-comparison'),
            $today
                /* translators: time format, see https://www.php.net/manual/datetime.format.php */
                ? wp_date(__('H:i', 'odds-comparison'), $time)
                /* translators: date and time format, see https://www.php.net/manual/datetime.format.php */
                : wp_date(__('j M, H:i', 'odds-comparison'), $time)
        );
    }

    /**
     * A message-only view model.
     *
     * @param string $state   State.
     * @param string $message Plain text ("" renders nothing).
     * @return array{state: string, message: string}
     */
    private function message(string $state, string $message): array
    {
        return ['state' => $state, 'message' => $message];
    }

    /**
     * Whether the page is viewed by someone who can edit the post being shown (front end).
     *
     * @return bool
     */
    private function editorViewing(): bool
    {
        $postId = (int) get_the_ID();

        return $postId > 0 && current_user_can('edit_post', $postId);
    }

    /**
     * Fragment cache key, computed before the view model so a hit skips all of its work.
     *
     * It covers everything the view model depends on: the attributes, the event as indexed
     * (name, kick-off, started or not), the stored odds' fetch time and stale flag, the
     * bookmakers' links as filtered by `odds_comparison/bookmaker_link`, the settings, the locale
     * and the site's current date (the footer adds the date to the fetch time on other days).
     * Nothing in it changes by the minute, so a cached table is reused until something visible
     * changes. The event and odds lookups are memoized per request, so the later view model build
     * reads them for free.
     *
     * @param array<string, mixed> $attributes        Attributes.
     * @param bool                 $preview           Preview flag.
     * @param string               $wrapperAttributes Wrapper attributes.
     * @param int                  $instance          Table number in this request.
     * @return string
     */
    private function cacheKey(array $attributes, bool $preview, string $wrapperAttributes, int $instance): string
    {
        $now       = ($this->clock)();
        $eventId   = is_string($attributes['eventId'] ?? null) ? $attributes['eventId'] : '';
        $marketKey = is_string($attributes['marketKey'] ?? null) ? $attributes['marketKey'] : '';
        $event     = $eventId === '' ? null : $this->events->get($eventId);
        $odds      = $event === null || $marketKey === '' ? null : $this->repository->get($event->id, $marketKey);
        $market    = $event === null ? null : $this->enabledMarket($marketKey);
        $links     = [];

        if ($event !== null && $market !== null) {
            foreach ($this->bookmakersFor($attributes['bookmakers'] ?? []) as $slug => $bookmaker) {
                $links[$slug] = $this->link($bookmaker, $event, $market);
            }
        }

        return 'table_' . md5((string) wp_json_encode([
            $attributes,
            $event === null ? null : [
                $event->name,
                $event->startsAt?->getTimestamp(),
                $event->hasStarted($now),
            ],
            $odds?->fetchedAt->getTimestamp(),
            $odds === null ? null : $this->repository->isStale($odds),
            $links,
            $this->settings->all(),
            determine_locale(),
            $preview,
            $event === null && $eventId !== '' && !$preview ? $this->editorViewing() : null,
            $wrapperAttributes,
            $instance,
            wp_date('Y-m-d', $now->getTimestamp()),
        ]));
    }

    /**
     * Render the template: theme override `{theme}/odds-comparison/odds-table.php`, else the plugin's.
     *
     * @param array<string, mixed> $args Template data.
     * @return string
     */
    private function template(array $args): string
    {
        $file = locate_template(['odds-comparison/odds-table.php']);
        $file = $file !== '' ? $file : $this->templateDir . '/odds-table.php';

        ob_start();
        (static function (string $templateFile, array $args): void {
            include $templateFile;
        })($file, $args);

        return (string) ob_get_clean();
    }
}
