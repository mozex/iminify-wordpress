/**
 * The other folders page: a folder chosen and looked through, one refused, an
 * image sent from its row, and the folders' run started from the bulk page.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson } = require( '../support/wp' );

const foldersPage = '/wp-admin/upload.php?page=iminify-folders';
const bulkPage = '/wp-admin/upload.php?page=iminify-bulk';

test.beforeEach( () => {
	seed( 'reset' );
	seed( 'connect' );
} );

test( 'A folder is chosen by its path and looked through, the library is refused, and an image goes from its row', async ( { page } ) => {
	const folder = seed( 'folder', { count: 2 } );
	const input = page.getByLabel( 'Another folder in wp-content' );

	await page.goto( foldersPage );
	await expect( page.getByText( 'Add a folder to see its images here.' ) ).toBeVisible();

	await input.fill( 'uploads' );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await expect( page.getByRole( 'alert' ) ).toContainText( "media library's folder" );

	await input.fill( folder );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await expect( page.locator( '.iminify-file__path', { hasText: `${ folder }/picture-0.jpg` } ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '.iminify-file__path', { hasText: `${ folder }/picture-1.jpg` } ) ).toBeVisible();
	await expect( page.getByText( 'Last looked on' ) ).toContainText( '2 images, 2 new, 0 changed' );

	/* Each row shows its picture from the file's own address, and each filter what it holds. */
	await expect( page.locator( '.iminify-file', { hasText: 'picture-0.jpg' } ).locator( 'img.iminify-file__thumb' ) ).toHaveAttribute( 'src', new RegExp( `/wp-content/${ folder }/picture-0\\.jpg\\?v=\\d+$` ) );
	await expect( page.getByRole( 'button', { name: 'Not optimized' } ) ).toContainText( '2' );

	const row = page.locator( '.iminify-file', { hasText: 'picture-0.jpg' } );
	await row.getByRole( 'button', { name: 'Optimize' } ).click();
	await expect( page.getByText( 'Sent to Iminify.' ) ).toBeVisible();
	await expect( row.getByText( 'On its way' ) ).toBeVisible();

	const files = seedJson( 'files' );
	expect( files.find( ( file ) => file.path.endsWith( 'picture-0.jpg' ) ).status ).toBe( 'queued' );
	expect( files.find( ( file ) => file.path.endsWith( 'picture-1.jpg' ) ).status ).toBe( 'not_optimized' );

	await page.getByRole( 'button', { name: 'On their way' } ).click();
	await expect( page.locator( '.iminify-file' ).filter( { hasText: 'picture-' } ) ).toHaveCount( 1 );
} );

test( 'The bulk page sends the folders’ images in a run of their own', async ( { page } ) => {
	const folder = seed( 'folder', { count: 2 } );

	await page.goto( foldersPage );
	await page.getByLabel( 'Another folder in wp-content' ).fill( folder );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await expect( page.locator( '.iminify-file__path', { hasText: 'picture-1.jpg' } ) ).toBeVisible( { timeout: 15_000 } );

	await page.goto( bulkPage );
	await expect( page.getByText( '2 images in your folders: 0 optimized, 2 not yet.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Optimize them' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Optimizing other folders' } ) ).toBeVisible( { timeout: 15_000 } );

	for ( const file of seedJson( 'files' ) ) {
		expect( file.status ).toBe( 'queued' );
		expect( file.trigger ).toBe( 'bulk' );
	}
} );
