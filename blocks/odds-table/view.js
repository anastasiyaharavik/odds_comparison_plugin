/**
 * Visitor odds-format switcher. Dependency-free; loaded only where the block is.
 *
 * Every price carries its three formats in data-dec / data-frac / data-us, all computed by the
 * PHP OddsConverter, so this script only swaps text. Without JS the switcher stays hidden and
 * the table shows the server-rendered format. The visitor's choice is kept in localStorage.
 *
 * On narrow blocks the stylesheet shows the best price per selection first; this script collapses
 * the full table behind a "Compare all" button. Without JS the full table stays visible.
 *
 * Tables added to the page later (AJAX navigation, infinite scroll, "load more") are set up as
 * they appear, through a MutationObserver. Each table is set up once (data-oc-ready). Themes that
 * insert tables in other ways can call window.oddsComparison.init( element ).
 */
const STORAGE_KEY = 'oddsComparisonFormat';
const ATTRIBUTE = { fractional: 'frac', decimal: 'dec', american: 'us' };
const TABLE = '[data-oc-table]';

/**
 * Show one table's prices in a format.
 *
 * @param {HTMLElement} table  Table root ([data-oc-table]).
 * @param {string}      format fractional|decimal|american.
 */
function apply( table, format ) {
	table.querySelectorAll( '.oc-odds__value' ).forEach( ( value ) => {
		const text = value.dataset[ ATTRIBUTE[ format ] ];

		if ( text ) {
			value.textContent = text;
		}
	} );

	table.dataset.format = format;

	const switcher = table.querySelector( '[data-oc-format-switcher]' );

	if ( switcher ) {
		switcher.value = format;
	}
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

/**
 * Set up one table: format switcher and the "Compare all" toggle. Runs once per table.
 *
 * @param {HTMLElement} table Table root ([data-oc-table]).
 */
function setUp( table ) {
	if ( table.dataset.ocReady ) {
		return;
	}

	table.dataset.ocReady = '1';

	const switcher = table.querySelector( '[data-oc-format-switcher]' );

	if ( switcher ) {
		switcher.closest( '.oc-odds__switcher' ).hidden = false;

		const saved = stored();

		if ( ATTRIBUTE[ saved ] ) {
			apply( table, saved );
		}

		switcher.addEventListener( 'change', () => {
			const format = switcher.value;

			if ( ! ATTRIBUTE[ format ] ) {
				return;
			}

			document
				.querySelectorAll( TABLE )
				.forEach( ( other ) => apply( other, format ) );

			try {
				window.localStorage.setItem( STORAGE_KEY, format );
			} catch ( error ) {
				// Preference is not kept; switching still works on this page.
			}
		} );
	}

	const button = table.querySelector( '.oc-odds__more' );

	if ( button ) {
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
	}
}

/**
 * Set up every table in (or being) an element.
 *
 * @param {Element|Document} root Element or document.
 */
function init( root = document ) {
	if ( root instanceof window.Element && root.matches( TABLE ) ) {
		setUp( root );
	}

	root.querySelectorAll( TABLE ).forEach( setUp );
}

init();

window.oddsComparison = { init };

if ( 'MutationObserver' in window && document.body ) {
	new window.MutationObserver( ( mutations ) => {
		mutations.forEach( ( mutation ) =>
			mutation.addedNodes.forEach( ( node ) => {
				if ( node instanceof window.Element ) {
					init( node );
				}
			} )
		);
	} ).observe( document.body, { childList: true, subtree: true } );
}
