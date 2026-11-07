<?php
/**
 * What each other optimizer left behind, read with that plugin inactive.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Places;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_UnitTestCase;

/**
 * Covers, for Imagify, ShortPixel, EWWW (its rows captured from it running), Smush
 * and TinyPNG: detection from their records alone, each file's bytes before and
 * after, the backup taken only through its own size check (a compressed one
 * refused), their copies, the original lookup taking a verified original, a file
 * changed since read as an original, and the hostile records.
 */
class ReadersTest extends WP_UnitTestCase {

	use Others_Fixture;

	public function set_up(): void {
		parent::set_up();
		Others::forget();
	}

	public function tear_down(): void {
		$this->forget_others();
		parent::tear_down();
	}

	public function test_imagify_on_a_scaled_upload_leaves_the_original_wordpress_keeps_as_the_original(): void {
		$id      = $this->scaled_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_imagify( $id );
		$main    = (string) Paths::relative( $files['full'] );
		$trace   = (array) Others::trace( $id );

		$this->assertSame( 'imagify', $trace['plugin'] );
		$this->assertSame( $numbers['full'], array_intersect_key( $trace['files'][ $main ], array_flip( array( 'before', 'after' ) ) ) );
		$this->assertContains( Paths::relative( $files['medium'] ) . '.webp', $trace['copies'] );
		$this->assertNotContains( Paths::relative( $files['original'] ), $trace['outputs'], 'Imagify never compresses the original WordPress keeps.' );
		$this->assertSame(
			array(
				'plugin'   => 'imagify',
				'before'   => $numbers['full']['before'] + $numbers['thumbnail']['before'] + $numbers['medium']['before'],
				'after'    => $numbers['full']['after'] + $numbers['thumbnail']['after'] + $numbers['medium']['after'],
				'files'    => 3,
				'original' => true,
				'outputs'  => array(
					Paths::relative( $files['full'] )      => $numbers['full']['after'],
					Paths::relative( $files['thumbnail'] ) => $numbers['thumbnail']['after'],
					Paths::relative( $files['medium'] )    => $numbers['medium']['after'],
				),
			),
			Others::verdict( $id )
		);

		$source = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( $files['original'], $source['path'] );
		$this->assertSame( Originals::ORIGINAL, $source['kind'] );
		$this->assertSame( Others::CODE, Optimizer::why_not( $id )['code'] );
	}

	public function test_imagify_on_a_plain_upload_answers_its_backup_and_refuses_one_taken_of_a_compressed_file(): void {
		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_imagify( $id );

		$backup = Paths::uploads() . '/backup/' . Paths::relative( $files['full'] );
		$source = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( $backup, $source['path'] );
		$this->assertSame( Originals::OTHER, $source['kind'] );
		$this->assertSame( Paths::relative( $files['full'] ), $source['file'] );

		/* A backup taken after an earlier pass has the compressed file's bytes, never those recorded before. */
		copy( $files['full'], $backup );
		Others::forget();

		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
		$this->assertFalse( Others::verdict( $id )['original'] );
		$this->assertSame( Others::CODE, Optimizer::why_not( $id )['code'], 'Still its output: never sent for a second pass.' );
	}

	public function test_shortpixel_answers_its_backup_of_the_original_wordpress_keeps_and_its_copies(): void {
		$id      = $this->scaled_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_shortpixel( $id );
		$trace   = (array) Others::trace( $id );
		$source  = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( 'shortpixel', $trace['plugin'] );
		$this->assertContains( Paths::relative( $files['original'] ), $trace['outputs'], 'ShortPixel compresses the original WordPress keeps.' );
		$this->assertSame( $numbers['original']['after'], $trace['files'][ Paths::relative( $files['original'] ) ]['after'] );
		$this->assertSame( array( dirname( (string) Paths::relative( $files['medium'] ) ) . '/' . wp_basename( $files['medium'], '.jpg' ) . '.webp' ), $trace['copies'], 'Its copy is photo.webp beside the file.' );
		$this->assertSame( Paths::uploads() . '/ShortpixelBackups/' . $this->from_abspath( $files['original'] ), $source['path'] );
		$this->assertSame( Originals::OTHER, $source['kind'] );
		$this->assertSame( Paths::relative( $files['original'] ), $source['file'] );
		$this->assertSame( $numbers['original']['before'], filesize( $source['path'] ) );
	}

	public function test_shortpixel_without_backups_has_no_original_and_a_file_it_gained_nothing_on_is_the_upload(): void {
		global $wpdb;

		$id    = $this->scaled_upload();
		$files = $this->named( $id );

		$this->as_shortpixel( $id, false );

		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
		$this->assertFalse( Others::verdict( $id )['original'] );

		/* A row where nothing was gained left the file as it was uploaded. */
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}shortpixel_postmeta SET original_size = compressed_size WHERE attach_id = %d AND image_type = 0", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
		Others::forget();

		$this->assertNull( Others::verdict( $id ), 'The attached file reads as the upload.' );
		$this->assertNotContains( Paths::relative( $files['full'] ), (array) Others::trace( $id )['outputs'] );
	}

	public function test_shortpixel_before_5_0_left_its_work_in_the_metadata_and_that_is_read_too(): void {
		$id       = $this->scaled_upload();
		$files    = $this->named( $id );
		$metadata = wp_get_attachment_metadata( $id );

		$metadata['ShortPixel']            = array(
			'type' => 'lossy',
			'date' => '2019-05-04 10:00:00',
		);
		$metadata['ShortPixelImprovement'] = '48.12';
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		update_option( 'wp-short-pixel-apiKey', 'old' );
		Others::forget();

		$this->assertSame( 'shortpixel', Others::verdict( $id )['plugin'] );
		$this->assertFalse( Others::verdict( $id )['original'], 'Those records keep no bytes, so no backup is trusted.' );
		$this->assertContains( Paths::relative( $files['original'] ), Others::trace( $id )['outputs'], 'It compressed WordPress\'s original too.' );
		$this->assertNull( Originals::find( $id, $metadata, null ) );

		/* An error string in place of the improvement means it changed nothing. */
		$metadata['ShortPixelImprovement'] = 'Error: quota exceeded';
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		Others::forget();

		$this->assertNull( Others::verdict( $id ) );
		delete_option( 'wp-short-pixel-apiKey' );
	}

	public function test_ewww_rows_captured_from_it_running_read_with_their_backups_and_copies(): void {
		$id      = $this->plain_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_ewww( $id );
		$main    = (string) Paths::relative( $files['full'] );
		$trace   = (array) Others::trace( $id );
		$source  = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( 'ewww', $trace['plugin'] );
		$this->assertSame( $numbers['full']['after'], $trace['files'][ $main ]['after'] );
		$this->assertContains( $main . '.webp', $trace['copies'] );
		$this->assertSame( WP_CONTENT_DIR . '/ewww/image-backup/uploads/' . $main, $source['path'] );
		$this->assertSame( Originals::OTHER, $source['kind'] );
		$this->assertTrue( Others::verdict( $id )['original'] );
	}

	public function test_a_record_that_points_outside_and_a_backup_that_links_out_are_never_followed(): void {
		global $wpdb;

		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_ewww( $id );

		$main   = (string) Paths::relative( $files['full'] );
		$backup = WP_CONTENT_DIR . '/ewww/image-backup/uploads/' . $main;
		$secret = get_temp_dir() . 'iminify-outside.jpg';

		/* The backup swapped for a link to a file elsewhere with the same bytes. */
		copy( $backup, $secret );
		unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test file.
		symlink( $secret, $backup );
		$this->others_made[] = $secret;

		/* A row naming a file outside the places, for this attachment. */
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
			$wpdb->prefix . 'ewwwio_images',
			array(
				'attachment_id' => $id,
				'gallery'       => 'media',
				'resize'        => 'large',
				'path'          => $secret,
				'converted'     => '',
				'image_size'    => 1,
				'orig_size'     => 2,
				'pending'       => 0,
			)
		);
		Others::forget();

		$trace = (array) Others::trace( $id );

		$this->assertArrayNotHasKey( $main, $trace['backups'] );
		$this->assertNull( Places::file( $backup ) );
		$this->assertSame( array(), array_diff( array_keys( $trace['files'] ), array_map( array( Paths::class, 'relative' ), $files ) ), 'A row naming a file outside names nothing of the attachment.' );
		$this->assertNull( Places::file( Paths::uploads() . '/../../../etc/hostname' ) );
		$this->assertNull( Places::file( $secret ) );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ), 'Without its backup the original is gone, so nothing is sent.' );
	}

	public function test_a_folder_on_the_way_that_links_out_and_a_size_swapped_for_a_link_are_never_read(): void {
		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_ewww( $id );

		$main    = (string) Paths::relative( $files['full'] );
		$year    = WP_CONTENT_DIR . '/ewww/image-backup/uploads/' . strtok( $main, '/' );
		$outside = WP_CONTENT_DIR . '/iminify-outside-' . wp_generate_password( 6, false );

		/* The backup folder's year moved elsewhere and linked back: the same files, reached through a link. */
		rename( $year, $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moves a test folder.
		symlink( $outside, $year );
		$this->others_made[] = $outside;

		/* A size's file swapped for a link to a file elsewhere with the bytes EWWW recorded. */
		$medium = $files['medium'];
		$moved  = WP_CONTENT_DIR . '/iminify-medium-' . wp_generate_password( 6, false ) . '.jpg';
		rename( $medium, $moved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moves a test file.
		symlink( $moved, $medium );
		$this->others_made[] = $moved;
		$this->others_made[] = $year;
		Others::forget();

		$trace = (array) Others::trace( $id );

		$this->assertSame( array(), $trace['backups'], 'No backup is read through a link out of its place.' );
		$this->assertNotContains( Paths::relative( $medium ), $trace['outputs'] );
		$this->assertContains( $main, $trace['outputs'] );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );

		unlink( $medium ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test link.
		unlink( $year ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test link.
	}

	public function test_another_plugins_records_on_a_translation_count_for_the_files_it_shares(): void {
		$holder      = $this->plain_upload();
		$translation = (int) self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );

		update_post_meta( $translation, '_wp_attached_file', get_post_meta( $holder, '_wp_attached_file', true ) );
		update_post_meta( $translation, '_wp_attachment_metadata', wp_get_attachment_metadata( $holder ) );

		/* Imagify, which knows nothing of translations, recorded its work on the translation alone. */
		$this->as_imagify( $translation );

		$this->assertSame( 'imagify', Others::verdict( $holder )['plugin'] );
		$this->assertSame( Others::CODE, Optimizer::why_not( $holder, true )['code'] );
	}

	public function test_an_image_whose_plugin_deleted_its_records_stays_its_while_its_files_hold_their_bytes(): void {
		$id    = $this->scaled_upload();
		$files = $this->named( $id );

		$this->as_smush( $id );
		Others::mark( $id, (array) Others::verdict( $id ) );

		/* Smush's uninstall with "Keep Data" off deletes its records; its files stay as it compressed them. */
		delete_post_meta( $id, 'wp-smpro-smush-data' );
		Others::forget();

		$this->assertSame( 'smush', Others::verdict( $id )['plugin'] );
		$this->assertFalse( Others::verdict( $id )['original'], 'Its backup was checked against records that are gone.' );
		$this->assertSame( Others::CODE, Optimizer::why_not( $id, true )['code'] );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );

		/* Once the file changes, it is an upload of its own again. */
		copy( DIR_TESTDATA . '/images/canola.jpg', $files['full'] );
		Others::forget();

		$this->assertNull( Others::verdict( $id ) );
	}

	public function test_smush_answers_its_bak_file_beside_the_original_and_refuses_one_with_other_bytes(): void {
		$id      = $this->scaled_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_smush( $id );
		$trace   = (array) Others::trace( $id );
		$bak     = dirname( $files['original'] ) . '/' . wp_basename( $files['original'], '.jpg' ) . '.bak.jpg';
		$source  = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( 'smush', $trace['plugin'] );
		$this->assertSame( $numbers['wp_scaled']['after'], $trace['files'][ Paths::relative( $files['full'] ) ]['after'], 'Smush\'s wp_scaled is the attached file.' );
		$this->assertSame( $numbers['full']['after'], $trace['files'][ Paths::relative( $files['original'] ) ]['after'], 'Smush\'s full is the original WordPress keeps.' );
		$this->assertSame( $bak, $source['path'] );
		$this->assertSame( Paths::relative( $files['original'] ), $source['file'] );

		file_put_contents( $bak, 'shorter' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a backup with other bytes.
		Others::forget();

		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
		$this->assertFalse( Others::verdict( $id )['original'] );
	}

	public function test_a_backup_with_other_bytes_is_refused_for_ewww_shortpixel_and_tinypng(): void {
		$ewww       = $this->plain_upload();
		$shortpixel = $this->scaled_upload();
		$tinypng    = $this->scaled_upload();

		$this->as_ewww( $ewww );
		$this->as_shortpixel( $shortpixel );
		$this->as_tinypng( $tinypng );

		/* ShortPixel backs up each file it compresses, the attached `-scaled` copy and WordPress's original both. */
		$backups = array(
			$ewww       => array( WP_CONTENT_DIR . '/ewww/image-backup/uploads/' . Paths::relative( $this->named( $ewww )['full'] ) ),
			$shortpixel => array(
				Paths::uploads() . '/ShortpixelBackups/' . $this->from_abspath( $this->named( $shortpixel )['original'] ),
				Paths::uploads() . '/ShortpixelBackups/' . $this->from_abspath( $this->named( $shortpixel )['full'] ),
			),
			$tinypng    => array( Paths::uploads() . '/tinify_backup/' . Paths::relative( $this->named( $tinypng )['original'] ) ),
		);

		foreach ( $backups as $id => $kept ) {
			$this->assertNotNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ), 'Its backup passes first.' );

			foreach ( $kept as $backup ) {
				$this->assertFileExists( $backup );

				/* A backup taken after an earlier pass: its bytes aren't the ones recorded before. */
				file_put_contents( $backup, 'compressed already' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a backup with other bytes.
			}
		}

		Others::forget();

		foreach ( array_keys( $backups ) as $id ) {
			$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
			$this->assertFalse( Others::verdict( $id )['original'] );
		}
	}

	public function test_tinypng_reads_its_current_and_legacy_records_and_leaves_marked_images_alone(): void {
		$id      = $this->scaled_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_tinypng( $id, true, 'tiny_compress_images' );
		$source  = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( 'tinypng', Others::trace( $id )['plugin'], 'The key before 3.7.0 is read too.' );
		$this->assertSame( $numbers['original_unscaled']['after'], Others::trace( $id )['files'][ Paths::relative( $files['original'] ) ]['after'] );
		$this->assertSame( Paths::uploads() . '/tinify_backup/' . Paths::relative( $files['original'] ), $source['path'] );

		/* "Mark as compressed" records sizes without a ratio and changes nothing. */
		$marked = $this->plain_upload();
		update_post_meta(
			$marked,
			'_tiny_compress_images',
			array(
				0 => array(
					'input'  => array( 'size' => filesize( (string) get_attached_file( $marked ) ) ),
					'output' => array(
						'size' => filesize( (string) get_attached_file( $marked ) ),
						'type' => 'image/jpeg',
					),
					'end'    => time(),
				),
			)
		);
		Others::forget();

		$this->assertNull( Others::verdict( $marked ) );
		$this->assertNull( Optimizer::why_not( $marked ) );
	}

	public function test_a_file_changed_since_the_plugin_wrote_it_is_the_original_again(): void {
		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_tinypng( $id, false );

		/* Something wrote a new picture over the file the records name. */
		copy( DIR_TESTDATA . '/images/canola.jpg', $files['full'] );
		Others::forget();

		$source = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertNull( Others::verdict( $id ) );
		$this->assertSame( $files['full'], $source['path'] );
		$this->assertSame( Originals::ATTACHED, $source['kind'] );
	}
}
