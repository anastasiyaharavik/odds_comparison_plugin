/**
 * Runs WP-CLI against the local WordPress used by the Playwright suite.
 *
 * WP_CLI defaults to the project's Docker stack; override it for other setups,
 * e.g. WP_CLI="wp --path=/var/www/html".
 */
const { execFileSync } = require( 'node:child_process' );

const [ command, ...prefix ] = (
	process.env.WP_CLI || 'docker exec -i wordpress_app wp --allow-root'
).split( ' ' );

/**
 * Run a WP-CLI command and return its trimmed output.
 *
 * @param {string[]} args  WP-CLI arguments.
 * @param {string}   input Optional stdin.
 * @return {string} Output.
 */
function wp( args, input = '' ) {
	return execFileSync( command, [ ...prefix, ...args ], {
		input,
		encoding: 'utf8',
		stdio: [ 'pipe', 'pipe', 'pipe' ],
	} ).trim();
}

/**
 * Run PHP inside WordPress (values must be inlined: `wp eval` takes no arguments).
 *
 * @param {string} php PHP code.
 * @return {string} Output.
 */
function wpEval( php ) {
	return wp( [ 'eval', php ] );
}

module.exports = { wp, wpEval };
