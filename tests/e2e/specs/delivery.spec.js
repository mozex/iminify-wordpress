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

test( "A translation's page serves the copies of the image it translates", async ( { page } ) => {
	seed( 'reset' );
	const { id } = seedJson( 'optimized' );
	const translated = seedJson( 'translated', { id } );

	expect( translated.holder ).toBe( id );

	await page.goto( translated.post );
	const image = page.locator( `picture > img.wp-image-${ translated.id }[data-iminify-picture]` );
	await expect( image ).toHaveCount( 1 );
	await expect( page.locator( 'picture > source[type="image/webp"]' ) ).toHaveCount( 1 );
	expect( await image.evaluate( ( img ) => img.currentSrc ) ).toMatch( /\.jpg\.webp$/ );
} );

test( "Across the whole page a builder's background and a style element's load their AVIF", async ( { page } ) => {
	seed( 'reset' );
	const { id } = seedJson( 'optimized' );
	const { post, main, large, avif } = seedJson( 'backgrounds', { id } );

	expect( avif, "The e2e server's image editor writes AVIF." ).toBe( true );

	await page.goto( settingsPage );
	await page.getByLabel( 'WebP and AVIF on your pages' ).selectOption( 'page' );
	await expect( page.getByText( 'A background set in a stylesheet file keeps its original.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'Settings saved.' } ) ).toBeVisible();

	await page.goto( post, { waitUntil: 'load' } );

	/* The first declaration stays as it was, so a browser that can't read the second keeps the original. */
	const style = ( await page.locator( '.iminify-hero' ).getAttribute( 'style' ) ) ?? '';
	expect( style.indexOf( `url(${ large })` ) ).toBeLessThan( style.indexOf( 'image-set(' ) );

	const loaded = async () =>
		page.evaluate( () => performance.getEntriesByType( 'resource' ).map( ( entry ) => entry.name ) );

	await expect.poll( loaded ).toContain( `${ large }.avif` );
	await expect.poll( loaded ).toContain( `${ main }.avif` );
	expect( await loaded() ).not.toContain( large );
	expect( await loaded() ).not.toContain( main );
} );
