<?php
/**
 * The check of the directory's assets.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a unit test that lays out folders, without WordPress.

namespace Iminify\WordPress\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/check-assets.php';

/**
 * Covers bin/check-assets.php on the plugin's own assets and on small
 * folders laid out with one fault each.
 */
class AssetsTest extends TestCase {

	/**
	 * The folder each test lays out.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * A readme whose Screenshots section captions two screenshots.
	 */
	const README = "=== Plugin ===\n\n== Frequently Asked Questions ==\n\n= A question =\n\nAn answer.\n\n== Screenshots ==\n\n1. The first screen.\n2. The second screen.\n\n== Changelog ==\n\n= 1.0.0 =\n* First release.\n";

	protected function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/iminify-assets-check-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down(): void {
		$this->remove( $this->dir );
		parent::tear_down();
	}

	public function test_the_plugins_own_assets_pass(): void {
		$root = dirname( __DIR__, 2 );

		$this->assertSame( array(), iminify_check_assets( $root . '/.wordpress-org', (string) file_get_contents( $root . '/readme.txt' ) ) );
	}

	public function test_a_complete_folder_passes(): void {
		$this->lay_out();

		$this->assertSame( array(), iminify_check_assets( $this->dir, self::README ) );
	}

	public function test_an_empty_folder_lacks_every_asset(): void {
		$this->assertSame(
			array(
				'icon-128x128: missing',
				'icon-256x256: missing',
				'banner-772x250: missing',
				'banner-1544x500: missing',
				'screenshot-1: missing',
				'readme.txt: captions numbered 1, 2 for 0 screenshots',
			),
			iminify_check_assets( $this->dir, self::README )
		);
		$this->assertSame( array( $this->dir . '/none: no assets folder' ), iminify_check_assets( $this->dir . '/none', self::README ) );
	}

	public function test_an_asset_at_the_wrong_size_fails(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/icon-128x128.png', $this->png( 64, 64 ) );
		file_put_contents( $this->dir . '/banner-1544x500.png', $this->png( 1544, 501 ) );

		$this->assertSame(
			array( 'banner-1544x500.png: 1544x501, not 1544x500', 'icon-128x128.png: 64x64, not 128x128' ),
			iminify_check_assets( $this->dir, self::README )
		);
	}

	public function test_an_image_in_another_format_than_its_name_fails(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/screenshot-2.png', 'GIF89a' . pack( 'vv', 1280, 800 ) . "\x00\x00\x00;" );
		file_put_contents( $this->dir . '/icon-256x256.png', 'not an image' );

		$this->assertSame(
			array( 'icon-256x256.png: not a PNG image', 'screenshot-2.png: not a PNG image' ),
			iminify_check_assets( $this->dir, self::README )
		);
	}

	public function test_an_asset_over_its_size_fails(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/icon-128x128.png', $this->png( 128, 128, array( 'IDAT' => str_repeat( "\0", 1000000 ) ) ) );

		$this->assertSame( array( 'icon-128x128.png: ' . filesize( $this->dir . '/icon-128x128.png' ) . ' bytes, over 1000000' ), iminify_check_assets( $this->dir, self::README ) );
	}

	public function test_metadata_in_an_image_fails(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/screenshot-1.png', $this->png( 1280, 800, array( 'tEXt' => "date:create\0" . '2026-11-10' ) ) );
		file_put_contents( $this->dir . '/banner-772x250.png', $this->png( 772, 250, array( 'tIME' => str_repeat( "\0", 7 ) ) ) );
		unlink( $this->dir . '/screenshot-2.png' );
		file_put_contents( $this->dir . '/screenshot-2.jpg', $this->jpeg( 1280, 800, "\xFF\xE1" . pack( 'n', 8 ) . "Exif\0\0" ) );
		file_put_contents( $this->dir . '/icon-128x128.png', $this->png( 128, 128, array( 'iTXt' => "XML:com.adobe.xmp\0\0\0\0\0<x/>" ) ) );
		file_put_contents( $this->dir . '/icon-256x256.png', $this->png( 256, 256, array( 'prVt' => 'x' ) ) . 'trailing' );

		$this->assertSame(
			array(
				'banner-772x250.png: carries a chunk named tIME',
				'icon-128x128.png: carries a chunk named iTXt',
				'icon-256x256.png: carries a chunk named prVt',
				'icon-256x256.png: carries bytes after its end',
				'screenshot-1.png: carries a chunk named tEXt',
				'screenshot-2.jpg: carries an APP or comment segment (0xE1)',
			),
			iminify_check_assets( $this->dir, self::README )
		);

		file_put_contents( $this->dir . '/screenshot-2.jpg', $this->jpeg( 1280, 800, "\xFF\xFE" . pack( 'n', 6 ) . 'made' . "\xFF\xED" . pack( 'n', 6 ) . '8BIM' ) );
		$this->assertSame(
			array( 'screenshot-2.jpg: carries an APP or comment segment (0xFE)', 'screenshot-2.jpg: carries an APP or comment segment (0xED)' ),
			array_values( (array) preg_grep( '~^screenshot-2~', iminify_check_assets( $this->dir, self::README ) ) )
		);

		file_put_contents(
			$this->dir . '/icon-128x128.png',
			$this->png(
				128,
				128,
				array(
					'sRGB' => "\0",
					'pHYs' => str_repeat( "\0", 9 ),
				)
			)
		);
		file_put_contents( $this->dir . '/icon-256x256.png', $this->png( 256, 256 ) );

		file_put_contents( $this->dir . '/screenshot-2.jpg', $this->jpeg( 1280, 800 ) );
		file_put_contents( $this->dir . '/screenshot-1.png', $this->png( 1280, 800 ) );
		file_put_contents( $this->dir . '/banner-772x250.png', $this->png( 772, 250 ) );

		$this->assertSame( array(), iminify_check_assets( $this->dir, self::README ) );
	}

	public function test_a_name_wordpress_org_doesnt_read_fails(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/Screenshot-3.png', $this->png( 10, 10 ) );
		file_put_contents( $this->dir . '/banner-772x250.webp', 'x' );
		file_put_contents( $this->dir . '/icon-128x128.jpg', $this->jpeg( 128, 128 ) );
		mkdir( $this->dir . '/drafts' );

		$this->assertSame(
			array(
				'Screenshot-3.png: not a name wordpress.org reads',
				'banner-772x250.webp: not a name wordpress.org reads',
				'drafts/: not a folder wordpress.org reads',
				'icon-128x128: in more than one format',
			),
			iminify_check_assets( $this->dir, self::README )
		);
	}

	public function test_screenshots_and_captions_must_match_one_for_one(): void {
		$this->lay_out();
		file_put_contents( $this->dir . '/screenshot-4.png', $this->png( 1280, 800 ) );

		$this->assertSame(
			array( 'screenshots: numbered 1, 2, 4, not 1 to 3', 'readme.txt: captions numbered 1, 2 for 3 screenshots' ),
			iminify_check_assets( $this->dir, self::README )
		);

		unlink( $this->dir . '/screenshot-4.png' );
		$this->assertSame(
			array( 'readme.txt: captions numbered 1 for 2 screenshots' ),
			iminify_check_assets( $this->dir, str_replace( "2. The second screen.\n", '', self::README ) )
		);
		$this->assertSame(
			array( 'readme.txt: a Screenshots line that is no numbered caption: 2.', 'readme.txt: captions numbered 1 for 2 screenshots' ),
			iminify_check_assets( $this->dir, str_replace( '2. The second screen.', '2.', self::README ) )
		);
		$this->assertSame(
			array( 'readme.txt: captions numbered 2, 1 for 2 screenshots' ),
			iminify_check_assets( $this->dir, str_replace( array( '1. The first', '2. The second' ), array( '2. The first', '1. The second' ), self::README ) )
		);
		$this->assertSame(
			array( 'readme.txt: no Screenshots section' ),
			iminify_check_assets( $this->dir, (string) preg_replace( '~== Screenshots ==.*?(?=== Changelog)~s', '', self::README ) )
		);
	}

	public function test_an_svg_icon_that_runs_or_loads_anything_fails(): void {
		$this->lay_out();
		$cases = array(
			'<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><image href="https://example.com/a.png"/></svg>' => array( 'icon.svg: runs a script', 'icon.svg: loads another file' ),
			'<svg xmlns="http://www.w3.org/2000/svg"><path style="fill: url( \'https://cdn.example.com/p.svg#a\' )"/></svg>' => array( 'icon.svg: loads another file' ),
			'<svg xmlns="http://www.w3.org/2000/svg"><style>@import "a.css";</style></svg>' => array( 'icon.svg: loads another file' ),
			'<svg xmlns="http://www.w3.org/2000/svg"><!-- C:/Users/someone/art/icon.svg --></svg>' => array( 'icon.svg: names a local address or path' ),
			'<html><svg></svg></html>' => array( 'icon.svg: not an SVG image' ),
		);

		foreach ( $cases as $svg => $expected ) {
			file_put_contents( $this->dir . '/icon.svg', $svg );
			$this->assertSame( $expected, iminify_check_assets( $this->dir, self::README ), $svg );
		}
	}

	/**
	 * Lays out a complete set of assets with two screenshots and the
	 * Live Preview's folder.
	 */
	private function lay_out(): void {
		file_put_contents( $this->dir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g"/></defs><path fill="url(#g)" d="M0 0h64v64H0z"/></svg>' );
		file_put_contents( $this->dir . '/icon-128x128.png', $this->png( 128, 128 ) );
		file_put_contents( $this->dir . '/icon-256x256.png', $this->png( 256, 256 ) );
		file_put_contents( $this->dir . '/banner-772x250.png', $this->png( 772, 250 ) );
		file_put_contents( $this->dir . '/banner-1544x500.png', $this->png( 1544, 500 ) );
		file_put_contents( $this->dir . '/screenshot-1.png', $this->png( 1280, 800 ) );
		file_put_contents( $this->dir . '/screenshot-2.png', $this->png( 1280, 800 ) );
		mkdir( $this->dir . '/blueprints' );
		file_put_contents( $this->dir . '/blueprints/blueprint.json', '{}' );
	}

	/**
	 * A PNG of the given size, black, with any extra chunks before its image data.
	 *
	 * @param int                   $width  Its width.
	 * @param int                   $height Its height.
	 * @param array<string, string> $extra  Chunks by type.
	 */
	private function png( int $width, int $height, array $extra = array() ): string {
		$png = "\x89PNG\r\n\x1a\n" . $this->chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 0, 0, 0, 0 ) );

		foreach ( $extra as $type => $data ) {
			$png .= $this->chunk( $type, $data );
		}

		return $png . $this->chunk( 'IDAT', (string) gzcompress( str_repeat( "\0", ( $width + 1 ) * $height ) ) ) . $this->chunk( 'IEND', '' );
	}

	/**
	 * One PNG chunk.
	 *
	 * @param string $type Its type.
	 * @param string $data Its data.
	 */
	private function chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * The headers of a JPEG of the given size, which is all getimagesize()
	 * reads, with any segments after its JFIF one.
	 *
	 * @param int    $width    Its width.
	 * @param int    $height   Its height.
	 * @param string $segments Segments to add.
	 */
	private function jpeg( int $width, int $height, string $segments = '' ): string {
		return "\xFF\xD8\xFF\xE0" . pack( 'n', 16 ) . "JFIF\0\x01\x01\0\0\x01\0\x01\0\0" . $segments
			. "\xFF\xC0" . pack( 'nCnnC', 11, 8, $height, $width, 1 ) . "\x01\x11\0"
			. "\xFF\xDA" . pack( 'n', 8 ) . "\x01\x01\0\0\x3F\0\xFF\xD9";
	}

	/**
	 * Deletes a folder and everything in it.
	 *
	 * @param string $path The folder or file.
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
