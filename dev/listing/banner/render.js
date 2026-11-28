/**
 * Renders the wordpress.org banner from banner.html, one layout at 772x250
 * CSS pixels: banner-1544x500.png at twice the pixels and banner-772x250.png
 * at its own, never one scaled from the other. Also writes
 * preview-overlay.png, the small banner under the dark box in which
 * wp-admin's plugin details window prints the plugin's name, to check that
 * nothing that matters sits under it.
 *
 * Usage: node dev/listing/banner/render.js ["name for the overlay"]
 * Writes to dev/local/listing/. The pieces under crops/ are cut at their own
 * pixels from the 2x screenshots in .wordpress-org, so a changed screen is
 * cut again from its new capture.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

( async () => {
	const out = path.join( __dirname, '..', '..', 'local', 'listing' );
	const url = 'file:///' + path.join( __dirname, 'banner.html' ).replace( /\\/g, '/' );
	const clip = { x: 0, y: 0, width: 772, height: 250 };

	fs.mkdirSync( out, { recursive: true } );

	const browser = await chromium.launch();

	for ( const [ scale, name ] of [
		[ 2, 'banner-1544x500.png' ],
		[ 1, 'banner-772x250.png' ],
	] ) {
		const context = await browser.newContext( {
			viewport: { width: 772, height: 250 },
			deviceScaleFactor: scale,
		} );
		const page = await context.newPage();

		await page.goto( url );
		await page.evaluate( () => document.fonts.ready );
		await page.screenshot( { path: path.join( out, name ), clip } );

		if ( 1 === scale ) {
			await page.evaluate( ( title ) => {
				const bar = document.createElement( 'div' );
				bar.textContent = title;
				bar.style.cssText =
					'position:absolute;left:26px;top:174px;max-width:720px;padding:0 15px;font:30px/1.68 "Helvetica Neue",Arial,sans-serif;color:#fff;background:rgba(29,35,39,.9);border-radius:8px;z-index:9';
				document.querySelector( '.canvas' ).appendChild( bar );
			}, process.argv[ 2 ] || 'Iminify Image Optimizer' );
			await page.screenshot( { path: path.join( out, 'preview-overlay.png' ), clip } );
		}

		await context.close();
	}

	await browser.close();
	console.log( 'Rendered into ' + out );
} )();
