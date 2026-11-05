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

test( 'An image shows what it lacks, and its row action sends it for that after asking', async ( { page } ) => {
	seed( 'state', { settings: { avif: true } } );
	const { id } = seedJson( 'optimized' );

	/* The settings page reads the site's record, as any of the plugin's pages does. */
	await page.goto( '/wp-admin/upload.php?page=iminify-bulk' );
	await expect( page.getByRole( 'button', { name: 'Make the missing formats' } ) ).toBeVisible();

	await page.goto( '/wp-admin/upload.php?mode=list' );
	const row = page.locator( `#post-${ id }` );
	await expect( row.locator( 'td.column-iminify' ) ).toContainText( 'Missing formats: AVIF' );

	await row.hover();
	await row.getByRole( 'button', { name: 'Make the missing formats' } ).click();
	await expect( row.getByText( 'This counts as one more image' ) ).toBeVisible();
	await row.locator( '.iminify-confirm' ).getByRole( 'button', { name: 'Make the missing formats' } ).click();
	await expect( page.locator( `#post-${ id } td.column-iminify` ) ).toContainText( 'Queued' );

	const record = seedJson( 'record', { id } );
	expect( record.fill ).toBe( 'formats' );
	expect( record.trigger ).toBe( 'manual' );
	expect( record.level ).toBe( 'smart' );
} );

test( 'The grid has the filter too, and it narrows the images it shows', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	const [ fresh ] = seedJson( 'fresh', { count: 1 } );

	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await expect( page.locator( `li.attachment[data-id="${ id }"]` ) ).toBeVisible();
	await expect( page.locator( `li.attachment[data-id="${ fresh }"]` ) ).toBeVisible();

	await page.getByLabel( 'Filter by Iminify status' ).selectOption( { label: 'Optimized' } );
	await expect( page.locator( `li.attachment[data-id="${ fresh }"]` ) ).toHaveCount( 0 );
	await expect( page.locator( `li.attachment[data-id="${ id }"]` ) ).toBeVisible();

	await page.getByLabel( 'Filter by Iminify status' ).selectOption( { label: 'Not optimized' } );
	await expect( page.locator( `li.attachment[data-id="${ id }"]` ) ).toHaveCount( 0 );
	await expect( page.locator( `li.attachment[data-id="${ fresh }"]` ) ).toBeVisible();
} );

test( 'On a phone, a row action asks under the row, where the Iminify column is hidden, and goes ahead', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );

	await page.setViewportSize( { width: 390, height: 844 } );
	await page.goto( '/wp-admin/upload.php?mode=list' );
	const row = page.locator( `#post-${ id }` );
	await expect( row.locator( 'td.column-iminify' ) ).toBeHidden();

	/* Core shows a row's actions on hover, or always on a touch screen. */
	await row.hover();
	await row.getByRole( 'button', { name: 'Restore original' } ).click();
	const confirm = row.locator( '.iminify-row-place .iminify-confirm' );
	await expect( confirm ).toBeVisible();
	await expect( confirm.getByRole( 'button', { name: 'Cancel' } ) ).toBeFocused();
	expect( seedJson( 'record', { id } ).status ).toBe( 'optimized' );

	await confirm.getByRole( 'button', { name: 'Restore original' } ).click();
	await expect.poll( () => seedJson( 'record', { id } ).status, { timeout: 20_000 } ).toBe( 'not_optimized' );
} );

test( 'The panel deletes the copies, then restores the original, each asked in the panel first', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );

	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	const panel = page.locator( '.iminify-media[data-iminify-view="panel"]' );
	const confirm = panel.locator( '.iminify-confirm' );
	await expect( panel ).toContainText( 'Copies: WebP' );

	await panel.getByRole( 'button', { name: 'Delete the WebP and AVIF copies' } ).click();
	await expect( confirm ).toContainText( "Delete this image's WebP and AVIF copies?" );
	await confirm.getByRole( 'button', { name: 'Delete the copies' } ).click();
	await expect( panel ).not.toContainText( 'Copies: WebP' );
	expect( seedJson( 'record', { id } ).copies ).toEqual( [] );

	await panel.getByRole( 'button', { name: 'Restore original' } ).click();
	await confirm.getByRole( 'button', { name: 'Restore original' } ).click();
	await expect( panel ).toContainText( 'Not optimized' );
	expect( seedJson( 'record', { id } ).status ).toBe( 'not_optimized' );
} );

test( 'Start now sends the images waiting for the next batch', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	seed( 'paused', { id } );

	await page.goto( settingsPage );
	await page.getByRole( 'button', { name: 'Start now' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'The images waiting are on their way.' } ) ).toBeVisible();
	expect( seedJson( 'record', { id } ).status ).toBe( 'queued' );
} );
