/**
 * Renders the animated wordpress.org icon from icon.html, frame by frame at
 * 25 frames a second over its 4 second loop, at 256 and at 128 pixels, each
 * drawn at its own size rather than scaled. The first and last frames are
 * the plain mark, since some places show only the first.
 *
 * Usage: node dev/listing/icon/capture.mjs
 * Writes the frames to dev/local/listing/frames-<size>/, then join each
 * size into a looping GIF from the repository's root:
 *
 *   ffmpeg -framerate 25 -i dev/local/listing/frames-256/f%03d.png -vf "palettegen=stats_mode=full" dev/local/listing/palette-256.png
 *   ffmpeg -framerate 25 -i dev/local/listing/frames-256/f%03d.png -i dev/local/listing/palette-256.png -lavfi "paletteuse=dither=none" -loop 0 dev/local/listing/icon-256x256.gif
 *
 * and the same with 128.
 */
import { mkdirSync, rmSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const out = path.join( here, '..', '..', 'local', 'listing' );
const fps = 25;
const frames = 4 * fps;
const browser = await chromium.launch();

for ( const size of [ 256, 128 ] ) {
	const dir = path.join( out, `frames-${ size }` );
	rmSync( dir, { recursive: true, force: true } );
	mkdirSync( dir, { recursive: true } );

	const page = await browser.newPage( {
		viewport: { width: size, height: size },
		deviceScaleFactor: 1,
	} );
	await page.goto( pathToFileURL( path.join( here, 'icon.html' ) ).href );
	await page.evaluate( ( px ) => window.setSize( px ), size );

	for ( let i = 0; i < frames; i++ ) {
		await page.evaluate( ( t ) => window.render( t ), i / fps );
		await page.screenshot( {
			path: path.join( dir, `f${ String( i ).padStart( 3, '0' ) }.png` ),
			clip: { x: 0, y: 0, width: size, height: size },
		} );
	}

	await page.close();
}

await browser.close();
console.log( `${ frames } frames a size, in ${ out }` );
