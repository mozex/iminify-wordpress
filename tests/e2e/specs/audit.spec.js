/**
 * The page audit: a stubbed audit's every finding beside its attachment and
 * size at desktop and phone width and right to left, a scan started and
 * followed until it is done, a page the site turned away, the refusals, and a
 * site that isn't connected.
 */
const { test, expect } = require( '@playwright/test' );
const { wp, seed, seedJson, settingsPage } = require( '../support/wp' );

const auditPage = '/wp-admin/upload.php?page=iminify-audit';

test.beforeEach( () => {
	seed( 'reset' );
	seed( 'connect' );
} );

/**
 * One image of the page in the list, by the words in its row.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          text Words in its row.
 */
function shot( page, text ) {
	return page.locator( '.iminify-shot', { hasText: text } );
}

for ( const width of [ 1280, 390 ] ) {
	test( `Every finding of an audit shows beside its attachment and size, at ${ width } px`, async ( { page } ) => {
		seed( 'audit-images' );
		seed( 'state', { audited: true } );

		await page.setViewportSize( { width, height: 900 } );
		await page.goto( auditPage );

		await expect( page.getByText( '4 images on the page, 3 with something to fix.' ) ).toBeVisible();

		const main = shot( page, 'Full Size, 800 × 500' );
		await expect( main.locator( '.iminify-shot__name a' ) ).toHaveText( 'iminify-e2e-audited' );
		await expect( main.locator( '.iminify-thumb img' ) ).toHaveAttribute( 'src', /iminify-e2e-audited-150x150\.jpg$/ );
		await expect( main ).toContainText( 'Oversized' );
		await expect( main ).toContainText( 'Show a size about 1,280 pixels wide here' );
		await expect( main ).toContainText( 'No width and height' );
		await expect( main ).toContainText( 'Give the image its width and height in the page.' );
		await expect( main ).toContainText( 'No alt text' );
		await expect( main ).toContainText( 'Not prioritized' );
		await expect( main ).toContainText( 'Largest in the first screen' );
		await expect( main ).toContainText( 'Not optimized' );

		const copy = shot( page, 'WEBP copy' );
		await expect( copy ).toContainText( 'Medium, 300 × 188' );
		await expect( copy ).toContainText( 'Loads too early' );
		await expect( copy ).toContainText( 'Load the image lazily' );

		await expect( shot( page, 'Thumbnail, 150 × 150' ) ).toContainText( 'Lazy in the first screen' );

		/* The theme's image, which no attachment is, shows by its address once asked for. */
		await expect( shot( page, 'logo.png' ) ).toHaveCount( 0 );
		await page.getByRole( 'button', { name: 'Show 1 image with nothing to fix' } ).click();
		await expect( shot( page, 'logo.png' ) ).toContainText( '/wp-content/themes/twentytwentyfive/assets/logo.png' );
		await expect( shot( page, 'logo.png' ).locator( 'img' ) ).toHaveCount( 0 );

		await expect( page.getByText( '2 of 3 page scans left today.' ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: /Set up a weekly check/ } ) ).toHaveAttribute( 'href', 'https://www.iminify.com/scan?utm_source=wordpress&utm_medium=plugin&utm_campaign=audit' );
		await expect( page.locator( '.iminify-audits__item' ) ).toHaveCount( 1 );
		await expect( page.locator( '.iminify-audits__item' ) ).toHaveAttribute( 'aria-current', 'true' );

		/* Nothing in the screen is wider than the screen. */
		const overflow = await page.locator( '#iminify-audit' ).evaluate( ( app ) => app.scrollWidth - app.clientWidth );
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
}

test( 'Beside the findings: the alt text setting, filling in what the markup lacks, and optimizing the image', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true } );

	await page.goto( auditPage );

	const main = shot( page, 'Full Size, 800 × 500' );

	/* The site's AI alt text is off, so the fix sends the person to turn it on. */
	await expect( main ).toContainText( 'Iminify can write it once AI alt text is on for this site.' );
	await expect( main.getByRole( 'link', { name: 'Turn it on in the settings' } ) ).toHaveAttribute( 'href', /page=iminify-image-optimizer/ );

	const fill = page.locator( '.iminify-callout', { hasText: 'without its width and height' } );
	await expect( fill ).toContainText( '1 image from your media library is on this page' );
	await fill.getByRole( 'button', { name: 'Fill them in on every page' } ).click();

	await expect( page.getByText( 'Your pages now get the missing width, height and alt text' ) ).toBeVisible();
	await expect( fill ).toHaveCount( 0 );
	await expect( main ).toContainText( 'The plugin adds them as the page is sent.' );
	expect( JSON.parse( wp( 'option', 'get', 'iminify_settings', '--format=json' ) ).complete_images ).toBe( true );

	await main.getByRole( 'button', { name: 'Optimize' } ).click();

	await expect( page.getByText( 'The image is on its way to Iminify.' ) ).toBeVisible();
	await expect( main ).toContainText( 'On its way' );
	await expect( main.getByRole( 'button', { name: 'Optimize' } ) ).toHaveCount( 0 );
} );

test( 'Right to left, the page loads its right-to-left stylesheet', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, rtl: true } );

	await page.goto( auditPage );

	await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
	await expect( page.locator( 'link[href*="/assets/build/audit-rtl.css"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'link[href*="/assets/build/audit.css"]' ) ).toHaveCount( 0 );
	await expect( shot( page, 'Full Size, 800 × 500' ) ).toContainText( 'Oversized' );
} );

test( 'A scan starts on a press, shows while it runs and fills in once it is done', async ( { page } ) => {
	seed( 'audit-images' );

	await page.goto( auditPage );
	await expect( page.getByText( 'Choose a page and press Scan.' ) ).toBeVisible();
	await expect( page.getByLabel( 'Page', { exact: true } ) ).toHaveValue( /\/$/ );

	await page.getByRole( 'button', { name: 'Scan', exact: true } ).click();
	await expect( page.getByText( 'Iminify is scanning the page.' ) ).toBeVisible();
	await expect( page.locator( '.iminify-panel__head' ).getByText( 'Waiting to scan' ) ).toBeVisible();
	await expect( page.getByText( 'Iminify is opening the page and measuring its images.' ) ).toBeVisible();

	const started = seedJson( 'calls' ).filter( ( call ) => 'POST' === call.method && 'site/audits' === call.path );
	expect( started ).toHaveLength( 1 );
	expect( started[ 0 ].body.url ).toMatch( /^http:\/\/localhost:\d+\/$/ );

	seed( 'state', { audit_status: 'completed' } );

	await expect( page.getByText( '4 images on the page, 3 with something to fix.' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '.iminify-audits__item' ) ).toContainText( 'Done' );
} );

test( 'A page the site turned away says how to let the scanner through, and a refused scan says why', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, audit_blocked: true } );

	await page.goto( auditPage );
	await expect( page.getByText( 'it answered 403' ) ).toBeVisible();
	await expect( page.getByText( 'Bot Fight Mode' ) ).toBeVisible();
	await expect( page.locator( '.iminify-audits__item' ) ).toContainText( "Couldn't read the page" );

	await page.getByLabel( 'Page', { exact: true } ).selectOption( { label: 'Another address on this site' } );
	await page.getByLabel( 'Address' ).fill( 'https://elsewhere.example.net/' );
	await page.getByRole( 'button', { name: 'Scan', exact: true } ).click();
	await expect( page.getByRole( 'alert' ) ).toContainText( "this site's own pages" );

	/* A spent day is said once, with no count of its hours in seconds, and Scan stays pressable. */
	seed( 'state', { audit_spent: true } );

	await page.getByLabel( 'Page', { exact: true } ).selectOption( { label: 'Home page' } );
	await page.getByRole( 'button', { name: 'Scan', exact: true } ).click();
	await expect( page.getByRole( 'alert' ) ).toContainText( 'Come back in about 14 hours' );
	await expect( page.getByText( 'You can scan again in' ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'button', { name: 'Scan', exact: true } ) ).toBeEnabled();
} );

test( 'A scan refused for the minute counts down its wait while the audit already running is followed to its end', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, audit_status: 'scanning', audit_wait: 42 } );

	await page.goto( auditPage );
	await expect( page.locator( '.iminify-panel__head' ).getByText( 'Scanning' ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Scan', exact: true } ).click();
	await expect( page.getByRole( 'alert' ) ).toContainText( 'Iminify asked the plugin to wait.' );
	await expect( page.getByText( /You can scan again in \d+ seconds\./ ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Scan', exact: true } ) ).toBeDisabled();

	seed( 'state', { audit_status: 'completed' } );

	await expect( page.getByText( '4 images on the page, 3 with something to fix.' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '.iminify-audits__item' ) ).toContainText( 'Done' );
} );

test( 'An older audit opens from the list while the newer one runs, and the newer reaches Done in the list', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, older: true, audit_status: 'scanning' } );

	await page.goto( auditPage );
	const items = page.locator( '.iminify-audits__item' );
	await expect( items ).toHaveCount( 2 );
	await expect( items.nth( 0 ) ).toContainText( 'Scanning' );

	await items.nth( 1 ).click();
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();
	await expect( page.getByText( '1 image on the page, 1 with something to fix.' ) ).toBeVisible();
	await expect( items.nth( 1 ) ).toHaveAttribute( 'aria-current', 'true' );

	seed( 'state', { audit_status: 'completed' } );

	await expect( items.nth( 0 ) ).toContainText( 'Done', { timeout: 15_000 } );
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();

	expect( reads( '9c2b3c4d-1111-4a2b-9c3d-000000000002' ) ).toBeGreaterThanOrEqual( 2 );
} );

/**
 * The reads of one audit the stub answered so far.
 *
 * @param {string} id The audit's id.
 * @return {number} How many.
 */
function reads( id ) {
	return seedJson( 'calls' ).filter( ( call ) => 'GET' === call.method && `site/audits/${ id }` === call.path ).length;
}

test( 'A read of a running audit that fails is tried again until the audit is done', async ( { page } ) => {
	const newest = '9c2b3c4d-1111-4a2b-9c3d-000000000001';

	seed( 'audit-images' );
	seed( 'state', { audited: true, audit_status: 'scanning' } );

	await page.goto( auditPage );
	await expect( page.locator( '.iminify-panel__head' ).getByText( 'Scanning' ) ).toBeVisible();

	const before = reads( newest );
	seed( 'state', { audit_down: newest } );

	/* The first read after the outage fails; a second one shows the poll went on. */
	await expect.poll( () => reads( newest ), { timeout: 20_000 } ).toBeGreaterThanOrEqual( before + 2 );

	seed( 'state', { audit_down: '', audit_status: 'completed' } );

	await expect( page.getByText( '4 images on the page, 3 with something to fix.' ) ).toBeVisible( { timeout: 15_000 } );
} );

test( 'With an older audit open, a failed read of it or of the list keeps both while the newer one is followed to Done', async ( { page } ) => {
	const older = '9c2b3c4d-1111-4a2b-9c3d-000000000002';

	seed( 'audit-images' );
	seed( 'state', { audited: true, older: true, audit_status: 'scanning' } );

	await page.goto( auditPage );
	const items = page.locator( '.iminify-audits__item' );
	await items.nth( 1 ).click();
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();

	const before = reads( older );
	seed( 'state', { audit_down: older } );

	await expect.poll( () => reads( older ), { timeout: 15_000 } ).toBeGreaterThan( before );
	await expect( page.getByRole( 'alert' ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();
	await expect( items ).toHaveCount( 2 );

	seed( 'state', { audit_down: 'list' } );
	await page.waitForTimeout( 5_000 );
	await expect( items ).toHaveCount( 2 );
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();

	seed( 'state', { audit_down: '', audit_status: 'completed' } );

	await expect( items.nth( 0 ) ).toContainText( 'Done', { timeout: 15_000 } );
	await expect( page.getByRole( 'heading', { name: 'Sample Page' } ) ).toBeVisible();
	await expect( page.getByRole( 'alert' ) ).toHaveCount( 0 );
} );

test( 'A key forgotten elsewhere while an audit runs shows the site not connected', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, audit_status: 'scanning' } );

	await page.goto( auditPage );
	await expect( page.locator( '.iminify-panel__head' ).getByText( 'Scanning' ) ).toBeVisible();

	wp( 'eval', 'Iminify\\WordPress\\Plugin::keys()->forget();' );

	await expect( page.getByText( 'Connect this site to Iminify to audit its pages.' ) ).toBeVisible( { timeout: 15_000 } );
} );

test( 'A key Iminify stops while an audit runs shows the site disconnected', async ( { page } ) => {
	seed( 'audit-images' );
	seed( 'state', { audited: true, audit_status: 'scanning' } );

	await page.goto( auditPage );
	await expect( page.locator( '.iminify-panel__head' ).getByText( 'Scanning' ) ).toBeVisible();

	seed( 'state', { audit_revoked: true } );

	await expect( page.getByText( 'Iminify disconnected this site, so its pages can’t be audited.' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '#iminify-conn' ) ).toHaveAttribute( 'data-state', 'off' );
} );

test( 'A site that isn’t connected is sent to the settings', async ( { page } ) => {
	seed( 'reset' );

	await page.goto( auditPage );
	await expect( page.getByText( 'Connect this site to Iminify to audit its pages.' ) ).toBeVisible();
	await expect( page.getByRole( 'link', { name: 'Go to the settings' } ) ).toHaveAttribute( 'href', new RegExp( settingsPage.replace( /[?.]/g, '\\$&' ) + '$' ) );
} );
