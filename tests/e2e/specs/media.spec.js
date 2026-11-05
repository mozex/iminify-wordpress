/**
 * The media library: the column, the filter, the panel's buttons, and the queue's Start now.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage } = require( '../support/wp' );

test.beforeEach( () => {
	seed( 'reset' );
	seed( 'connect' );
} );

test( 'The column and the filter show an optimized image', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );

	await page.goto( '/wp-admin/upload.php?mode=list' );
	const row = page.locator( `#post-${ id }` );
	await expect( row.locator( 'td.column-iminify' ) ).toContainText( 'Optimized' );

	await page.getByLabel( 'Filter by Iminify status' ).selectOption( { label: 'Optimized' } );
	await page.locator( '#post-query-submit' ).click();
	await expect( page.locator( `#post-${ id }` ) ).toBeVisible();

	await page.getByLabel( 'Filter by Iminify status' ).selectOption( { label: 'Not optimized' } );
	await page.locator( '#post-query-submit' ).click();
	await expect( page.locator( `#post-${ id }` ) ).toHaveCount( 0 );

	await page.goto( '/wp-admin/upload.php?mode=list' );
	await page.locator( `#post-${ id }` ).hover();
	await expect( page.locator( `#post-${ id } .row-actions` ) ).toContainText( 'Restore original' );
} );

test( 'The panel deletes the copies, then restores the original', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	page.on( 'dialog', ( dialog ) => dialog.accept() );

	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	const panel = page.locator( '.iminify-media[data-iminify-view="panel"]' );
	await expect( panel ).toContainText( 'Copies: WebP' );

	await panel.getByRole( 'button', { name: 'Delete the WebP and AVIF copies' } ).click();
	await expect( panel ).not.toContainText( 'Copies: WebP' );
	expect( seedJson( 'record', { id } ).copies ).toEqual( [] );

	await panel.getByRole( 'button', { name: 'Restore original' } ).click();
	await expect( panel ).toContainText( 'Not optimized' );
	expect( seedJson( 'record', { id } ).status ).toBe( 'not_optimized' );
} );

test( 'Start now sends the images waiting for the next batch', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	seed( 'paused', { id } );

	await page.goto( settingsPage );
	await page.getByRole( 'button', { name: 'Start now' } ).click();
	await expect( page.locator( '.components-notice__content', { hasText: 'The images waiting are on their way.' } ) ).toBeVisible();
	expect( seedJson( 'record', { id } ).status ).toBe( 'queued' );
} );
