<?php
/**
 * The design system's root classes and the scheme colour.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a unit test that reads the stylesheets' sources, without WordPress.

namespace Iminify\WordPress\Tests\Unit;

use FilesystemIterator;
use Iminify\WordPress\Admin\Scheme;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers that every root class of the design system, each block that takes
 * `tokens.root`, is one `Admin\Scheme` declares the scheme colour on, and the
 * other way round: a root it misses keeps core's default focus colour before
 * WordPress 7.0, whatever the person's scheme.
 */
class DesignRootsTest extends TestCase {

	public function test_every_root_class_takes_the_scheme_colour(): void {
		$includes = 0;
		$opening  = 0;
		$found    = array();
		$sources  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', FilesystemIterator::SKIP_DOTS ) );

		foreach ( $sources as $source ) {
			if ( 'scss' !== $source->getExtension() ) {
				continue;
			}

			$scss      = (string) preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents( $source->getPathname() ) );
			$includes += substr_count( $scss, '@include tokens.root;' );

			preg_match_all( '~([^{};]+)\{\s*@include tokens\.root;~', $scss, $blocks );
			$opening += count( $blocks[1] );

			foreach ( $blocks[1] as $selectors ) {
				foreach ( explode( ',', $selectors ) as $selector ) {
					preg_match_all( '~\.iminify-[\w-]+~', $selector, $classes );
					$found[] = (string) end( $classes[0] );
				}
			}
		}

		$roots = explode( ',', Scheme::ROOTS );
		$found = array_values( array_unique( $found ) );

		sort( $roots );
		sort( $found );

		$this->assertGreaterThan( 0, $includes );
		$this->assertSame( $includes, $opening, 'Each `@include tokens.root;` opens its block, so its selector can be read.' );
		$this->assertSame( $roots, $found, 'Admin\Scheme::ROOTS names every root class, and only those.' );
	}
}
