/**
 * Another optimizer's leftover rules on the e2e site's Apache: EWWW's block,
 * as it wrote it running, still sends a browser the WebP beside an image once
 * EWWW is off; the settings page shows it, and it goes on the press, the rest
 * of the site's .htaccess kept.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage, wp } = require( '../support/wp' );

const webp = { Accept: 'image/webp,*/*' };

test( 'A rule another optimizer left is shown with its lines and removed on the press', async ( {
	page,
	request,
} ) => {
	/* A handful of WP-CLI calls, each a few seconds through wp-env. */
	test.slow();
	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	seed( 'reset' );
	const { post } = seedJson( 'optimized' );
	await page.goto( post );
	const image = await page
		.locator( 'figure img' )
		.first()
		.getAttribute( 'src' );

	let answer = await request.get( image, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );

	/* EWWW's rule serves the WebP named after the image, whoever made it. */
	seed( 'leftovers' );
	answer = await request.get( image, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/webp' );

	await page.goto( settingsPage );
	const panel = page.locator( '.iminify-leftovers' );
	await expect(
		panel.getByRole( 'heading', { name: 'EWWW Image Optimizer’s rules' } )
	).toBeVisible();
	await expect( panel.locator( '.iminify-code--block code' ) ).toContainText(
		'# BEGIN EWWWIO'
	);

	/* Asked in the page first; Cancel changes nothing. */
	await panel.getByRole( 'button', { name: 'Remove' } ).click();
	await panel.getByRole( 'button', { name: 'Cancel' } ).click();
	expect( seedJson( 'rules-file' ).text ).toContain( '# BEGIN EWWWIO' );

	await panel.getByRole( 'button', { name: 'Remove' } ).click();
	await panel
		.getByRole( 'group' )
		.getByRole( 'button', { name: 'Remove' } )
		.click();
	await expect(
		page.locator( '.iminify-toast', { hasText: 'The lines are removed.' } )
	).toBeVisible();
	/* The last one gone, the focus lands on the line saying so rather than on the page's top. */
	await expect(
		panel.getByText( 'The rules another optimizer left are gone.' )
	).toBeFocused();
	await expect( panel.getByRole( 'button', { name: 'Remove' } ) ).toHaveCount(
		0
	);

	const text = seedJson( 'rules-file' ).text;
	expect( text ).not.toContain( 'EWWWIO' );
	expect( text ).toContain( '# BEGIN WordPress' );

	answer = await request.get( image, { headers: webp } );
	expect( answer.headers()[ 'content-type' ] ).toBe( 'image/jpeg' );
} );
