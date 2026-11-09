/**
 * The plugin's own REST routes. Every call to Iminify happens on the site's
 * server behind them, so the page never holds the site key.
 */
import apiFetch from '@wordpress/api-fetch';

const BASE = '/iminify/v1';

/**
 * Calls one of the routes and answers its JSON, or throws `{ code, message,
 * errors, retryAfter }`, `retryAfter` being the seconds Iminify asked to wait,
 * or null.
 *
 * @param {string} path   The route under the plugin's namespace.
 * @param {string} method The HTTP method.
 * @param {Object} data   The body, if any.
 * @return {Promise<Object>} The page's state, with anything the call adds.
 */
export function call( path, method = 'GET', data = undefined ) {
	return apiFetch( { path: BASE + path, method, data } ).catch( ( error ) => {
		const wait = Number( error?.data?.retry_after );

		throw {
			code: error?.code ?? 'unknown',
			message: error?.message ?? '',
			errors: error?.data?.errors ?? {},
			retryAfter: wait > 0 ? wait : null,
		};
	} );
}

/**
 * Opens an empty tab while the click still counts as the person's, so the
 * browser doesn't block it, and points it at an address once the server
 * answers one. The tab can't reach back into wp-admin.
 *
 * @return {{ opened: boolean, go: (url: string) => void, close: () => void }} Sends the tab somewhere, or closes it.
 */
export function openTab() {
	const tab = window.open( '', '_blank' );

	if ( tab ) {
		tab.opener = null;
	}

	return {
		opened: Boolean( tab ),
		go( url ) {
			if ( tab && ! tab.closed ) {
				tab.location.href = url;
			}
		},
		close() {
			if ( tab && ! tab.closed ) {
				tab.close();
			}
		},
	};
}
