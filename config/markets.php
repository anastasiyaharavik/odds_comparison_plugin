<?php

/**
 * Default market registry: the markets the default source offers.
 *
 * Extend or modify with the `odds_comparison/markets` filter. `sourceNames.bettingodds` is the
 * market's `data-marketname` value on BettingOdds.com; `sourceNames.theoddsapi` is The Odds API
 * market key. A market without a name for the active source is hidden while that source is active.
 * Outright markets are only offered where a competition lists an outright path for them
 * (config/competitions.php).
 *
 * @package OddsComparison
 */

use OddsComparison\Domain\Market;

defined('ABSPATH') || exit;

return [
    [
        'key'           => 'match-result',
        'label'         => 'Match Result',
        'sourceNames'   => ['bettingodds' => 'Match Result', 'theoddsapi' => 'h2h'],
        'selectionType' => Market::SELECTION_THREE_WAY,
    ],
    [
        'key'           => 'outright-winner',
        'label'         => 'Outright Winner',
        'sourceNames'   => ['bettingodds' => 'Outright Winner'],
        'selectionType' => Market::SELECTION_OUTRIGHT,
    ],
    [
        'key'           => 'top-goalscorer',
        'label'         => 'Top Goalscorer',
        'sourceNames'   => ['bettingodds' => 'Top Goalscorer'],
        'selectionType' => Market::SELECTION_OUTRIGHT,
    ],
    [
        'key'           => 'relegation',
        'label'         => 'To Be Relegated',
        'sourceNames'   => ['bettingodds' => 'To be Relegated'],
        'selectionType' => Market::SELECTION_OUTRIGHT,
    ],
    [
        'key'           => 'top-four-finish',
        'label'         => 'Top 4 Finish',
        'sourceNames'   => ['bettingodds' => 'Top 4 Finish'],
        'selectionType' => Market::SELECTION_OUTRIGHT,
    ],
];
