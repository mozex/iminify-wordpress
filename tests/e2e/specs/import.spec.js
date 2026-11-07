/**
 * "Use <plugin>'s settings": EWWW's settings, kept on the site with EWWW gone,
 * offered on the connected site's settings page; the first press shows what
 * Iminify proposes beside the values now and changes nothing, the second uses
 * them, and the form shows them at once.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage, wp } = require( '../support/wp' );

test( 'Another optimizer’s settings are shown before they are used, then used', async ( {
	page,
} ) => {
	/* A handful of WP-CLI calls, each a few seconds through wp-env. */
	test.slow();
	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	seed( 'reset' );
	seed( 'connect' );
	seed( 'ewww-settings' );

	await page.goto( settingsPage );
	const panel = page.locator( '.iminify-import-offer' );
	await expect(
		panel.getByRole( 'heading', { name: 'EWWW Image Optimizer’s settings' } )
	).toBeVisible();

	await panel
		.getByRole( 'button', { name: 'Use EWWW Image Optimizer’s settings' } )
		.click();
	const level = panel
		.locator( '.iminify-import > div' )
		.filter( { hasText: 'Compression level' } );
	await expect( level ).toContainText( 'Smart' );
	await expect( level ).toContainText( 'Lossless' );
	await expect(
		panel
			.locator( '.iminify-import > div' )
			.filter( { hasText: 'Make WebP copies' } )
	).toContainText( 'no change' );

	let calls = seedJson( 'calls' );
	const sent = calls.find( ( call ) => 'site/import' === call.path );
	expect( sent.body.plugin ).toBe( 'ewww' );
	expect( sent.body.settings ).toEqual( {
		ewww_image_optimizer_jpg_level: '20',
		ewww_image_optimizer_maxmediawidth: '1920',
		ewww_image_optimizer_webp: '1',
		ewww_image_optimizer_backup_files: '',
	} );
	expect( calls.some( ( call ) => 'PATCH' === call.method ) ).toBe( false );

	await panel.getByRole( 'button', { name: 'Use these settings' } ).click();
	await expect(
		page.locator( '.iminify-toast', {
			hasText: 'This site now uses EWWW Image Optimizer’s settings.',
		} )
	).toBeVisible();
	await expect( panel ).toHaveCount( 0 );

	calls = seedJson( 'calls' );
	expect(
		calls.find( ( call ) => 'PATCH' === call.method ).body
	).toEqual( {
		level: 'lossless',
		webp: true,
		max_width: 1920,
		keep_metadata: false,
		sizes: [ 'thumbnail', 'medium' ],
	} );

	/* The form starts again from what was used, with nothing left unsaved. */
	await expect( page.getByLabel( 'Compression level' ) ).toHaveValue(
		'lossless'
	);
	await expect( page.getByLabel( 'Maximum width, in pixels' ) ).toHaveValue(
		'1920'
	);
	await expect( page.getByText( 'Unsaved changes' ) ).toHaveCount( 0 );
	await expect(
		page.getByLabel( 'Keep a backup of each original' )
	).toBeChecked();
} );
