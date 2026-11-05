/**
 * The media library's buttons: each press goes to the plugin's REST routes, and
 * the markup they answer replaces the attachment's cell or panel. Every word on
 * screen comes from the server. The panel can appear at any time in the grid's
 * modal, so events are read from the document.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

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
 * Shows a refusal beside the attachment, or in a dialog where it isn't on screen.
 *
 * @param {string} id      The attachment's id.
 * @param {string} message The refusal.
 */
function refuse( id, message ) {
	const view = document.querySelector( `[data-iminify-media="${ id }"]` );

	if ( ! view ) {
		// eslint-disable-next-line no-alert -- a row action whose cell the screen doesn't show.
		window.alert( message );

		return;
	}

	const line = document.createElement( 'p' );

	line.className = 'iminify-refusal';
	line.setAttribute( 'role', 'alert' );
	line.textContent = message;

	view.querySelector( '.iminify-refusal' )?.remove();
	view.prepend( line );
}

/**
 * The call a button asks for.
 *
 * @param {HTMLElement} button The button.
 * @return {Object} The request.
 */
function request( button ) {
	const { iminifyAction, iminifyAttachment, iminifyLevel } = button.dataset;
	const path = `${ BASE }/${ iminifyAttachment }/${ iminifyAction }`;

	if ( 'copies' === iminifyAction ) {
		return { path, method: 'DELETE' };
	}

	return {
		path,
		method: 'POST',
		data: iminifyLevel ? { level: iminifyLevel } : {},
	};
}

function press( event ) {
	const button = event.target.closest( '[data-iminify-action]' );

	if ( ! button ) {
		return;
	}

	event.preventDefault();

	const { iminifyConfirm, iminifyAttachment, iminifyReload } = button.dataset;

	// eslint-disable-next-line no-alert -- a one-line yes or no before an action that writes files or counts an image.
	if ( iminifyConfirm && ! window.confirm( iminifyConfirm ) ) {
		return;
	}

	button.disabled = true;

	apiFetch( request( button ) )
		.then( ( answer ) => {
			if ( iminifyReload ) {
				window.location.reload();
			} else {
				draw( answer );
			}
		} )
		.catch( ( error ) => {
			button.disabled = false;
			refuse( iminifyAttachment, error?.message ?? '' );
		} );
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
