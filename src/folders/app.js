/**
 * The other folders page: the folders chosen outside the media library, the
 * look through them for new and changed images, what they hold, and each image
 * with what it can do. A scan is walked from here one request after another, and
 * images on their way are read again every few seconds. Optimizing or restoring
 * them all is the bulk page's, where the run shows. Drawn with the design
 * system's parts (`src/ui`).
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { call } from '../settings/api';
import {
	Button,
	Callout,
	Chip,
	Confirm,
	Count,
	Empty,
	ErrorState,
	formatNumber,
	Icon,
	Loading,
	Panel,
	PanelTitle,
	Toasts,
	useAsk,
	useHeaderStatus,
	useToasts,
} from '../ui';

/**
 * How often the page reads images on their way.
 */
const READ_EVERY_MS = 5000;

export default function App() {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ filter, setFilter ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const { toasts, toast, dismiss } = useToasts();
	const presses = useRef( 0 );
	const query = `?filter=${ filter }&page=${ page }`;

	const run = useCallback(
		( path = '', method = 'GET', data = undefined, done = '' ) => {
			presses.current++;
			setBusy( true );
			setError( null );

			return call( '/folders' + path + query, method, data )
				.then( ( next ) => {
					setState( next );

					if ( done ) {
						toast( done );
					}

					return next;
				} )
				.catch( ( failure ) => setError( failure ) )
				.finally( () => setBusy( false ) );
		},
		[ query, toast ]
	);

	useEffect( () => {
		run();
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

	const scanning = Boolean( state?.scan?.running );
	const moving = ! scanning && ( state?.counts?.waiting > 0 || state?.run );

	/* A scan under way goes on from here, one budgeted request after another. */
	useEffect( () => {
		if ( ! scanning ) {
			return undefined;
		}

		let stopped = false;
		const press = presses.current;
		const timer = setTimeout( () => {
			call( '/folders/scan' + query, 'POST', { continue: true } )
				.then(
					( next ) =>
						! stopped &&
						press === presses.current &&
						setState( next )
				)
				.catch( ( failure ) => ! stopped && setError( failure ) );
		}, 300 );

		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ scanning, state, query ] );

	/* Images on their way are read again, so each shows where it stands. */
	useEffect( () => {
		if ( ! moving ) {
			return undefined;
		}

		let stopped = false;
		const press = presses.current;
		const timer = setTimeout( () => {
			call( '/folders' + query )
				.then(
					( next ) =>
						! stopped &&
						press === presses.current &&
						setState( next )
				)
				.catch( () => {} );
		}, READ_EVERY_MS );

		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ moving, state, query ] );

	if ( null === state ) {
		return error ? (
			<ErrorState
				message={ error.message }
				busy={ busy }
				onRetry={ () => run() }
			/>
		) : (
			<Loading />
		);
	}

	const show = ( next ) => {
		setFilter( next );
		setPage( 1 );
	};

	return (
		<>
			{ error && (
				<Callout tone="danger" role="alert">
					<p>{ error.message }</p>
				</Callout>
			) }
			<div className="iminify-grid">
				<div className="iminify-col">
					<Files
						state={ state }
						busy={ busy }
						filter={ filter }
						onFilter={ show }
						onPage={ setPage }
						onOptimize={ ( id ) =>
							run(
								`/files/${ id }/optimize`,
								'POST',
								undefined,
								__(
									'Sent to Iminify.',
									'iminify-image-optimizer'
								)
							)
						}
						onRestore={ ( id ) =>
							run(
								`/files/${ id }/restore`,
								'POST',
								undefined,
								__(
									'The original is back.',
									'iminify-image-optimizer'
								)
							)
						}
					/>
				</div>
				<div className="iminify-col">
					<Folders
						state={ state }
						busy={ busy || scanning }
						onAdd={ ( folder ) =>
							run( '', 'POST', { folder } ).then(
								( next ) => next && show( 'all' )
							)
						}
						onRemove={ ( folder ) =>
							run( '', 'DELETE', { folder } )
						}
						onScan={ () => run( '/scan', 'POST' ) }
					/>
					<Summary state={ state } />
				</div>
			</div>
			<Toasts toasts={ toasts } onDismiss={ dismiss } />
		</>
	);
}

/**
 * The folders chosen, the ones offered with a press, a folder named by its path, and the look through them.
 *
 * @param {Object}                   props          The component's props.
 * @param {Object}                   props.state    The page's state.
 * @param {boolean}                  props.busy     Whether a press or a scan is on its way.
 * @param {(folder: string) => void} props.onAdd    Adds a folder.
 * @param {(folder: string) => void} props.onRemove Takes a folder off the list.
 * @param {() => void}               props.onScan   Looks through the folders again.
 */
function Folders( { state, busy, onAdd, onRemove, onScan } ) {
	const [ named, setNamed ] = useState( '' );
	const { listed, offered, scan } = state;
	const choices = offered.filter( ( folder ) => ! folder.listed );

	const add = ( event ) => {
		event.preventDefault();

		if ( '' !== named.trim() ) {
			onAdd( named.trim() );
			setNamed( '' );
		}
	};

	let chip = null;

	if ( scan?.running ) {
		chip = (
			<Chip tone="accent" moving>
				{ __( 'Looking', 'iminify-image-optimizer' ) }
			</Chip>
		);
	}

	return (
		<Panel>
			<PanelTitle icon="folder" aside={ chip }>
				{ __( 'Folders', 'iminify-image-optimizer' ) }
			</PanelTitle>
			{ 0 === listed.length ? (
				<p className="iminify-text">
					{ __(
						'Choose your theme or any folder in wp-content, and Iminify optimizes the images in it where they are, keeping a backup of each.',
						'iminify-image-optimizer'
					) }
				</p>
			) : (
				<ul className="iminify-files">
					{ listed.map( ( folder ) => (
						<li
							key={ folder }
							className="iminify-file iminify-file--one"
						>
							<span className="iminify-file__path">
								<bdi dir="ltr">{ folder }</bdi>
							</span>
							<div className="iminify-file__actions">
								<Button
									variant="ghost"
									size="small"
									disabled={ busy }
									onClick={ () => onRemove( folder ) }
								>
									{ __(
										'Remove',
										'iminify-image-optimizer'
									) }
								</Button>
							</div>
						</li>
					) ) }
				</ul>
			) }
			{ choices.length > 0 && (
				<div className="iminify-actions">
					{ choices.map( ( folder ) => (
						<Button
							key={ folder.path }
							icon="folder"
							disabled={ busy }
							onClick={ () => onAdd( folder.path ) }
						>
							{ 'theme' === folder.kind
								? sprintf(
										/* translators: %s: the theme's name. */
										__(
											'Add your theme, %s',
											'iminify-image-optimizer'
										),
										folder.name
									)
								: sprintf(
										/* translators: %s: the parent theme's name. */
										__(
											'Add its parent theme, %s',
											'iminify-image-optimizer'
										),
										folder.name
									) }
						</Button>
					) ) }
				</div>
			) }
			<form className="iminify-section" onSubmit={ add }>
				<label className="iminify-label" htmlFor="iminify-folder">
					{ __(
						'Another folder in wp-content',
						'iminify-image-optimizer'
					) }
				</label>
				<div className="iminify-inline">
					<input
						id="iminify-folder"
						className="iminify-input"
						type="text"
						dir="ltr"
						placeholder="themes/your-theme/assets"
						value={ named }
						onChange={ ( event ) => setNamed( event.target.value ) }
					/>
					<Button
						type="submit"
						variant="primary"
						disabled={ busy || '' === named.trim() }
					>
						{ __( 'Add', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			</form>
			{ listed.length > 0 && (
				<div className="iminify-section">
					<p className="iminify-text">
						{ scan && ! scan.running && scan.at
							? sprintf(
									/* translators: 1: when, 2: how many images, 3: how many were new, 4: how many changed. */
									__(
										'Last looked on %1$s: %2$s images, %3$s new, %4$s changed.',
										'iminify-image-optimizer'
									),
									scan.at,
									scan.found,
									scan.added,
									scan.changed
								)
							: __(
									'Iminify looks again after a theme or plugin update. Look now after changing images by hand.',
									'iminify-image-optimizer'
								) }
					</p>
					<div className="iminify-actions">
						<Button
							icon="restore"
							busy={ Boolean( scan?.running ) }
							disabled={ busy }
							onClick={ onScan }
						>
							{ __(
								'Look for new and changed images',
								'iminify-image-optimizer'
							) }
						</Button>
					</div>
				</div>
			) }
		</Panel>
	);
}

/**
 * What the folders hold, what the optimized images saved, and where to optimize or restore them all.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
function Summary( { state } ) {
	const { counts, numbers, saved, links, run } = state;
	const labels = {
		optimized: __( 'Optimized', 'iminify-image-optimizer' ),
		not_optimized: __( 'Not optimized', 'iminify-image-optimizer' ),
		waiting: __( 'On their way', 'iminify-image-optimizer' ),
		failed: __( 'Failed', 'iminify-image-optimizer' ),
		skipped: __( 'Skipped', 'iminify-image-optimizer' ),
		restored: __( 'Restored', 'iminify-image-optimizer' ),
	};

	if ( 0 === counts.files ) {
		return null;
	}

	return (
		<Panel>
			<div className="iminify-panel__head">
				<h2>{ __( 'In your folders', 'iminify-image-optimizer' ) }</h2>
				<span className="iminify-label">
					{ sprintf(
						/* translators: %s: how many images the folders hold. */
						_n(
							'%s image',
							'%s images',
							counts.files,
							'iminify-image-optimizer'
						),
						numbers.files
					) }
				</span>
			</div>
			<ul className="iminify-rows">
				{ Object.keys( labels )
					.filter(
						( key ) =>
							! [ 'skipped', 'restored' ].includes( key ) ||
							counts[ key ] > 0
					)
					.map( ( key ) => (
						<li key={ key }>
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
						{ __( 'Saved so far', 'iminify-image-optimizer' ) }
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
			<p className="iminify-text">
				{ run
					? __(
							'A bulk run over these images is going on the bulk page.',
							'iminify-image-optimizer'
						)
					: __(
							'Optimize or restore them all on the bulk page.',
							'iminify-image-optimizer'
						) }
			</p>
			<div className="iminify-actions">
				<a
					className="iminify-btn iminify-btn--secondary"
					href={ links.bulk }
				>
					{ __(
						'Go to Bulk Optimization',
						'iminify-image-optimizer'
					) }
					<Icon name="arrow" flip />
				</a>
			</div>
		</Panel>
	);
}

/**
 * The chip for an image: where it stands in a word or two.
 *
 * @param {Object} file The image.
 * @return {{ label: string, tone: string, moving: boolean }} The chip.
 */
function chipFor( file ) {
	switch ( file.status ) {
		case 'optimized':
			return {
				label: __( 'Optimized', 'iminify-image-optimizer' ),
				tone: 'success',
				moving: false,
			};
		case 'queued':
		case 'waiting':
			return {
				label: __( 'On its way', 'iminify-image-optimizer' ),
				tone: 'accent',
				moving: true,
			};
		case 'paused':
			return {
				label: __(
					'Waiting for the next batch',
					'iminify-image-optimizer'
				),
				tone: 'warning',
				moving: false,
			};
		case 'parked':
			return {
				label: __( 'Waiting to connect', 'iminify-image-optimizer' ),
				tone: 'warning',
				moving: false,
			};
		case 'error':
			return {
				label: __( 'Failed', 'iminify-image-optimizer' ),
				tone: 'danger',
				moving: false,
			};
		case 'skipped':
			return {
				label: __( 'Skipped', 'iminify-image-optimizer' ),
				tone: 'neutral',
				moving: false,
			};
		case 'restored':
			return {
				label: __( 'Restored', 'iminify-image-optimizer' ),
				tone: 'neutral',
				moving: false,
			};
		default:
			return {
				label: __( 'Not optimized', 'iminify-image-optimizer' ),
				tone: 'neutral',
				moving: false,
			};
	}
}

/**
 * The images in the chosen folders, a page at a time, with a filter and what each can do.
 *
 * @param {Object}                   props            The component's props.
 * @param {Object}                   props.state      The page's state.
 * @param {boolean}                  props.busy       Whether a press is on its way.
 * @param {string}                   props.filter     The filter showing.
 * @param {(filter: string) => void} props.onFilter   Shows another filter.
 * @param {(page: number) => void}   props.onPage     Shows another page.
 * @param {(id: number) => void}     props.onOptimize Optimizes an image.
 * @param {(id: number) => void}     props.onRestore  Restores an image.
 */
function Files( {
	state,
	busy,
	filter,
	onFilter,
	onPage,
	onOptimize,
	onRestore,
} ) {
	const { files, counts, connected, listed } = state;
	const filters = {
		all: __( 'All', 'iminify-image-optimizer' ),
		optimized: __( 'Optimized', 'iminify-image-optimizer' ),
		not_optimized: __( 'Not optimized', 'iminify-image-optimizer' ),
		waiting: __( 'On their way', 'iminify-image-optimizer' ),
		failed: __( 'Failed', 'iminify-image-optimizer' ),
	};

	if ( 0 === listed.length ) {
		return (
			<Panel>
				<h2>{ __( 'Images', 'iminify-image-optimizer' ) }</h2>
				<Empty icon="folder">
					<p>
						{ __(
							'Add a folder to see its images here.',
							'iminify-image-optimizer'
						) }
					</p>
				</Empty>
			</Panel>
		);
	}

	return (
		<Panel>
			<div className="iminify-panel__head">
				<h2>{ __( 'Images', 'iminify-image-optimizer' ) }</h2>
				<span className="iminify-label">
					{ sprintf(
						/* translators: %s: how many images the filter shows. */
						_n(
							'%s image',
							'%s images',
							files.total,
							'iminify-image-optimizer'
						),
						formatNumber( files.total )
					) }
				</span>
			</div>
			{ counts.files > 0 && (
				<div className="iminify-tabs">
					{ Object.keys( filters ).map( ( key ) => (
						<button
							key={ key }
							type="button"
							className="iminify-tab"
							aria-pressed={ key === filter }
							onClick={ () => onFilter( key ) }
						>
							{ filters[ key ] }
						</button>
					) ) }
				</div>
			) }
			{ 0 === files.rows.length ? (
				<Empty>
					<p>
						{ 'all' === filter
							? __(
									'No images were found in these folders.',
									'iminify-image-optimizer'
								)
							: __(
									'No image here.',
									'iminify-image-optimizer'
								) }
					</p>
				</Empty>
			) : (
				<ul className="iminify-files">
					{ files.rows.map( ( file ) => (
						<FileRow
							key={ file.id }
							file={ file }
							connected={ connected }
							busy={ busy }
							onOptimize={ onOptimize }
							onRestore={ onRestore }
						/>
					) ) }
				</ul>
			) }
			{ files.pages > 1 && (
				<div className="iminify-pager">
					<Button
						size="small"
						variant="ghost"
						disabled={ busy || files.page <= 1 }
						onClick={ () => onPage( files.page - 1 ) }
					>
						{ __( 'Previous', 'iminify-image-optimizer' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: this page, 2: how many pages. */
							__(
								'Page %1$s of %2$s',
								'iminify-image-optimizer'
							),
							files.page,
							files.pages
						) }
					</span>
					<Button
						size="small"
						variant="ghost"
						iconEnd="arrow"
						disabled={ busy || files.page >= files.pages }
						onClick={ () => onPage( files.page + 1 ) }
					>
						{ __( 'Next', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			) }
		</Panel>
	);
}

/**
 * One image in the list: its path, where it stands, what it can do, and the
 * restore asked in the row before it starts, which gives focus back to its
 * button when it closes.
 *
 * @param {Object}               props            The component's props.
 * @param {Object}               props.file       The image.
 * @param {boolean}              props.connected  Whether the site is connected.
 * @param {boolean}              props.busy       Whether a press is on its way.
 * @param {(id: number) => void} props.onOptimize Optimizes it.
 * @param {(id: number) => void} props.onRestore  Restores it.
 */
function FileRow( { file, connected, busy, onOptimize, onRestore } ) {
	const { asking, open, close, opener } = useAsk();
	const chip = chipFor( file );
	const optimizable =
		connected && ! file.on_its_way && 'skipped' !== file.status;

	return (
		<li className="iminify-file">
			<span className="iminify-file__path">
				<bdi dir="ltr">{ file.path }</bdi>
			</span>
			<span className="iminify-file__meta">
				<Chip tone={ chip.tone } moving={ chip.moving }>
					{ chip.label }
				</Chip>
				<span>{ file.size }</span>
				{ file.saved && (
					<span>
						{ sprintf(
							/* translators: %s: the share saved, such as "38.2". */
							__( '%s%% smaller', 'iminify-image-optimizer' ),
							file.saved
						) }
					</span>
				) }
				{ file.formats.length > 0 && (
					<span>{ file.formats.join( ', ' ) }</span>
				) }
			</span>
			<div className="iminify-file__actions">
				{ optimizable && (
					<Button
						size="small"
						disabled={ busy }
						onClick={ () => onOptimize( file.id ) }
					>
						{ 'optimized' === file.status
							? __( 'Optimize again', 'iminify-image-optimizer' )
							: __( 'Optimize', 'iminify-image-optimizer' ) }
					</Button>
				) }
				{ file.can_restore && ! asking && (
					<Button
						ref={ opener }
						size="small"
						variant="ghost"
						disabled={ busy }
						onClick={ open }
					>
						{ __( 'Restore', 'iminify-image-optimizer' ) }
					</Button>
				) }
			</div>
			{ file.reason && (
				<p className="iminify-file__why">{ file.reason }</p>
			) }
			{ asking && (
				<Confirm
					question={ __(
						'Put the original back? Its WebP and AVIF copies are deleted; the backup stays.',
						'iminify-image-optimizer'
					) }
					yes={ __( 'Restore', 'iminify-image-optimizer' ) }
					busy={ busy }
					onYes={ () => {
						close();
						onRestore( file.id );
					} }
					onNo={ close }
				/>
			) }
		</li>
	);
}
