<?php

/**
 * Default competition registry (top 5 European leagues, Championship, Champions League).
 *
 * Extend or modify with the `odds_comparison/competitions` filter. `sourcePaths.bettingodds` is
 * the competition listing on BettingOdds.com; `outrightPaths.bettingodds` lists the outright
 * market pages the source offers for that competition (paths observed on 2026-10-30).
 * `sourcePaths.theoddsapi` is the API's odds endpoint path for the competition's sport key.
 * The Odds API has no football outright markets (verified 2026-10-30).
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;

return [
    [
        'slug'          => 'premier-league',
        'name'          => 'Premier League',
        'sport'         => 'football',
        'sourcePaths'   => [
            'bettingodds' => '/football/premier-league',
            'theoddsapi'  => '/v4/sports/soccer_epl/odds',
        ],
        'outrightPaths' => [
            'bettingodds' => [
                'outright-winner' => '/football/premier-league/winner',
                'top-goalscorer'  => '/football/premier-league/top-scorer',
                'relegation'      => '/football/premier-league/relegation',
                'top-four-finish' => '/football/premier-league/top-four-finish',
            ],
        ],
    ],
    [
        'slug'          => 'championship',
        'name'          => 'Championship',
        'sport'         => 'football',
        'sourcePaths'   => [
            'bettingodds' => '/football/championship',
            'theoddsapi'  => '/v4/sports/soccer_efl_champ/odds',
        ],
        'outrightPaths' => [
            'bettingodds' => [
                'outright-winner' => '/football/championship/winner',
                'top-goalscorer'  => '/football/championship/top-scorer',
                'relegation'      => '/football/championship/relegation',
            ],
        ],
    ],
    [
        'slug'          => 'champions-league',
        'name'          => 'Champions League',
        'sport'         => 'football',
        'sourcePaths'   => [
            'bettingodds' => '/football/champions-league',
            'theoddsapi'  => '/v4/sports/soccer_uefa_champs_league/odds',
        ],
        'outrightPaths' => [
            'bettingodds' => [
                'outright-winner' => '/football/champions-league/winner',
                'top-goalscorer'  => '/football/champions-league/top-scorer',
            ],
        ],
    ],
    [
        'slug'        => 'la-liga',
        'name'        => 'La Liga',
        'sport'       => 'football',
        'sourcePaths' => [
            'bettingodds' => '/football/la-liga',
            'theoddsapi'  => '/v4/sports/soccer_spain_la_liga/odds',
        ],
    ],
    [
        'slug'        => 'serie-a',
        'name'        => 'Serie A',
        'sport'       => 'football',
        'sourcePaths' => [
            'bettingodds' => '/football/serie-a',
            'theoddsapi'  => '/v4/sports/soccer_italy_serie_a/odds',
        ],
    ],
    [
        'slug'        => 'bundesliga',
        'name'        => 'Bundesliga',
        'sport'       => 'football',
        'sourcePaths' => [
            'bettingodds' => '/football/bundesliga',
            'theoddsapi'  => '/v4/sports/soccer_germany_bundesliga/odds',
        ],
    ],
    [
        'slug'        => 'ligue-1',
        'name'        => 'Ligue 1',
        'sport'       => 'football',
        'sourcePaths' => [
            'bettingodds' => '/football/ligue-1',
            'theoddsapi'  => '/v4/sports/soccer_france_ligue_one/odds',
        ],
    ],
];
