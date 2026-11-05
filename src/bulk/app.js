/**
 * The bulk page: the library's counts and savings, the run with its controls,
 * and the two runs a person starts here, optimize and restore. A run in the
 * background is read every few seconds; a run "while this page is open" is
 * driven by this page, one request after another, for as long as it stays open.
 * Drawn with the design system's parts (`src/ui`).
 */
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { call } from '../settings/api';
import {
	Button,
	Callout,
	Chip,
	CodeCopy,
	Confirm,
	Count,
	Empty,
	ErrorState,
	formatNumber,
	Icon,
	Loading,
	Panel,
	ProgressBar,
	Ring,
	useAsk,
	useHeaderStatus,
} from '../ui';

/**
 * How often the page reads a background run.
 */
const READ_EVERY_MS = 5000;

export default function App() {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ mode, setMode ] = useState( 'background' );
	const timer = useRef( null );
	const presses = useRef( 0 );

	const run = useCallback( ( path, method, data ) => {
		presses.current++;
		setBusy( true );
		setError( null );

		return call( path, method, data )
			.then( ( next ) => {
				setState( next );

				return next;
			} )
			.catch( ( failure ) => setError( failure ) )
			.finally( () => setBusy( false ) );
	}, [] );

	useEffect( () => {
		run( '/bulk?estimate=1' );
	}, [ run ] );

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

	const going = state?.run?.status === 'running';
	const pageMode = state?.run?.mode === 'page';
	/* A run in this page's mode is driven from here while it goes on, and after a pause or a stop until what it sent is back. */
	const driving = pageMode && ( going || state?.queue?.working > 0 );
	const reading = going && ! pageMode;

	useEffect( () => {
		if ( ! driving && ! reading ) {
			return undefined;
		}

		let stopped = false;

		const next = ( wait ) => {
			timer.current = setTimeout( () => {
				const press = presses.current;

				call(
					driving ? '/bulk/step' : '/bulk',
					driving ? 'POST' : 'GET'
				)
					.then( ( answer ) => {
						if ( stopped ) {
							return;
						}

						/* A press answered while this was on its way already drew the newer state. */
						if ( press === presses.current ) {
							setState( answer );
						}

						const now = answer.run;
						const goesOn =
							now?.mode === 'page'
								? now.status === 'running' ||
									answer.queue?.working > 0
								: now?.status === 'running';

						if ( ! goesOn ) {
							call( '/bulk?estimate=1' )
								.then(
									( fresh ) =>
										press === presses.current &&
										setState( fresh )
								)
								.catch( () => {} );

							return;
						}

						next(
							driving
								? Math.max( 1, answer.next_step ?? 1 ) * 1000
								: READ_EVERY_MS
						);
					} )
					.catch( () => ! stopped && next( READ_EVERY_MS * 2 ) );
			}, wait );
		};

		next( driving ? 0 : READ_EVERY_MS );

		return () => {
			stopped = true;
			clearTimeout( timer.current );
		};
	}, [ driving, reading ] );

	if ( null === state ) {
		return error ? (
			<ErrorState
				message={ error.message }
				busy={ busy }
				onRetry={ () => run( '/bulk?estimate=1' ) }
			/>
		) : (
			<Loading />
		);
	}

	const active =
		state.run && [ 'running', 'paused' ].includes( state.run.status );

	const start = ( action ) => run( '/bulk/start', 'POST', { action, mode } );

	return (
		<>
			{ error && (
				<Callout tone="danger" role="alert">
					<p>{ error.message }</p>
				</Callout>
			) }
			{ state.error && (
				<Callout>
					<p>{ state.error }</p>
				</Callout>
			) }
			<div className="iminify-grid">
				<div className="iminify-col">
					{ state.run && (
						<Run
							state={ state }
							busy={ busy }
							onPause={ () => run( '/bulk/pause', 'POST' ) }
							onResume={ () => run( '/bulk/resume', 'POST' ) }
							onStop={ () => run( '/bulk/stop', 'POST' ) }
							onMode={ ( next ) =>
								run( '/bulk/mode', 'POST', { mode: next } )
							}
						/>
					) }
					{ ! active && (
						<Optimize
							state={ state }
							busy={ busy }
							mode={ mode }
							onMode={ setMode }
							onStart={ () => start( 'optimize' ) }
							onRetry={ () => start( 'retry' ) }
						/>
					) }
				</div>
				<div className="iminify-col">
					<Library state={ state } />
					{ ! active && state.counts.images > 0 && (
						<Restore
							state={ state }
							busy={ busy }
							onRestore={ () => start( 'restore' ) }
						/>
					) }
				</div>
			</div>
		</>
	);
}

/**
 * The library's counts as a ring and rows, and what the optimized images saved.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
function Library( { state } ) {
	const { counts, numbers, saved } = state;
	const share = counts.images
		? Math.floor( ( counts.optimized / counts.images ) * 100 )
		: 0;
	const labels = {
		optimized: __( 'Optimized', 'iminify-image-optimizer' ),
		not_optimized: __( 'Not optimized', 'iminify-image-optimizer' ),
		waiting: __( 'On their way', 'iminify-image-optimizer' ),
		failed: __( 'Failed', 'iminify-image-optimizer' ),
		skipped: __( 'Skipped', 'iminify-image-optimizer' ),
		restored: __( 'Restored', 'iminify-image-optimizer' ),
	};
	/* The four that always show, then the two that only matter once they happen. */
	const rows = Object.keys( labels ).filter(
		( key ) =>
			! [ 'skipped', 'restored' ].includes( key ) || counts[ key ] > 0
	);

	return (
		<Panel>
			<div className="iminify-panel__head">
				<h2>{ __( 'Your library', 'iminify-image-optimizer' ) }</h2>
				<span className="iminify-label">
					{ sprintf(
						/* translators: %s: how many images the media library has. */
						_n(
							'%s image',
							'%s images',
							counts.images,
							'iminify-image-optimizer'
						),
						numbers.images
					) }
				</span>
			</div>
			{ 0 === counts.images ? (
				<Empty>
					<p>
						{ __(
							'Your media library has no images yet.',
							'iminify-image-optimizer'
						) }
					</p>
				</Empty>
			) : (
				<div className="iminify-library">
					<Ring
						value={ share }
						label={ __( 'Optimized', 'iminify-image-optimizer' ) }
						caption={ __( 'optimized', 'iminify-image-optimizer' ) }
					/>
					<ul className="iminify-rows">
						{ rows.map( ( key ) => (
							<li
								key={ key }
								className={
									'failed' === key && counts.failed > 0
										? 'iminify-rows__item--bad'
										: undefined
								}
							>
								<span
									className={ `iminify-rows__swatch iminify-swatch--${ key }` }
								/>
								<span className="iminify-rows__key">
									{ labels[ key ] }
								</span>
								<span className="iminify-rows__value">
									<Count
										value={ counts[ key ] }
										text={ numbers[ key ] }
									/>
								</span>
							</li>
						) ) }
					</ul>
					{ saved && (
						<div className="iminify-saved">
							<span className="iminify-label">
								{ __(
									'Saved so far',
									'iminify-image-optimizer'
								) }
							</span>
							<span className="iminify-saved__amount">
								{ saved.bytes }
							</span>
							<span className="iminify-saved__of">
								{ sprintf(
									/* translators: 1: the bytes before, such as "12.4 MB", 2: the share saved, such as "38.2". */
									__(
										'of %1$s, %2$s%% less',
										'iminify-image-optimizer'
									),
									saved.before,
									saved.percent
								) }
							</span>
						</div>
					) }
				</div>
			) }
		</Panel>
	);
}

/**
 * The run's chip: its state in a word or two, with a tone and an icon.
 *
 * @param {Object} run   The run.
 * @param {Object} queue What waits.
 * @return {{ label: string, tone: string, icon: string }} The chip.
 */
function chipFor( run, queue ) {
	if ( 'done' === run.status ) {
		return {
			label: __( 'Finished', 'iminify-image-optimizer' ),
			tone: 'success',
			icon: 'check',
		};
	}

	if ( 'stopped' === run.status ) {
		return {
			label: __( 'Stopped', 'iminify-image-optimizer' ),
			tone: 'neutral',
			icon: 'stop',
		};
	}

	if ( 'paused' === run.status ) {
		return 'disk_full' === run.reason
			? {
					label: __( 'Paused: low disk', 'iminify-image-optimizer' ),
					tone: 'warning',
					icon: 'pause',
				}
			: {
					label: __( 'Paused', 'iminify-image-optimizer' ),
					tone: 'neutral',
					icon: 'pause',
				};
	}

	if ( run.stalled ) {
		return {
			label: __( 'Not moving', 'iminify-image-optimizer' ),
			tone: 'warning',
			icon: 'alert',
		};
	}

	if ( queue.next_batch && 'restore' !== run.action ) {
		return {
			label: __(
				'Waiting for the next batch',
				'iminify-image-optimizer'
			),
			tone: 'info',
			icon: 'clock',
		};
	}

	if ( run.held ) {
		return {
			label: __( 'Iminify is busy', 'iminify-image-optimizer' ),
			tone: 'info',
			icon: 'clock',
		};
	}

	if ( 'restore' === run.action ) {
		return {
			label: __( 'Restoring', 'iminify-image-optimizer' ),
			tone: 'accent',
			icon: '',
		};
	}

	return {
		label:
			'page' === run.mode
				? __( 'Running on this page', 'iminify-image-optimizer' )
				: __( 'Running', 'iminify-image-optimizer' ),
		tone: 'accent',
		icon: '',
	};
}

/**
 * The run: how far it got, why it waits, and its controls.
 *
 * @param {Object}                 props          The component's props.
 * @param {Object}                 props.state    The page's state.
 * @param {boolean}                props.busy     Whether a press is on its way.
 * @param {() => void}             props.onPause  Holds the run.
 * @param {() => void}             props.onResume Goes on with the run.
 * @param {() => void}             props.onStop   Ends the run.
 * @param {(mode: string) => void} props.onMode   Moves the run to the background or to this page.
 */
function Run( { state, busy, onPause, onResume, onStop, onMode } ) {
	const { run, queue } = state;
	const restore = 'restore' === run.action;
	const active = [ 'running', 'paused' ].includes( run.status );
	const chip = chipFor( run, queue );
	const moving =
		'running' === run.status &&
		! run.stalled &&
		! run.held &&
		! ( queue.next_batch && ! restore );
	const percent = run.total
		? Math.min( 100, Math.round( ( run.sent / run.total ) * 100 ) )
		: 0;

	return (
		<Panel>
			<div className="iminify-panel__head">
				<h2>
					{ restore
						? __( 'Restoring', 'iminify-image-optimizer' )
						: __( 'Bulk optimization', 'iminify-image-optimizer' ) }
				</h2>
				<Chip tone={ chip.tone } icon={ chip.icon } moving={ moving }>
					{ chip.label }
				</Chip>
			</div>
			<div className="iminify-count" aria-hidden="true">
				<span className="iminify-count__big">
					<Count value={ run.sent } />
				</span>
				<span className="iminify-count__of">
					/ <Count value={ run.total } />
				</span>
				<span className="iminify-count__what">
					{ restore
						? _n(
								'image restored',
								'images restored',
								run.total,
								'iminify-image-optimizer'
							)
						: _n(
								'image sent to Iminify',
								'images sent to Iminify',
								run.total,
								'iminify-image-optimizer'
							) }
				</span>
			</div>
			<ProgressBar
				value={ percent }
				moving={ moving }
				restore={ restore }
				label={
					restore
						? __( 'Restoring', 'iminify-image-optimizer' )
						: __( 'Bulk optimization', 'iminify-image-optimizer' )
				}
			/>
			<div className="iminify-progress__row">
				<span>
					{ restore
						? sprintf(
								/* translators: 1: how many images were restored, 2: how many there were to restore. */
								_n(
									'Restored %1$s of %2$s image.',
									'Restored %1$s of %2$s images.',
									run.total,
									'iminify-image-optimizer'
								),
								formatNumber( run.sent ),
								formatNumber( run.total )
							)
						: sprintf(
								/* translators: 1: how many images were sent, 2: how many there were to send. */
								_n(
									'Sent %1$s of %2$s image to Iminify.',
									'Sent %1$s of %2$s images to Iminify.',
									run.total,
									'iminify-image-optimizer'
								),
								formatNumber( run.sent ),
								formatNumber( run.total )
							) }
				</span>
				<span className="iminify-num" aria-hidden="true">
					{ percent }%
				</span>
			</div>
			{ restore && run.failed > 0 && (
				<div className="iminify-status-gap">
					<Callout>
						<p>
							{ sprintf(
								/* translators: %d: how many images. */
								_n(
									"%d image couldn't be restored. Its panel in the media library says why.",
									"%d images couldn't be restored. Their panels in the media library say why.",
									run.failed,
									'iminify-image-optimizer'
								),
								run.failed
							) }
						</p>
					</Callout>
				</div>
			) }
			<RunStatus run={ run } queue={ queue } />
			{ run.stalled && (
				<Stalled
					run={ run }
					busy={ busy }
					onPageMode={ () => onMode( 'page' ) }
				/>
			) }
			{ active && (
				<div className="iminify-actions">
					{ 'running' === run.status ? (
						<Button
							icon="pause"
							disabled={ busy }
							onClick={ onPause }
						>
							{ __( 'Pause', 'iminify-image-optimizer' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							icon="play"
							disabled={ busy }
							onClick={ onResume }
						>
							{ __( 'Resume', 'iminify-image-optimizer' ) }
						</Button>
					) }
					<Button
						variant="ghost"
						danger
						icon="stop"
						disabled={ busy }
						onClick={ onStop }
					>
						{ __( 'Stop', 'iminify-image-optimizer' ) }
					</Button>
					{ 'page' === run.mode && 'running' === run.status && (
						<Button
							variant="ghost"
							icon="moon"
							disabled={ busy }
							onClick={ () => onMode( 'background' ) }
						>
							{ __(
								'Run it in the background instead',
								'iminify-image-optimizer'
							) }
						</Button>
					) }
				</div>
			) }
		</Panel>
	);
}

/**
 * Where the run stands, in words, with an icon, read out when it changes.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.run   The run.
 * @param {Object} props.queue What waits.
 */
function RunStatus( { run, queue } ) {
	const [ icon, text ] = runStatus( run, queue );

	return (
		<div className="iminify-status" role="status">
			<Icon name={ icon } />
			<span>{ text }</span>
		</div>
	);
}

/**
 * The run's state in a sentence, and the icon beside it.
 *
 * @param {Object} run   The run.
 * @param {Object} queue What waits.
 * @return {string[]} The icon and the sentence.
 */
function runStatus( run, queue ) {
	if ( 'done' === run.status ) {
		return [
			'check',
			'restore' === run.action
				? __(
						'Finished: the images are back as they were uploaded.',
						'iminify-image-optimizer'
					)
				: __(
						'Finished: every image was sent. Each one shows how it came out in the media library.',
						'iminify-image-optimizer'
					),
		];
	}

	if ( 'stopped' === run.status ) {
		return [
			'stop',
			'page' === run.mode
				? __(
						'Stopped. Images already with Iminify finish while this page is open; the rest stay as they are.',
						'iminify-image-optimizer'
					)
				: __(
						'Stopped. Images already with Iminify finish; the rest stay as they are.',
						'iminify-image-optimizer'
					),
		];
	}

	if ( 'paused' === run.status ) {
		if ( 'disk_full' === run.reason ) {
			return [
				'alert',
				__(
					'Paused: the server is low on disk space. Free some up, then resume.',
					'iminify-image-optimizer'
				),
			];
		}

		if ( 'deactivated' === run.reason ) {
			return [
				'alert',
				__(
					'Paused while the plugin was turned off.',
					'iminify-image-optimizer'
				),
			];
		}

		return [
			'pause',
			'page' === run.mode
				? __(
						'Paused. Images already with Iminify finish while this page is open.',
						'iminify-image-optimizer'
					)
				: __(
						'Paused. Images already with Iminify finish.',
						'iminify-image-optimizer'
					),
		];
	}

	if ( queue.next_batch && 'restore' !== run.action ) {
		return [
			'clock',
			sprintf(
				/* translators: %s: the date and time the next batch opens. */
				__(
					"Your account's images for the day are used up. The run goes on by itself when the next batch opens on %s.",
					'iminify-image-optimizer'
				),
				queue.next_batch
			),
		];
	}

	if ( run.held ) {
		return [
			'clock',
			sprintf(
				/* translators: %s: the date and time the run goes on. */
				__(
					'Iminify is busy with the images already sent. The run sends more at %s.',
					'iminify-image-optimizer'
				),
				run.held
			),
		];
	}

	return 'page' === run.mode
		? [
				'screen',
				__(
					'Keep this page open: the images go out while it is.',
					'iminify-image-optimizer'
				),
			]
		: [
				'moon',
				__(
					'The run goes on in the background, so you can leave this page.',
					'iminify-image-optimizer'
				),
			];
}

/**
 * A background run nothing has moved for a while: the host's cron or loopback
 * requests don't work. What Site Health says, the shell command, and this page.
 *
 * @param {Object}     props            The component's props.
 * @param {Object}     props.run        The run.
 * @param {boolean}    props.busy       Whether a press is on its way.
 * @param {() => void} props.onPageMode Moves the run to this page.
 */
function Stalled( { run, busy, onPageMode } ) {
	return (
		<div className="iminify-status-gap">
			<Callout>
				<p>
					<strong>
						{ __(
							"The run hasn't moved for a while: this site's scheduled tasks don't seem to run.",
							'iminify-image-optimizer'
						) }
					</strong>
				</p>
				{ run.loopback && ! run.loopback.ok && (
					<p>
						{ sprintf(
							/* translators: %s: what Site Health's loopback check found. */
							__(
								'Site Health says: %s',
								'iminify-image-optimizer'
							),
							run.loopback.message
						) }
					</p>
				) }
				<p>
					{ __(
						'Run it while this page is open instead, or run the queue from the shell:',
						'iminify-image-optimizer'
					) }
				</p>
				<CodeCopy command={ run.command } />
				<div>
					<Button
						variant="primary"
						icon="screen"
						disabled={ busy }
						onClick={ onPageMode }
					>
						{ __(
							'Run it while this page is open',
							'iminify-image-optimizer'
						) }
					</Button>
				</div>
			</Callout>
		</div>
	);
}

/**
 * Starting a run: what is left, the service's estimate, the disk the backups
 * need, and how the run goes, which a restore takes too.
 *
 * @param {Object}     props         The component's props.
 * @param {Object}     props.state   The page's state.
 * @param {boolean}    props.busy    Whether a press is on its way.
 * @param {string}     props.mode    How the next run goes.
 * @param {() => void} props.onMode  Picks how it goes.
 * @param {() => void} props.onStart Starts optimizing.
 * @param {() => void} props.onRetry Sends the failed images again.
 */
function Optimize( { state, busy, mode, onMode, onStart, onRetry } ) {
	const { counts, numbers, estimate, disk, links, connected } = state;
	const left = counts.not_optimized;

	return (
		<Panel>
			{ ! connected && (
				<Callout icon="plug">
					<p>
						{ __(
							'Connect this site to Iminify to optimize its images.',
							'iminify-image-optimizer'
						) }{ ' ' }
						<a href={ links.settings }>
							{ __(
								'Go to the settings',
								'iminify-image-optimizer'
							) }
						</a>
					</p>
				</Callout>
			) }
			{ disk.low && (
				<Callout icon="disk">
					<p>
						{ __(
							'The server is low on disk space, so nothing is optimized until some is freed.',
							'iminify-image-optimizer'
						) }
					</p>
				</Callout>
			) }
			<span className="iminify-label">
				{ __( 'Optimize the library', 'iminify-image-optimizer' ) }
			</span>
			{ left > 0 ? (
				<p className="iminify-hero">
					{ createInterpolateElement(
						sprintf(
							/* translators: %s: how many images. */
							_n(
								'%s image is not optimized yet.',
								'%s images are not optimized yet.',
								left,
								'iminify-image-optimizer'
							),
							'<count />'
						),
						{
							count: (
								<Count
									value={ left }
									text={ numbers.not_optimized }
								/>
							),
						}
					) }
				</p>
			) : (
				<div className="iminify-done">
					<span className="iminify-done__badge">
						<Icon name={ counts.images > 0 ? 'check' : 'image' } />
					</span>
					<p className="iminify-hero">
						{ counts.images > 0
							? __(
									'Every image is optimized or on its way.',
									'iminify-image-optimizer'
								)
							: __(
									'Your media library has no images yet.',
									'iminify-image-optimizer'
								) }
					</p>
				</div>
			) }
			{ estimate && left > 0 && (
				<Estimate estimate={ estimate } links={ links } />
			) }
			{ disk.backups && left > 0 && (
				<p className="iminify-meta">
					<Icon name="disk" />
					<span>
						{ disk.free
							? sprintf(
									/* translators: 1: the disk space the backups need, such as "120 MB", 2: the free space. */
									__(
										'Their backups need about %1$s of disk space (%2$s free).',
										'iminify-image-optimizer'
									),
									disk.backups,
									disk.free
								)
							: sprintf(
									/* translators: %s: the disk space the backups need, such as "120 MB". */
									__(
										'Their backups need about %s of disk space.',
										'iminify-image-optimizer'
									),
									disk.backups
								) }
					</span>
				</p>
			) }
			{ counts.images > 0 && <Modes mode={ mode } onMode={ onMode } /> }
			{ ( left > 0 || counts.failed > 0 ) && (
				<div className="iminify-actions">
					{ left > 0 && (
						<Button
							variant="primary"
							size="big"
							iconEnd="arrow"
							busy={ busy }
							disabled={ busy || ! connected || disk.low }
							onClick={ onStart }
						>
							{ __(
								'Optimize the library',
								'iminify-image-optimizer'
							) }
						</Button>
					) }
					{ counts.failed > 0 && (
						<Button
							icon="send"
							disabled={ busy || ! connected || disk.low }
							onClick={ onRetry }
						>
							{ sprintf(
								/* translators: %s: how many images. */
								_n(
									'Send the %s failed image again',
									'Send the %s failed images again',
									counts.failed,
									'iminify-image-optimizer'
								),
								numbers.failed
							) }
						</Button>
					) }
				</div>
			) }
		</Panel>
	);
}

/**
 * How a run goes: in the background, or driven by this page.
 *
 * @param {Object}                 props        The component's props.
 * @param {string}                 props.mode   The choice.
 * @param {(mode: string) => void} props.onMode Picks one.
 */
function Modes( { mode, onMode } ) {
	const id = `iminify-mode-${ useId().replace( /[^a-z0-9]/gi, '' ) }`;
	const modes = [
		{
			value: 'background',
			icon: 'moon',
			title: __( 'In the background', 'iminify-image-optimizer' ),
			about: __(
				'So you can leave this page.',
				'iminify-image-optimizer'
			),
		},
		{
			value: 'page',
			icon: 'screen',
			title: __( 'While this page is open', 'iminify-image-optimizer' ),
			about: __(
				"For a host whose scheduled tasks don't run.",
				'iminify-image-optimizer'
			),
		},
	];

	return (
		<fieldset className="iminify-modes">
			<legend className="iminify-label">
				{ __( 'Run it', 'iminify-image-optimizer' ) }
			</legend>
			{ modes.map( ( option ) => (
				<label
					key={ option.value }
					htmlFor={ `${ id }-${ option.value }` }
					className={ `iminify-mode${
						mode === option.value ? ' iminify-mode--on' : ''
					}` }
				>
					<input
						id={ `${ id }-${ option.value }` }
						className="iminify-mode__input"
						type="radio"
						name={ id }
						value={ option.value }
						checked={ mode === option.value }
						onChange={ () => onMode( option.value ) }
						aria-describedby={ `${ id }-${ option.value }-about` }
					/>
					<span className="iminify-mode__ico">
						<Icon name={ option.icon } />
					</span>
					<b>{ option.title }</b>
					<small id={ `${ id }-${ option.value }-about` }>
						{ option.about }
					</small>
					<span className="iminify-mode__tick" aria-hidden="true">
						<Icon name="check" />
					</span>
				</label>
			) ) }
		</fieldset>
	);
}

/**
 * How long the images not optimized take on the account's plan, and on the
 * plans that would take fewer days, as a bar each.
 *
 * @param {Object} props          The component's props.
 * @param {Object} props.estimate The service's estimate.
 * @param {Object} props.links    The page's links.
 */
function Estimate( { estimate, links } ) {
	const rows = [
		{
			who: __( 'Your plan', 'iminify-image-optimizer' ),
			days: estimate.days,
			faster: false,
		},
		...estimate.faster.map( ( plan ) => ( {
			who: plan.plan,
			days: plan.days,
			faster: true,
		} ) ),
	];
	const longest = Math.max( 1, ...rows.map( ( row ) => row.days ) );

	return (
		<div className="iminify-est">
			{ rows.map( ( row ) => (
				<div
					key={ row.who }
					className={ `iminify-est__row${
						row.faster ? ' iminify-est__row--faster' : ''
					}` }
				>
					<span className="iminify-est__who">{ row.who }</span>
					<span className="iminify-est__track" aria-hidden="true">
						<i
							style={ {
								width: `${ Math.max(
									4,
									( Math.max( 1, row.days ) / longest ) * 100
								) }%`,
							} }
						/>
					</span>
					<span className="iminify-est__val">
						{ row.days <= 1
							? __( 'all today', 'iminify-image-optimizer' )
							: sprintf(
									/* translators: %d: how many days. */
									_n(
										'about %d day',
										'about %d days',
										row.days,
										'iminify-image-optimizer'
									),
									row.days
								) }
					</span>
				</div>
			) ) }
			<div className="iminify-est__note">
				<span>
					{ estimate.days <= 1
						? __(
								"Your plan's daily images cover them all today.",
								'iminify-image-optimizer'
							)
						: __(
								'The run goes on by itself each day.',
								'iminify-image-optimizer'
							) }
				</span>
				{ links.plans && estimate.faster.length > 0 && (
					<a href={ links.plans } target="_blank" rel="noreferrer">
						{ __( 'See the plans', 'iminify-image-optimizer' ) }
						<Icon name="arrow" flip />
					</a>
				) }
			</div>
		</div>
	);
}

/**
 * Restoring every optimized image, which works disconnected and on any plan,
 * asked in the page before it starts.
 *
 * @param {Object}     props           The component's props.
 * @param {Object}     props.state     The page's state.
 * @param {boolean}    props.busy      Whether a press is on its way.
 * @param {() => void} props.onRestore Starts restoring.
 */
function Restore( { state, busy, onRestore } ) {
	const { asking, open, close, opener } = useAsk();

	return (
		<Panel>
			<h2>{ __( 'Restore the library', 'iminify-image-optimizer' ) }</h2>
			<p className="iminify-text">
				{ __(
					'Put every optimized image back as it was uploaded, from its backup, with its sizes made again by WordPress. Do this before removing the plugin if you want your originals back in place.',
					'iminify-image-optimizer'
				) }
			</p>
			{ asking ? (
				<Confirm
					question={ __(
						'Put every optimized image back as it was uploaded? Their WebP and AVIF copies are deleted; the backups stay.',
						'iminify-image-optimizer'
					) }
					yes={ __( 'Restore all', 'iminify-image-optimizer' ) }
					busy={ busy }
					onYes={ () => {
						close();
						onRestore();
					} }
					onNo={ close }
				/>
			) : (
				<div className="iminify-actions">
					<Button
						ref={ opener }
						icon="restore"
						disabled={ busy || 0 === state.counts.optimized }
						onClick={ open }
					>
						{ __( 'Restore all', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			) }
		</Panel>
	);
}
