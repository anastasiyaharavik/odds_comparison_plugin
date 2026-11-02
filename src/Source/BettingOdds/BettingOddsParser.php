<?php

/**
 * HTML parser for BettingOdds.com pages.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source\BettingOdds;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

defined('ABSPATH') || exit;

/**
 * Extracts events and raw prices from BettingOdds.com pages. Pure PHP; no WordPress calls.
 *
 * Every price on the site is a link whose `data-*` attributes carry the bookmaker code, market
 * name, event name, participant and price. Only the decimal price is read: the page's fraction
 * is the nearest standard fraction, not the bookmaker's price. The parser relies on those
 * attributes rather than on table layout, and keeps every selector in {@see self::SELECTORS}
 * so a markup change is a one-place fix.
 *
 * Each page also has a sidebar widget of featured matches from other competitions. Links inside
 * it are always ignored.
 *
 * Results are raw strings. Numeric parsing, mapping to bookmaker slugs and plausibility checks
 * happen in the source and in OddsValidator. When a page yields nothing, {@see self::warnings()}
 * explains why (e.g. "markup changed") instead of failing.
 *
 * @phpstan-type RawEvent array{
 *     id: string, path: string, name: string, home: string, away: string, day: string, time: string
 * }
 * @phpstan-type RawPrice array{bookmaker: string, participant: string, decimal: string}
 */
final class BettingOddsParser
{
    /** Market name of match result prices on the source. */
    public const MATCH_RESULT = 'Match Result';

    /** Separator between home and away in the source's event names. */
    private const EVENT_SEPARATOR = ' vs ';

    /**
     * XPath expressions and attribute names (the only place that knows the source markup).
     */
    public const SELECTORS = [
        // Listing: one container per day, one item per match.
        'day'           => '//div[contains(concat(" ", normalize-space(@class), " "), " day-container ")]',
        'day_title'     => './/div[contains(concat(" ", normalize-space(@class), " "), " day-title ")]',
        'match_item'    => './/div[contains(concat(" ", normalize-space(@class), " "), " match-item ")]',
        // Only matches with their own page have a link; later fixtures are listed without one.
        'match_link'    => './/a[contains(concat(" ", normalize-space(@class), " "), " mm-top ")]',
        'match_time'    => './/div[contains(concat(" ", normalize-space(@class), " "), " mi-time ")]',
        // Any price link anywhere on a page.
        'price'         => '//a[@data-bookmaker and @data-marketname and @data-participantname]',
        'price_in_item' => './/a[@data-bookmaker and @data-marketname and @data-participantname]',
        // Sidebar widget of featured matches; prices inside it are ignored.
        'widget'        => 'ancestor::div[contains(concat(" ", normalize-space(@class), " "), " mi-box ")]',
        'attr_match_id'    => 'data-id',
        'attr_bookmaker'   => 'data-bookmaker',
        'attr_market'      => 'data-marketname',
        'attr_event'       => 'data-eventname',
        'attr_participant' => 'data-participantname',
        'attr_decimal'     => 'data-decimal',
    ];

    /**
     * Diagnostics from the last parse call.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Matches listed on a competition page (main list only, widget excluded).
     *
     * @param string $html Competition listing page.
     * @return list<RawEvent>
     */
    public function parseEvents(string $html): array
    {
        $this->warnings = [];
        $xpath          = $this->load($html);
        $events         = [];

        foreach ($this->query($xpath, self::SELECTORS['day']) as $day) {
            $dayTitle = $this->text($this->first($xpath, self::SELECTORS['day_title'], $day));

            foreach ($this->query($xpath, self::SELECTORS['match_item'], $day) as $item) {
                $event = $this->eventFromItem($xpath, $item, $dayTitle);

                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        if ($events === []) {
            $this->warnings[] = 'No matches found in the listing; the page has none or its markup changed.';
        }

        return $events;
    }

    /**
     * Prices of one market on a page, optionally limited to one event.
     *
     * Duplicate (bookmaker, participant) pairs keep the first occurrence.
     *
     * @param string      $html       Listing, match or outright page.
     * @param string      $marketName Market name on the source (e.g. "Match Result", "Outright Winner").
     * @param string|null $eventName  Source event name to keep (matches), or null for any (outrights).
     * @return list<RawPrice>
     */
    public function parsePrices(string $html, string $marketName, ?string $eventName = null): array
    {
        $this->warnings = [];
        $xpath          = $this->load($html);
        $prices         = [];

        foreach ($this->query($xpath, self::SELECTORS['price']) as $link) {
            if (
                $this->query($xpath, self::SELECTORS['widget'], $link)->length > 0
                || $this->attr($link, 'attr_market') !== $marketName
                || ($eventName !== null && $this->attr($link, 'attr_event') !== $eventName)
            ) {
                continue;
            }

            $price = $this->priceFromLink($link);
            $key   = $price['bookmaker'] . "\0" . $price['participant'];

            if ($price['bookmaker'] !== '' && $price['participant'] !== '' && !isset($prices[$key])) {
                $prices[$key] = $price;
            }
        }

        if ($prices === []) {
            $this->warnings[] = sprintf(
                'No "%s" prices found%s; the market is not listed or the markup changed.',
                $marketName,
                $eventName === null ? '' : ' for "' . $eventName . '"'
            );
        }

        return array_values($prices);
    }

    /**
     * Diagnostics from the last parse call (empty when it found data).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Build one raw event from a listing item.
     *
     * `id` is the source's stable match id (`data-id`). `path` is the match's own page, or "" for
     * fixtures listed before the source publishes a page; their prices are on the listing anyway.
     * Names come from the price links (full names, e.g. "Manchester United"), not the shortened
     * labels shown next to the logos.
     *
     * @param DOMXPath $xpath    XPath over the page.
     * @param DOMNode  $item     Match item.
     * @param string   $dayTitle Day heading, e.g. "Saturday 31 October".
     * @return RawEvent|null Null when the item lacks an id, time or event name.
     */
    private function eventFromItem(DOMXPath $xpath, DOMNode $item, string $dayTitle): ?array
    {
        $id    = $this->attr($item, 'attr_match_id');
        $link  = $this->first($xpath, self::SELECTORS['match_link'], $item);
        $path  = $link instanceof DOMElement ? trim($link->getAttribute('href')) : '';
        $time  = $this->text($this->first($xpath, self::SELECTORS['match_time'], $item));
        $name  = '';

        foreach ($this->query($xpath, self::SELECTORS['price_in_item'], $item) as $priceLink) {
            if ($this->attr($priceLink, 'attr_market') === self::MATCH_RESULT) {
                $name = $this->attr($priceLink, 'attr_event');
                break;
            }
        }

        $teams = explode(self::EVENT_SEPARATOR, $name);

        if ($id === '' || $time === '' || $dayTitle === '' || count($teams) !== 2) {
            $this->warnings[] = sprintf('Skipped an incomplete match item (%s).', $id !== '' ? $id : 'no id');

            return null;
        }

        return [
            'id'   => $id,
            'path' => $path,
            'name' => $name,
            'home' => trim($teams[0]),
            'away' => trim($teams[1]),
            'day'  => $dayTitle,
            'time' => $time,
        ];
    }

    /**
     * Raw price from a price link.
     *
     * @param DOMNode $link Price link.
     * @return RawPrice
     */
    private function priceFromLink(DOMNode $link): array
    {
        return [
            'bookmaker'   => $this->attr($link, 'attr_bookmaker'),
            'participant' => $this->attr($link, 'attr_participant'),
            'decimal'     => $this->attr($link, 'attr_decimal'),
        ];
    }

    /**
     * Parse untrusted HTML safely (no network, errors suppressed, UTF-8).
     *
     * @param string $html Page.
     * @return DOMXPath
     */
    private function load(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $dom      = new DOMDocument();

        if ($html !== '') {
            // The XML declaration makes libxml read the page as UTF-8 (team/player names).
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    /**
     * Run a query, returning an empty list on invalid expressions.
     *
     * @param DOMXPath     $xpath   XPath.
     * @param string       $query   Expression.
     * @param DOMNode|null $context Context node.
     * @return \DOMNodeList<DOMNode>
     */
    private function query(DOMXPath $xpath, string $query, ?DOMNode $context = null): \DOMNodeList
    {
        $result = $xpath->query($query, $context);

        return $result instanceof \DOMNodeList ? $result : (new DOMXPath(new DOMDocument()))->query('/none');
    }

    /**
     * First node of a query, or null.
     *
     * @param DOMXPath $xpath   XPath.
     * @param string   $query   Expression.
     * @param DOMNode  $context Context node.
     * @return DOMNode|null
     */
    private function first(DOMXPath $xpath, string $query, DOMNode $context): ?DOMNode
    {
        return $this->query($xpath, $query, $context)->item(0);
    }

    /**
     * Normalised text content (whitespace collapsed); "" for null.
     *
     * @param DOMNode|null $node Node.
     * @return string
     */
    private function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /**
     * Trimmed attribute value by SELECTORS key; "" when absent.
     *
     * @param DOMNode $node Element.
     * @param string  $key  SELECTORS key of the attribute name.
     * @return string
     */
    private function attr(DOMNode $node, string $key): string
    {
        return $node instanceof DOMElement ? trim($node->getAttribute(self::SELECTORS[$key])) : '';
    }
}
