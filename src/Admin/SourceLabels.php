<?php

/**
 * Display names of the odds sources.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

defined('ABSPATH') || exit;

/**
 * Human names of source ids for the settings and Status tabs. Unknown ids (a source added
 * through `odds_comparison/sources`) are shown as their id.
 */
final class SourceLabels
{
    /**
     * Name with its kind, for choosing a source ("BettingOdds.com (scraper)").
     *
     * @param string $sourceId Source id.
     * @return string
     */
    public static function long(string $sourceId): string
    {
        return match ($sourceId) {
            'bettingodds' => __('BettingOdds.com (scraper)', 'odds-comparison'),
            'theoddsapi'  => __('The Odds API (licensed feed)', 'odds-comparison'),
            default       => $sourceId,
        };
    }

    /**
     * Short name ("BettingOdds.com").
     *
     * @param string $sourceId Source id.
     * @return string
     */
    public static function short(string $sourceId): string
    {
        return match ($sourceId) {
            'bettingodds' => __('BettingOdds.com', 'odds-comparison'),
            'theoddsapi'  => __('The Odds API', 'odds-comparison'),
            default       => $sourceId,
        };
    }
}
