/**
 * Odds Comparison admin screen enhancements. Vanilla JS + wp.apiFetch.
 *
 * - Bookmakers & markets: the card counts ("9 of 10 shown"), the dimmed state of hidden
 *   bookmakers and the "The name in odds tables links to …" line follow the fields as they change. Without JS
 *   they are correct after saving.
 * - "Refresh now": queues a run via REST, polls the status and reports the result. Without JS
 *   the form posts to admin-post.php.
 */
( function () {
	const { __, sprintf } = window.wp.i18n;
	const apiFetch = window.wp.apiFetch;

	/**
	 * Update the count in the header of the card that holds a checkbox.
	 *
	 * @param {HTMLInputElement} input    Changed checkbox.
	 * @param {string}           selector Checkboxes counted in that card.
	 * @param {string}           template Translated "%1$d of %2$d …" text.
	 */
	function updateCount( input, selector, template ) {
		const card = input.closest( '.oc-card' );
		const count = card.querySelector( '[data-oc-count]' );
		const boxes = card.querySelectorAll( selector );
		const checked = card.querySelectorAll( `${ selector }:checked` );

		if ( count ) {
			count.textContent = sprintf(
				template,
				checked.length,
				boxes.length
			);
		}
	}

	/**
	 * Show where a bookmaker's name in odds tables links to, from its link field.
	 *
	 * @param {HTMLInputElement} field Link field.
	 */
	function updateTarget( field ) {
		const target = document.getElementById(
			field.getAttribute( 'aria-describedby' )
		);
		const custom = field.value.trim() !== '';

		target.classList.toggle( 'is-custom', custom );
		target.textContent = custom
			? target.dataset.custom
			: target.dataset.website;
	}

	/**
	 * Show a result notice in the live region.
	 *
	 * @param {HTMLElement} region  Live region.
	 * @param {string}      type    success|warning|error.
	 * @param {string}      message Text.
	 */
	function showResult( region, type, message ) {
		region.innerHTML = '';
		const notice = document.createElement( 'div' );
		notice.className = `notice notice-${ type } inline`;
		const paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		notice.appendChild( paragraph );
		region.appendChild( notice );
	}

	/**
	 * Queue a refresh and poll until a newer run has finished.
	 *
	 * @param {HTMLFormElement} form Refresh form.
	 */
	async function refreshNow( form ) {
		const button = form.querySelector( 'button' );
		const spinner = form.querySelector( '.oc-refresh-spinner' );
		const region = document.querySelector( '.oc-refresh-result' );
		const deadline = Date.now() + 120000;

		button.disabled = true;
		spinner.classList.add( 'is-active' );
		showResult(
			region,
			'info',
			__( 'Refreshing… this can take up to a minute.', 'odds-comparison' )
		);

		try {
			const queued = await apiFetch( {
				path: '/odds-comparison/v1/refresh',
				method: 'POST',
			} );
			const before = queued.lastRun ? queued.lastRun.finishedAt : 0;
			let status = queued;

			while (
				Date.now() < deadline &&
				( status.running ||
					status.queued ||
					! status.lastRun ||
					status.lastRun.finishedAt <= before )
			) {
				await new Promise( ( resolve ) => setTimeout( resolve, 3000 ) );
				status = await apiFetch( {
					path: '/odds-comparison/v1/status',
				} );
			}

			if ( status.lastRun && status.lastRun.finishedAt > before ) {
				const run = status.lastRun;
				showResult(
					region,
					run.failed > 0 ? 'warning' : 'success',
					sprintf(
						/* translators: 1: fetched markets, 2: failed, 3: deferred, 4: discovered events. */
						__(
							'Fetched %1$d markets, %2$d failed, %3$d deferred; %4$d events discovered. Reload the page to update the tables.',
							'odds-comparison'
						),
						run.fetched,
						run.failed,
						run.skipped,
						run.discovered
					)
				);
			} else {
				showResult(
					region,
					'warning',
					__(
						'The refresh is queued but WP-Cron has not run it yet. It will run on the next site visit or system cron.',
						'odds-comparison'
					)
				);
			}
		} catch ( error ) {
			showResult(
				region,
				'error',
				error.message ||
					__( 'The refresh could not be queued.', 'odds-comparison' )
			);
		} finally {
			button.disabled = false;
			spinner.classList.remove( 'is-active' );
		}
	}

	document.addEventListener( 'change', ( event ) => {
		const input = event.target;

		if ( input.matches( '[data-oc-bookmaker]' ) ) {
			input
				.closest( '.oc-tile' )
				.classList.toggle( 'is-off', ! input.checked );
			updateCount(
				input,
				'[data-oc-bookmaker]',
				/* translators: 1: bookmakers shown, 2: all bookmakers. */
				__( '%1$d of %2$d shown', 'odds-comparison' )
			);
		}

		if ( input.matches( '[data-oc-market]' ) ) {
			updateCount(
				input,
				'[data-oc-market]',
				/* translators: 1: enabled markets, 2: all markets. */
				__( '%1$d of %2$d on', 'odds-comparison' )
			);
		}
	} );

	document.addEventListener( 'input', ( event ) => {
		if ( event.target.matches( '[data-oc-link]' ) ) {
			updateTarget( event.target );
		}
	} );

	document.addEventListener( 'submit', ( event ) => {
		if ( event.target.id === 'oc-refresh-form' ) {
			event.preventDefault();
			refreshNow( event.target );
		}
	} );
} )();
