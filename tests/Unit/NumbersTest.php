<?php
/**
 * Every number the plugin shows goes through Translations.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * WordPress writes a space thousands separator as the `&nbsp;` entity, which the
 * screens' scripts, REST answers and WP-CLI print as six characters, so only
 * `Translations::number()` and `size()` may call core's formatters.
 */
class NumbersTest extends TestCase {

	public function test_only_translations_calls_cores_number_formatters(): void {
		$root    = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$files   = array( $root . 'iminify-image-optimizer.php', $root . 'uninstall.php' );
		$callers = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'includes', RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() && 'class-translations.php' !== $file->getFilename() ) {
				$files[] = $file->getPathname();
			}
		}

		foreach ( $files as $file ) {
			if ( preg_match( '/\b(number_format_i18n|size_format)\b/', (string) file_get_contents( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own source.
				$callers[] = basename( $file );
			}
		}

		$this->assertSame( array(), $callers );
	}
}
