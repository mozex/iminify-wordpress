/**
 * The bulk page: the library's counts, a run started and stopped, and restoring the library while the page is open.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson } = require( '../support/wp' );

const bulkPage = '/wp-admin/upload.php?page=iminify-bulk';

test.beforeEach( () => {
	seed( 'reset' );
	seed( 'connect' );
} );

/**
 * The value the library's table shows for one count.
 *
 * @param {import('@playwright/test').Page} page  The page.
 * @param {string}                          label The count's label.
 */
function count( page, label ) {
	return page.locator( '.iminify-counts tr', { has: page.locator( 'th', { hasText: new RegExp( `^${ label }$` ) } ) } ).locator( 'td' );
}

test( 'A run sends the images not optimized, and stopping it takes back what waits', async ( { page } ) => {
	const fresh = seedJson( 'fresh', { count: 2 } );
	seedJson( 'optimized' );

	await page.goto( bulkPage );
	await expect( count( page, 'Not optimized' ) ).toHaveText( '2' );
	await expect( count( page, 'Optimized' ) ).toHaveText( '1' );
	await expect( page.getByText( 'Optimizing saved' ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Optimize the library' } ).click();
	await expect( page.getByText( 'Sent 2 of 2 images to Iminify.' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( count( page, 'On their way' ) ).toHaveText( '2' );

	for ( const id of fresh ) {
		const record = seedJson( 'record', { id } );
		expect( record.status ).toBe( 'queued' );
		expect( record.trigger ).toBe( 'bulk' );
	}

	await page.getByRole( 'button', { name: 'Stop' } ).click();
	await expect( page.getByText( 'Stopped.' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( count( page, 'Not optimized' ) ).toHaveText( '2' );
	expect( seedJson( 'record', { id: fresh[ 0 ] } ).status ).toBe( 'not_optimized' );
} );

test( 'Restore all puts the optimized images back while the page is open', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	page.on( 'dialog', ( dialog ) => dialog.accept() );

	await page.goto( bulkPage );
	await page.getByLabel( "While this page is open, for a host whose scheduled tasks don't run" ).check();
	await page.getByRole( 'button', { name: 'Restore all' } ).click();

	/* WordPress makes every size again from the backup, which takes a few seconds. */
	await expect( page.getByText( 'Finished: the images are back as they were uploaded.' ) ).toBeVisible( { timeout: 20_000 } );
	await expect( count( page, 'Restored' ) ).toHaveText( '1' );
	expect( seedJson( 'record', { id } ).status ).toBe( 'not_optimized' );
} );
