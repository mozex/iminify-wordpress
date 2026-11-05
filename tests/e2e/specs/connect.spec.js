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
	await expect( page.getByText( 'Waiting for your approval' ) ).toBeVisible();

	seed( 'state', { approved: true } );

	await expect( page.getByRole( 'heading', { name: 'Connected' } ) ).toBeVisible( { timeout: 30_000 } );
	await expect( page.getByText( '3 used, 37 left' ) ).toBeVisible();
} );

test( 'A site setting goes to the service, and the local ones stay on the site', async ( { page } ) => {
	seed( 'connect' );
	await page.goto( settingsPage );

	await page.getByLabel( 'Compression level' ).selectOption( 'ultra' );
	await page.getByLabel( 'Also delete the backups' ).check();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.components-notice__content', { hasText: 'Settings saved.' } ) ).toBeVisible();

	const patch = seedJson( 'calls' ).filter( ( call ) => 'PATCH' === call.method );
	expect( patch ).toHaveLength( 1 );
	expect( patch[ 0 ].body ).toMatchObject( { level: 'ultra', webp: true } );
	expect( patch[ 0 ].body ).not.toHaveProperty( 'uninstall_backups' );

	await page.reload();
	await expect( page.getByLabel( 'Compression level' ) ).toHaveValue( 'ultra' );
	await expect( page.getByLabel( 'Also delete the backups' ) ).toBeChecked();
	await expect( page.getByLabel( 'Also delete the WebP and AVIF copies' ) ).not.toBeChecked();
} );
