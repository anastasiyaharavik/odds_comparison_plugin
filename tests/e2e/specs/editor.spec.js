/**
 * Block editor: insert, choose an event, add / remove / reorder bookmakers with the keyboard,
 * preview equals frontend.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Odds Comparison block in the editor', () => {
	test( 'editor builds the table and the site shows the same bookmakers in the same order', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'OC e2e editor' } );
		await editor.insertBlock( { name: 'odds-comparison/odds-table' } );
		await editor.openDocumentSettingsSidebar();

		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		// Per-row Remove buttons only (not "Remove all").
		const removeButtons = sidebar
			.locator( '.oc-odds-edit__bookmakers' )
			.getByRole( 'button', { name: /^Remove / } );

		// New blocks start with every enabled bookmaker.
		await expect( removeButtons ).toHaveCount( 10 );
		await expect(
			editor.canvas.getByText( 'Choose an event in the block settings' )
		).toBeVisible();

		const event = sidebar.getByRole( 'combobox', { name: 'Event' } );
		await event.fill( 'Chelsea' );
		await page
			.getByRole( 'option', { name: /Chelsea vs Manchester United/ } )
			.click();

		const previewRows = editor.canvas.locator( '.oc-odds__table tbody tr' );
		await expect( previewRows ).toHaveCount( 10 );

		// Remove Betfred with the keyboard: focus moves to the next bookmaker's Remove button.
		await sidebar.getByRole( 'button', { name: 'Remove Betfred' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			sidebar.getByRole( 'button', { name: 'Remove Unibet' } )
		).toBeFocused();
		await expect( removeButtons ).toHaveCount( 9 );
		await expect( previewRows ).toHaveCount( 9 );

		// Move Unibet up twice with the keyboard: focus stays on the same button.
		const up = sidebar.getByRole( 'button', { name: 'Move Unibet up' } );
		await up.focus();
		await page.keyboard.press( 'Enter' );
		await expect( up ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( up ).toBeFocused();

		const expectedOrder = [
			'William Hill',
			'Paddy Power',
			'Unibet',
			'Betfair Sportsbook',
			'Sky Bet',
			'Betway',
			'BoyleSports',
			'Matchbook',
			'Betano',
		];
		await expect( previewRows.locator( 'th' ) ).toHaveText( expectedOrder );

		// Add Betfred back: it goes to the end; "Add all" is then disabled.
		await sidebar
			.getByRole( 'combobox', { name: 'Add bookmaker' } )
			.selectOption( 'betfred' );
		await sidebar
			.getByRole( 'button', { name: 'Add', exact: true } )
			.click();
		await expect( removeButtons ).toHaveCount( 10 );
		await expect( previewRows.last().locator( 'th' ) ).toHaveText(
			'Betfred'
		);

		const postId = await editor.publishPost();
		await page.goto( `/?p=${ postId }` );

		const siteRows = page.locator( '[data-oc-table] tbody tr th' );
		await expect( siteRows ).toHaveText( [ ...expectedOrder, 'Betfred' ] );
	} );

	test( 'Remove all warns and Add all restores every enabled bookmaker', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'OC e2e remove all' } );
		await editor.insertBlock( { name: 'odds-comparison/odds-table' } );
		await editor.openDocumentSettingsSidebar();

		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		const rows = sidebar
			.locator( '.oc-odds-edit__bookmakers' )
			.getByRole( 'button', { name: /^Remove / } );
		await expect( rows ).toHaveCount( 10 );

		await sidebar.getByRole( 'button', { name: 'Remove all' } ).click();
		await expect( rows ).toHaveCount( 0 );
		await expect(
			sidebar.getByText(
				'No bookmakers selected: the block shows nothing on the site.'
			)
		).toBeVisible();
		await expect(
			sidebar.getByRole( 'combobox', { name: 'Add bookmaker' } )
		).toBeFocused();

		await sidebar.getByRole( 'button', { name: 'Add all' } ).click();
		await expect( rows ).toHaveCount( 10 );
		await expect(
			sidebar.getByRole( 'button', { name: 'Add all' } )
		).toBeDisabled();
	} );

	test( 'selections filter limits the columns in preview', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'OC e2e selections' } );
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
			editor.canvas.locator( '.oc-odds__table' )
		).toBeVisible();

		await sidebar.getByRole( 'button', { name: 'Selections' } ).click();
		await sidebar.getByRole( 'checkbox', { name: 'Chelsea' } ).check();

		const headers = editor.canvas.locator( '.oc-odds__table thead th' );
		await expect( headers ).toHaveText( [ 'Bookmaker', 'Chelsea' ] );
	} );
} );
