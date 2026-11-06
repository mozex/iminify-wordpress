/**
 * The server's rules on the e2e site's Apache: the lines the settings page
 * shows where it can't write them, the copy sent to a browser that accepts it,
 * a file replaced in place, a folder the rules don't cover, and the rules
 * coming out and going back with the plugin.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage, wp } = require( '../support/wp' );

const webp = { Accept: 'image/webp,*/*' };

test( 'The server sends a copy only where the rules cover it, and the rules follow the plugin', async ( {
	page,
	request,
} ) => {
	/* About ten WP-CLI calls, each a few seconds through wp-env. */
	test.slow();
	/* A run cut short after turning the plugin off leaves it off. */
	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	seed( 'reset' );
	const { id, post } = seedJson( 'optimized' );
	await page.goto( post );
	const large = await page
		.locator( 'figure img' )
		.first()
		.getAttribute( 'src' );
	const main = large.replace( /-\d+x\d+(\.jpg)$/, '$1' );
	const outside = seedJson( 'outside' ).url;

	let answer = await request.get( large, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );

	/* The web server can't write the file: the settings show the lines to paste. */
	seed( 'rules-lock' );
	await page.goto( settingsPage );
	await page
		.getByLabel( 'WebP and AVIF on your pages' )
		.selectOption( 'rules' );
	await expect(
		page.getByText( 'Your pages stay as they are' )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect(
		page.locator( '.iminify-toast', { hasText: 'Settings saved.' } )
	).toBeVisible();
	await expect( page.getByText( 'The plugin can’t write to' ) ).toBeVisible();
	await expect( page.locator( '.iminify-code--block code' ) ).toContainText(
		'# BEGIN Iminify'
	);
	await expect(
		page.getByText( 'They cover: The uploads folder.' )
	).toBeVisible();

	/* WP-CLI can: once they're in, the page says so. */
	expect( seedJson( 'rules-write' ).where ).toBe( 'written' );
	await page.reload();
	await expect( page.getByText( 'The rules are in' ) ).toBeVisible();

	/* A browser that reads WebP gets the copy, one that doesn't the original, both told the answer varies. */
	answer = await request.get( large, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/webp' );
	expect( answer.headers().vary ).toContain( 'Accept' );
	answer = await request.get( large, {
		headers: { Accept: 'image/jpeg,*/*' },
	} );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );
	expect( answer.headers().vary ).toContain( 'Accept' );

	/* The page names the original alone, and the browser is sent the copy for it. */
	const loaded = page.waitForResponse(
		( response ) => response.url() === large
	);
	await page.goto( post );
	await expect( page.locator( 'picture' ) ).toHaveCount( 0 );
	expect( ( await loaded ).headers()[ 'content-type' ] ).toBe( 'image/webp' );

	/* A folder the rules don't cover keeps its original. */
	answer = await request.get( outside, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );
	expect( answer.headers().vary ).toBeUndefined();

	/* A file replaced in place loses the copy made of its old bytes, so its new bytes are what a browser gets. */
	expect(
		( await request.get( main, { headers: webp } ) ).headers()[
			'content-type'
		]
	).toBe( 'image/webp' );
	const { bytes } = seedJson( 'replace', { id } );
	answer = await request.get( main, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );
	expect( ( await answer.body() ).length ).toBe( bytes );

	/* Turned off, the plugin takes its rules out; turned on, they go back. */
	wp( 'plugin', 'deactivate', 'iminify-image-optimizer' );
	expect( seedJson( 'rules-file' ).text ).not.toContain( '# BEGIN Iminify' );
	expect(
		( await request.get( large, { headers: webp } ) ).headers()[
			'content-type'
		]
	).toBe( 'image/jpeg' );

	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	expect( seedJson( 'rules-file' ).text ).toContain( '# BEGIN Iminify' );
	expect(
		( await request.get( large, { headers: webp } ) ).headers()[
			'content-type'
		]
	).toBe( 'image/webp' );

	seed( 'reset' );
} );
