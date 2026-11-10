<?php
/**
 * The check CI runs on what ships.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a unit test that lays out folders, without WordPress.

namespace Iminify\WordPress\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/check-build.php';

/**
 * Covers bin/check-build.php on small folders laid out as the build lays
 * out the plugin.
 */
class BuildCheckTest extends TestCase {

	/**
	 * The folder each test lays out.
	 *
	 * @var string
	 */
	private $dir;

	protected function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/iminify-build-check-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down(): void {
		$this->remove( $this->dir );
		parent::tear_down();
	}

	public function test_the_service_the_licence_and_an_svg_namespace_pass(): void {
		$this->write( 'includes/class-client.php', "<?php \$base = 'https://www.iminify.com/api/v1'; \$scheme = 'https://' . \$host;" );
		$this->write( 'license.txt', 'See https://www.gnu.org/licenses/ and https://fsf.org/.' );
		$this->write( 'assets/images/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
		$this->write( 'languages/x.mo', "https://www.iminify.com\0image\0https://wordpress.org/support/plugin/iminify-image-optimizer\0" );
		$this->write( 'languages/x.json', '{"a":"See https:\/\/www.iminify.com\/pricing"}' );
		$this->write( 'assets/build/settings.js', 'if(e.test(a)){return b.example(c)}x.replace(/a/g,"");a?e.test:b;u="//"+host;img.src="//www.iminify.com/x.png"' );

		$this->assertSame( array(), iminify_check_addresses( $this->dir ) );
	}

	public function test_any_other_address_is_named_with_its_file_however_it_is_written(): void {
		$this->write( 'assets/build/audit.js', 'img.src="//cdn.example.com/a.png"' );
		$this->write( 'assets/build/settings.js', 'fetch("https://cdn.example.com/x.js")' );
		$this->write( 'assets/build/settings.css', '.a{background:url(//cdn.example.com/x.png)}' );
		$this->write( 'languages/x.json', '{"a":"See https:\/\/cdn.example.com\/x"}' );
		$this->write( 'readme.txt', 'Read https://www.iminify.com.example.net/ first.' );

		$this->assertSame(
			array(
				'assets/build/audit.js: //cdn.example.com/a.png',
				'assets/build/settings.css: //cdn.example.com/x.png',
				'assets/build/settings.js: https://cdn.example.com/x.js',
				'languages/x.json: https://cdn.example.com/x',
				'readme.txt: https://www.iminify.com.example.net/',
			),
			iminify_check_addresses( $this->dir )
		);
	}

	public function test_action_schedulers_own_links_pass(): void {
		$this->write( 'vendor/woocommerce/action-scheduler/readme.txt', 'Docs: https://actionscheduler.org/ and https://github.com/woocommerce/action-scheduler' );

		$this->assertSame( array(), iminify_check_addresses( $this->dir ) );
	}

	/**
	 * Each kind of local name, planted in Action Scheduler's folder so only the
	 * local names are read there, with what the check names. A backslash is
	 * written \x5c.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function local_names(): array {
		return array(
			'localhost'                    => array( 'see localhost', 'localhost' ),
			'loopback address'             => array( 'at 127.0.0.1:80', '127.0.0.1' ),
			'container host'               => array( 'h=host.docker.internal;', 'host.docker.internal' ),
			'reserved host after //'       => array( 'https://shop.test/api', '//shop.test' ),
			'reserved host standing alone' => array( '"api.shop.example:8443"', 'api.shop.example' ),
			'drive path with slashes'      => array( 'C:/Users/dev/site', 'C:/Users/' ),
			'drive path, escaped'          => array( "\"C:\x5c\x5cUsers\x5c\x5cdev\"", "C:\x5c\x5cUsers\x5c" ),
			'drive path, any folder'       => array( "D:\x5cprojects\x5csite", "D:\x5cprojects\x5c" ),
			'WSL network path'             => array( "\x5c\x5cwsl\$\x5cUbuntu", "\x5c\x5cwsl\$" ),
			'WSL mount'                    => array( 'cd /mnt/c/site', '/mnt/c/' ),
			'Git Bash drive'               => array( 'cd /d/projects/site/', '/d/projects/' ),
			'macOS home'                   => array( 'see /Users/alice/site', '/Users/alice/' ),
			'Linux home'                   => array( 'see /home/runner/site', '/home/runner/' ),
		);
	}

	/**
	 * @dataProvider local_names
	 *
	 * @param string $content What the file holds.
	 * @param string $named   What the check names.
	 */
	public function test_a_local_name_is_found_even_in_action_schedulers_folder( string $content, string $named ): void {
		$this->write( 'vendor/woocommerce/action-scheduler/x.php', $content );

		$this->assertContains( 'vendor/woocommerce/action-scheduler/x.php: ' . $named, iminify_check_addresses( $this->dir ) );
	}

	public function test_every_locale_has_its_files_and_the_checkouts_bytes(): void {
		$source = $this->dir . '/source';
		$build  = $this->dir . '/build';
		$pot    = "#: assets/build/settings.js:1 assets/build/media-grid.js:1\nmsgid \"Optimized\"\n";
		$json   = 'iminify-image-optimizer-de_DE-' . md5( 'assets/build/settings.js' ) . '.json';
		$grid   = 'iminify-image-optimizer-de_DE-' . md5( 'assets/build/media-grid.js' ) . '.json';

		foreach ( array( $source, $build ) as $root ) {
			$this->write( substr( $root, strlen( $this->dir ) + 1 ) . '/languages/iminify-image-optimizer.pot', $pot );

			foreach ( array( 'iminify-image-optimizer-de_DE.po', 'iminify-image-optimizer-de_DE.mo', 'iminify-image-optimizer-de_DE.l10n.php', $json, $grid ) as $file ) {
				$this->write( substr( $root, strlen( $this->dir ) + 1 ) . '/languages/' . $file, $file );
			}
		}

		$this->write( 'source/languages/GLOSSARY.md', 'Kept out of the zip.' );
		$this->write( 'build/assets/build/settings.js', '' );
		$this->write( 'build/assets/build/media-grid.js', '' );

		$this->assertSame( array(), iminify_check_locales( $build, $source ) );

		unlink( $build . '/languages/iminify-image-optimizer-de_DE.mo' );
		unlink( $build . '/languages/' . $grid );
		unlink( $build . '/assets/build/media-grid.js' );
		$this->write( 'build/languages/iminify-image-optimizer-de_DE.po', 'An older copy.' );

		$this->assertSame(
			array(
				'languages/' . $grid . ': missing',
				'languages/iminify-image-optimizer-de_DE.mo: missing',
				'languages/iminify-image-optimizer-de_DE.po: not the checkout\'s',
				'assets/build/media-grid.js: missing',
			),
			iminify_check_locales( $build, $source )
		);
	}

	/**
	 * Writes a file under the test's folder, making its folders first.
	 *
	 * @param string $path    The file's path in the folder.
	 * @param string $content What it holds.
	 */
	private function write( string $path, string $content ): void {
		$file = $this->dir . '/' . $path;

		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0755, true );
		}

		file_put_contents( $file, $content );
	}

	/**
	 * Deletes a folder and everything in it.
	 *
	 * @param string $path The folder.
	 */
	private function remove( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}

		rmdir( $path );
	}
}
