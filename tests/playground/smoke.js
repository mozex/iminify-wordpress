/**
 * Boots the wordpress.org Live Preview's blueprint in WordPress Playground with
 * the built plugin in place of the directory's copy, and opens each of the
 * plugin's screens as an administrator would.
 *
 * Usage: node tests/playground/smoke.js <the plugin's built folder>
 *
 * It fails on a PHP error, notice or warning, a database error, a script error
 * on a screen, a screen that doesn't draw, and on the preview reaching for
 * Iminify. A preview must never start a real pairing, so the blueprint keeps
 * Playground's networking off and writes a must-use plugin that answers every
 * call to the service itself, since Playground's CLI ignores the networking
 * switch; pressing Connect has to show that plugin's answer. The test points
 * the plugin at a host that never resolves too, so a guard that stops working
 * fails here rather than reaching the service.
 */

/* eslint-disable no-console -- a command-line script, whose output is its report. */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawn } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const SLUG = 'iminify-image-optimizer';
const PORT = Number( process.env.IMINIFY_PLAYGROUND_PORT || 9400 );
const BASE = `http://127.0.0.1:${ PORT }`;
const ROOT = path.resolve( __dirname, '../..' );
// A line the test logs itself, so a debug.log the server can't hand over fails rather than reads as empty.
const CANARY = 'iminify-smoke-canary';
const PROBLEMS = [
	/Fatal error/i,
	/Parse error/i,
	/<b>Warning<\/b>/i,
	/<b>Notice<\/b>/i,
	/<b>Deprecated<\/b>/i,
	/WordPress database error/i,
];

/**
 * The blueprint as the directory serves it, checked, with its step that
 * installs the plugin from wordpress.org turned into one that activates the
 * copy mounted from the build, and WordPress's debugging on.
 *
 * @return {Object} The blueprint to boot.
 */
function blueprint() {
	const shipped = JSON.parse(
		fs.readFileSync(
			path.join( ROOT, '.wordpress-org/blueprints/blueprint.json' ),
			'utf8'
		)
	);

	if ( ! shipped.features || shipped.features.networking !== false ) {
		throw new Error(
			'The blueprint must keep networking off, so the preview never reaches Iminify.'
		);
	}

	if ( ! String( shipped.landingPage ).startsWith( '/wp-admin/' ) ) {
		throw new Error( 'The blueprint must land on a wp-admin screen.' );
	}

	const installs = shipped.steps.filter(
		( step ) =>
			step.step === 'installPlugin' &&
			step.pluginData &&
			step.pluginData.slug === SLUG
	);

	if ( installs.length !== 1 ) {
		throw new Error(
			`The blueprint must install ${ SLUG } from wordpress.org once; it does ${ installs.length } times.`
		);
	}

	const guards = shipped.steps.filter(
		( step ) =>
			step.step === 'writeFile' &&
			String( step.path ).startsWith(
				'/wordpress/wp-content/mu-plugins/'
			) &&
			String( step.data ).includes( 'pre_http_request' )
	);

	if ( guards.length !== 1 ) {
		throw new Error(
			"The blueprint must write the must-use plugin that answers every call to Iminify itself: Playground's CLI ignores the networking switch, and a preview must never reach the service."
		);
	}

	return {
		...shipped,
		steps: [
			{
				step: 'defineWpConfigConsts',
				consts: {
					WP_DEBUG: true,
					WP_DEBUG_DISPLAY: true,
					WP_DEBUG_LOG: true,
					// A host that never resolves, so a guard that stops working fails here and never reaches Iminify.
					IMINIFY_API_BASE: 'https://iminify.invalid/api/v1',
				},
			},
			...shipped.steps.map( ( step ) =>
				step === installs[ 0 ]
					? {
							step: 'activatePlugin',
							pluginPath: `${ SLUG }/${ SLUG }.php`,
						}
					: step
			),
			{
				step: 'runPHP',
				code: `<?php require '/wordpress/wp-load.php'; error_log( '${ CANARY }' );`,
			},
		],
	};
}

/**
 * Starts Playground's server and resolves once it answers.
 *
 * @param {string} plugin The built plugin's folder.
 * @return {Promise<import('child_process').ChildProcess>} The server.
 */
async function start( plugin ) {
	const file = path.join(
		fs.mkdtempSync( path.join( os.tmpdir(), 'iminify-playground-' ) ),
		'blueprint.json'
	);
	fs.writeFileSync( file, JSON.stringify( blueprint() ) );

	const server = spawn(
		process.execPath,
		[
			path.join(
				ROOT,
				'node_modules/@wp-playground/cli/wp-playground.js'
			),
			'server',
			`--port=${ PORT }`,
			`--blueprint=${ file }`,
			'--mount-dir',
			path.resolve( plugin ),
			`/wordpress/wp-content/plugins/${ SLUG }`,
		],
		{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	let output = '';

	server.stdout.on( 'data', ( chunk ) => ( output += chunk ) );
	server.stderr.on( 'data', ( chunk ) => ( output += chunk ) );

	const deadline = Date.now() + 10 * 60 * 1000;

	while ( Date.now() < deadline ) {
		if ( server.exitCode !== null ) {
			throw new Error( `Playground stopped:\n${ output }` );
		}

		/* Playground answers as soon as WordPress boots, before the blueprint's steps run, and prints this line only once they have. */
		if ( output.includes( 'WordPress is running on' ) ) {
			try {
				const answer = await fetch( `${ BASE }/wp-login.php`, {
					redirect: 'manual',
				} );

				if ( answer.status > 0 && answer.status < 500 ) {
					return server;
				}
			} catch {
				// Not answering yet.
			}
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
	}

	server.kill();
	throw new Error( `Playground didn't start in ten minutes:\n${ output }` );
}

/**
 * Opens one screen and fails on anything wrong with it.
 *
 * @param {import('@playwright/test').Page} page     The browser tab.
 * @param {string}                          address  The screen, under wp-admin.
 * @param {string}                          selector What the screen draws once it works.
 * @param {string[]}                        errors   The tab's script errors so far.
 */
async function screen( page, address, selector, errors ) {
	const answer = await page.goto( `${ BASE }${ address }` );
	const html = await page.content();

	if ( ! answer || answer.status() !== 200 ) {
		throw new Error(
			`${ address } answered ${ answer ? answer.status() : 'nothing' }.`
		);
	}

	for ( const problem of PROBLEMS ) {
		if ( problem.test( html ) ) {
			throw new Error(
				`${ address } shows ${ problem }:\n${ html.slice( 0, 4000 ) }`
			);
		}
	}

	await page.locator( selector ).first().waitFor( { timeout: 60_000 } );

	if ( errors.length ) {
		throw new Error(
			`${ address } had script errors:\n${ errors.join( '\n' ) }`
		);
	}

	console.log( `ok ${ address }` );
}

( async () => {
	const plugin = process.argv[ 2 ];

	if ( ! plugin || ! fs.existsSync( path.join( plugin, `${ SLUG }.php` ) ) ) {
		throw new Error(
			"Give the built plugin's folder: node tests/playground/smoke.js build/iminify-image-optimizer"
		);
	}

	const server = await start( plugin );
	const browser = await chromium.launch();

	try {
		const page = await browser.newPage();
		const errors = [];
		const away = [];

		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		page.on( 'console', ( message ) => {
			const source = message.location().url || '';

			/* A request to another host is stopped below, and the browser reports each as a failed load. */
			if (
				message.type() === 'error' &&
				! (
					message.text().startsWith( 'Failed to load resource' ) &&
					source !== '' &&
					! source.startsWith( BASE )
				)
			) {
				errors.push( `${ message.text() } ${ source }` );
			}
		} );
		page.on( 'request', ( request ) => {
			if (
				! request.url().startsWith( BASE ) &&
				! request.url().startsWith( 'data:' )
			) {
				away.push( request.url() );
			}
		} );
		await page.route(
			( url ) => ! url.href.startsWith( BASE ),
			( route ) => route.abort()
		);
		page.on( 'popup', ( popup ) => away.push( `a tab: ${ popup.url() }` ) );

		await page.goto( `${ BASE }/wp-login.php` );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.click( '#wp-submit' ),
		] );

		await screen(
			page,
			blueprint().landingPage,
			'#iminify-settings button:has-text("Connect")',
			errors
		);
		await screen(
			page,
			'/wp-admin/upload.php?mode=list',
			'td.column-iminify, td[class*="iminify"]',
			errors
		);
		await screen(
			page,
			'/wp-admin/upload.php?page=iminify-bulk',
			'#iminify-bulk .iminify-panel, #iminify-bulk section',
			errors
		);
		await screen(
			page,
			'/wp-admin/upload.php?page=iminify-audit',
			'#iminify-audit .iminify-panel, #iminify-audit section',
			errors
		);
		await screen(
			page,
			'/wp-admin/upload.php?page=iminify-folders',
			'#iminify-folders .iminify-panel, #iminify-folders section',
			errors
		);

		await page.goto( `${ BASE }${ blueprint().landingPage }` );
		await page
			.locator( '#iminify-settings button:has-text("Connect")' )
			.first()
			.click();
		try {
			await page
				.getByText(
					/couldn't be reached: this preview runs in your browser/i
				)
				.first()
				.waitFor( { timeout: 60_000 } );
		} catch {
			throw new Error(
				`Connect didn't give the preview's answer. The page shows:\n${ await page
					.locator( '#iminify-settings' )
					.innerText() }`
			);
		}

		console.log( 'ok Connect reaches nothing' );

		const log = await page.request.get( `${ BASE }/wp-content/debug.log` );
		const logged = log.ok() ? await log.text() : '';

		if ( ! logged.includes( CANARY ) ) {
			throw new Error(
				`debug.log answered ${ log.status() } without the test's own line, so what PHP logged can't be read.`
			);
		}

		const others = logged
			.split( '\n' )
			.filter(
				( line ) => line.trim() !== '' && ! line.includes( CANARY )
			);

		if ( others.length ) {
			throw new Error(
				`PHP logged:\n${ others.join( '\n' ).slice( 0, 4000 ) }`
			);
		}

		const iminify = away.filter( ( url ) => /iminify/i.test( url ) );

		if ( iminify.length ) {
			throw new Error(
				`The preview reached for Iminify:\n${ iminify.join( '\n' ) }`
			);
		}

		if ( away.length ) {
			const hosts = new Set(
				away.map( ( url ) =>
					url.startsWith( 'a tab: ' ) ? url : new URL( url ).host
				)
			);

			console.log( `Kept from leaving: ${ [ ...hosts ].join( ', ' ) }` );
		}

		console.log( 'The Live Preview boots and every screen works.' );
	} finally {
		await browser.close();
		server.kill();
	}
} )().catch( ( error ) => {
	console.error( error.message );
	process.exit( 1 );
} );
