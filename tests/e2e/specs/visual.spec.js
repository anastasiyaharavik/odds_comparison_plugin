/**
 * Visual check: screenshots of the block in Twenty Twenty-Five and Twenty
 * Twenty-Four at desktop and phone width, attached to the HTML report and saved under
 * test-results/screenshots for review. Layout assertions guard against overflow.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wp } = require( '../wp-cli' );

const THEMES = [ 'twentytwentyfive', 'twentytwentyfour' ];
const WIDTHS = [ 1280, 375 ];

test.use( { storageState: { cookies: [], origins: [] } } );

for ( const theme of THEMES ) {
	test.describe( `in ${ theme }`, () => {
		test.beforeAll( () => wp( [ 'theme', 'activate', theme ] ) );

		for ( const width of WIDTHS ) {
			test( `renders at ${ width }px without overflow`, async ( {
				page,
			}, testInfo ) => {
				await page.setViewportSize( { width, height: 900 } );
				await page.goto( `/?p=${ process.env.OC_POST_ID }` );

				const block = page.locator( '[data-oc-table]' );
				await expect( block ).toBeVisible();
				await expect(
					block.locator( '.oc-odds__switcher' )
				).toBeVisible();

				const overflow = await page.evaluate(
					() =>
						document.documentElement.scrollWidth - window.innerWidth
				);
				expect( overflow ).toBeLessThanOrEqual( 0 );

				const file = testInfo.outputPath( `${ theme }-${ width }.png` );
				await block.screenshot( { path: file } );
				await block.screenshot( {
					path: `test-results/screenshots/${ theme }-${ width }.png`,
				} );
				await testInfo.attach( `${ theme } ${ width }px`, {
					path: file,
					contentType: 'image/png',
				} );
			} );
		}
	} );
}
