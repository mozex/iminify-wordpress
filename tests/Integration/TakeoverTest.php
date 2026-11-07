<?php
/**
 * Integration tests for the copies another optimizer made, taken over.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Media\Writer;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_UnitTestCase;

/**
 * `Switching\Takeover`, the copies `Media\Picture` serves, `Media\Writer`'s one
 * write over another plugin's file, and every path in `Media\Copies` that
 * deletes copies, which only forgets a taken one (W137, W133).
 */
class TakeoverTest extends WP_UnitTestCase {

	use Others_Fixture;

	public function set_up(): void {
		parent::set_up();
		delete_option( Others::STAMP_OPTION );
		update_option( 'active_plugins', array() );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		Picture::flush();
		Others::forget();

		/* A purge another test left waiting for its request's end. */
		Caches::at_shutdown();
	}

	public function tear_down(): void {
		$this->forget_others();
		delete_option( Others::STAMP_OPTION );
		delete_option( Local_Settings::OPTION );
		parent::tear_down();
	}

	public function test_a_copy_another_plugins_records_name_is_taken_over_and_served_but_never_a_stale_unnamed_or_litespeed_one(): void {
		$id    = $this->fresh( $this->plain_upload() );
		$files = $this->named( $id );

		$this->as_ewww( $id );

		$full   = (string) Paths::relative( $files['full'] );
		$medium = (string) Paths::relative( $files['medium'] );

		$this->webp_of( $files['full'] );
		$this->webp_of( $files['medium'] );
		touch( $files['medium'] . '.webp', (int) filemtime( $files['medium'] ) - 60 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- a copy made before its file was replaced.
		file_put_contents( $files['full'] . '.avif', 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a copy no record names.
		$this->others_made[] = $files['full'] . '.avif';

		$litespeed = $this->fresh( $this->plain_upload() );
		$this->as_litespeed( $litespeed );
		$this->webp_of( $this->named( $litespeed )['medium'] );

		Others::look( true );

		$copies = Records::get( $id )['copies'];

		clearstatcache();
		$this->assertSame(
			array(
				$full . '.webp' => array(
					'for'       => $full,
					'format'    => 'webp',
					'bytes'     => (int) filesize( $files['full'] . '.webp' ),
					'origin'    => 'ewww',
					'for_bytes' => (int) filesize( $files['full'] ),
				),
			),
			$copies,
			'Only the copy EWWW names that reads as WebP and is no older than its file: the thumbnail\'s isn\'t a WebP, the medium\'s was made before its file, and no record names the AVIF.'
		);
		$this->assertSame( array(), Records::get( $litespeed )['copies'], 'No record names LiteSpeed\'s copies.' );
		$this->assertSame( Records::OTHER, get_post_meta( $id, Records::STATUS_META, true ), 'Taking its copies over changes nothing of whose the image is.' );
		$this->assertSame( '', get_post_meta( $id, Records::FORMATS_META, true ) );
		$this->assertSame( 'other_plugin', \Iminify\WordPress\Media\Optimizer::why_not( $id )['code'] );
		$this->assertStringContainsString( 'copies EWWW Image Optimizer made, until Iminify makes its own', Media_View::panel( $id ) );
		$this->assertSame( array(), Offload_Media::file_paths( array(), $id ), 'WP Offload Media never takes another plugin\'s copy up, or "Remove Local Media" would delete it.' );

		$url  = (string) wp_get_attachment_url( $id );
		$html = Picture::wrap( '<img src="' . esc_url( $url ) . '" alt="">', $id );

		$this->assertStringContainsString( '<source type="image/webp" srcset="' . esc_url( $url . '.webp' ) . '">', $html );

		/* Its file replaced since: never served. */
		$this->replace_picture( $files['full'] );
		Picture::flush();

		$this->assertStringNotContainsString( '<picture', Picture::wrap( '<img src="' . esc_url( $url ) . '" alt="">', $id ) );

		/* And the next look forgets it, even with the new picture written in the same second as the copy. */
		touch( $files['full'], (int) filemtime( $files['full'] . '.webp' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- a replacement as fast as a quick machine makes it.
		Others::look( true );

		$this->assertSame( array(), Records::get( $id )['copies'] );
	}

	public function test_each_path_that_deletes_copies_only_forgets_a_taken_one_and_leaves_its_file(): void {
		$paths = array(
			'deleting the copies'     => static function ( int $id ): void {
				Copies::delete_own( $id );
			},
			'a restore'               => static function ( int $id ): void {
				Restorer::restore( $id );
			},
			'the rules\' stale sweep' => function ( int $id ): void {
				$this->replace_picture( (string) get_attached_file( $id ) );
				Copies::delete_stale( $id );
			},
			'deleting the image'      => static function ( int $id ): void {
				wp_delete_attachment( $id, true );
			},
		);

		foreach ( $paths as $name => $path ) {
			list( $id, $copy ) = $this->taken();

			$bytes = md5_file( $copy );
			$own   = $this->own_copy( $id );

			$path( $id );

			/* A deleted image's record goes with it. */
			if ( null !== get_post( $id ) ) {
				$this->assertSame( array(), Copies::taken( Records::get( $id )['copies'] ), $name . ' forgets it.' );
			}

			clearstatcache();
			$this->assertFileExists( $copy, $name . ' leaves the taken copy\'s file.' );
			$this->assertSame( $bytes, md5_file( $copy ), $name );
			$this->assertFileDoesNotExist( $own, $name . ' deletes the plugin\'s own.' );

			$this->forget_others();
		}

		list( $id, $copy ) = $this->taken();

		$this->assertStringNotContainsString( 'Delete the WebP and AVIF copies', Media_View::panel( $id ), 'Nothing of the plugin\'s own to delete.' );

		/* LiteSpeed deleting it on a regeneration, or putting its file back, is forgotten. */
		wp_delete_file( $copy );
		Copies::forget_gone( $id );

		$this->assertSame( array(), Records::get( $id )['copies'] );
	}

	public function test_the_writer_replaces_a_copy_another_plugin_names_in_place_and_stops_serving_one_of_another_name(): void {
		global $wpdb;

		$id    = $this->fresh( $this->plain_upload() );
		$files = $this->named( $id );

		$this->as_ewww( $id );

		$full      = (string) Paths::relative( $files['full'] );
		$thumbnail = (string) Paths::relative( $files['thumbnail'] );
		$medium    = (string) Paths::relative( $files['medium'] );
		$other     = dirname( $files['full'] ) . '/' . wp_basename( $files['full'], '.jpg' ) . '.webp';

		foreach ( array( 'full', 'thumbnail', 'medium' ) as $size ) {
			$this->webp_of( $files[ $size ] );
		}

		$this->webp_of( $files['full'], $other );

		/* EWWW no longer names the thumbnail's and the medium's copies: the thumbnail's was taken over before, and nothing ever named the medium's. */
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ewwwio_images SET webp_size = 0 WHERE attachment_id = %d AND resize IN ('thumbnail', 'medium')", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, as a test changes it.
		Others::forget();

		$medium_copy = md5_file( $files['medium'] . '.webp' );
		$other_copy  = md5_file( $other );
		$avif        = $files['medium'] . '.avif';

		file_put_contents( $avif, 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another plugin's copy, taken over before.
		$this->others_made[] = $avif;
		$avif_copy           = md5_file( $avif );

		clearstatcache();
		Records::update(
			$id,
			array(
				'copies' => array(
					(string) Paths::relative( $other ) => $this->entry( $full, $other, $files['full'], 'shortpixel' ),
					$thumbnail . '.webp'               => $this->entry( $thumbnail, $files['thumbnail'] . '.webp', $files['thumbnail'], 'ewww' ),
					$medium . '.avif'                  => $this->entry( $medium, $avif, $files['medium'], 'imagify' ),
				),
			)
		);

		$declared = array();
		$answer   = array( 'outputs' => array() );
		$entries  = array();

		foreach ( array( 'full', 'thumbnail', 'medium' ) as $size ) {
			$dimensions                 = (array) wp_getimagesize( $files[ $size ] );
			$declared[ $size ]          = array(
				'file'   => (string) Paths::relative( $files[ $size ] ),
				'size'   => (int) filesize( $files[ $size ] ),
				'width'  => (int) $dimensions[0],
				'height' => (int) $dimensions[1],
			);
			$answer['outputs'][]        = array(
				'name'    => $size,
				'format'  => 'webp',
				'file'    => $size . '.webp',
				'width'   => (int) $dimensions[0],
				'height'  => (int) $dimensions[1],
				'replace' => true,
			);
			$entries[ $size . '.webp' ] = $this->webp_bytes( $files[ $size ], 30 );
		}

		/* The medium size itself is written over too, in its own format. */
		$answer['outputs'][]   = array(
			'name'    => 'medium',
			'format'  => 'original',
			'file'    => 'medium.jpg',
			'width'   => $declared['medium']['width'],
			'height'  => $declared['medium']['height'],
			'replace' => true,
		);
		$entries['medium.jpg'] = $this->jpeg_bytes( $files['medium'] );

		$written = Writer::write(
			$id,
			array(
				'outputs' => $declared,
				'type'    => 'image/jpeg',
			),
			$answer,
			$this->zip( $entries )
		);
		$copies  = Records::get( $id )['copies'];

		clearstatcache();
		$this->assertIsArray( $written );
		$this->assertSame( md5( $entries['full.webp'] ), md5_file( $files['full'] . '.webp' ), 'The copy EWWW names is replaced in place by the plugin\'s own.' );
		$this->assertSame( Records::ORIGIN, $copies[ $full . '.webp' ]['origin'] );
		$this->assertSame( md5( $entries['thumbnail.webp'] ), md5_file( $files['thumbnail'] . '.webp' ), 'A copy taken over is replaced in place.' );
		$this->assertSame( Records::ORIGIN, $copies[ $thumbnail . '.webp' ]['origin'] );
		$this->assertSame( $medium_copy, md5_file( $files['medium'] . '.webp' ), 'A copy no record names stays.' );
		$this->assertSame( array( $medium . '.webp' ), $written['kept'] );
		$this->assertSame( $other_copy, md5_file( $other ), 'The other name\'s copy stays where its plugin left it.' );
		$this->assertArrayNotHasKey( (string) Paths::relative( $other ), $copies, 'And is no longer served, now that the plugin has its own of that file in that format.' );
		$this->assertSame( md5( $entries['medium.jpg'] ), md5_file( $files['medium'] ) );
		$this->assertArrayNotHasKey( $medium . '.avif', $copies, 'A taken copy of a file the plugin wrote over is no longer served.' );
		$this->assertSame( $avif_copy, md5_file( $avif ), 'And stays where its plugin left it.' );
	}

	public function test_a_file_another_attachment_holds_as_its_own_is_never_taken_over_or_written_over(): void {
		$id    = $this->fresh( $this->plain_upload() );
		$files = $this->named( $id );
		$main  = (string) Paths::relative( $files['full'] );

		$this->as_ewww( $id );
		$this->webp_of( $files['full'] );

		/* Someone uploaded a WebP whose name WordPress kept as it was: `photo.jpg.webp`, beside the JPEG EWWW named it a copy of. */
		$upload = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/webp',
				'post_title'     => 'Their own WebP',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $upload, '_wp_attached_file', $main . '.webp' );

		$theirs = md5_file( $files['full'] . '.webp' );

		Others::look( true );

		$this->assertSame( array(), Records::get( $id )['copies'], 'Never served as the JPEG\'s copy.' );

		$dimensions = (array) wp_getimagesize( $files['full'] );
		$written    = Writer::write(
			$id,
			array(
				'outputs' => array(
					'full' => array(
						'file'   => $main,
						'size'   => (int) filesize( $files['full'] ),
						'width'  => (int) $dimensions[0],
						'height' => (int) $dimensions[1],
					),
				),
				'type'    => 'image/jpeg',
			),
			array(
				'outputs' => array(
					array(
						'name'    => 'full',
						'format'  => 'webp',
						'file'    => 'full.webp',
						'width'   => (int) $dimensions[0],
						'height'  => (int) $dimensions[1],
						'replace' => true,
					),
				),
			),
			$this->zip( array( 'full.webp' => $this->webp_bytes( $files['full'], 30 ) ) )
		);

		clearstatcache();
		$this->assertSame( array( $main . '.webp' ), $written['kept'] );
		$this->assertSame( $theirs, md5_file( $files['full'] . '.webp' ), 'Never written over.' );
	}

	public function test_converter_for_medias_copies_are_taken_over_and_served_from_their_own_address(): void {
		$id    = $this->fresh( $this->plain_upload() );
		$files = $this->named( $id );
		$main  = (string) Paths::relative( $files['full'] );
		$copy  = WP_CONTENT_DIR . '/uploads-webpc/uploads/' . $main . '.webp';
		$key   = Copies::IN_CONTENT . 'uploads-webpc/uploads/' . $main . '.webp';

		wp_mkdir_p( dirname( $copy ) );
		$this->webp_of( $files['full'], $copy );

		$translation = $this->translation( $id );
		$tag         = '<img src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" alt="">';
		$served      = '<source type="image/webp" srcset="' . esc_url( content_url( 'uploads-webpc/uploads/' . $main . '.webp' ) ) . '">';

		Others::look( true );

		$this->assertSame( 'converter', Records::get( $id )['copies'][ $key ]['origin'] );
		$this->assertStringContainsString( $served, Picture::wrap( $tag, $id ) );
		$this->assertStringContainsString( $served, Picture::wrap( $tag, $translation ), 'A translation sharing its files serves them too.' );

		/* The plugin's own copy of that file in that format takes the taken one's place. */
		$this->webp_of( $files['full'] );

		$copies                    = Records::get( $id )['copies'];
		$copies[ $main . '.webp' ] = $this->entry( $main, $files['full'] . '.webp', $files['full'], Records::ORIGIN );
		Records::update( $id, array( 'copies' => $copies ) );
		Picture::flush();

		$this->assertStringContainsString( '<source type="image/webp" srcset="' . esc_url( wp_get_attachment_url( $id ) . '.webp' ) . '">', Picture::wrap( $tag, $id ) );

		/* Converter for Media's uninstall deletes its folder: the caches are emptied and the next look forgets the copies. */
		Records::update( $id, array( 'copies' => array_diff_key( $copies, array( $main . '.webp' => true ) ) ) );
		$this->rmdir( WP_CONTENT_DIR . '/uploads-webpc' );
		do_action( 'deleted_plugin', 'webp-converter-for-media/webp-converter-for-media.php', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's action, as a deletion fires it.

		$this->assertNotFalse( has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
		$this->assertTrue( as_has_scheduled_action( Others::SWEEP_HOOK ) );

		Others::sweep( 0 );

		$this->assertSame( array(), Records::get( $id )['copies'] );

		Caches::at_shutdown();
	}

	/**
	 * An image EWWW optimized whose main file's copy the plugin took over.
	 *
	 * @return array{0: int, 1: string} The image and its taken copy.
	 */
	protected function taken(): array {
		$id   = $this->fresh( $this->plain_upload() );
		$main = $this->named( $id )['full'];

		$this->as_ewww( $id );
		$this->webp_of( $main );
		Others::look( true );

		$this->assertCount( 1, Copies::taken( Records::get( $id )['copies'] ) );

		return array( $id, $main . '.webp' );
	}

	/**
	 * Puts another picture in a file's place, as Enable Media Replace or FTP would.
	 *
	 * @param string $file The file.
	 */
	protected function replace_picture( string $file ): void {
		copy( DIR_TESTDATA . '/images/33772.jpg', $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- a test fixture.
		clearstatcache();
	}

	/**
	 * A translation of an attachment as Polylang makes one: a new attachment naming the same file.
	 *
	 * @param int $of The attachment translated.
	 */
	protected function translation( int $of ): int {
		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Translation',
				'post_status'    => 'inherit',
			)
		);

		add_post_meta( $id, '_wp_attachment_metadata', wp_get_attachment_metadata( $of, true ) );
		add_post_meta( $id, '_wp_attached_file', (string) get_post_meta( $of, '_wp_attached_file', true ) );

		return $id;
	}

	/**
	 * A JPEG of a file at its own dimensions, as bytes.
	 *
	 * @param string $file The file.
	 */
	protected function jpeg_bytes( string $file ): string {
		$editor = wp_get_image_editor( $file );
		$editor->set_quality( 30 );
		$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );
		$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.

		wp_delete_file( $saved['path'] );

		return $bytes;
	}

	/**
	 * Records an AVIF copy of the plugin's own beside the main file.
	 *
	 * @param int $id The attachment's id.
	 * @return string Its path.
	 */
	protected function own_copy( int $id ): string {
		$main = (string) get_attached_file( $id );
		$path = $main . '.avif';

		file_put_contents( $path, 'iminify-avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- the plugin's copy.
		clearstatcache();

		$copies = Records::get( $id )['copies'];

		$copies[ (string) Paths::relative( $path ) ] = $this->entry( (string) Paths::relative( $main ), $path, $main, Records::ORIGIN );
		Records::update( $id, array( 'copies' => $copies ) );

		return $path;
	}

	/**
	 * A record's entry for a copy.
	 *
	 * @param string $file   The file it stands beside, relative to the uploads folder.
	 * @param string $copy   The copy's path.
	 * @param string $beside The file's path.
	 * @param string $origin Who made it.
	 * @return array<string, mixed>
	 */
	protected function entry( string $file, string $copy, string $beside, string $origin ): array {
		return array(
			'for'       => $file,
			'format'    => pathinfo( $copy, PATHINFO_EXTENSION ),
			'bytes'     => (int) filesize( $copy ),
			'origin'    => $origin,
			'for_bytes' => (int) filesize( $beside ),
		);
	}

	/**
	 * Writes a real WebP of a file beside it (or at a path), as another plugin would.
	 *
	 * @param string      $file The file.
	 * @param string|null $to   Where, `photo.jpg.webp` beside it when null.
	 */
	protected function webp_of( string $file, ?string $to = null ): void {
		$to = $to ?? $file . '.webp';

		file_put_contents( $to, $this->webp_bytes( $file, 70 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another plugin's copy.
		$this->others_made[] = $to;
		clearstatcache();
	}

	/**
	 * A WebP of a file at its own dimensions, as bytes.
	 *
	 * @param string $file    The file.
	 * @param int    $quality The quality.
	 */
	protected function webp_bytes( string $file, int $quality ): string {
		$editor = wp_get_image_editor( $file );
		$editor->set_quality( $quality );
		$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.webp', 'image/webp' );
		$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.

		wp_delete_file( $saved['path'] );

		return $bytes;
	}

	/**
	 * A zip of entries, as the service's group zip.
	 *
	 * @param array<string, string> $entries The files, by name.
	 * @return string Its path.
	 */
	protected function zip( array $entries ): string {
		$path    = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $bytes ) {
			$archive->addFromString( $name, $bytes );
		}

		$archive->close();
		$this->others_made[] = $path;

		return $path;
	}

	/**
	 * An attachment with no record or run of its own yet, whatever its upload queued.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function fresh( int $id ): int {
		as_unschedule_all_actions( '', array(), \Iminify\WordPress\Media\Optimizer::GROUP );
		delete_post_meta( $id, Records::META );
		delete_post_meta( $id, Records::STATUS_META );

		return $id;
	}
}
