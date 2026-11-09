/**
 * "Use <plugin>'s settings": EWWW's settings, kept on the site with EWWW gone,
 * offered on the connected site's settings page; the first press shows what
 * Iminify proposes beside the values now and changes nothing, the second uses
 * them, and the form shows them at once, an edit not saved yet dropped as the
 * proposal said it would be. A proposal that changes nothing ends the offer
 * with nothing sent.
 */
const { test, expect } = require( '@playwright/test' );
const { seed, seedJson, settingsPage, wp } = require( '../support/wp' );

test( 'Another optimizer’s settings are shown before they are used, then used', async ( {
	page,
} ) => {
	/* A handful of WP-CLI calls, each loading WordPress again. */
	test.slow();
	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	seed( 'reset' );
	seed( 'connect' );
	seed( 'ewww-settings' );

	await page.goto( settingsPage );
	const panel = page.locator( '.iminify-import-offer' );
	await expect(
		panel.getByRole( 'heading', {
			name: 'EWWW Image Optimizer’s settings',
		} )
	).toBeVisible();

	await panel
		.getByRole( 'button', { name: 'Use EWWW Image Optimizer’s settings' } )
		.click();
	const level = panel
		.locator( '.iminify-import > div' )
		.filter( { hasText: 'Compression level' } );
	await expect( level ).toContainText( 'Smart' );
	await expect( level ).toContainText( 'Lossless' );

	/* The proposal says it replaces an edit not saved yet only while there is one. */
	const lead = panel.getByRole( 'status' );
	const replaces = 'using them replaces the changes you haven’t saved';
	await expect( lead ).not.toContainText( replaces );
	const width = page.getByLabel( 'Maximum width, in pixels' );
	await width.fill( '1234' );
	await expect( lead ).toContainText( replaces );
	await width.fill( '' );
	await expect( lead ).not.toContainText( replaces );
	await width.fill( '1234' );
	await expect( page.getByText( 'Unsaved changes' ) ).toBeVisible();
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
	expect( calls.find( ( call ) => 'PATCH' === call.method ).body ).toEqual( {
		level: 'lossless',
		webp: true,
		max_width: 1920,
		keep_metadata: false,
		sizes: [ 'thumbnail', 'medium' ],
	} );

	/* The form starts again from what was used, with nothing left unsaved. */
	await expect(
		page
			.getByRole( 'group', { name: 'Compression level' } )
			.getByRole( 'radio', { name: 'Lossless' } )
	).toBeChecked();
	await expect( page.getByLabel( 'Maximum width, in pixels' ) ).toHaveValue(
		'1920'
	);
	await expect( page.getByText( 'Unsaved changes' ) ).toHaveCount( 0 );
	await expect(
		page.getByLabel( 'Keep a backup of each original' )
	).toBeChecked();
} );

test( 'A proposal that changes nothing ends the offer and sends nothing', async ( {
	page,
} ) => {
	test.slow();
	wp( 'plugin', 'activate', 'iminify-image-optimizer' );
	seed( 'reset' );
	seed( 'connect' );
	seed( 'ewww-settings' );
	/* The site already has what EWWW's settings match. */
	seed( 'state', {
		settings: {
			level: 'lossless',
			webp: true,
			max_width: 1920,
			keep_metadata: false,
			sizes: [ 'thumbnail', 'medium' ],
		},
	} );

	await page.goto( settingsPage );
	const panel = page.locator( '.iminify-import-offer' );
	await panel
		.getByRole( 'button', { name: 'Use EWWW Image Optimizer’s settings' } )
		.click();
	await expect( panel.getByRole( 'status' ) ).toContainText(
		'This site already uses what EWWW Image Optimizer’s settings match.'
	);

	/* A change made in the form meanwhile, which OK must neither send back nor drop. */
	await page.getByLabel( 'Maximum width, in pixels' ).fill( '1500' );
	await panel.getByRole( 'button', { name: 'OK' } ).click();
	await expect( panel ).toHaveCount( 0 );

	expect(
		seedJson( 'calls' ).some( ( call ) => 'PATCH' === call.method )
	).toBe( false );
	await expect( page.getByLabel( 'Maximum width, in pixels' ) ).toHaveValue(
		'1500'
	);
	await expect( page.getByText( 'Unsaved changes' ) ).toBeVisible();
} );
