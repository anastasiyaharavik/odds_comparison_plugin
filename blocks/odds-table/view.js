/**
 * Visitor odds-format switcher. Dependency-free; loaded only where the block is.
 *
 * Every price carries its three formats in data-dec / data-frac / data-us, all computed by the
 * PHP OddsConverter, so this script only swaps text. Without JS the switcher stays hidden and
 * the table shows the server-rendered format. The visitor's choice is kept in localStorage.
 *
 * On narrow blocks the stylesheet shows the best price per selection first; this script collapses
 * the full table behind a "Compare all" button. Without JS the full table stays visible.
 */
const STORAGE_KEY = 'oddsComparisonFormat';
const ATTRIBUTE = { fractional: 'frac', decimal: 'dec', american: 'us' };

const switchers = () =>
	document.querySelectorAll( '[data-oc-table] [data-oc-format-switcher]' );

/**
 * Show one table's prices in a format.
 *
 * @param {HTMLSelectElement} switcher Table's format select.
 * @param {string}            format   fractional|decimal|american.
 */
function apply( switcher, format ) {
	const table = switcher.closest( '[data-oc-table]' );

	table.querySelectorAll( '.oc-odds__value' ).forEach( ( value ) => {
		const text = value.dataset[ ATTRIBUTE[ format ] ];

		if ( text ) {
			value.textContent = text;
		}
	} );

	table.dataset.format = format;
	switcher.value = format;
}

/**
 * Stored preference, if any (storage can be blocked).
 *
 * @return {string|null} Format.
 */
function stored() {
	try {
		return window.localStorage.getItem( STORAGE_KEY );
	} catch ( error ) {
		return null;
	}
}

switchers().forEach( ( switcher ) => {
	switcher.closest( '.oc-odds__switcher' ).hidden = false;

	const saved = stored();

	if ( ATTRIBUTE[ saved ] ) {
		apply( switcher, saved );
	}

	switcher.addEventListener( 'change', () => {
		const format = switcher.value;

		if ( ! ATTRIBUTE[ format ] ) {
			return;
		}

		switchers().forEach( ( other ) => apply( other, format ) );

		try {
			window.localStorage.setItem( STORAGE_KEY, format );
		} catch ( error ) {
			// Preference is not kept; switching still works on this page.
		}
	} );
} );

document
	.querySelectorAll( '[data-oc-table] .oc-odds__more' )
	.forEach( ( button ) => {
		const table = button.closest( '[data-oc-table]' );

		/**
		 * Show or hide the full table.
		 *
		 * @param {boolean} expanded Whether the table is shown.
		 */
		const toggle = ( expanded ) => {
			table.classList.toggle( 'is-collapsed', ! expanded );
			button.setAttribute( 'aria-expanded', String( expanded ) );
			button.textContent =
				button.dataset[ expanded ? 'labelHide' : 'labelShow' ];
		};

		toggle( false );
		button.hidden = false;
		button.addEventListener( 'click', () =>
			toggle( button.getAttribute( 'aria-expanded' ) !== 'true' )
		);
	} );
