/**
 * The bulk page: the library's counts and savings, the run with its controls,
 * and the two runs a person starts here, optimize and restore. A run in the
 * background is read every few seconds; a run "while this page is open" is
 * driven by this page, one request after another, for as long as it stays open.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { call } from '../settings/api';

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
			<Notice status="error" isDismissible={ false }>
				{ error.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const active =
		state.run && [ 'running', 'paused' ].includes( state.run.status );

	const start = ( action ) => run( '/bulk/start', 'POST', { action, mode } );

	const restoreAll = () => {
		if (
			// eslint-disable-next-line no-alert -- a one-line yes or no before every image is put back.
			window.confirm(
				__(
					'Put every optimized image back as it was uploaded? Their WebP and AVIF copies are deleted; the backups stay.',
					'iminify-image-optimizer'
				)
			)
		) {
			run( '/bulk/start', 'POST', { action: 'restore', mode } );
		}
	};

	return (
		<div className="iminify-bulk">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error.message }
				</Notice>
			) }
			{ state.error && (
				<Notice status="warning" isDismissible={ false }>
					{ state.error }
				</Notice>
			) }
			<Library state={ state } />
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
			<Optimize
				state={ state }
				busy={ busy }
				active={ active }
				mode={ mode }
				onMode={ setMode }
				onStart={ () => start( 'optimize' ) }
				onRetry={ () => start( 'retry' ) }
			/>
			<Restore
				state={ state }
				busy={ busy }
				active={ active }
				onRestore={ restoreAll }
			/>
		</div>
	);
}

/**
 * The library's counts and what the optimized images saved.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
function Library( { state } ) {
	const { numbers, saved } = state;
	const rows = [
		[ __( 'Images', 'iminify-image-optimizer' ), numbers.images ],
		[ __( 'Optimized', 'iminify-image-optimizer' ), numbers.optimized ],
		[
			__( 'Not optimized', 'iminify-image-optimizer' ),
			numbers.not_optimized,
		],
		[ __( 'On their way', 'iminify-image-optimizer' ), numbers.waiting ],
		[ __( 'Failed', 'iminify-image-optimizer' ), numbers.failed ],
		[ __( 'Skipped', 'iminify-image-optimizer' ), numbers.skipped ],
		[ __( 'Restored', 'iminify-image-optimizer' ), numbers.restored ],
	];

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>{ __( 'Your library', 'iminify-image-optimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<table className="iminify-counts">
					<tbody>
						{ rows.map( ( [ label, value ] ) => (
							<tr key={ label }>
								<th scope="row">{ label }</th>
								<td>{ value }</td>
							</tr>
						) ) }
					</tbody>
				</table>
				{ saved && (
					<p>
						{ sprintf(
							/* translators: 1: the bytes saved, such as "12.4 MB", 2: the bytes before, 3: the share saved, such as "38.2". */
							__(
								'Optimizing saved %1$s of %2$s (%3$s%%).',
								'iminify-image-optimizer'
							),
							saved.bytes,
							saved.before,
							saved.percent
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
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
	const restore = run.action === 'restore';
	const active = [ 'running', 'paused' ].includes( run.status );

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ restore
						? __( 'Restoring', 'iminify-image-optimizer' )
						: __( 'Bulk optimization', 'iminify-image-optimizer' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p className="iminify-progress">
					{ run.status === 'running' && <Spinner /> }
					<span>
						{ restore
							? sprintf(
									/* translators: 1: how many images were restored, 2: how many there were to restore. */
									__(
										'Restored %1$d of %2$d images.',
										'iminify-image-optimizer'
									),
									run.sent,
									run.total
								)
							: sprintf(
									/* translators: 1: how many images were sent, 2: how many there were to send. */
									__(
										'Sent %1$d of %2$d images to Iminify.',
										'iminify-image-optimizer'
									),
									run.sent,
									run.total
								) }
					</span>
				</p>
				{ restore && run.failed > 0 && (
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
					<div className="iminify-buttons">
						{ run.status === 'running' ? (
							<Button
								variant="secondary"
								__next40pxDefaultSize
								disabled={ busy }
								onClick={ onPause }
							>
								{ __( 'Pause', 'iminify-image-optimizer' ) }
							</Button>
						) : (
							<Button
								variant="secondary"
								__next40pxDefaultSize
								disabled={ busy }
								onClick={ onResume }
							>
								{ __( 'Resume', 'iminify-image-optimizer' ) }
							</Button>
						) }
						<Button
							variant="tertiary"
							isDestructive
							__next40pxDefaultSize
							disabled={ busy }
							onClick={ onStop }
						>
							{ __( 'Stop', 'iminify-image-optimizer' ) }
						</Button>
						{ run.mode === 'page' && run.status === 'running' && (
							<Button
								variant="tertiary"
								__next40pxDefaultSize
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
			</CardBody>
		</Card>
	);
}

/**
 * Where the run stands, in words.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.run   The run.
 * @param {Object} props.queue What waits.
 */
function RunStatus( { run, queue } ) {
	if ( run.status === 'done' ) {
		return (
			<p>
				{ run.action === 'restore'
					? __(
							'Finished: the images are back as they were uploaded.',
							'iminify-image-optimizer'
						)
					: __(
							'Finished: every image was sent. Each one shows how it came out in the media library.',
							'iminify-image-optimizer'
						) }
			</p>
		);
	}

	if ( run.status === 'stopped' ) {
		return (
			<p>
				{ run.mode === 'page'
					? __(
							'Stopped. Images already with Iminify finish while this page is open; the rest stay as they are.',
							'iminify-image-optimizer'
						)
					: __(
							'Stopped. Images already with Iminify finish; the rest stay as they are.',
							'iminify-image-optimizer'
						) }
			</p>
		);
	}

	if ( run.status === 'paused' ) {
		const reasons = {
			disk_full: __(
				'Paused: the server is low on disk space. Free some up, then resume.',
				'iminify-image-optimizer'
			),
			deactivated: __(
				'Paused while the plugin was turned off.',
				'iminify-image-optimizer'
			),
		};

		return (
			<p>
				{ reasons[ run.reason ] ??
					( run.mode === 'page'
						? __(
								'Paused. Images already with Iminify finish while this page is open.',
								'iminify-image-optimizer'
							)
						: __(
								'Paused. Images already with Iminify finish.',
								'iminify-image-optimizer'
							) ) }
			</p>
		);
	}

	if ( queue.next_batch && run.action !== 'restore' ) {
		return (
			<p>
				{ sprintf(
					/* translators: %s: the date and time the next batch opens. */
					__(
						"Your account's images for the day are used up. The run goes on by itself when the next batch opens on %s.",
						'iminify-image-optimizer'
					),
					queue.next_batch
				) }
			</p>
		);
	}

	if ( run.held ) {
		return (
			<p>
				{ sprintf(
					/* translators: %s: the date and time the run goes on. */
					__(
						'Iminify is busy with the images already sent. The run sends more at %s.',
						'iminify-image-optimizer'
					),
					run.held
				) }
			</p>
		);
	}

	return (
		<p>
			{ run.mode === 'page'
				? __(
						'Keep this page open: the images go out while it is.',
						'iminify-image-optimizer'
					)
				: __(
						'The run goes on in the background, so you can leave this page.',
						'iminify-image-optimizer'
					) }
		</p>
	);
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
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ __(
					"The run hasn't moved for a while: this site's scheduled tasks don't seem to run.",
					'iminify-image-optimizer'
				) }
			</p>
			{ run.loopback && ! run.loopback.ok && (
				<p>
					{ sprintf(
						/* translators: %s: what Site Health's loopback check found. */
						__( 'Site Health says: %s', 'iminify-image-optimizer' ),
						run.loopback.message
					) }
				</p>
			) }
			<p>
				{ __(
					'Run it while this page is open instead, or run the queue from the shell:',
					'iminify-image-optimizer'
				) }{ ' ' }
				<code>{ run.command }</code>
			</p>
			<Button
				variant="primary"
				__next40pxDefaultSize
				disabled={ busy }
				onClick={ onPageMode }
			>
				{ __(
					'Run it while this page is open',
					'iminify-image-optimizer'
				) }
			</Button>
		</Notice>
	);
}

/**
 * Starting a run: the service's estimate, the disk the backups need, and how it runs.
 *
 * @param {Object}     props         The component's props.
 * @param {Object}     props.state   The page's state.
 * @param {boolean}    props.busy    Whether a press is on its way.
 * @param {boolean}    props.active  Whether a run is going on.
 * @param {string}     props.mode    How the next run goes.
 * @param {() => void} props.onMode  Picks how it goes.
 * @param {() => void} props.onStart Starts optimizing.
 * @param {() => void} props.onRetry Sends the failed images again.
 */
function Optimize( { state, busy, active, mode, onMode, onStart, onRetry } ) {
	const { counts, numbers, estimate, disk, links, connected } = state;

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ __( 'Optimize the library', 'iminify-image-optimizer' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ ! connected && (
					<Notice status="warning" isDismissible={ false }>
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
					</Notice>
				) }
				{ disk.low && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The server is low on disk space, so nothing is optimized until some is freed.',
							'iminify-image-optimizer'
						) }
					</Notice>
				) }
				<p>
					{ counts.not_optimized > 0
						? sprintf(
								/* translators: %s: how many images. */
								_n(
									'%s image is not optimized yet.',
									'%s images are not optimized yet.',
									counts.not_optimized,
									'iminify-image-optimizer'
								),
								numbers.not_optimized
							)
						: __(
								'Every image is optimized or on its way.',
								'iminify-image-optimizer'
							) }
				</p>
				{ estimate && (
					<Estimate estimate={ estimate } links={ links } />
				) }
				{ disk.backups && (
					<p>
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
					</p>
				) }
				<RadioControl
					label={ __( 'Run it', 'iminify-image-optimizer' ) }
					selected={ mode }
					onChange={ onMode }
					options={ [
						{
							value: 'background',
							label: __(
								'In the background, so you can leave this page',
								'iminify-image-optimizer'
							),
						},
						{
							value: 'page',
							label: __(
								"While this page is open, for a host whose scheduled tasks don't run",
								'iminify-image-optimizer'
							),
						},
					] }
				/>
				<div className="iminify-buttons">
					<Button
						variant="primary"
						__next40pxDefaultSize
						disabled={
							busy ||
							active ||
							! connected ||
							disk.low ||
							0 === counts.not_optimized
						}
						onClick={ onStart }
					>
						{ __(
							'Optimize the library',
							'iminify-image-optimizer'
						) }
					</Button>
					{ counts.failed > 0 && (
						<Button
							variant="secondary"
							__next40pxDefaultSize
							disabled={
								busy || active || ! connected || disk.low
							}
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
			</CardBody>
		</Card>
	);
}

/**
 * How long the images not optimized take on the account's plan, and on the plans that would take fewer days.
 *
 * @param {Object} props          The component's props.
 * @param {Object} props.estimate The service's estimate.
 * @param {Object} props.links    The page's links.
 */
function Estimate( { estimate, links } ) {
	return (
		<>
			<p>
				{ estimate.days <= 1
					? __(
							"Your plan's daily images cover them all today.",
							'iminify-image-optimizer'
						)
					: sprintf(
							/* translators: %d: how many days. */
							_n(
								"At your plan's daily images, sending them takes about %d day. The run goes on by itself each day.",
								"At your plan's daily images, sending them takes about %d days. The run goes on by itself each day.",
								estimate.days,
								'iminify-image-optimizer'
							),
							estimate.days
						) }
			</p>
			{ estimate.faster.length > 0 && (
				<ul className="iminify-faster">
					{ estimate.faster.map( ( plan ) => (
						<li key={ plan.plan }>
							{ plan.days <= 1
								? sprintf(
										/* translators: %s: a plan's name, such as "Pro". */
										__(
											'%s sends them all today.',
											'iminify-image-optimizer'
										),
										plan.plan
									)
								: sprintf(
										/* translators: 1: a plan's name, such as "Pro", 2: how many days. */
										_n(
											'%1$s sends them in about %2$d day.',
											'%1$s sends them in about %2$d days.',
											plan.days,
											'iminify-image-optimizer'
										),
										plan.plan,
										plan.days
									) }
						</li>
					) ) }
					{ links.plans && (
						<li>
							<a
								href={ links.plans }
								target="_blank"
								rel="noreferrer"
							>
								{ __(
									'See the plans',
									'iminify-image-optimizer'
								) }
							</a>
						</li>
					) }
				</ul>
			) }
		</>
	);
}

/**
 * Restoring every optimized image, which works disconnected and on any plan.
 *
 * @param {Object}     props           The component's props.
 * @param {Object}     props.state     The page's state.
 * @param {boolean}    props.busy      Whether a press is on its way.
 * @param {boolean}    props.active    Whether a run is going on.
 * @param {() => void} props.onRestore Starts restoring.
 */
function Restore( { state, busy, active, onRestore } ) {
	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ __( 'Restore the library', 'iminify-image-optimizer' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Put every optimized image back as it was uploaded, from its backup, with its sizes made again by WordPress. Do this before removing the plugin if you want your originals back in place.',
						'iminify-image-optimizer'
					) }
				</p>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					disabled={ busy || active || 0 === state.counts.optimized }
					onClick={ onRestore }
				>
					{ __( 'Restore all', 'iminify-image-optimizer' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
