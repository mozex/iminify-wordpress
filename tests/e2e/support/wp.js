/**
 * Runs the seed script inside the e2e WordPress, without a shell, so the JSON it
 * is given reaches it as it is on every platform.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../..' );
const wpEnv = path.join( root, 'node_modules/@wordpress/env/bin/wp-env' );

/**
 * The command that reaches the e2e site's WP-CLI container, read once a process.
 *
 * @type {string[]|undefined}
 */
let cli;

/**
 * How WP-CLI is reached: `docker compose exec` on the compose file wp-env wrote
 * for the e2e site, which `wp-env status` names, run as `wp-env run` runs it;
 * `wp-env run` itself when the site isn't running on Docker. wp-env starts Node
 * and reads its configuration on every call, about two seconds each, and a test
 * sets up its state through several calls.
 *
 * @return {string[]} The program and its arguments, before WP-CLI's.
 */
function cliCommand() {
	if ( undefined !== cli ) {
		return cli;
	}

	cli = [ process.execPath, wpEnv, '--config=.wp-env.e2e.json', 'run', 'cli' ];

	try {
		const status = JSON.parse(
			execFileSync( process.execPath, [ wpEnv, '--config=.wp-env.e2e.json', 'status', '--json' ], {
				cwd: root,
				encoding: 'utf8',
				stdio: [ 'ignore', 'pipe', 'ignore' ],
			} )
		);

		if ( 'running' === status.status && 'docker' === status.runtime && status.installPath ) {
			cli = [ 'docker', 'compose', '-f', path.join( status.installPath, 'docker-compose.yml' ), 'exec', '-T', '-w', '/var/www/html', 'cli' ];
		}
	} catch ( error ) {
		/* An older wp-env without `status`, or no Docker: wp-env runs it. */
	}

	if ( 'docker' !== cli[ 0 ] ) {
		/* Said once, so a run that went back to the slow way shows it. */
		// eslint-disable-next-line no-console
		console.warn( 'WP-CLI goes through wp-env run: wp-env status --json named no running Docker site.' );
	}

	return cli;
}

/**
 * Runs WP-CLI in the e2e site and answers its last line of output.
 *
 * @param {...string} args WP-CLI's arguments.
 * @return {string} The last line it printed.
 */
function wp( ...args ) {
	const [ program, ...through ] = cliCommand();
	const output = execFileSync( program, [ ...through, 'wp', ...args ], {
		cwd: root,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	/* wp-env run, the fallback, adds its own status lines around the command's output. */
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
