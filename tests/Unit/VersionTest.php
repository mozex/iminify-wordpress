<?php
/**
 * The version and requirements the plugin states in more than one place.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * wordpress.org reads the version a release ships from the readme's Stable
 * tag, WordPress reads it from the main file's header, the plugin's own code
 * from its constant, and the deploy tags the release with it; the
 * requirements are read from the main file, and the readme repeats them.
 */
class VersionTest extends TestCase {

	public function test_every_place_names_the_same_version(): void {
		$readme = $this->read( 'readme.txt' );
		$main   = $this->read( 'iminify-image-optimizer.php' );

		preg_match( '/^Stable tag:\s*(\S+)$/mi', $readme, $stable );
		preg_match( '/^ \* Version:\s*(\S+)$/m', $main, $header );
		preg_match( "/define\( 'IMINIFY_VERSION', '([^']+)' \);/", $main, $constant );
		preg_match( '/^== Changelog ==\s+= (\S+) =/m', $readme, $changelog );

		$version = $stable[1] ?? '';

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $version, 'The readme\'s Stable tag is a version wordpress.org takes as a tag.' );
		$this->assertSame( $version, $header[1] ?? null, 'The main file\'s Version' );
		$this->assertSame( $version, $constant[1] ?? null, 'IMINIFY_VERSION' );
		$this->assertSame( $version, $changelog[1] ?? null, 'The changelog\'s newest entry' );
	}

	public function test_the_readme_repeats_the_main_files_requirements_and_alone_says_what_it_was_tested_up_to(): void {
		$readme = $this->read( 'readme.txt' );
		$main   = $this->read( 'iminify-image-optimizer.php' );

		foreach ( array( 'Requires at least', 'Requires PHP' ) as $field ) {
			preg_match( '/^' . $field . ':\s*(\S+)$/mi', $readme, $in_readme );
			preg_match( '/^ \* ' . $field . ':\s*(\S+)$/m', $main, $in_main );

			$required = $in_main[1] ?? '';

			$this->assertNotSame( '', $required, $field . ' in the main file' );
			$this->assertSame( $required, $in_readme[1] ?? null, $field . ' in the readme' );
		}

		$this->assertMatchesRegularExpression( '/^Tested up to:\s*\d+\.\d+$/mi', $readme );
		$this->assertStringNotContainsString( 'Tested up to', $main );
	}

	/**
	 * Reads a file of the plugin.
	 *
	 * @param string $file Its path in the plugin.
	 */
	private function read( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a unit test, without WordPress.
	}
}
