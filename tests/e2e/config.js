/**
 * Shared constants of the browser test suite.
 */
const path = require( 'node:path' );

module.exports = {
	STATE_FILE: path.join( __dirname, '.state', 'run.json' ),
	STORAGE_STATE: path.join( __dirname, '.state', 'storage.json' ),
	OPTIONS: [
		'odds_comparison_settings',
		'odds_comparison_tracked',
		'odds_comparison_events',
		'odds_comparison_status',
	],
	// Chelsea v Manchester United in the recorded BettingOdds.com pages (fixtures/bettingodds).
	CHELSEA: 'bettingodds:premier-league/4othg6zhiyox0rivijbld3swk',
	BOOKMAKERS: [
		'william-hill',
		'paddy-power',
		'betfair-sportsbook',
		'sky-bet',
		'betfred',
		'unibet',
		'betway',
		'boylesports',
		'matchbook',
		'betano',
	],
};
