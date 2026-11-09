/**
 * The page audit: draws the state the plugin's audit routes answer.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './app';
import './audit.scss';

domReady( () => {
	const root = document.getElementById( 'iminify-audit' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
