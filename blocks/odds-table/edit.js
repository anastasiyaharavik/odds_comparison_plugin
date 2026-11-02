/**
 * Editor component for the Odds Comparison block.
 *
 * - Event picker (searchable), market, selections filter, odds format and display toggles.
 * - Bookmakers: add / remove / move up / move down per block, "Add all", "Remove all".
 *   New blocks are prefilled with every admin-enabled bookmaker.
 * - Preview: ServerSideRender, i.e. the same PHP renderer as the frontend.
 * - Choosing an event/market calls POST /track, which queues a background fetch; the editor
 *   never scrapes.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ComboboxControl,
	SelectControl,
	CheckboxControl,
	ToggleControl,
	TextControl,
	Button,
	Notice,
	Spinner,
	Placeholder,
} from '@wordpress/components';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import ServerSideRender from '@wordpress/server-side-render';

import './editor.scss';

const NAMESPACE = '/odds-comparison/v1';

/**
 * Fetch a REST path whenever it changes (null = nothing to fetch).
 *
 * @param {string|null} path REST path.
 * @return {{data: *, error: string, loading: boolean}} Request state.
 */
function useApi( path ) {
	const [ state, setState ] = useState( {
		data: null,
		error: '',
		loading: false,
	} );

	useEffect( () => {
		if ( ! path ) {
			setState( { data: null, error: '', loading: false } );
			return undefined;
		}

		let active = true;
		setState( ( previous ) => ( {
			...previous,
			error: '',
			loading: true,
		} ) );

		apiFetch( { path } )
			.then( ( data ) => {
				if ( active ) {
					setState( { data, error: '', loading: false } );
				}
			} )
			.catch( ( error ) => {
				if ( active ) {
					setState( {
						data: null,
						error:
							error.message ||
							__( 'Request failed.', 'odds-comparison' ),
						loading: false,
					} );
				}
			} );

		return () => {
			active = false;
		};
	}, [ path ] );

	return state;
}

/**
 * Loading and error states of a request.
 *
 * @param {Object} props         Props.
 * @param {Object} props.request useApi() state.
 * @return {Element|null} Spinner, error notice or nothing.
 */
function RequestState( { request } ) {
	if ( request.loading ) {
		return <Spinner />;
	}

	if ( request.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ request.error }
			</Notice>
		);
	}

	return null;
}

/**
 * Render the block in the editor.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor element.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		eventId,
		marketKey,
		bookmakers,
		selections,
		oddsFormat,
		highlightBest,
		showFormatSwitcher,
		caption,
	} = attributes;
	const instanceId = useInstanceId( Edit, 'oc-odds-edit' );
	const [ search, setSearch ] = useState( '' );
	const [ toAdd, setToAdd ] = useState( '' );
	const pendingFocus = useRef( null );
	const prefilled = useRef( false );
	const debouncedSearch = useDebounce( setSearch, 300 );

	const events = useApi(
		addQueryArgs( `${ NAMESPACE }/events`, {
			search,
			include: eventId || undefined,
		} )
	);
	const markets = useApi(
		eventId ? addQueryArgs( `${ NAMESPACE }/markets`, { eventId } ) : null
	);
	const available = useApi( `${ NAMESPACE }/bookmakers` );
	const cachedSelections = useApi(
		eventId && marketKey
			? addQueryArgs( `${ NAMESPACE }/selections`, {
					eventId,
					marketKey,
			  } )
			: null
	);

	const labels = Object.fromEntries(
		( available.data || [] ).map( ( item ) => [ item.slug, item.label ] )
	);
	const addable = ( available.data || [] ).filter(
		( item ) => ! bookmakers.includes( item.slug )
	);

	// Prefill a newly inserted block with every enabled bookmaker.
	useEffect( () => {
		if (
			! prefilled.current &&
			available.data &&
			bookmakers.length === 0 &&
			! eventId
		) {
			prefilled.current = true;
			setAttributes( {
				bookmakers: available.data.map( ( item ) => item.slug ),
			} );
		}
	}, [ available.data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Keep the market valid for the chosen event.
	useEffect( () => {
		if (
			markets.data &&
			markets.data.length > 0 &&
			! markets.data.some( ( market ) => market.key === marketKey )
		) {
			setAttributes( {
				marketKey: markets.data[ 0 ].key,
				selections: [],
			} );
		}
	}, [ markets.data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Track the pair so the background refresh fetches it for the preview.
	useEffect( () => {
		if ( eventId && marketKey ) {
			apiFetch( {
				path: `${ NAMESPACE }/track`,
				method: 'POST',
				data: { eventId, marketKey },
			} ).catch( () => {} );
		}
	}, [ eventId, marketKey ] );

	// Restore keyboard focus after the bookmaker list changed.
	useEffect( () => {
		if ( pendingFocus.current ) {
			const element = document.getElementById( pendingFocus.current );
			pendingFocus.current = null;

			if ( element ) {
				element.focus();
			}
		}
	}, [ bookmakers ] );

	const focusId = ( action, slug ) => `${ instanceId }-${ action }-${ slug }`;

	const move = ( index, direction ) => {
		const target = index + direction;
		const next = [ ...bookmakers ];
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];

		// Keep focus on the same button, unless it became disabled at the list edge.
		let action = direction < 0 ? 'up' : 'down';

		if ( direction < 0 && target === 0 ) {
			action = 'down';
		} else if ( direction > 0 && target === next.length - 1 ) {
			action = 'up';
		}

		pendingFocus.current = focusId( action, bookmakers[ index ] );
		setAttributes( { bookmakers: next } );
	};

	const remove = ( index ) => {
		const next = bookmakers.filter( ( _, position ) => position !== index );
		const neighbour = next[ index ] || next[ index - 1 ];
		pendingFocus.current = neighbour
			? focusId( 'remove', neighbour )
			: `${ instanceId }-add`;
		setAttributes( { bookmakers: next } );
	};

	const add = () => {
		const slug = toAdd || ( addable[ 0 ] && addable[ 0 ].slug );

		if ( slug ) {
			pendingFocus.current = `${ instanceId }-add`;
			setAttributes( { bookmakers: [ ...bookmakers, slug ] } );
			setToAdd( '' );
		}
	};

	const toggleSelection = ( key, checked ) => {
		setAttributes( {
			selections: checked
				? [ ...selections, key ]
				: selections.filter( ( item ) => item !== key ),
		} );
	};

	const eventOptions = ( events.data || [] ).map( ( event ) => ( {
		value: event.id,
		label: event.label,
	} ) );

	const inspector = (
		<InspectorControls>
			<PanelBody title={ __( 'Event and market', 'odds-comparison' ) }>
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Event', 'odds-comparison' ) }
					help={ __(
						'Type to search upcoming events of the enabled competitions.',
						'odds-comparison'
					) }
					value={ eventId || null }
					options={ eventOptions }
					onFilterValueChange={ debouncedSearch }
					onChange={ ( value ) =>
						setAttributes( {
							eventId: value || '',
							selections: [],
						} )
					}
				/>
				<RequestState request={ events } />
				{ events.data && events.data.length === 0 && ! search && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No upcoming events yet. Enable competitions under Odds Comparison → General and wait for the next refresh.',
							'odds-comparison'
						) }
					</Notice>
				) }
				{ eventId && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Market', 'odds-comparison' ) }
						value={ marketKey }
						options={ ( markets.data || [] ).map( ( market ) => ( {
							value: market.key,
							label: market.label,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( {
								marketKey: value,
								selections: [],
							} )
						}
					/>
				) }
				<RequestState request={ markets } />
			</PanelBody>

			<PanelBody title={ __( 'Bookmakers', 'odds-comparison' ) }>
				<RequestState request={ available } />
				{ bookmakers.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No bookmakers selected: the block shows nothing on the site.',
							'odds-comparison'
						) }
					</Notice>
				) }
				<ol className="oc-odds-edit__bookmakers">
					{ bookmakers.map( ( slug, index ) => {
						const name =
							labels[ slug ] ||
							sprintf(
								/* translators: %s: bookmaker slug. */
								__(
									'%s (disabled in settings)',
									'odds-comparison'
								),
								slug
							);

						return (
							<li key={ slug }>
								<span className="oc-odds-edit__name">
									{ name }
								</span>
								<Button
									size="small"
									icon="arrow-up-alt2"
									id={ focusId( 'up', slug ) }
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Move %s up', 'odds-comparison' ),
										name
									) }
									disabled={ index === 0 }
									accessibleWhenDisabled
									onClick={ () => move( index, -1 ) }
								/>
								<Button
									size="small"
									icon="arrow-down-alt2"
									id={ focusId( 'down', slug ) }
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Move %s down', 'odds-comparison' ),
										name
									) }
									disabled={ index === bookmakers.length - 1 }
									accessibleWhenDisabled
									onClick={ () => move( index, 1 ) }
								/>
								<Button
									size="small"
									icon="no-alt"
									isDestructive
									id={ focusId( 'remove', slug ) }
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Remove %s', 'odds-comparison' ),
										name
									) }
									onClick={ () => remove( index ) }
								/>
							</li>
						);
					} ) }
				</ol>
				{ addable.length > 0 && (
					<div className="oc-odds-edit__add">
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							id={ `${ instanceId }-add` }
							label={ __( 'Add bookmaker', 'odds-comparison' ) }
							value={ toAdd || addable[ 0 ].slug }
							options={ addable.map( ( item ) => ( {
								value: item.slug,
								label: item.label,
							} ) ) }
							onChange={ setToAdd }
						/>
						<Button variant="secondary" onClick={ add }>
							{ __( 'Add', 'odds-comparison' ) }
						</Button>
					</div>
				) }
				<div className="oc-odds-edit__bulk">
					<Button
						variant="link"
						disabled={ addable.length === 0 }
						accessibleWhenDisabled
						onClick={ () =>
							setAttributes( {
								bookmakers: [
									...bookmakers,
									...addable.map( ( item ) => item.slug ),
								],
							} )
						}
					>
						{ __( 'Add all', 'odds-comparison' ) }
					</Button>
					<Button
						variant="link"
						isDestructive
						disabled={ bookmakers.length === 0 }
						accessibleWhenDisabled
						onClick={ () => {
							pendingFocus.current = `${ instanceId }-add`;
							setAttributes( { bookmakers: [] } );
						} }
					>
						{ __( 'Remove all', 'odds-comparison' ) }
					</Button>
				</div>
			</PanelBody>

			{ eventId && (
				<PanelBody
					title={ __( 'Selections', 'odds-comparison' ) }
					initialOpen={ false }
				>
					<RequestState request={ cachedSelections } />
					{ cachedSelections.data &&
						cachedSelections.data.length === 0 && (
							<p>
								{ __(
									'Selections appear once odds have been fetched.',
									'odds-comparison'
								) }
							</p>
						) }
					{ ( cachedSelections.data || [] ).map( ( selection ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ selection.key }
							label={ selection.label }
							checked={ selections.includes( selection.key ) }
							onChange={ ( checked ) =>
								toggleSelection( selection.key, checked )
							}
						/>
					) ) }
					<p className="oc-odds-edit__hint">
						{ __(
							'Leave all unticked to show every selection (season-long markets: the 10 favourites).',
							'odds-comparison'
						) }
					</p>
				</PanelBody>
			) }

			<PanelBody
				title={ __( 'Display', 'odds-comparison' ) }
				initialOpen={ false }
			>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Odds format', 'odds-comparison' ) }
					value={ oddsFormat }
					options={ [
						{
							value: 'inherit',
							label: __( 'Use site default', 'odds-comparison' ),
						},
						{
							value: 'fractional',
							label: __( 'Fractional (5/2)', 'odds-comparison' ),
						},
						{
							value: 'decimal',
							label: __( 'Decimal (3.50)', 'odds-comparison' ),
						},
						{
							value: 'american',
							label: __( 'American (+250)', 'odds-comparison' ),
						},
					] }
					onChange={ ( value ) =>
						setAttributes( { oddsFormat: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Highlight best price', 'odds-comparison' ) }
					checked={ highlightBest }
					onChange={ ( value ) =>
						setAttributes( { highlightBest: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Let visitors switch the format',
						'odds-comparison'
					) }
					checked={ showFormatSwitcher }
					onChange={ ( value ) =>
						setAttributes( { showFormatSwitcher: value } )
					}
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Caption', 'odds-comparison' ) }
					help={ __(
						'Leave empty for "Event — Market".',
						'odds-comparison'
					) }
					value={ caption }
					onChange={ ( value ) =>
						setAttributes( { caption: value } )
					}
				/>
			</PanelBody>
		</InspectorControls>
	);

	return (
		<div { ...useBlockProps() }>
			{ inspector }
			{ eventId ? (
				<ServerSideRender
					block="odds-comparison/odds-table"
					attributes={ attributes }
				/>
			) : (
				<Placeholder
					icon="chart-bar"
					label={ __( 'Odds Comparison', 'odds-comparison' ) }
					instructions={ __(
						'Choose an event in the block settings (sidebar) to show its odds.',
						'odds-comparison'
					) }
				/>
			) }
		</div>
	);
}
