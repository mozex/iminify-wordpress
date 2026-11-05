/**
 * The bulk page: draws the state the plugin's bulk routes answer.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './app';
import './bulk.scss';

domReady( () => {
	const root = document.getElementById( 'iminify-bulk' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
