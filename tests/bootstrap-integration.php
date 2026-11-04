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
