<?php
/**
 * Integration tests run inside the test WordPress (.wp-env.test.json) on WordPress's own test library.
 *
 * @package Iminify\WordPress
 */

$iminify_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $iminify_tests_dir || ! is_file( $iminify_tests_dir . '/includes/functions.php' ) ) {
	echo 'WordPress\'s test library was not found. Run the integration tests inside the test environment: npm run test:integration' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $iminify_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/iminify-image-optimizer.php';
	}
);

require $iminify_tests_dir . '/includes/bootstrap.php';

/*
 * The database is installed now. A test run in a separate process (one that
 * defines a wp-config.php constant) reuses it rather than installing it again
 * under the tests still to come, whose connection keeps the temporary tables of
 * the sites they made on a network: a second install resets the sites' ids, and
 * the next site made takes an id whose stale tables that connection still has.
 */
putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- the test library reads it in each separate process.

/*
 * The run's own process puts the uploads folder back as it found it once the run
 * ends (Uploads_Sweep says why). A test run in a separate process starts with the
 * variable set, so it leaves the folder to the run.
 */
if ( false === getenv( 'IMINIFY_TESTS_CHILD' ) ) {
	putenv( 'IMINIFY_TESTS_CHILD=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- each separate process inherits it.
	\Iminify\WordPress\Tests\Support\Uploads_Sweep::start( wp_upload_dir( null, false )['basedir'] );
}
