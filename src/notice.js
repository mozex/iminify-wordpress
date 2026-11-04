/**
 * Remembers that someone closed the plugin's notice, so it stays closed for
 * them until it says something new.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

domReady( () => {
	const notice = document.getElementById( 'iminify-notice' );

	if ( ! notice ) {
		return;
	}

	notice.addEventListener( 'click', ( event ) => {
		if ( ! event.target.closest( '.notice-dismiss' ) ) {
			return;
		}

		apiFetch( {
			path: '/iminify/v1/notice',
			method: 'POST',
			data: { notice: notice.dataset.notice },
		} ).catch( () => {} );
	} );
} );
