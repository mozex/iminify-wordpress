<?php
/**
 * How a class name maps to its file.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use Iminify\WordPress\Autoloader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers WordPress's file naming under includes/ and classes outside the plugin's namespace.
 */
class AutoloaderTest extends TestCase {

	public function test_a_class_maps_to_its_wordpress_named_file(): void {
		$includes = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;

		$this->assertSame( $includes . 'class-key-store.php', Autoloader::path( 'Iminify\WordPress\Key_Store' ) );
		$this->assertSame( $includes . 'api' . DIRECTORY_SEPARATOR . 'class-client.php', Autoloader::path( 'Iminify\WordPress\Api\Client' ) );
		$this->assertFileExists( (string) Autoloader::path( 'Iminify\WordPress\Api\Client' ) );
	}

	public function test_a_class_outside_the_plugin_is_left_to_other_autoloaders(): void {
		$this->assertNull( Autoloader::path( 'Iminify\WordPressOther\Client' ) );
		$this->assertNull( Autoloader::path( 'Vendor\Iminify\WordPress\Client' ) );
	}
}
