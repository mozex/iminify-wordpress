/**
 * The design system's parts for the plugin's own pages. Every page is built
 * from these, so the screens look and move as one: buttons, status chips,
 * panels, the progress bar and ring, callouts, the inline confirm, the shell
 * command with its Copy button, toasts, and the loading, empty and error
 * states. The styles are `_tokens.scss`, `_parts.scss` and `_page.scss` beside
 * this file, which a page's stylesheet takes through `screens.scss`.
 */
import {
	createPortal,
	forwardRef,
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, isRTL } from '@wordpress/i18n';

const PATHS = {
	check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
	pause: <path d="M9 5.5v13M15 5.5v13" />,
	play: <path d="M8 5.5l11 6.5-11 6.5z" />,
	stop: <rect x="6" y="6" width="12" height="12" rx="2" />,
	alert: (
		<>
			<path d="M12 3.5l9.5 17h-19z" />
			<path d="M12 10v4.5M12 17.6v.1" />
		</>
	),
	clock: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M12 7.5V12l3 2" />
		</>
	),
	disk: (
		<>
			<rect x="3.5" y="13" width="17" height="7" rx="2" />
			<path d="M3.5 13l3-7.5h11l3 7.5M7.5 16.5h.1" />
		</>
	),
	moon: <path d="M19.5 14.5A7.5 7.5 0 0 1 9.5 4.5a7.5 7.5 0 1 0 10 10z" />,
	screen: (
		<>
			<rect x="3.5" y="4.5" width="17" height="11.5" rx="2" />
			<path d="M8.5 20h7M12 16v4" />
		</>
	),
	restore: (
		<>
			<path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3" />
			<path d="M4.5 4.5v4h4" />
		</>
	),
	plug: (
		<>
			<path d="M9 3.5v4.5M15 3.5v4.5M6.5 8h11v3a5.5 5.5 0 0 1-11 0z" />
			<path d="M12 16.5v4" />
		</>
	),
	arrow: <path d="M5 12h13M13 6.5l5.5 5.5-5.5 5.5" />,
	send: (
		<>
			<path d="M4 12h12M11.5 6.5L17 12l-5.5 5.5" />
			<path d="M20 5v14" />
		</>
	),
	image: (
		<>
			<rect x="3.5" y="4.5" width="17" height="15" rx="3" />
			<circle cx="9" cy="10" r="1.8" />
			<path d="M20.5 16l-5-5-9.5 8.5" />
		</>
	),
	close: <path d="M6.5 6.5l11 11M17.5 6.5l-11 11" />,
	external: (
		<>
			<path d="M13.5 5.5h5v5" />
			<path d="M18.5 5.5L11 13" />
			<path d="M16.5 14v3.5a1.5 1.5 0 0 1-1.5 1.5H7A1.5 1.5 0 0 1 5.5 17.5V9.5A1.5 1.5 0 0 1 7 8h3.5" />
		</>
	),
	key: (
		<>
			<circle cx="8" cy="15" r="3.5" />
			<path d="M10.5 12.5L19 4M16 7l2.5 2.5M14 9l2 2" />
		</>
	),
	queue: <path d="M5 7h14M5 12h14M5 17h9" />,
	folder: (
		<path d="M3.5 7.5a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z" />
	),
	sliders: (
		<>
			<path d="M5 7h8M17 7h2M5 17h2M11 17h8" />
			<circle cx="15" cy="7" r="2" />
			<circle cx="9" cy="17" r="2" />
		</>
	),
	server: (
		<>
			<rect x="4" y="4.5" width="16" height="6.5" rx="2" />
			<rect x="4" y="13" width="16" height="6.5" rx="2" />
			<path d="M8 7.75h.1M8 16.25h.1" />
		</>
	),
};

/**
 * One of the design's icons, mirrored in a right-to-left language when it points a way.
 *
 * @param {Object}  props      The component's props.
 * @param {string}  props.name The icon.
 * @param {boolean} props.flip Whether it points a way, as an arrow does.
 */
export function Icon( { name, flip = false } ) {
	return (
		<svg
			className="iminify-icon"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
			style={ flip && isRTL() ? { transform: 'scaleX(-1)' } : undefined }
		>
			{ PATHS[ name ] }
		</svg>
	);
}

/**
 * A button in one of the design's variants, with a spinner while its press is on its way.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.variant  `primary`, `secondary` or `ghost`.
 * @param {string}         props.size     `big`, `small`, or none.
 * @param {boolean}        props.danger   Whether it destroys something.
 * @param {boolean}        props.busy     Whether its press is on its way.
 * @param {string}         props.icon     The icon before its words.
 * @param {string}         props.iconEnd  The icon after its words, mirrored right to left.
 * @param {Element|string} props.children Its words.
 * @param {Object}         ref            Its element, for focus.
 */
export const Button = forwardRef( function IminifyButton(
	{
		variant = 'secondary',
		size = '',
		danger = false,
		busy = false,
		icon = '',
		iconEnd = '',
		children,
		className = '',
		type = 'button',
		...rest
	},
	ref
) {
	const classes = [
		'iminify-btn',
		`iminify-btn--${ variant }`,
		size && `iminify-btn--${ size }`,
		danger && 'iminify-btn--danger',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<button ref={ ref } type={ type } className={ classes } { ...rest }>
			{ busy ? (
				<span className="iminify-spinner" aria-hidden="true" />
			) : (
				icon && <Icon name={ icon } />
			) }
			{ children }
			{ iconEnd && <Icon name={ iconEnd } flip /> }
		</button>
	);
} );

/**
 * A status chip.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.tone     `accent`, `info`, `success`, `warning`, `danger` or `neutral`.
 * @param {string}         props.icon     Its icon, or none for a dot.
 * @param {boolean}        props.moving   Whether the dot pulses, for something under way.
 * @param {Element|string} props.children Its words.
 */
export function Chip( {
	tone = 'neutral',
	icon = '',
	moving = false,
	children,
} ) {
	return (
		<span
			className={ `iminify-chip iminify-chip--${ tone }${
				moving ? ' iminify-chip--moving' : ''
			}` }
		>
			{ icon ? (
				<Icon name={ icon } />
			) : (
				<span className="iminify-chip__dot" />
			) }
			{ children }
		</span>
	);
}

/**
 * A panel: the card every section of a page sits on.
 *
 * @param {Object}         props           The component's props.
 * @param {string}         props.className More classes.
 * @param {Element|string} props.children  What it holds.
 */
export function Panel( { className = '', children, ...rest } ) {
	return (
		<section
			className={ `iminify-panel${ className ? ` ${ className }` : '' }` }
			{ ...rest }
		>
			{ children }
		</section>
	);
}

/**
 * A panel's heading row: an icon, the title, and whatever sits at its end.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.icon     The icon beside the title.
 * @param {Element|string} props.aside    What sits at the row's end, such as a chip.
 * @param {Element|string} props.children The title.
 */
export function PanelTitle( { icon = '', aside = null, children } ) {
	return (
		<div className="iminify-panel__head">
			<div className="iminify-panel__title">
				{ icon && (
					<span className="iminify-panel__icon">
						<Icon name={ icon } />
					</span>
				) }
				<h2>{ children }</h2>
			</div>
			{ aside }
		</div>
	);
}

/**
 * A callout: something to read before going on.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.tone     `warning`, `danger`, `info` or `success`.
 * @param {string}         props.icon     Its icon.
 * @param {string}         props.role     `alert` for a refusal that just happened.
 * @param {Element|string} props.children What it says.
 */
export function Callout( {
	tone = 'warning',
	icon = 'alert',
	role,
	children,
} ) {
	return (
		<div
			className={ `iminify-callout iminify-callout--${ tone }` }
			role={ role }
		>
			<Icon name={ icon } />
			<div className="iminify-callout__body">{ children }</div>
		</div>
	);
}

/**
 * Whether the person asked their system for less motion.
 */
export function reducedMotion() {
	return (
		'function' === typeof window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	);
}

/**
 * Groups numbers as the admin's language does, in the same Western digits
 * WordPress prints on the server, so a number counted here matches the ones
 * beside it. A language tag the browser can't read falls back to its own.
 */
const numbers = ( () => {
	try {
		return new Intl.NumberFormat(
			document.documentElement.lang || undefined,
			{
				numberingSystem: 'latn',
			}
		);
	} catch {
		return new Intl.NumberFormat( undefined, { numberingSystem: 'latn' } );
	}
} )();

/**
 * A number with the grouping of the admin's language, such as 1,212.
 *
 * @param {number} value The number.
 * @return {string} The number in words' company.
 */
export function formatNumber( value ) {
	return numbers.format( value );
}

/**
 * A number that counts to its new value when it changes.
 *
 * @param {Object} props       The component's props.
 * @param {number} props.value The number.
 * @param {string} props.text  The number as the server formatted it, shown once the count stops.
 */
export function Count( { value, text } ) {
	const [ shown, setShown ] = useState( value );
	const from = useRef( value );

	useEffect( () => {
		if ( from.current === value || reducedMotion() ) {
			from.current = value;
			setShown( value );

			return undefined;
		}

		const start = from.current;
		const began = window.performance.now();
		let frame;

		const step = ( now ) => {
			const done = Math.min( 1, ( now - began ) / 450 );
			const next = Math.round(
				start + ( value - start ) * ( 1 - Math.pow( 1 - done, 3 ) )
			);

			from.current = next;
			setShown( next );

			if ( done < 1 ) {
				frame = window.requestAnimationFrame( step );
			}
		};

		frame = window.requestAnimationFrame( step );

		return () => window.cancelAnimationFrame( frame );
	}, [ value ] );

	return (
		<span className="iminify-num">
			{ shown === value && text ? text : formatNumber( shown ) }
		</span>
	);
}

/**
 * A progress bar.
 *
 * @param {Object}  props         The component's props.
 * @param {number}  props.value   How far, from 0 to 100.
 * @param {string}  props.label   What it measures, for screen readers.
 * @param {boolean} props.moving  Whether it is moving, which draws a sheen across it.
 * @param {boolean} props.restore Whether it measures a restore, drawn in grey.
 */
export function ProgressBar( {
	value,
	label,
	moving = false,
	restore = false,
} ) {
	return (
		<div
			className={ `iminify-progress${
				moving ? ' iminify-progress--moving' : ''
			}${ restore ? ' iminify-progress--restore' : '' }` }
			role="progressbar"
			aria-label={ label }
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuenow={ value }
		>
			<i
				className="iminify-progress__fill"
				style={ { width: `${ value }%` } }
			/>
		</div>
	);
}

/**
 * A ring that fills clockwise from the top, in every language, as a clock does.
 *
 * @param {Object} props         The component's props.
 * @param {number} props.value   How far, from 0 to 100.
 * @param {string} props.label   What it measures, for screen readers.
 * @param {string} props.caption The word under the number.
 */
export function Ring( { value, label, caption } ) {
	const gradient = `iminify-ring-${ useId().replace( /[^a-z0-9]/gi, '' ) }`;
	const circle = 2 * Math.PI * 54;

	return (
		<div
			className="iminify-ring"
			role="progressbar"
			aria-label={ label }
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuenow={ value }
		>
			<svg viewBox="0 0 132 132" aria-hidden="true" focusable="false">
				<defs>
					<linearGradient id={ gradient } x1="0" y1="0" x2="1" y2="1">
						<stop offset="0" stopColor="#38bdf8" />
						<stop offset="1" stopColor="#0369a1" />
					</linearGradient>
				</defs>
				<circle
					className="iminify-ring__track"
					cx="66"
					cy="66"
					r="54"
				/>
				<circle
					className="iminify-ring__value"
					cx="66"
					cy="66"
					r="54"
					transform="rotate(-90 66 66)"
					stroke={ `url(#${ gradient })` }
					strokeDasharray={ circle.toFixed( 1 ) }
					strokeDashoffset={ ( circle * ( 1 - value / 100 ) ).toFixed(
						1
					) }
					style={ value > 0 ? undefined : { opacity: 0 } }
				/>
			</svg>
			<div className="iminify-ring__mid" aria-hidden="true">
				<b>{ value }%</b>
				<span>{ caption }</span>
			</div>
		</div>
	);
}

/**
 * A shell command, or lines to paste into a file, with a button that copies it.
 *
 * @param {Object}  props         The component's props.
 * @param {string}  props.command The command or the lines.
 * @param {boolean} props.block   Whether it is lines to keep as they are.
 */
export function CodeCopy( { command, block = false } ) {
	const [ copied, setCopied ] = useState( false );

	const copy = () => {
		window.navigator.clipboard
			?.writeText( command )
			.then( () => setCopied( true ) )
			.catch( () => {} );
	};

	return (
		<div
			className={
				block ? 'iminify-code iminify-code--block' : 'iminify-code'
			}
		>
			{ /* A shell command reads left to right in every language. */ }
			<code dir="ltr">{ command }</code>
			{ window.navigator.clipboard && (
				<button type="button" onClick={ copy } aria-live="polite">
					{ copied
						? __( 'Copied', 'iminify-image-optimizer' )
						: __( 'Copy', 'iminify-image-optimizer' ) }
				</button>
			) }
		</div>
	);
}

/**
 * A yes or no asked in the page rather than in the browser's dialog. It opens
 * with focus on Cancel, the button that changes nothing, and Escape closes it;
 * closing it gives focus back to the button that opened it (`useAsk()`).
 *
 * @param {Object}     props          The component's props.
 * @param {string}     props.question What it asks.
 * @param {string}     props.yes      The words of the button that goes ahead.
 * @param {boolean}    props.busy     Whether the yes is on its way.
 * @param {() => void} props.onYes    Goes ahead.
 * @param {() => void} props.onNo     Closes it.
 */
export function Confirm( { question, yes, busy = false, onYes, onNo } ) {
	const cancel = useRef( null );
	const escape = ( event ) => 'Escape' === event.key && onNo();

	useEffect( () => cancel.current?.focus(), [] );

	return (
		<div className="iminify-confirm" role="group" aria-label={ question }>
			<p>{ question }</p>
			<div className="iminify-actions">
				<Button
					variant="primary"
					busy={ busy }
					disabled={ busy }
					onClick={ onYes }
					onKeyDown={ escape }
				>
					{ yes }
				</Button>
				<Button
					ref={ cancel }
					variant="ghost"
					disabled={ busy }
					onClick={ onNo }
					onKeyDown={ escape }
				>
					{ __( 'Cancel', 'iminify-image-optimizer' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Whether a confirm is open, with the button that opens it: closing the
 * confirm gives that button focus again, since the confirm took its place.
 *
 * @return {{ asking: boolean, open: () => void, close: () => void, opener: Object }} The confirm's state, and the opener's ref.
 */
export function useAsk() {
	const [ asking, setAsking ] = useState( false );
	const opener = useRef( null );

	const open = useCallback( () => setAsking( true ), [] );
	const close = useCallback( () => {
		setAsking( false );
		window.requestAnimationFrame( () => opener.current?.focus() );
	}, [] );

	return { asking, open, close, opener };
}

/**
 * A link that opens a page of iminify.com in a new tab, saying so to screen readers.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.href     Where it goes.
 * @param {Element|string} props.children Its words.
 */
export function ExternalLink( { href, children } ) {
	return (
		<a href={ href } target="_blank" rel="noopener noreferrer">
			{ children }
			<span className="iminify-sr">
				{
					/* translators: said by screen readers after a link's words. */
					__( '(opens in a new tab)', 'iminify-image-optimizer' )
				}
			</span>
			<Icon name="external" flip />
		</a>
	);
}

/**
 * A grey block the size of what is loading.
 *
 * @param {Object} props        The component's props.
 * @param {string} props.width  Its width.
 * @param {number} props.height Its height, in pixels.
 */
export function Skeleton( { width = '100%', height = 14 } ) {
	return <span className="iminify-skeleton" style={ { width, height } } />;
}

/**
 * The page while its first answer is on its way: panels in the shape of the page.
 *
 * @param {Object}  props      The component's props.
 * @param {boolean} props.side Whether the page has a side column.
 */
export function Loading( { side = true } ) {
	const panel = ( lines ) => (
		<div className="iminify-panel iminify-loading">
			<Skeleton width="38%" height={ 12 } />
			<Skeleton width="72%" height={ 24 } />
			{ lines.map( ( width, index ) => (
				<Skeleton key={ index } width={ width } />
			) ) }
		</div>
	);

	return (
		<div className="iminify-grid" aria-busy="true">
			<span className="iminify-sr" role="status">
				{ __( 'Loading…', 'iminify-image-optimizer' ) }
			</span>
			<div className="iminify-col">
				{ panel( [ '100%', '92%', '64%' ] ) }
				{ panel( [ '100%', '80%' ] ) }
			</div>
			{ side && (
				<div className="iminify-col">
					{ panel( [ '100%', '70%' ] ) }
				</div>
			) }
		</div>
	);
}

/**
 * What a page shows when its first answer failed, with a way to ask again.
 *
 * @param {Object}     props         The component's props.
 * @param {string}     props.message What went wrong.
 * @param {boolean}    props.busy    Whether the next try is on its way.
 * @param {() => void} props.onRetry Asks again.
 */
export function ErrorState( { message, busy, onRetry } ) {
	return (
		<Callout tone="danger" role="alert">
			<p>{ message }</p>
			<div>
				<Button
					variant="secondary"
					size="small"
					icon="restore"
					busy={ busy }
					disabled={ busy }
					onClick={ onRetry }
				>
					{ __( 'Try again', 'iminify-image-optimizer' ) }
				</Button>
			</div>
		</Callout>
	);
}

/**
 * Something with nothing to show yet.
 *
 * @param {Object}         props          The component's props.
 * @param {string}         props.icon     Its icon.
 * @param {Element|string} props.children What it says.
 */
export function Empty( { icon = 'image', children } ) {
	return (
		<div className="iminify-empty">
			<span className="iminify-empty__icon">
				<Icon name={ icon } />
			</span>
			{ children }
		</div>
	);
}

/**
 * Short-lived messages for things that went well. Refusals stay on the page instead.
 *
 * @return {{ toasts: Array, toast: (text: string) => void, dismiss: (id: number) => void }} The messages, and how to add and remove one.
 */
export function useToasts() {
	const [ toasts, setToasts ] = useState( [] );
	const next = useRef( 0 );

	const dismiss = useCallback(
		( id ) =>
			setToasts( ( current ) =>
				current.filter( ( toast ) => toast.id !== id )
			),
		[]
	);

	const toast = useCallback( ( text ) => {
		const id = ++next.current;

		setToasts( ( current ) => [
			...current.filter( ( kept ) => kept.text !== text ).slice( -2 ),
			{ id, text },
		] );
	}, [] );

	return { toasts, toast, dismiss };
}

/**
 * One toast, which goes by itself unless the pointer or focus is on it.
 *
 * @param {Object}               props           The component's props.
 * @param {Object}               props.toast     The message.
 * @param {(id: number) => void} props.onDismiss Removes it.
 */
function Toast( { toast, onDismiss } ) {
	const [ held, setHeld ] = useState( false );

	useEffect( () => {
		if ( held ) {
			return undefined;
		}

		const timer = window.setTimeout( () => onDismiss( toast.id ), 6000 );

		return () => window.clearTimeout( timer );
	}, [ held, toast.id, onDismiss ] );

	return (
		<li
			className="iminify-toast"
			onMouseEnter={ () => setHeld( true ) }
			onMouseLeave={ () => setHeld( false ) }
			onFocus={ () => setHeld( true ) }
			onBlur={ () => setHeld( false ) }
		>
			<Icon name="check" />
			<p>{ toast.text }</p>
			<button
				type="button"
				onClick={ () => onDismiss( toast.id ) }
				aria-label={ __( 'Dismiss', 'iminify-image-optimizer' ) }
			>
				<Icon name="close" />
			</button>
		</li>
	);
}

/**
 * The page's toasts, drawn over the admin in the end corner and read out politely.
 *
 * @param {Object}               props           The component's props.
 * @param {Array}                props.toasts    The messages.
 * @param {(id: number) => void} props.onDismiss Removes one.
 */
export function Toasts( { toasts, onDismiss } ) {
	return createPortal(
		<ul className="iminify-toasts" role="status" aria-live="polite">
			{ toasts.map( ( toast ) => (
				<Toast
					key={ toast.id }
					toast={ toast }
					onDismiss={ onDismiss }
				/>
			) ) }
		</ul>,
		document.body
	);
}

/**
 * Keeps the header's connection pill, which the server printed, in step with
 * the page once its state has come; until then the server's pill stands.
 *
 * @param {string|null} state `on`, `off`, `waiting` or `warning`, or null before the page's state came.
 * @param {string}      text  Its words.
 */
export function useHeaderStatus( state, text ) {
	useEffect( () => {
		const pill = document.getElementById( 'iminify-conn' );
		const words = pill?.querySelector( '.iminify-conn__text' );

		if ( state && pill && words ) {
			pill.dataset.state = state;
			words.textContent = text;
		}
	}, [ state, text ] );
}
