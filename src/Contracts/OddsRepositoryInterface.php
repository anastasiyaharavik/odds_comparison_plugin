<?php

/**
 * Contract for odds storage.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Domain\MarketOdds;

defined('ABSPATH') || exit;

/**
 * Stores and reads the latest good odds per (event, market) pair (Repository pattern).
 *
 * Freshness is decided from MarketOdds::$fetchedAt, so stale data stays readable and can be
 * shown with a "may be out of date" marker.
 */
interface OddsRepositoryInterface
{
    /**
     * Latest stored odds, or null when none are cached.
     *
     * @param string $eventId   Source-scoped event id.
     * @param string $marketKey Market key.
     * @return MarketOdds|null
     */
    public function get(string $eventId, string $marketKey): ?MarketOdds;

    /**
     * Store odds, replacing the previous snapshot for the same pair.
     *
     * Callers must only pass validated odds, so failed fetches never overwrite good data.
     *
     * @param MarketOdds $odds Validated odds.
     * @return bool Whether the data was stored.
     */
    public function save(MarketOdds $odds): bool;

    /**
     * Remove the stored odds of one pair.
     *
     * @param string $eventId   Source-scoped event id.
     * @param string $marketKey Market key.
     * @return bool Whether something was deleted.
     */
    public function delete(string $eventId, string $marketKey): bool;

    /**
     * Whether the odds are older than the configured `stale_after` threshold.
     *
     * @param MarketOdds $odds Odds to check.
     * @return bool
     */
    public function isStale(MarketOdds $odds): bool;

    /**
     * Remove all stored odds.
     *
     * @return void
     */
    public function flush(): void;
}
