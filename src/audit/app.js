/**
 * The page audit: one of the site's pages scanned by Iminify, and every image
 * the page showed beside the attachment and size it is, with what is worth
 * fixing and the fix. An audit on its way is read again every few seconds.
 * Drawn with the design system's parts (`src/ui`) from what the plugin's
 * audit routes answer; every call to Iminify happens behind them.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { call } from '../settings/api';
import {
	Button,
	Callout,
	Chip,
	Empty,
	ErrorState,
	ExternalLink,
	formatNumber,
	Icon,
	Loading,
	Panel,
	PanelTitle,
	Toasts,
	useHeaderStatus,
	useToasts,
} from '../ui';

/**
 * How often the page reads an audit on its way.
 */
const READ_EVERY_MS = 4000;

/**
 * The page's choice that opens the field for an address of its own.
 */
const ANOTHER = '';

/**
 * The longest wait the page counts down, in seconds. Iminify's wait for the
 * minute's scans is shorter; a longer one is a spent day, whose message says
 * when the scans come back.
 */
const COUNT_DOWN_TO = 120;

export default function App() {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ wait, setWait ] = useState( 0 );
	const [ opening, setOpening ] = useState( null );
	const [ doing, setDoing ] = useState( null );
	const [ beat, setBeat ] = useState( 0 );
	const { toasts, toast, dismiss } = useToasts();
	const presses = useRef( 0 );

	/*
	 * A refusal shows on the page, and a wait of the minute is counted down; a
	 * refusal leaves the state as it was, so the poll it may have stopped is
	 * started again.
	 */
	const refuse = useCallback( ( failure ) => {
		setError( failure );

		if (
			'rate_limited' === failure?.code &&
			failure.retryAfter &&
			failure.retryAfter <= COUNT_DOWN_TO
		) {
			setWait( failure.retryAfter );
		}

		setBeat( ( count ) => count + 1 );
	}, [] );

	const load = useCallback(
		( id = null ) => {
			presses.current++;
			setBusy( true );
			setError( null );

			return call( id ? `/audit?audit=${ id }` : '/audit' )
				.then( setState )
				.catch( refuse )
				.finally( () => setBusy( false ) );
		},
		[ refuse ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	/* Asks again for the state, keeping the audit that is open. */
	const retry = () => load( state?.audit?.id ?? null );

	const connected = Boolean( state?.connected );

	let pillState = null;

	if ( null !== state ) {
		pillState = connected ? 'on' : 'off';
	}

	useHeaderStatus(
		pillState,
		connected
			? __( 'Connected', 'iminify-image-optimizer' )
			: __( 'Not connected', 'iminify-image-optimizer' )
	);

	/* The seconds Iminify asked to wait before the next scan, counted down. */
	useEffect( () => {
		if ( wait <= 0 ) {
			return undefined;
		}

		const timer = setTimeout( () => setWait( wait - 1 ), 1000 );

		return () => clearTimeout( timer );
	}, [ wait ] );

	const open = state?.audit ?? null;
	const running = Boolean( open && ! open.done );
	const othersRunning = Boolean(
		state?.audits?.some(
			( audit ) => ! audit.done && audit.id !== open?.id
		)
	);

	/*
	 * An audit on its way is read again until it is done, and its line in the
	 * list with it; another one still running is read through the whole state,
	 * keeping the one that is open. A read that fails tries again.
	 */
	useEffect( () => {
		if ( ! connected || ( ! running && ! othersRunning ) ) {
			return undefined;
		}

		let stopped = false;
		const press = presses.current;
		const timer = setTimeout( () => {
			let path = '/audit';

			if ( running ) {
				path = `/audit/${ open.id }`;
			} else if ( open ) {
				path = `/audit?audit=${ open.id }`;
			}

			call( path )
				.then( ( next ) => {
					if ( ! stopped && press === presses.current ) {
						setState( ( current ) => settle( current, next ) );
					}
				} )
				.catch( () => {
					if ( ! stopped && press === presses.current ) {
						setBeat( ( count ) => count + 1 );
					}
				} );
		}, READ_EVERY_MS );

		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ connected, running, othersRunning, open, state, beat ] );

	if ( null === state ) {
		return error ? (
			<ErrorState
				message={ error.message }
				busy={ busy }
				onRetry={ retry }
			/>
		) : (
			<Loading />
		);
	}

	if ( ! connected ) {
		return <NotConnected state={ state } />;
	}

	/* A start and an opening each answer for the latest press alone, so a later press is never undone. */
	const start = ( url ) => {
		const press = ++presses.current;
		setBusy( true );
		setError( null );

		return call( '/audit', 'POST', { url } )
			.then( ( next ) => {
				if ( press !== presses.current ) {
					/* A later press opened another audit, which stays open; the new one joins the list. */
					setState( ( current ) =>
						next.connected
							? { ...current, audits: next.audits }
							: current
					);

					return;
				}

				setState( next );

				if ( next.connected ) {
					toast(
						__(
							'Iminify is scanning the page.',
							'iminify-image-optimizer'
						)
					);
				}
			} )
			.catch( refuse )
			.finally( () => setBusy( false ) );
	};

	const show = ( id ) => {
		const press = ++presses.current;
		setOpening( id );
		setError( null );

		return call( `/audit/${ id }` )
			.then( ( next ) => {
				if ( press === presses.current ) {
					setState( ( current ) => settle( current, next ) );
				}
			} )
			.catch( refuse )
			.finally( () => setOpening( null ) );
	};

	/*
	 * A fix pressed beside an image: the media library's own route, then the
	 * state again with the audit kept open. The audit itself is what Iminify saw,
	 * so the toast asks for another scan rather than hiding the finding.
	 */
	const fix = ( key, path, said ) => {
		setDoing( key );
		setError( null );

		return call( path, 'POST' )
			.then( () => {
				toast( said );

				return load( open?.id ?? null );
			} )
			.catch( refuse )
			.finally( () => setDoing( null ) );
	};

	/* Turns on filling in what the pages' images lack, which answers the state. */
	const complete = () => {
		setDoing( 'complete' );
		setError( null );

		return call( '/audit/complete', 'POST', open ? { audit: open.id } : {} )
			.then( ( next ) => {
				setState( ( current ) => settle( current, next ) );
				toast(
					__(
						'Your pages now get the missing width, height and alt text as they’re sent. Scan the page again to see it.',
						'iminify-image-optimizer'
					)
				);
			} )
			.catch( refuse )
			.finally( () => setDoing( null ) );
	};

	return (
		<>
			{ error && (
				<Callout tone="danger" role="alert">
					<p>{ error.message }</p>
				</Callout>
			) }
			{ state.error && (
				<ErrorState
					message={ state.error }
					busy={ busy }
					onRetry={ retry }
				/>
			) }
			{ /* Scan comes first in the page, for a phone and for the keyboard; on a wide screen it sits beside the list. */ }
			<div className="iminify-grid iminify-grid--audit">
				<div className="iminify-col iminify-grid__scan">
					<Scan
						state={ state }
						busy={ busy }
						wait={ wait }
						onScan={ start }
					/>
				</div>
				<div className="iminify-col iminify-grid__list">
					<Audit
						audit={ open }
						pages={ state.pages }
						settings={ state.links?.settings ?? null }
						doing={ doing }
						onFix={ fix }
						onComplete={ complete }
					/>
				</div>
				<div className="iminify-col iminify-grid__more">
					<History
						audits={ state.audits }
						pages={ state.pages }
						open={ open?.id ?? null }
						opening={ opening }
						onOpen={ show }
					/>
					<Weekly link={ state.links?.weekly ?? null } />
				</div>
			</div>
			<Toasts toasts={ toasts } onDismiss={ dismiss } />
		</>
	);
}

/**
 * A weekly check of the whole site, which iminify.com sets up from a crawl of
 * it: shown on every plan, without naming any, since the website shows its
 * controls to every plan and says there which ones have it.
 *
 * @param {Object}      props      The component's props.
 * @param {string|null} props.link Where it is set up, or null while the account is kept out of sight.
 */
function Weekly( { link } ) {
	if ( ! link ) {
		return null;
	}

	return (
		<Panel>
			<PanelTitle icon="clock">
				{ __( 'Every page, every week', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-text">
				{ __(
					"A weekly check audits every page in your site's sitemap once a week, compresses the images that are new or changed, and emails you what it found. You set it up on iminify.com from a crawl of your site, where you can see which plans include it.",
					'iminify-image-optimizer'
				) }
			</p>
			<p className="iminify-links">
				<ExternalLink href={ link }>
					{ __( 'Set up a weekly check', 'iminify-image-optimizer' ) }
				</ExternalLink>
			</p>
		</Panel>
	);
}

/**
 * The state after an answer: one audit's route answers `{ audit }`, which is
 * opened in the state; a site no longer connected answers the whole state,
 * which replaces it; and a whole state that came with a refusal keeps the
 * audit and the list it failed to bring, so the open audit stays open and the
 * poll goes on.
 *
 * @param {Object} current The page's state.
 * @param {Object} next    What the route answered.
 * @return {Object} The state.
 */
function settle( current, next ) {
	if ( undefined === next.connected ) {
		return withAudit( current, next.audit );
	}

	if ( next.connected && next.error && current ) {
		return {
			...next,
			audit: next.audit ?? current.audit,
			audits: next.audits.length > 0 ? next.audits : current.audits,
		};
	}

	return next;
}

/**
 * The state with an audit open, its line in the list brought up to date.
 *
 * @param {Object} state The page's state.
 * @param {Object} audit The audit, as one audit's route answers it.
 * @return {Object} The state.
 */
function withAudit( state, audit ) {
	if ( ! state || ! audit ) {
		return state;
	}

	const line = {
		id: audit.id,
		url: audit.url,
		status: audit.status,
		done: audit.done,
		problem: audit.problem,
		images_found: audit.images_found,
		at: audit.at,
	};

	return {
		...state,
		audit,
		audits: state.audits.map( ( listed ) =>
			listed.id === audit.id ? line : listed
		),
	};
}

/**
 * A page of the site in words: its title where the site offers it, else its path.
 *
 * @param {string} url   The page's address.
 * @param {Array}  pages The pages the site offers.
 * @return {string} The page.
 */
function pageName( url, pages ) {
	const offered = pages.find( ( page ) => page.url === url );

	if ( offered ) {
		return offered.title;
	}

	try {
		const parsed = new URL( url );

		return parsed.pathname + parsed.search;
	} catch {
		return url;
	}
}

/**
 * The chip for an audit: where it stands in a word or two.
 *
 * @param {Object} audit The audit.
 * @return {{ label: string, tone: string, moving: boolean }} The chip.
 */
function auditChip( audit ) {
	if ( audit.problem ) {
		return {
			label: __( "Couldn't read the page", 'iminify-image-optimizer' ),
			tone: 'danger',
			moving: false,
		};
	}

	switch ( audit.status ) {
		case 'queued':
			return {
				label: __( 'Waiting to scan', 'iminify-image-optimizer' ),
				tone: 'accent',
				moving: true,
			};
		case 'scanning':
			return {
				label: __( 'Scanning', 'iminify-image-optimizer' ),
				tone: 'accent',
				moving: true,
			};
		case 'completed':
			return {
				label: __( 'Done', 'iminify-image-optimizer' ),
				tone: 'success',
				moving: false,
			};
		case 'no-image-found':
			return {
				label: __( 'No images', 'iminify-image-optimizer' ),
				tone: 'neutral',
				moving: false,
			};
		case 'cancelled':
			return {
				label: __( 'Stopped', 'iminify-image-optimizer' ),
				tone: 'neutral',
				moving: false,
			};
		default:
			return {
				label: __( 'Failed', 'iminify-image-optimizer' ),
				tone: 'danger',
				moving: false,
			};
	}
}

/**
 * The chip for an image in the library: where its optimization stands.
 *
 * @param {string} status The attachment's status.
 * @return {{ label: string, tone: string }} The chip.
 */
function imageChip( status ) {
	switch ( status ) {
		case 'optimized':
			return {
				label: __( 'Optimized', 'iminify-image-optimizer' ),
				tone: 'success',
			};
		case 'queued':
		case 'waiting':
			return {
				label: __( 'On its way', 'iminify-image-optimizer' ),
				tone: 'accent',
			};
		case 'paused':
		case 'parked':
			return {
				label: __( 'Waiting', 'iminify-image-optimizer' ),
				tone: 'warning',
			};
		case 'error':
			return {
				label: __( 'Failed', 'iminify-image-optimizer' ),
				tone: 'danger',
			};
		case 'other':
			return {
				label: __(
					'Optimized by another plugin',
					'iminify-image-optimizer'
				),
				tone: 'success',
			};
		default:
			return {
				label: __( 'Not optimized', 'iminify-image-optimizer' ),
				tone: 'neutral',
			};
	}
}

/**
 * The page for a site that isn't connected, or that Iminify disconnected.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
function NotConnected( { state } ) {
	return (
		<Panel>
			<Empty icon="plug">
				<p>
					{ state.revoked
						? __(
								'Iminify disconnected this site, so its pages can’t be audited. Connect it again to carry on.',
								'iminify-image-optimizer'
							)
						: __(
								'Connect this site to Iminify to audit its pages.',
								'iminify-image-optimizer'
							) }
				</p>
				<a
					className="iminify-btn iminify-btn--primary"
					href={ state.links.settings }
				>
					{ __( 'Go to the settings', 'iminify-image-optimizer' ) }
					<Icon name="arrow" flip />
				</a>
			</Empty>
		</Panel>
	);
}

/**
 * Choosing a page to scan: the home page, the site's pages, or an address on
 * the site, and the day's page scans each one spends.
 *
 * @param {Object}                props        The component's props.
 * @param {Object}                props.state  The page's state.
 * @param {boolean}               props.busy   Whether a press is on its way.
 * @param {number}                props.wait   The seconds left before Iminify takes the next scan.
 * @param {(url: string) => void} props.onScan Scans a page.
 */
function Scan( { state, busy, wait, onScan } ) {
	const [ chosen, setChosen ] = useState( state.home );
	const [ address, setAddress ] = useState( '' );
	const { pages, scans } = state;
	const url = ANOTHER === chosen ? address.trim() : chosen;

	const submit = ( event ) => {
		event.preventDefault();

		if ( '' !== url && ! busy && wait <= 0 ) {
			onScan( url );
		}
	};

	return (
		<Panel>
			<PanelTitle icon="screen">
				{ __( 'Scan a page', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-text">
				{ __(
					'Iminify opens the page as a visitor would and measures every image it shows.',
					'iminify-image-optimizer'
				) }
			</p>
			<form className="iminify-section" onSubmit={ submit }>
				<label className="iminify-label" htmlFor="iminify-audit-page">
					{ __( 'Page', 'iminify-image-optimizer' ) }
				</label>
				<select
					id="iminify-audit-page"
					className="iminify-select"
					value={ chosen }
					onChange={ ( event ) => setChosen( event.target.value ) }
				>
					{ pages.map( ( page ) => (
						<option key={ page.url } value={ page.url }>
							{ page.title }
						</option>
					) ) }
					<option value={ ANOTHER }>
						{ __(
							'Another address on this site',
							'iminify-image-optimizer'
						) }
					</option>
				</select>
				{ ANOTHER === chosen && (
					<>
						<label
							className="iminify-label"
							htmlFor="iminify-audit-address"
						>
							{ __( 'Address', 'iminify-image-optimizer' ) }
						</label>
						<input
							id="iminify-audit-address"
							className="iminify-input"
							type="url"
							dir="ltr"
							placeholder={ state.home }
							value={ address }
							onChange={ ( event ) =>
								setAddress( event.target.value )
							}
						/>
					</>
				) }
				<div className="iminify-actions">
					<Button
						type="submit"
						variant="primary"
						icon="screen"
						busy={ busy }
						disabled={ busy || wait > 0 || '' === url }
					>
						{ __( 'Scan', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			</form>
			{ wait > 0 && (
				/* The alert above says the wait once; the count goes unread, or it would be read out every second. */
				<p className="iminify-meta-line" aria-hidden="true">
					<Icon name="clock" />
					<span>
						{ sprintf(
							/* translators: %s: how many seconds are left before the next scan. */
							_n(
								'You can scan again in %s second.',
								'You can scan again in %s seconds.',
								wait,
								'iminify-image-optimizer'
							),
							formatNumber( wait )
						) }
					</span>
				</p>
			) }
			{ scans && <Scans scans={ scans } /> }
		</Panel>
	);
}

/**
 * The day's page scans, which each audit spends.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.scans The day's page scans.
 */
function Scans( { scans } ) {
	let line;

	if ( null === scans.remaining ) {
		line = sprintf(
			/* translators: %s: how many page scans the account used today. */
			_n(
				'%s page scan used today. Each audit counts as one.',
				'%s page scans used today. Each audit counts as one.',
				scans.used,
				'iminify-image-optimizer'
			),
			formatNumber( scans.used )
		);
	} else if ( scans.remaining > 0 ) {
		line = sprintf(
			/* translators: 1: how many page scans are left today, 2: how many the account has a day. */
			_n(
				'%1$s of %2$s page scan left today. Each audit counts as one.',
				'%1$s of %2$s page scans left today. Each audit counts as one.',
				scans.used + scans.remaining,
				'iminify-image-optimizer'
			),
			formatNumber( scans.remaining ),
			formatNumber( scans.used + scans.remaining )
		);
	} else if ( scans.resets_at ) {
		line = sprintf(
			/* translators: %s: the date and time more page scans come. */
			__(
				'Your page scans for today are used up. More come on %s.',
				'iminify-image-optimizer'
			),
			scans.resets_at
		);
	} else {
		line = __(
			'Your page scans for today are used up.',
			'iminify-image-optimizer'
		);
	}

	return (
		<p className="iminify-meta-line">
			<Icon name="clock" />
			<span>{ line }</span>
		</p>
	);
}

/**
 * The site's latest audits, each with where it stands; one opens on a press.
 *
 * @param {Object}               props         The component's props.
 * @param {Array}                props.audits  The audits.
 * @param {Array}                props.pages   The pages the site offers.
 * @param {string|null}          props.open    The audit showing.
 * @param {string|null}          props.opening The audit on its way to showing.
 * @param {(id: string) => void} props.onOpen  Shows an audit.
 */
function History( { audits, pages, open, opening, onOpen } ) {
	if ( 0 === audits.length ) {
		return null;
	}

	return (
		<Panel>
			<PanelTitle icon="queue">
				{ __( 'Recent audits', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<ul className="iminify-audits">
				{ audits.map( ( audit ) => {
					const chip = auditChip( audit );

					return (
						<li key={ audit.id }>
							<button
								type="button"
								className="iminify-audits__item"
								aria-current={ audit.id === open }
								aria-disabled={ null !== opening }
								onClick={ () =>
									null === opening && onOpen( audit.id )
								}
							>
								<span className="iminify-audits__page">
									<bdi>{ pageName( audit.url, pages ) }</bdi>
								</span>
								<span className="iminify-audits__meta">
									{ opening === audit.id ? (
										<span
											className="iminify-spinner"
											aria-hidden="true"
										/>
									) : (
										<Chip
											tone={ chip.tone }
											moving={ chip.moving }
										>
											{ chip.label }
										</Chip>
									) }
									{ audit.at && <span>{ audit.at }</span> }
								</span>
							</button>
						</li>
					);
				} ) }
			</ul>
		</Panel>
	);
}

/**
 * The audit showing: where it stands, why the page couldn't be read, or each
 * image the page showed, those with something to fix first.
 *
 * @param {Object}                                            props            The component's props.
 * @param {Object|null}                                       props.audit      The audit.
 * @param {?string}                                           props.settings   The settings page.
 * @param {?string}                                           props.doing      The press on its way, if any.
 * @param {(key: string, path: string, said: string) => void} props.onFix      Presses a fix.
 * @param {() => void}                                        props.onComplete Turns on filling in the pages' images.
 * @param {Array}                                             props.pages      The pages the site offers.
 */
function Audit( { audit, pages, settings, doing, onFix, onComplete } ) {
	const [ showAll, setShowAll ] = useState( false );

	if ( ! audit ) {
		return (
			<Panel>
				<PanelTitle icon="image">
					{ __( 'Images on the page', 'iminify-image-optimizer' ) }
				</PanelTitle>
				<Empty icon="screen">
					<p>
						{ __(
							'Choose a page and press Scan. Iminify lists every image on it, each beside the image in your library it is, with what is worth fixing.',
							'iminify-image-optimizer'
						) }
					</p>
				</Empty>
			</Panel>
		);
	}

	const chip = auditChip( audit );
	const images = audit.images ?? [];
	const fill = audit.complete ?? { images: 0, on: false, can: false };
	const shot = ( image, index ) => (
		<Shot
			key={ index }
			image={ image }
			edit={ audit.edit ?? null }
			filled={ fill.on }
			settings={ settings }
			doing={ doing }
			onFix={ onFix }
		/>
	);
	const flagged = images.filter( ( image ) => image.findings.length > 0 );
	const clean = images.filter( ( image ) => 0 === image.findings.length );

	return (
		<Panel>
			<PanelTitle
				icon="image"
				aside={
					<span role="status">
						<Chip tone={ chip.tone } moving={ chip.moving }>
							{ chip.label }
						</Chip>
					</span>
				}
			>
				<bdi>{ pageName( audit.url, pages ) }</bdi>
			</PanelTitle>
			<p className="iminify-audit__address">
				<bdi dir="ltr">{ audit.url }</bdi>
				{ audit.at && <span>{ audit.at }</span> }
			</p>
			{ ! audit.done && (
				<p className="iminify-text">
					{ __(
						'Iminify is opening the page and measuring its images. This usually takes less than a minute, and the page shows them as soon as they’re in.',
						'iminify-image-optimizer'
					) }
				</p>
			) }
			{ audit.problem && (
				<Callout tone="warning">
					<p>{ audit.problem.message }</p>
				</Callout>
			) }
			{ audit.done && ! audit.problem && 0 === images.length && (
				<Empty>
					<p>
						{ __(
							'The page showed no images.',
							'iminify-image-optimizer'
						) }
					</p>
				</Empty>
			) }
			{ images.length > 0 && (
				<>
					<p className="iminify-text">
						{ sprintf(
							/* translators: 1: how many images the page showed, 2: how many of them have something to fix. */
							_n(
								'%1$s image on the page, %2$s with something to fix.',
								'%1$s images on the page, %2$s with something to fix.',
								images.length,
								'iminify-image-optimizer'
							),
							formatNumber( images.length ),
							formatNumber( flagged.length )
						) }
					</p>
					{ fill.images > 0 && ! fill.on && (
						<Fill
							fill={ fill }
							doing={ doing }
							onComplete={ onComplete }
						/>
					) }
					{ 0 === flagged.length && (
						<Callout tone="success" icon="check">
							<p>
								{ __(
									'Nothing to fix: every image on this page is sized, loaded and described well.',
									'iminify-image-optimizer'
								) }
							</p>
						</Callout>
					) }
					{ flagged.length > 0 && (
						<ul className="iminify-shots">
							{ flagged.map( shot ) }
						</ul>
					) }
					{ clean.length > 0 && flagged.length > 0 && (
						<div className="iminify-actions">
							<Button
								variant="ghost"
								size="small"
								aria-expanded={ showAll }
								onClick={ () => setShowAll( ! showAll ) }
							>
								{ showAll
									? __(
											'Hide the images with nothing to fix',
											'iminify-image-optimizer'
										)
									: sprintf(
											/* translators: %s: how many images have nothing to fix. */
											_n(
												'Show %s image with nothing to fix',
												'Show %s images with nothing to fix',
												clean.length,
												'iminify-image-optimizer'
											),
											formatNumber( clean.length )
										) }
							</Button>
						</div>
					) }
					{ clean.length > 0 &&
						( showAll || 0 === flagged.length ) && (
							<ul className="iminify-shots">
								{ clean.map( shot ) }
							</ul>
						) }
				</>
			) }
		</Panel>
	);
}

/**
 * Filling in what the pages' images lack, offered once for the page: the
 * images of the media library it shows without their width and height, or
 * without the alt text their attachment has, get them as each page is sent.
 *
 * @param {Object}     props            The component's props.
 * @param {Object}     props.fill       How many images it would fill in, and whether the person may turn it on.
 * @param {?string}    props.doing      The press on its way, if any.
 * @param {() => void} props.onComplete Turns it on.
 */
function Fill( { fill, doing, onComplete } ) {
	return (
		<Callout tone="info" icon="image">
			<p>
				{ sprintf(
					/* translators: %s: how many images. */
					_n(
						'%s image from your media library is on this page without its width and height, or without the alt text it has in the library. The plugin can add what’s missing to your pages as they’re sent, from the library.',
						'%s images from your media library are on this page without their width and height, or without the alt text they have in the library. The plugin can add what’s missing to your pages as they’re sent, from the library.',
						fill.images,
						'iminify-image-optimizer'
					),
					formatNumber( fill.images )
				) }
			</p>
			{ fill.can ? (
				<div className="iminify-actions">
					<Button
						size="small"
						busy={ 'complete' === doing }
						disabled={ null !== doing }
						onClick={ onComplete }
					>
						{ __(
							'Fill them in on every page',
							'iminify-image-optimizer'
						) }
					</Button>
				</div>
			) : (
				<p>
					{ __(
						'Someone who can change the plugin’s settings can turn this on.',
						'iminify-image-optimizer'
					) }
				</p>
			) }
		</Callout>
	);
}

/**
 * What the plugin can do about one finding of an image in the media library,
 * beside the words for its fix.
 *
 * @param {Object}                                            props          The component's props.
 * @param {Object}                                            props.finding  The finding.
 * @param {Object}                                            props.image    The image.
 * @param {?string}                                           props.edit     Where the person edits the page, if they can.
 * @param {boolean}                                           props.filled   Whether the site fills in what images' markup lacks.
 * @param {?string}                                           props.settings The settings page.
 * @param {?string}                                           props.doing    The press on its way, if any.
 * @param {(key: string, path: string, said: string) => void} props.onFix    Presses a fix.
 */
function Remedy( { finding, image, edit, filled, settings, doing, onFix } ) {
	const { attachment, fixes } = image;

	if ( ! attachment || ! fixes ) {
		return null;
	}

	const key = `alt:${ attachment.id }`;

	if (
		'oversized' === finding.code &&
		fixes.smaller &&
		! fixes.smaller.editor
	) {
		return (
			<p className="iminify-findings__do">
				{ sprintf(
					/* translators: 1: a size's name, such as "Medium", 2: its width in pixels, 3: its height in pixels. */
					__(
						'Use the %1$s size (%2$s × %3$s) where this image is placed. The page’s editor doesn’t hold it: your theme, a template or a widget shows it, and the size is chosen there.',
						'iminify-image-optimizer'
					),
					fixes.smaller.size_name,
					formatNumber( fixes.smaller.width ),
					formatNumber( fixes.smaller.height )
				) }
			</p>
		);
	}

	if ( 'oversized' === finding.code && fixes.smaller ) {
		return (
			<p className="iminify-findings__do">
				{ sprintf(
					/* translators: 1: a size's name, such as "Medium", 2: its width in pixels, 3: its height in pixels. */
					__(
						'Use the %1$s size (%2$s × %3$s) here: in the editor, select the image and choose it under Resolution.',
						'iminify-image-optimizer'
					),
					fixes.smaller.size_name,
					formatNumber( fixes.smaller.width ),
					formatNumber( fixes.smaller.height )
				) }{ ' ' }
				{ edit && (
					<a href={ edit } target="_blank" rel="noreferrer">
						{ __( 'Edit the page', 'iminify-image-optimizer' ) }
					</a>
				) }
			</p>
		);
	}

	if ( 'without_dimensions' === finding.code ) {
		return filled && fixes.fill ? (
			<p className="iminify-findings__do">
				{ __(
					'The plugin adds them as the page is sent. Scan again to see it.',
					'iminify-image-optimizer'
				) }
			</p>
		) : null;
	}

	if ( 'missing_alt' !== finding.code || ! fixes.alt_text ) {
		return null;
	}

	switch ( fixes.alt_text ) {
		case 'write':
			return (
				<div className="iminify-actions">
					<Button
						size="small"
						variant="secondary"
						busy={ key === doing }
						disabled={ null !== doing }
						onClick={ () =>
							onFix(
								key,
								`/media/${ attachment.id }/alt-text`,
								__(
									'Iminify is writing the image’s alt text. Scan again once it’s in.',
									'iminify-image-optimizer'
								)
							)
						}
					>
						{ __(
							'Write alt text with AI',
							'iminify-image-optimizer'
						) }
					</Button>
				</div>
			);
		case 'settings':
			return (
				<p className="iminify-findings__do">
					{ __(
						'Iminify can write it once AI alt text is on for this site.',
						'iminify-image-optimizer'
					) }{ ' ' }
					{ settings && (
						<a href={ settings }>
							{ __(
								'Turn it on in the settings',
								'iminify-image-optimizer'
							) }
						</a>
					) }
				</p>
			);
		case 'waiting':
			return (
				<p className="iminify-findings__do">
					{ __(
						'Its alt text is on its way. Scan again once it’s in.',
						'iminify-image-optimizer'
					) }
				</p>
			);
		case 'translations':
			return (
				<p className="iminify-findings__do">
					{ __(
						'Translations share this image, each with an alt text in its own language: write it on the one this page shows, in its edit screen.',
						'iminify-image-optimizer'
					) }
				</p>
			);
		default:
			return filled && fixes.fill ? (
				<p className="iminify-findings__do">
					{ __(
						'The plugin adds the alt text the image has in the media library as the page is sent. Scan again to see it.',
						'iminify-image-optimizer'
					) }
				</p>
			) : null;
	}
}

/**
 * One image the page showed: the attachment and size it is, or its address
 * when no image in the library has it, then each finding with its fix, and a
 * press to optimize an image the plugin hasn't.
 *
 * @param {Object}                                            props          The component's props.
 * @param {Object}                                            props.image    The image.
 * @param {?string}                                           props.edit     Where the person edits the page, if they can.
 * @param {boolean}                                           props.filled   Whether the site fills in what images' markup lacks.
 * @param {?string}                                           props.settings The settings page.
 * @param {?string}                                           props.doing    The press on its way, if any.
 * @param {(key: string, path: string, said: string) => void} props.onFix    Presses a fix.
 */
function Shot( { image, edit, filled, settings, doing, onFix } ) {
	const { attachment } = image;
	const status = attachment ? imageChip( attachment.status ) : null;
	const optimize = attachment ? `optimize:${ attachment.id }` : null;

	return (
		<li className="iminify-shot">
			<span className="iminify-thumb">
				{ attachment?.thumbnail ? (
					<img
						src={ attachment.thumbnail }
						alt=""
						width="56"
						height="56"
						loading="lazy"
					/>
				) : (
					<Icon name="image" />
				) }
			</span>
			<div className="iminify-shot__body">
				<p className="iminify-shot__name">
					{ attachment ? (
						<a href={ attachment.edit ?? undefined }>
							{ attachment.title ||
								__( '(no title)', 'iminify-image-optimizer' ) }
						</a>
					) : (
						<bdi dir="ltr">{ image.url }</bdi>
					) }
				</p>
				<p className="iminify-shot__meta">
					{ attachment && (
						<span>
							{ sprintf(
								/* translators: 1: the size's name, such as "Medium", 2: its width in pixels, 3: its height in pixels. */
								__(
									'%1$s, %2$s × %3$s',
									'iminify-image-optimizer'
								),
								attachment.size_name,
								formatNumber( attachment.width ),
								formatNumber( attachment.height )
							) }
						</span>
					) }
					{ attachment?.copy && (
						<Chip tone="info">
							{ sprintf(
								/* translators: %s: a format, such as WEBP. */
								__( '%s copy', 'iminify-image-optimizer' ),
								attachment.copy
							) }
						</Chip>
					) }
					{ status && (
						<Chip tone={ status.tone }>{ status.label }</Chip>
					) }
					{ image.largest_paint && (
						<Chip tone="accent">
							{ __(
								'Largest in the first screen',
								'iminify-image-optimizer'
							) }
						</Chip>
					) }
					{ image.bytes && <span>{ image.bytes }</span> }
					{ image.rendered_width && image.rendered_height && (
						<span>
							{ sprintf(
								/* translators: 1: the width the page shows it at in pixels, 2: the height. */
								__(
									'shown at %1$s × %2$s',
									'iminify-image-optimizer'
								),
								formatNumber( image.rendered_width ),
								formatNumber( image.rendered_height )
							) }
						</span>
					) }
				</p>
				{ image.findings.length > 0 && (
					<ul className="iminify-findings">
						{ image.findings.map( ( finding ) => (
							<li key={ finding.code }>
								<p className="iminify-findings__what">
									<Icon name="alert" />
									<strong>{ finding.label }</strong>
								</p>
								<p>{ finding.message }</p>
								{ finding.fix_text && (
									<p className="iminify-findings__fix">
										<Icon name="arrow" flip />
										<span>{ finding.fix_text }</span>
									</p>
								) }
								<Remedy
									finding={ finding }
									image={ image }
									edit={ edit }
									filled={ filled }
									settings={ settings }
									doing={ doing }
									onFix={ onFix }
								/>
							</li>
						) ) }
					</ul>
				) }
				{ image.fixes?.optimize && (
					<div className="iminify-actions">
						<Button
							size="small"
							variant="secondary"
							busy={ optimize === doing }
							disabled={ null !== doing }
							onClick={ () =>
								onFix(
									optimize,
									`/media/${ attachment.id }/optimize`,
									__(
										'The image is on its way to Iminify.',
										'iminify-image-optimizer'
									)
								)
							}
						>
							{ __( 'Optimize', 'iminify-image-optimizer' ) }
						</Button>
					</div>
				) }
			</div>
		</li>
	);
}
