/**
 * The other folders page: draws the state the plugin's folders routes answer.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './app';
import './folders.scss';

domReady( () => {
	const root = document.getElementById( 'iminify-folders' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
