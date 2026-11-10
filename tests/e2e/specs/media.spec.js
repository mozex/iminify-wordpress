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

	await panel.getByRole( 'button', { name: 'Delete the copies' } ).click();
	await expect( confirm ).toContainText( "Delete this image's WebP and AVIF copies?" );
	/* A single image's copies cost one image to make again: no warning, as its Restore has none. */
	await expect( confirm ).not.toHaveCSS( 'background-color', 'rgb(255, 241, 242)' );
	await confirm.getByRole( 'button', { name: 'Delete the copies' } ).click();
	await expect( panel ).not.toContainText( 'Copies: WebP' );
	expect( seedJson( 'record', { id } ).copies ).toEqual( [] );

	await panel.getByRole( 'button', { name: 'Restore original' } ).click();
	await confirm.getByRole( 'button', { name: 'Restore original' } ).click();
	await expect( panel ).toContainText( 'Not optimized' );
	expect( seedJson( 'record', { id } ).status ).toBe( 'not_optimized' );
} );

test( 'The panel compares the original with each file the plugin made, by drag or by keys, and Escape closes only the compare', async ( {
	page,
} ) => {
	const { id } = seedJson( 'optimized' );

	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await page.locator( `.attachment[data-id="${ id }"]` ).click();
	const modal = page.locator( '.media-modal' );
	const open = modal.getByRole( 'button', {
		name: 'Compare with the original',
	} );
	await open.click();

	const dialog = page.getByRole( 'dialog', {
		name: 'Compare with the original',
	} );
	const slider = dialog.getByRole( 'slider', {
		name: 'How much of the original shows',
	} );
	await expect( dialog ).toBeVisible();
	await expect( slider ).toBeFocused();
	await expect( slider ).toHaveAttribute( 'aria-valuenow', '50' );
	await expect( dialog ).toContainText(
		'Optimized at Smart: a balance between quality and size.'
	);

	/* Both pictures load from the site: the backup of the original and the main file. */
	const loaded = () =>
		dialog
			.locator( 'img' )
			.evaluateAll( ( images ) =>
				images.map(
					( image ) => image.complete && image.naturalWidth > 0
				)
			);
	await expect.poll( loaded ).toEqual( [ true, true ] );
	await expect(
		dialog.locator( '.iminify-compare__before img' )
	).toHaveAttribute(
		'data-address',
		/\/iminify-backup\/.+iminify-e2e-photo\.jpg\?v=\d+$/
	);

	/* Each file is fetched as itself and shown from memory, so a server rule can't hand the original's side a copy. */
	await expect( dialog.locator( 'img' ).first() ).toHaveAttribute(
		'src',
		/^blob:/
	);

	/* The keys stay in the dialog: the media modal's own arrows would move it to another image. */
	await page.evaluate( () => {
		window.iminifyKeysOutside = 0;
		document.body.addEventListener( 'keydown', () => {
			window.iminifyKeysOutside++;
		} );
	} );
	await page.keyboard.press( 'ArrowLeft' );
	await expect( slider ).toHaveAttribute( 'aria-valuenow', '45' );
	expect( await page.evaluate( () => window.iminifyKeysOutside ) ).toBe( 0 );
	await page.keyboard.press( 'End' );
	await expect( slider ).toHaveAttribute( 'aria-valuenow', '100' );

	/* Tab from the last control comes round to the first, so focus never leaves the dialog for a place no key reaches. */
	await page.keyboard.press( 'Tab' );
	await expect( dialog.getByRole( 'button', { name: 'Close' } ) ).toBeFocused();
	await page.keyboard.press( 'Shift+Tab' );
	await expect( slider ).toBeFocused();

	/* A press on the stage puts the line where it landed. */
	const stage = await dialog
		.locator( '.iminify-compare__stage' )
		.boundingBox();
	await page.mouse.click(
		stage.x + stage.width / 4,
		stage.y + stage.height / 2
	);
	await expect
		.poll( async () =>
			Number( await slider.getAttribute( 'aria-valuenow' ) )
		)
		.toBeGreaterThan( 20 );
	await expect
		.poll( async () =>
			Number( await slider.getAttribute( 'aria-valuenow' ) )
		)
		.toBeLessThan( 30 );

	/* After a press on the picture or on the dialog's words, the keys still stay inside it. */
	const pressed = Number( await slider.getAttribute( 'aria-valuenow' ) );
	await page.keyboard.press( 'ArrowRight' );
	await expect( slider ).toHaveAttribute(
		'aria-valuenow',
		String( pressed + 5 )
	);
	await dialog.locator( 'p.iminify-detail' ).click();
	await page.keyboard.press( 'ArrowLeft' );
	expect( await page.evaluate( () => window.iminifyKeysOutside ) ).toBe( 0 );

	const webp = dialog.getByRole( 'button', { name: /^WebP, / } );
	await webp.click();
	await expect( webp ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( dialog.locator( '.iminify-compare__after' ) ).toHaveAttribute(
		'data-address',
		/iminify-e2e-photo\.jpg\.webp\?v=\d+$/
	);
	await expect.poll( loaded ).toEqual( [ true, true ] );

	/* The panel drawn again meanwhile, as its poll does, still gets the focus back. */
	await page.evaluate( () => {
		const button = document.querySelector( '[data-iminify-compare]' );
		button.replaceWith( button.cloneNode( true ) );
	} );
	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toHaveCount( 0 );
	await expect( modal ).toBeVisible();
	await expect( open ).toBeFocused();
} );

test( 'Start now sends the images waiting for the next batch', async ( { page } ) => {
	const { id } = seedJson( 'optimized' );
	seed( 'paused', { id } );

	await page.goto( settingsPage );
	await page.getByRole( 'button', { name: 'Start now' } ).click();
	await expect( page.locator( '.iminify-toast', { hasText: 'The images waiting are on their way.' } ) ).toBeVisible();
	expect( seedJson( 'record', { id } ).status ).toBe( 'queued' );
} );
