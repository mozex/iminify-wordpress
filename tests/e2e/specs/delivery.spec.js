/**
 * The WebP copies on a post's page, and the setting that turns them off.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage } = require( '../support/wp' );

test( 'A post serves its image through picture tags until delivery is turned off', async ( { page, request } ) => {
	seed( 'reset' );
	const { post } = seedJson( 'optimized' );

	await page.goto( post );
	const source = page.locator( 'picture > source[type="image/webp"]' );
	await expect( source ).toHaveCount( 1 );
	await expect( page.locator( 'picture picture' ) ).toHaveCount( 0 );
	await expect( page.locator( 'picture > img[data-iminify-picture]' ) ).toHaveCount( 1 );

	const srcset = ( await source.getAttribute( 'srcset' ) ) ?? '';
	const first = srcset.split( ',' )[ 0 ].trim().split( /\s+/ )[ 0 ];
	expect( first ).toMatch( /\.jpg\.webp$/ );
	const copy = await request.get( first );
	expect( copy.status() ).toBe( 200 );
	expect( await page.locator( 'picture > img' ).evaluate( ( img ) => img.currentSrc ) ).toMatch( /\.webp$/ );

	await page.goto( settingsPage );
	await page.getByLabel( 'WebP and AVIF on your pages' ).selectOption( 'off' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'Settings saved.' } ) ).toBeVisible();

	await page.goto( post );
	await expect( page.locator( 'picture' ) ).toHaveCount( 0 );
} );

test( 'Picture tags across the whole page reach an image in raw HTML and one in a chosen folder, once', async ( { page } ) => {
	seed( 'reset' );
	const { raw, post } = seedJson( 'optimized' );
	const folder = seedJson( 'folder-optimized' ).post;

	await page.goto( raw );
	await expect( page.locator( '.builder img' ) ).toHaveCount( 1 );
	await expect( page.locator( 'picture' ) ).toHaveCount( 0 );

	await page.goto( settingsPage );
	await page.getByLabel( 'WebP and AVIF on your pages' ).selectOption( 'page' );
	await expect( page.getByText( 'Also reaches the images in raw HTML' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'Settings saved.' } ) ).toBeVisible();

	await page.goto( raw );
	const image = page.locator( '.builder > picture > img[data-iminify-picture]' );
	await expect( image ).toHaveCount( 1 );
	await expect( page.locator( '.builder > picture > source[type="image/webp"]' ) ).toHaveCount( 1 );
	expect( await image.evaluate( ( img ) => img.currentSrc ) ).toMatch( /\.jpg\.webp$/ );

	await page.goto( folder );
	const theme = page.locator( '.theme-art > picture > img[data-iminify-picture]' );
	await expect( theme ).toHaveCount( 1 );
	expect( await theme.evaluate( ( img ) => img.currentSrc ) ).toMatch( /theme\.jpg\.webp$/ );

	/* The block's image, which core's filter wraps, isn't wrapped again by the page's buffer. */
	await page.goto( post );
	await expect( page.locator( 'picture' ) ).toHaveCount( 1 );
	await expect( page.locator( 'picture picture' ) ).toHaveCount( 0 );
} );
