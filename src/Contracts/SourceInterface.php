<?php

/**
 * Contract for odds source adapters.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Domain\Competition;
use OddsComparison\Domain\Event;
use OddsComparison\Domain\Market;
use OddsComparison\Domain\MarketOdds;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * An odds comparison site the plugin can read (e.g. Oddschecker).
 *
 * One implementation per source site; bookmakers are registry data, not sources. Implementations
 * build URLs only from a hardcoded base URL plus registry/validated relative paths (SSRF guard).
 * Register new sources through the `odds_comparison/sources` filter.
 */
interface SourceInterface
{
    /**
     * Stable kebab-case source id; prefixes every event id from this source.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Hardcoded base URL (scheme + host) of the source; used for robots.txt and diagnostics.
     *
     * @return string
     */
    public function baseUrl(): string;

    /**
     * Discover upcoming events of a competition.
     *
     * @param Competition $competition Competition to list.
     * @return list<Event> Events found (may be empty when the competition has none or the markup changed).
     * @throws RuntimeException When the listing cannot be fetched.
     */
    public function discoverEvents(Competition $competition): array;

    /**
     * Fetch the current odds of one market of one event.
     *
     * @param Event  $event  Event (must belong to this source).
     * @param Market $market Market to fetch.
     * @return MarketOdds
     * @throws RuntimeException When the page cannot be fetched or parsed into usable odds.
     */
    public function fetchMarket(Event $event, Market $market): MarketOdds;
}
