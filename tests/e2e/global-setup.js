/**
 * Prepares the local WordPress for the browser tests:
 * Recorded mode (real captured BettingOdds.com pages, no network, no API credits), a temporary
 * administrator, a published post with the block, and one real refresh run. Everything changed
 * here is backed up and restored by global-teardown.js.
 */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const crypto = require( 'node:crypto' );
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { wp, wpEval } = require( './wp-cli' );
const {
	STATE_FILE,
	STORAGE_STATE,
	OPTIONS,
	CHELSEA,
	BOOKMAKERS,
} = require( './config' );

module.exports = async function globalSetup( config ) {
	const baseURL = config.projects[ 0 ].use.baseURL;
	const state = { options: {}, posts: [] };

	fs.mkdirSync( path.dirname( STATE_FILE ), { recursive: true } );

	for ( const option of OPTIONS ) {
		try {
			state.options[ option ] = wp( [
				'option',
				'get',
				option,
				'--format=json',
			] );
		} catch {
			state.options[ option ] = null;
		}
	}

	state.stylesheet = wp( [ 'option', 'get', 'stylesheet' ] );
	state.username = `oc_e2e_pw_${ crypto.randomBytes( 4 ).toString( 'hex' ) }`;
	state.password = crypto.randomBytes( 16 ).toString( 'hex' );
	fs.writeFileSync( STATE_FILE, JSON.stringify( state ) );

	wp( [
		'user',
		'create',
		state.username,
		`${ state.username }@example.test`,
		'--role=administrator',
		`--user_pass=${ state.password }`,
	] );

	wpEval( `
		$s = array_replace((array) get_option('odds_comparison_settings', []), [
			'data_mode' => 'recorded', 'active_source' => 'bettingodds',
			'enabled_competitions' => ['premier-league'], 'enabled_markets' => ['match-result', 'outright-winner'],
			'bookmakers' => [], 'bookmaker_order' => [], 'default_odds_format' => 'fractional',
		]);
		update_option('odds_comparison_settings', $s);
		delete_option('odds_comparison_tracked');
		OddsComparison\\Plugin::instance()->circuitBreaker()->reset('www.bettingodds.com');
	` );

	const block = `<!-- wp:odds-comparison/odds-table {"eventId":"${ CHELSEA }","marketKey":"match-result","bookmakers":${ JSON.stringify(
		BOOKMAKERS
	) }} /-->`;
	state.postId = wp( [
		'post',
		'create',
		'--post_status=publish',
		'--post_title=OC e2e playwright',
		`--post_content=${ block }`,
		'--porcelain',
	] );
	state.posts.push( state.postId );
	fs.writeFileSync( STATE_FILE, JSON.stringify( state ) );

	const run = JSON.parse(
		wpEval(
			'echo wp_json_encode(OddsComparison\\Plugin::instance()->scheduler()->run("manual"));'
		)
	);

	if ( run.fetched < 1 ) {
		throw new Error(
			`Refresh fetched nothing: ${ JSON.stringify( run ) }`
		);
	}

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		user: { username: state.username, password: state.password },
		storageStatePath: STORAGE_STATE,
	} );
	await requestUtils.setupRest();
	await requestContext.dispose();

	process.env.WP_USERNAME = state.username;
	process.env.WP_PASSWORD = state.password;
	process.env.OC_POST_ID = state.postId;
};
