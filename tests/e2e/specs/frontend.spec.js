/**
 * Frontend: visitor format switcher and responsive layout.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const postUrl = () => `/?p=${ process.env.OC_POST_ID }`;

// Visitors are logged out.
test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'Odds table on the site', () => {
	test( 'format switcher shows the server-computed values and remembers the choice', async ( {
		page,
	} ) => {
		await page.goto( postUrl() );

		const table = page.locator( '[data-oc-table]' );
		const select = table.getByLabel( 'Odds format' );
		const values = table.locator( '.oc-odds__value' );

		await expect( select ).toBeVisible();
		await expect( select ).toHaveValue( 'fractional' );
		expect( await values.count() ).toBeGreaterThan( 20 );

		const shown = ( attribute ) =>
			values.evaluateAll(
				( elements, key ) =>
					elements.filter(
						( element ) =>
							element.textContent !== element.dataset[ key ]
					).length,
				attribute
			);

		expect( await shown( 'frac' ) ).toBe( 0 );

		await select.selectOption( 'decimal' );
		expect( await shown( 'dec' ) ).toBe( 0 );
		await expect( values.first() ).toHaveText( /^\d+\.\d{2}$/ );

		await select.selectOption( 'american' );
		expect( await shown( 'us' ) ).toBe( 0 );
		await expect( values.first() ).toHaveText( /^[+-]\d+$/ );

		await page.reload();
		await expect( table.getByLabel( 'Odds format' ) ).toHaveValue(
			'american'
		);
		expect( await shown( 'us' ) ).toBe( 0 );
	} );

	test( 'without JavaScript the table works in the server format and hides the switcher', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();

		await page.goto( `${ process.env.WP_BASE_URL }${ postUrl() }` );

		const table = page.locator( '[data-oc-table]' );
		await expect( table.locator( '.oc-odds__switcher' ) ).toBeHidden();
		await expect( table.locator( '.oc-odds__value' ).first() ).toHaveText(
			/^\d+\/\d+$/
		);
		await expect( table.locator( 'tbody tr' ) ).toHaveCount( 10 );

		await context.close();
	} );

	test( 'keyboard users can reach the scroll region and every bookmaker link', async ( {
		page,
	} ) => {
		await page.goto( postUrl() );

		const region = page.getByRole( 'region', {
			name: /Chelsea vs Manchester United/,
		} );
		await region.focus();
		await expect( region ).toBeFocused();

		await page.keyboard.press( 'Tab' );
		const focused = page.locator( ':focus' );
		await expect( focused ).toHaveAttribute(
			'rel',
			'nofollow sponsored noopener'
		);
		await expect( focused ).toHaveAttribute( 'target', '_blank' );
		await expect( focused ).toContainText( '(opens in a new tab)' );
	} );

	test( 'phone width leads with the best odds and folds the full table behind a button', async ( {
		page,
	}, testInfo ) => {
		await page.setViewportSize( { width: 375, height: 800 } );
		await page.goto( postUrl() );

		const table = page.locator( '[data-oc-table]' );
		const offers = table.getByRole( 'list', { name: 'Best odds' } );
		const more = table.getByRole( 'button', {
			name: 'Compare all 10 bookmakers',
		} );
		const rows = table.locator( 'tbody tr' );

		await expect( offers.getByRole( 'listitem' ) ).toHaveCount( 3 );
		await expect( offers.locator( '.oc-odds__price.is-best' ) ).toHaveCount(
			3
		);
		await expect( offers.getByRole( 'link' ).first() ).toContainText(
			/best odds, Chelsea, at \S/
		);
		await expect( more ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( rows.first() ).toBeHidden();
		await testInfo.attach( 'phone collapsed', {
			body: await table.screenshot(),
			contentType: 'image/png',
		} );

		await more.focus();
		await page.keyboard.press( 'Enter' );
		const less = table.getByRole( 'button', { name: 'Hide bookmakers' } );
		await expect( less ).toBeFocused();
		await expect( less ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( rows ).toHaveCount( 10 );
		await expect( rows.first() ).toBeVisible();

		// One line per bookmaker: name and three prices side by side.
		const row = await rows.first().boundingBox();
		expect( row.height ).toBeLessThan( 60 );

		const pageWidth = await page.evaluate(
			() => document.documentElement.scrollWidth
		);
		expect( pageWidth ).toBeLessThanOrEqual( 375 );
		await testInfo.attach( 'phone expanded', {
			body: await table.screenshot(),
			contentType: 'image/png',
		} );
		await table.screenshot( {
			path: 'test-results/screenshots/phone-expanded.png',
		} );
	} );

	test( 'desktop width shows the full table and no fold button', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( postUrl() );

		const table = page.locator( '[data-oc-table]' );
		await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();
		await expect( table.locator( '.oc-odds__more' ) ).toBeHidden();
		await expect(
			table.getByRole( 'list', { name: 'Best odds' } )
		).toBeHidden();
	} );
} );
