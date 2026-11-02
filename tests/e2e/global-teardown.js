/**
 * Restores everything global-setup.js changed: settings and plugin state options, the theme,
 * temporary posts (also those the tests created, titled "OC e2e …") and the temporary user.
 */
const fs = require( 'node:fs' );
const { wp, wpEval } = require( './wp-cli' );
const { STATE_FILE } = require( './config' );

module.exports = async function globalTeardown() {
	if ( ! fs.existsSync( STATE_FILE ) ) {
		return;
	}

	const state = JSON.parse( fs.readFileSync( STATE_FILE, 'utf8' ) );
	const attempt = ( fn ) => {
		try {
			fn();
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.warn( `Teardown step failed: ${ error.message }` );
		}
	};

	const posts = JSON.parse(
		wp( [
			'post',
			'list',
			'--post_type=post,page',
			'--post_status=any',
			'--fields=ID,post_title',
			'--format=json',
		] )
	);

	for ( const post of posts.filter( ( p ) =>
		p.post_title.startsWith( 'OC e2e' )
	) ) {
		attempt( () =>
			wp( [ 'post', 'delete', String( post.ID ), '--force' ] )
		);
	}

	attempt( () => wp( [ 'user', 'delete', state.username, '--yes' ] ) );

	for ( const [ option, value ] of Object.entries( state.options ) ) {
		const autoload = option === 'odds_comparison_settings' ? 'yes' : 'no';
		attempt( () =>
			value === null
				? wp( [ 'option', 'delete', option ] )
				: wp(
						[
							'option',
							'update',
							option,
							'--format=json',
							`--autoload=${ autoload }`,
						],
						value
				  )
		);
	}

	attempt( () => wp( [ 'theme', 'activate', state.stylesheet ] ) );
	attempt( () =>
		wpEval( 'OddsComparison\\Plugin::instance()->repository()->flush();' )
	);
	fs.rmSync( STATE_FILE );
};
