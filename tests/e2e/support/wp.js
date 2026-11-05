/**
 * Runs the seed script inside the e2e WordPress through wp-env, without a shell,
 * so the JSON it is given reaches it as it is on every platform.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../..' );
const wpEnv = path.join( root, 'node_modules/@wordpress/env/bin/wp-env' );

/**
 * Runs WP-CLI in the e2e site and answers its last line of output.
 *
 * @param {...string} args WP-CLI's arguments.
 * @return {string} The last line it printed.
 */
function wp( ...args ) {
	const output = execFileSync( process.execPath, [ wpEnv, '--config=.wp-env.e2e.json', 'run', 'cli', 'wp', ...args ], {
		cwd: root,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	/* wp-env adds its own status lines around the command's output. */
	const lines = output
		.trim()
		.split( /\r?\n/ )
		.filter( ( line ) => line && ! /^\s*[✔ℹ✖]/.test( line ) );

	return lines[ lines.length - 1 ] ?? '';
}

/**
 * Runs one of the seed script's commands and answers its last line of output.
 *
 * @param {string} command The command.
 * @param {Object} [given] What it is given, as JSON.
 * @return {string} The last line it printed.
 */
function seed( command, given ) {
	const args = [ 'eval-file', 'wp-content/plugins/iminify-image-optimizer/tests/e2e/bin/seed.php', command ];

	if ( undefined !== given ) {
		args.push( JSON.stringify( given ) );
	}

	return wp( ...args );
}

/**
 * The same, read as JSON.
 *
 * @param {string} command The command.
 * @param {Object} [given] What it is given.
 * @return {*} What it printed.
 */
function seedJson( command, given ) {
	return JSON.parse( seed( command, given ) );
}

const settingsPage = '/wp-admin/options-general.php?page=iminify-image-optimizer';

module.exports = { wp, seed, seedJson, settingsPage };
