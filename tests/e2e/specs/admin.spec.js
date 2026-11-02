/**
 * Admin screen JavaScript: live Bookmakers & markets cards and asynchronous "Refresh now".
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wp, wpEval } = require( '../wp-cli' );

test.describe( 'Odds Comparison admin screen', () => {
	// The bookmaker test saves settings; reset them so other specs see every bookmaker.
	test.afterAll( () =>
		wpEval(
			"$s = (array) get_option('odds_comparison_settings'); $s['bookmakers'] = []; $s['enabled_markets'] = ['match-result', 'outright-winner']; update_option('odds_comparison_settings', $s);"
		)
	);

	test( 'bookmaker tiles and market cards follow the fields and save together', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=odds-comparison&tab=bookmakers'
		);

		const bookmakers = page
			.locator( '.oc-card' )
			.filter( { hasText: 'Bookmakers and links' } );
		const markets = page
			.locator( '.oc-card' )
			.filter( { hasText: 'Markets' } )
			.last();
		await expect( bookmakers.locator( '[data-oc-count]' ) ).toHaveText(
			'10 of 10 shown'
		);

		// Hiding a bookmaker dims its tile and updates the count.
		const betfred = page.getByRole( 'checkbox', { name: 'Betfred' } );
		await betfred.uncheck();
		await expect( bookmakers.locator( '[data-oc-count]' ) ).toHaveText(
			'9 of 10 shown'
		);
		await expect(
			page.locator( '.oc-tile' ).filter( { has: betfred } )
		).toHaveClass( /is-off/ );

		// The link line says where prices go, before and after typing a link.
		const link = page.getByLabel( 'Affiliate link for William Hill' );
		const target = page.locator( '#oc-bm-william-hill-target' );
		await expect( target ).toHaveText(
			'The name in odds tables links to sports.williamhill.com'
		);
		await link.fill( 'https://aff.example/wh?ref=pw' );
		await expect( target ).toHaveText(
			'The name in odds tables links to your link'
		);
		await expect( link ).toHaveAttribute(
			'aria-describedby',
			'oc-bm-william-hill-target'
		);

		const box = await link.boundingBox();
		const tile = await page
			.locator( '.oc-tile' )
			.filter( { has: link } )
			.boundingBox();
		expect( box.x + box.width ).toBeLessThanOrEqual( tile.x + tile.width );

		await page.getByRole( 'checkbox', { name: /Top Goalscorer/ } ).check();
		await expect( markets.locator( '[data-oc-count]' ) ).toHaveText(
			'3 of 5 on'
		);

		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( betfred ).not.toBeChecked();
		await expect( link ).toHaveValue( 'https://aff.example/wh?ref=pw' );
		await expect(
			page.getByRole( 'checkbox', { name: /Top Goalscorer/ } )
		).toBeChecked();
	} );

	test( 'Refresh now queues a background run and reports its result', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=odds-comparison&tab=status'
		);

		await page.getByRole( 'button', { name: 'Refresh now' } ).click();
		await expect( page.locator( '.oc-refresh-spinner' ) ).toHaveClass(
			/is-active/
		);
		await expect( page.getByRole( 'status' ) ).toContainText(
			'Refreshing'
		);

		// The run itself is executed by cron; run due events like a system cron would.
		wp( [ 'cron', 'event', 'run', '--due-now' ] );

		await expect( page.getByRole( 'status' ) ).toContainText(
			/Fetched \d+ markets, 0 failed/,
			{
				timeout: 60_000,
			}
		);
		await expect( page.locator( '.oc-refresh-spinner' ) ).not.toHaveClass(
			/is-active/
		);
		expect( page.url() ).toContain( 'tab=status' );
	} );
} );
