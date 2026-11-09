/**
 * The attachment panel's compare: the original and an optimized file on one
 * stage, the original on the left of a line the person drags, or moves with
 * the arrow keys, and each WebP or AVIF copy a press away. Every word and
 * figure comes from the plugin's route; the stage keeps left to right in every
 * language, since it shows two pictures, not text. It opens over the media
 * modal, keeps every key it gets from that modal (whose arrows move to another
 * image on older WordPress), and closes with Escape without closing it.
 */
import apiFetch from '@wordpress/api-fetch';

const STEP = 5;

/**
 * Makes an element with a class and, optionally, its text.
 *
 * @param {string} tag       The element.
 * @param {string} className Its class.
 * @param {string} text      Its text.
 * @return {HTMLElement} The element.
 */
function make( tag, className, text = '' ) {
	const element = document.createElement( tag );

	element.className = className;

	if ( text ) {
		element.textContent = text;
	}

	return element;
}

/**
 * The files a dialog shows, each fetched as itself once. A site whose server
 * rules send a WebP or AVIF copy in place of an image to a browser that reads
 * it would otherwise show the copy on the original's side, so each file is
 * fetched asking for any type, which no such rule answers with a copy, and
 * shown from memory, the address itself being the fallback. Closing the dialog
 * stops what is still on its way and lets go of what came.
 *
 * @return {{ load: (image: HTMLImageElement, url: string) => void, release: () => void }} The loader.
 */
function files() {
	const abort = new window.AbortController();
	const shown = new Map();

	const put = ( image, url, source ) => {
		if ( image.dataset.address === url ) {
			image.src = source;
		}
	};

	return {
		load( image, url ) {
			image.dataset.address = url;
			delete image.dataset.failed;

			if ( shown.has( url ) ) {
				put( image, url, shown.get( url ) );

				return;
			}

			window
				.fetch( url, {
					credentials: 'same-origin',
					headers: { Accept: '*/*' },
					signal: abort.signal,
				} )
				.then( ( answer ) => {
					if ( ! answer.ok ) {
						throw new Error( String( answer.status ) );
					}

					return answer.blob();
				} )
				.then( ( blob ) => {
					if ( abort.signal.aborted ) {
						return;
					}

					shown.set( url, window.URL.createObjectURL( blob ) );
					put( image, url, shown.get( url ) );
				} )
				.catch( () => {
					if ( ! abort.signal.aborted ) {
						put( image, url, url );
					}
				} );
		},
		release() {
			abort.abort();
			shown.forEach( ( source ) => window.URL.revokeObjectURL( source ) );
			shown.clear();
		},
	};
}

/**
 * Builds the dialog for what the route answered.
 *
 * @param {Object}      compare The route's answer.
 * @param {HTMLElement} opener  The button that opened it, given focus back on close.
 */
function show( compare, opener ) {
	const loader = files();
	const attachment = opener.dataset.iminifyCompare;
	/* The optimized file is drawn upright, while a phone's original can keep its turn in its EXIF, which the browser applies. */
	const shape = compare.after[ 0 ];
	const root = make( 'div', 'iminify-media iminify-compare' );
	const box = make( 'div', 'iminify-compare__box' );
	const head = make( 'div', 'iminify-compare__head' );
	const title = make( 'h2', 'iminify-compare__title', compare.title );
	const close = make(
		'button',
		'iminify-btn iminify-btn--ghost iminify-btn--small',
		compare.close
	);
	const formats = make( 'div', 'iminify-actions iminify-compare__formats' );
	const figure = make( 'div', 'iminify-compare__figure' );
	const stage = make( 'div', 'iminify-compare__stage' );
	const after = make( 'img', 'iminify-compare__after' );
	const cover = make( 'div', 'iminify-compare__before' );
	const before = make( 'img', '' );
	const handle = make( 'div', 'iminify-compare__handle' );
	const tags = make( 'div', 'iminify-compare__tags' );
	const beforeTag = make(
		'span',
		'iminify-compare__tag',
		compare.before.label
	);
	const afterTag = make( 'span', 'iminify-compare__tag' );
	const note = make( 'p', 'iminify-detail', compare.note );
	const failed = make( 'p', 'iminify-refusal', compare.failed );
	let position = 50;

	root.setAttribute( 'role', 'dialog' );
	root.setAttribute( 'aria-modal', 'true' );
	title.id = 'iminify-compare-title';
	root.setAttribute( 'aria-labelledby', title.id );
	/* A press on its text keeps focus, and so the keys, inside the dialog. */
	box.tabIndex = -1;
	close.type = 'button';
	stage.setAttribute( 'dir', 'ltr' );
	stage.style.aspectRatio = `${ shape.width } / ${ shape.height }`;
	/* As wide as the box allows, and no taller than most of the screen. */
	figure.style.maxWidth = `calc(62vh * ${ shape.width } / ${ shape.height })`;
	before.alt = '';
	after.alt = '';
	handle.tabIndex = 0;
	handle.setAttribute( 'role', 'slider' );
	handle.setAttribute( 'aria-label', compare.slider );
	handle.setAttribute( 'aria-valuemin', '0' );
	handle.setAttribute( 'aria-valuemax', '100' );
	tags.setAttribute( 'dir', 'ltr' );
	failed.hidden = true;
	failed.setAttribute( 'role', 'alert' );
	formats.setAttribute( 'role', 'group' );
	formats.setAttribute( 'aria-label', compare.formats );
	formats.hidden = compare.after.length < 2;

	const place = ( value ) => {
		position = Math.min( 100, Math.max( 0, value ) );
		cover.style.clipPath = `inset(0 ${ 100 - position }% 0 0)`;
		handle.style.left = `${ position }%`;
		handle.setAttribute(
			'aria-valuenow',
			String( Math.round( position ) )
		);
		handle.setAttribute( 'aria-valuetext', `${ Math.round( position ) }%` );
	};

	/* Either side that couldn't be loaded keeps the alert up, whichever file the other side shows. */
	const fail = () => {
		failed.hidden = ! before.dataset.failed && ! after.dataset.failed;
	};

	const choose = ( index ) => {
		const file = compare.after[ index ];

		loader.load( after, file.url );
		afterTag.textContent = file.label;
		fail();
		formats
			.querySelectorAll( 'button' )
			.forEach( ( button, at ) =>
				button.setAttribute( 'aria-pressed', String( at === index ) )
			);
	};

	compare.after.forEach( ( file, index ) => {
		const button = make(
			'button',
			'iminify-btn iminify-btn--secondary iminify-btn--small',
			file.label
		);

		button.type = 'button';
		button.addEventListener( 'click', () => choose( index ) );
		formats.append( button );
	} );

	const fromPointer = ( event ) => {
		const bounds = stage.getBoundingClientRect();

		place( ( 100 * ( event.clientX - bounds.left ) ) / bounds.width );
	};

	stage.addEventListener( 'pointerdown', ( event ) => {
		/* The browser would otherwise move focus to the page for this press, and the keys with it. */
		event.preventDefault();
		stage.setPointerCapture( event.pointerId );
		fromPointer( event );
		handle.focus();
	} );
	stage.addEventListener( 'pointermove', ( event ) => {
		if ( stage.hasPointerCapture( event.pointerId ) ) {
			fromPointer( event );
		}
	} );
	handle.addEventListener( 'keydown', ( event ) => {
		const moves = {
			ArrowLeft: position - STEP,
			ArrowDown: position - STEP,
			ArrowRight: position + STEP,
			ArrowUp: position + STEP,
			Home: 0,
			End: 100,
		};

		if ( event.key in moves ) {
			event.preventDefault();
			place( moves[ event.key ] );
		}
	} );
	[ before, after ].forEach( ( image ) => {
		image.addEventListener( 'error', () => {
			image.dataset.failed = '1';
			fail();
		} );
		image.addEventListener( 'load', () => {
			delete image.dataset.failed;
			fail();
		} );
	} );

	/* Focus that leaves the dialog comes back to it, so the media modal behind it can't be reached while it is open. */
	const keepFocus = ( event ) => {
		if ( ! root.contains( event.target ) ) {
			close.focus();
		}
	};

	const shut = () => {
		root.remove();
		document.removeEventListener( 'focusin', keepFocus );
		loader.release();

		/* The panel may have been drawn again meanwhile, its button with it. */
		const button = opener.isConnected
			? opener
			: document.querySelector(
					`[data-iminify-compare="${ attachment }"]`
				);

		button?.focus();
	};

	close.addEventListener( 'click', shut );
	root.addEventListener( 'keydown', ( event ) => {
		event.stopPropagation();

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			shut();
		}
	} );
	root.addEventListener( 'click', ( event ) => {
		if ( event.target === root ) {
			shut();
		}
	} );

	cover.append( before );
	stage.append( after, cover, handle );
	tags.append( beforeTag, afterTag );
	head.append( title, close );
	figure.append( stage, tags );
	box.append( head, formats, figure, failed, note );
	root.append( box );
	document.body.append( root );
	document.addEventListener( 'focusin', keepFocus );

	loader.load( before, compare.before.url );
	place( position );
	choose( 0 );
	handle.focus();
}

/**
 * Opens the compare of the attachment the button names, or says why it can't.
 *
 * @param {HTMLElement}                                    button The button pressed.
 * @param {(button: HTMLElement, message: string) => void} refuse Shows a refusal beside it.
 */
export default function openCompare( button, refuse ) {
	button.disabled = true;

	apiFetch( {
		path: `/iminify/v1/media/${ button.dataset.iminifyCompare }/compare`,
	} )
		.then( ( compare ) => show( compare, button ) )
		.catch( ( error ) => refuse( button, error?.message ?? '' ) )
		.finally( () => {
			button.disabled = false;
		} );
}
