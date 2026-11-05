/**
 * Signs in as wp-env's administrator once, for every test.
 */
const { chromium } = require( '@playwright/test' );

module.exports = async ( config ) => {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.click( '#wp-submit' ),
	] );
	await page.context().storageState( { path: storageState } );
	await browser.close();
};
