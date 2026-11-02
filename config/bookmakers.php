<?php

/**
 * Default bookmaker registry: the 10 UK bookmakers that both sources quote.
 *
 * Extend or modify with the `odds_comparison/bookmakers` filter; no class is needed.
 * - `sourceCodes.bettingodds`: the `data-bookmaker` value on BettingOdds.com (observed 2026-10-30).
 * - `sourceCodes.theoddsapi`: the bookmaker key in The Odds API's `uk` region.
 * Bet365 / BetMGM are on BettingOdds only and can be added with a `bettingodds` code alone.
 * A wrong code shows up on the Status tab as "seen in 0/N markets", and the codes the source lists
 * but no bookmaker here uses are named under Status → Recent errors.
 *
 * Logos are not bundled yet: an empty `logoUrl` renders the bookmaker name only.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

return [
    [
        'slug'        => 'william-hill',
        'name'        => 'William Hill',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'williamhill',
            'theoddsapi'  => 'williamhill',
        ],
        'defaultUrl'  => 'https://sports.williamhill.com/',
    ],
    [
        'slug'        => 'paddy-power',
        'name'        => 'Paddy Power',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'paddypower',
            'theoddsapi'  => 'paddypower',
        ],
        'defaultUrl'  => 'https://www.paddypower.com/',
    ],
    [
        'slug'        => 'betfair-sportsbook',
        'name'        => 'Betfair Sportsbook',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'betfair',
            'theoddsapi'  => 'betfair_sb_uk',
        ],
        'defaultUrl'  => 'https://www.betfair.com/sport/',
    ],
    [
        'slug'        => 'sky-bet',
        'name'        => 'Sky Bet',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'skybet',
            'theoddsapi'  => 'skybet',
        ],
        'defaultUrl'  => 'https://www.skybet.com/',
    ],
    [
        'slug'        => 'betfred',
        'name'        => 'Betfred',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'betfred',
            'theoddsapi'  => 'betfred_uk',
        ],
        'defaultUrl'  => 'https://www.betfred.com/',
    ],
    [
        'slug'        => 'unibet',
        'name'        => 'Unibet',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'unibet',
            'theoddsapi'  => 'unibet_uk',
        ],
        'defaultUrl'  => 'https://www.unibet.co.uk/',
    ],
    [
        'slug'        => 'betway',
        'name'        => 'Betway',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'betway',
            'theoddsapi'  => 'betway',
        ],
        'defaultUrl'  => 'https://betway.com/en/sports',
    ],
    [
        'slug'        => 'boylesports',
        'name'        => 'BoyleSports',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'boylesports',
            'theoddsapi'  => 'boylesports',
        ],
        'defaultUrl'  => 'https://www.boylesports.com/',
    ],
    [
        'slug'        => 'matchbook',
        'name'        => 'Matchbook',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'matchbook',
            'theoddsapi'  => 'matchbook',
        ],
        'defaultUrl'  => 'https://www.matchbook.com/',
    ],
    [
        'slug'        => 'betano',
        'name'        => 'Betano',
        'logoUrl'     => '',
        'sourceCodes' => [
            'bettingodds' => 'betano',
            'theoddsapi'  => 'betano_uk',
        ],
        'defaultUrl'  => 'https://www.betano.co.uk/',
    ],
];
