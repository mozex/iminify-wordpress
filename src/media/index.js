/**
 * The media library's buttons: each press goes to the plugin's REST routes, and
 * the markup they answer replaces the attachment's cell or panel. Every word on
 * screen comes from the server, except the confirm's Cancel. A press that writes
 * files or counts an image is asked first, inside the cell or panel, in the
 * design system's look. The panel can appear at any time in the grid's modal,
 * so events are read from the document.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';

import './media.scss';

const BASE = '/iminify/v1/media';
const POLL_EVERY_MS = 10000;

/**
 * Puts an attachment's new markup in each place it shows.
 *
 * @param {{id: number, cell: string, panel: string}} answer The route's answer.
 */
function draw( answer ) {
	document
		.querySelectorAll( `[data-iminify-media="${ answer.id }"]` )
		.forEach( ( view ) => {
			view.outerHTML =
				'panel' === view.dataset.iminifyView
					? answer.panel
					: answer.cell;
		} );
}

/**
 * Where a button's confirm and refusal go: the cell or panel the button sits
 * in, or, for a row action, a place of the plugin's own right under the row's
 * actions, which shows at every width and with the Iminify column hidden.
 *
 * @param {HTMLElement} button The button.
 * @return {HTMLElement} The place.
 */
function placeOf( button ) {
	const view = button.closest( '[data-iminify-media]' );

	if ( view ) {
		return view;
	}

	const actions = button.closest( '.row-actions' ) ?? button.parentElement;
	const next = actions.nextElementSibling;

	if ( next?.classList.contains( 'iminify-row-place' ) ) {
		return next;
	}

	const place = document.createElement( 'div' );

	place.className = 'iminify-media iminify-row-place';
	actions.after( place );

	return place;
}

/**
 * Shows a refusal beside the button.
 *
 * @param {HTMLElement} button  The button pressed.
 * @param {string}      message The refusal.
 */
function refuse( button, message ) {
	const place = placeOf( button );
	const line = document.createElement( 'p' );

	line.className = 'iminify-refusal';
	line.setAttribute( 'role', 'alert' );
	line.textContent = message;

	place.querySelector( '.iminify-refusal' )?.remove();
	place.prepend( line );
}

/**
 * Asks before a press goes ahead, beside the button, with focus on Cancel;
 * Escape closes it without reaching the media modal, which would close too.
 *
 * @param {HTMLElement} button The button pressed.
 * @return {Promise<boolean>} Whether to go ahead.
 */
function ask( button ) {
	const { iminifyConfirm: question, iminifyYes: yes } = button.dataset;
	const place = placeOf( button );

	place.querySelector( '.iminify-confirm' )?.remove();

	return new Promise( ( resolve ) => {
		const box = document.createElement( 'div' );
		const text = document.createElement( 'p' );
		const actions = document.createElement( 'div' );
		const go = document.createElement( 'button' );
		const cancel = document.createElement( 'button' );

		box.className = 'iminify-confirm';
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-label', question );
		text.textContent = question;
		actions.className = 'iminify-actions';
		go.type = 'button';
		go.className = 'iminify-btn iminify-btn--primary iminify-btn--small';
		go.textContent = yes || button.textContent;
		cancel.type = 'button';
		cancel.className = 'iminify-btn iminify-btn--ghost iminify-btn--small';
		cancel.textContent = __( 'Cancel', 'iminify-image-optimizer' );

		const close = ( answer ) => {
			box.remove();

			if ( ! answer && button.isConnected ) {
				button.focus();
			}

			resolve( answer );
		};

		go.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			close( true );
		} );
		cancel.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			close( false );
		} );
		box.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close( false );
			}
		} );

		actions.append( go, cancel );
		box.append( text, actions );
		place.append( box );
		cancel.focus();
	} );
}

/**
 * The call a button asks for.
 *
 * @param {HTMLElement} button The button.
 * @return {Object} The request.
 */
function request( button ) {
	const { iminifyAction, iminifyAttachment, iminifyLevel, iminifyWhat } =
		button.dataset;
	const path = `${ BASE }/${ iminifyAttachment }/${ iminifyAction }`;

	if ( 'copies' === iminifyAction ) {
		return { path, method: 'DELETE' };
	}

	const data = {};

	if ( iminifyLevel ) {
		data.level = iminifyLevel;
	}

	if ( iminifyWhat ) {
		data.what = iminifyWhat;
	}

	return { path, method: 'POST', data };
}

function send( button ) {
	button.disabled = true;
	button.classList.add( 'is-busy' );

	apiFetch( request( button ) )
		.then( ( answer ) => {
			if ( button.dataset.iminifyReload ) {
				window.location.reload();
			} else {
				draw( answer );
			}
		} )
		.catch( ( error ) => {
			button.disabled = false;
			button.classList.remove( 'is-busy' );
			refuse( button, error?.message ?? '' );
		} );
}

function press( event ) {
	const button = event.target.closest( '[data-iminify-action]' );

	if ( ! button ) {
		return;
	}

	event.preventDefault();

	if ( ! button.dataset.iminifyConfirm ) {
		send( button );

		return;
	}

	ask( button ).then( ( yes ) => yes && send( button ) );
}

function poll() {
	const ids = [
		...new Set(
			[ ...document.querySelectorAll( '[data-iminify-busy]' ) ].map(
				( view ) => view.dataset.iminifyMedia
			)
		),
	].slice( 0, 100 );

	if ( 0 === ids.length ) {
		return;
	}

	apiFetch( {
		path: `${ BASE }?${ ids.map( ( id ) => `ids[]=${ id }` ).join( '&' ) }`,
	} )
		.then( ( answer ) => answer.items.forEach( draw ) )
		.catch( () => {} );
}

domReady( () => {
	document.addEventListener( 'click', press );
	window.setInterval( poll, POLL_EVERY_MS );
} );
