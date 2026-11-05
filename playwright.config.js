/**
 * The browser tests: the plugin's screens on the e2e WordPress (.wp-env.e2e.json,
 * port 8890), whose must-use stub answers every call to Iminify from the
 * fixtures. They share one site, so they run one at a time.
 */
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: require.resolve( './tests/e2e/support/global-setup.js' ),
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 90_000,
	reporter: process.env.CI ? [ [ 'list' ], [ 'github' ] ] : 'list',
	use: {
		baseURL: process.env.IMINIFY_E2E_URL || 'http://localhost:8890',
		storageState: 'tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
