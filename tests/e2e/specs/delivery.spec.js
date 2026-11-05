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
	await expect( page.locator( '.components-notice__content', { hasText: 'Settings saved.' } ) ).toBeVisible();

	await page.goto( post );
	await expect( page.locator( 'picture' ) ).toHaveCount( 0 );
} );
