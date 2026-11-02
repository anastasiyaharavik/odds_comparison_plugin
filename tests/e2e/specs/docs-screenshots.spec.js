/**
 * README screenshots. Skipped in normal runs; `npm run docs:screenshots` saves
 * them to docs/screenshots/. Global setup puts the site in Recorded mode, so every price is
 * from real captured pages.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const OUT = 'docs/screenshots';
const TABS = [ 'general', 'bookmakers', 'cache', 'status' ];

test.skip(
	! process.env.OC_DOCS_SCREENSHOTS,
	'Set OC_DOCS_SCREENSHOTS=1 (npm run docs:screenshots).'
);

test.describe( 'README screenshots', () => {
	for ( const tab of TABS ) {
		test( `admin tab ${ tab }`, async ( { admin, page } ) => {
			await page.setViewportSize( { width: 1280, height: 900 } );
			await admin.visitAdminPage(
				'admin.php',
				`page=odds-comparison&tab=${ tab }`
			);
			await expect( page.locator( '.oc-admin' ) ).toBeVisible();
			await page
				.locator( '.oc-admin' )
				.screenshot( { path: `${ OUT }/admin-${ tab }.png` } );
		} );
	}

	test( 'block in the editor', async ( { admin, editor, page } ) => {
		await page.setViewportSize( { width: 1440, height: 1000 } );
		await admin.createNewPost( {
			title: 'Chelsea v Manchester United preview',
		} );
		await editor.insertBlock( { name: 'odds-comparison/odds-table' } );
		await editor.openDocumentSettingsSidebar();
		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		await sidebar
			.getByRole( 'combobox', { name: 'Event' } )
			.fill( 'Chelsea' );
		await page
			.getByRole( 'option', { name: /Chelsea vs Manchester United/ } )
			.click();
		await expect(
			editor.canvas.locator( '.oc-odds__table tbody tr' )
		).toHaveCount( 10 );
		await page.screenshot( { path: `${ OUT }/editor.png` } );
	} );

	for ( const width of [ 1280, 375 ] ) {
		test( `block on the site at ${ width }px`, async ( { page } ) => {
			await page.context().clearCookies();
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( `/?p=${ process.env.OC_POST_ID }` );
			const block = page.locator( '[data-oc-table]' );
			await expect( block ).toBeVisible();
			await block.screenshot( {
				path: `${ OUT }/block-${
					width < 600 ? 'phone' : 'desktop'
				}.png`,
			} );
		} );
	}
} );
