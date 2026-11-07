/**
 * Another image optimizer on the site: an image it optimized reads as its, and while it acts on uploads the bulk page holds and says what to switch off.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson } = require( '../support/wp' );

const bulkPage = '/wp-admin/upload.php?page=iminify-bulk';

test.beforeEach( () => {
	seed( 'reset' );
	seed( 'connect' );
} );

test( 'An image another plugin optimized reads as its in the bulk page and the media library', async ( { page } ) => {
	const [ id ] = seedJson( 'fresh', { count: 1 } );
	seedJson( 'fresh', { count: 1 } );
	seed( 'smushed', { id } );

	await page.goto( bulkPage );
	await expect( page.getByText( /^Smush optimized 1 image, /, { exact: false } ) ).toBeVisible();
	await expect( page.getByText( '1 image is not optimized yet.' ) ).toBeVisible();

	await page.goto( '/wp-admin/upload.php?mode=list' );
	const cell = page.locator( `.iminify-media[data-iminify-media="${ id }"]` );
	await expect( cell ).toContainText( 'Optimized by Smush' );
	await expect( page.locator( `#post-${ id } [data-iminify-action]` ) ).toHaveCount( 0 );
} );

test( 'While another optimizer acts on uploads, the bulk page holds its run and names what to switch off', async ( { page } ) => {
	seedJson( 'fresh', { count: 1 } );
	seed( 'acting' );

	await page.goto( bulkPage );
	await expect( page.getByText( /Smush is active and optimizes your uploads itself/ ) ).toBeVisible();
	await expect( page.getByText( /"Automatic compression"/ ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Optimize the library' } ) ).toBeDisabled();
} );

test( 'Images another plugin optimized with their originals kept are optimized again from those originals, asked first', async ( { page } ) => {
	const [ id ] = seedJson( 'fresh', { count: 1 } );
	seed( 'smushed', { id, backup: true } );

	await page.goto( bulkPage );
	await expect( page.getByRole( 'heading', { name: 'Optimized by another plugin' } ) ).toBeVisible();
	await expect( page.getByText( /^1 image another plugin optimized kept its original\./ ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Optimize them again from their originals' } ).click();
	await expect( page.getByText( /Each counts as one more image/ ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Optimize them again from their originals' } ).click();

	await expect( page.getByRole( 'heading', { name: 'Optimizing again from the originals' } ) ).toBeVisible();
} );
