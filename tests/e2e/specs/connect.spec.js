/**
 * Connecting from the settings page, and changing the settings there.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage } = require( '../support/wp' );

test.beforeEach( async ( { context } ) => {
	seed( 'reset' );

	/* The connect page is iminify.com's: the tests never load the real one. */
	await context.route( 'https://www.iminify.com/**', ( route ) =>
		route.fulfill( {
			status: 200,
			contentType: 'text/html',
			body: '<!doctype html><title>Connect your site</title>',
		} )
	);
} );

test( 'Connect opens its tab before the server answers, and the page collects the key once the site is approved', async ( {
	page,
	context,
} ) => {
	await page.goto( settingsPage );

	/* Before a connection the side column says what one does; the account takes its place after. */
	const welcome = page.getByRole( 'heading', { name: 'Once you connect' } );
	await expect( welcome ).toBeVisible();

	/* Held until the tab exists: a tab opened only after the answer would be blocked as a pop-up, and this test would time out. */
	let release;
	const held = new Promise( ( resolve ) => ( release = resolve ) );
	await page.route(
		( url ) => /iminify\/v1\/connect(?!\/)/.test( decodeURIComponent( url.toString() ) ),
		async ( route ) => {
			await held;
			await route.continue();
		}
	);

	const opened = context.waitForEvent( 'page' );
	await page.getByRole( 'button', { name: 'Connect', exact: true } ).click();
	const tab = await opened;
	release();

	await expect.poll( () => tab.url() ).toContain( 'https://www.iminify.com/connect/wordpress/' );
	await expect( page.getByRole( 'heading', { name: 'Waiting for your approval' } ) ).toBeVisible();
	await expect( page.locator( '#iminify-conn' ) ).toHaveText( 'Waiting for your approval' );

	seed( 'state', { approved: true } );

	await expect( page.getByRole( 'heading', { name: 'Your account' } ) ).toBeVisible( { timeout: 30_000 } );
	await expect( welcome ).toHaveCount( 0 );
	await expect( page.locator( '#iminify-conn' ) ).toHaveText( 'Connected' );
	await expect( page.getByText( '3 used, 37 left' ) ).toBeVisible();

	/* What Iminify saved, for the site and for the whole account, as the service counts it. */
	const totals = page.locator( '.iminify-totals .iminify-facts > div' );
	await expect( totals.nth( 0 ).locator( 'dt' ) ).toHaveText( 'This site' );
	await expect( totals.nth( 0 ).locator( 'dd' ) ).toHaveText( '312.5 MB saved1,204 images' );
	await expect( totals.nth( 1 ).locator( 'dt' ) ).toHaveText( 'Your whole account' );
	await expect( totals.nth( 1 ).locator( 'dd' ) ).toHaveText( '1.2 GB saved5,021 images' );
} );

test( 'A site setting goes to the service, and the local ones stay on the site', async ( { page } ) => {
	seed( 'connect' );
	await page.goto( settingsPage );

	/* The save bar stays in the page's flow until something waits to be saved, so it never covers the form for nothing. */
	const savebar = page.locator( '.iminify-savebar' );
	await expect( savebar ).not.toHaveClass( /iminify-savebar--changed/ );

	/* The level is a card on a real radio, named by its title alone, its line on what it does being the description. */
	const levels = page.getByRole( 'group', { name: 'Compression level' } );
	const ultra = levels.getByRole( 'radio', { name: 'Ultra', exact: true } );
	await expect( ultra ).toHaveAccessibleDescription( 'The smallest files, with some loss of quality.' );
	await ultra.check();
	await expect( savebar ).toHaveClass( /iminify-savebar--changed/ );
	await expect( savebar ).toContainText( 'Unsaved changes' );
	await page.getByLabel( 'Also delete the backups' ).check();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'Settings saved.' } ) ).toBeVisible();
	await expect( savebar ).not.toHaveClass( /iminify-savebar--changed/ );

	/* Disconnect asks in the page, with its yes drawn red and Cancel taking focus; Escape gives focus back. */
	const disconnect = page.getByRole( 'button', { name: 'Disconnect', exact: true } );
	await disconnect.click();
	const ask = page.getByRole( 'group', { name: /^Disconnect this site from Iminify\?/ } );
	await expect( ask.getByRole( 'button', { name: 'Disconnect' } ) ).toHaveClass( /iminify-btn--danger/ );
	await expect( ask.getByRole( 'button', { name: 'Cancel' } ) ).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( ask ).toHaveCount( 0 );
	await expect( disconnect ).toBeFocused();

	const patch = seedJson( 'calls' ).filter( ( call ) => 'PATCH' === call.method );
	expect( patch ).toHaveLength( 1 );
	expect( patch[ 0 ].body ).toMatchObject( { level: 'ultra', webp: true } );
	expect( patch[ 0 ].body ).not.toHaveProperty( 'uninstall_backups' );

	await page.reload();
	await expect( page.getByRole( 'group', { name: 'Compression level' } ).getByRole( 'radio', { name: 'Ultra' } ) ).toBeChecked();
	await expect( page.getByLabel( 'Also delete the backups' ) ).toBeChecked();
	await expect( page.getByLabel( 'Also delete the WebP and AVIF copies' ) ).not.toBeChecked();
} );
