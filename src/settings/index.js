/**
 * The settings page: draws the state the plugin's REST routes answer.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './app';
import './settings.scss';

domReady( () => {
	const root = document.getElementById( 'iminify-settings' );

	if ( root ) {
		createRoot( root ).render(
			<App scope={ root.dataset.scope || 'site' } />
		);
	}
} );
