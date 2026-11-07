<?php
/**
 * What each other optimizer left behind, read with that plugin inactive.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Switching\Converter;
use Iminify\WordPress\Switching\Modern_Formats;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Places;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_UnitTestCase;

/**
 * Covers, for Imagify, ShortPixel, EWWW (its rows captured from it running), Smush
 * and TinyPNG: detection from their records alone, each file's bytes before and
 * after, the backup taken only through its own size check (a compressed one
 * refused), their copies, the original lookup taking a verified original, a file
 * changed since read as an original, and the hostile records. For LiteSpeed and
 * Image Optimizer by Elementor, which record no bytes of their own, what they did
 * to the files; for Modern Image Formats and Converter for Media, their records
 * replayed from captures of them running; for Optimole, its offload.
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

		$this->as_smush( $id, false );
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

	public function test_litespeed_reads_a_file_with_a_differing_bk_as_its_output_and_trusts_the_bk_only_with_the_bytes_wordpress_recorded(): void {
		$id      = $this->plain_upload();
		$files   = $this->named( $id );
		$numbers = $this->as_litespeed( $id );
		$main    = (string) Paths::relative( $files['full'] );
		$medium  = (string) Paths::relative( $files['medium'] );
		$thumb   = (string) Paths::relative( $files['thumbnail'] );

		/* LiteSpeed's own restore of the thumbnail moved its `.bk.` back and parked its output beside it, on an upload whose metadata, made before WordPress 6.0, gives the thumbnail no bytes; the medium size's `.bk.` was taken of a file it had already compressed. */
		$metadata = wp_get_attachment_metadata( $id );
		unset( $metadata['sizes']['thumbnail']['filesize'] );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		$parked = dirname( $files['thumbnail'] ) . '/' . wp_basename( $files['thumbnail'], '.jpg' ) . '.bk.optm.jpg';
		rename( $files['thumbnail'], $parked ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- as LiteSpeed does it.
		rename( dirname( $files['thumbnail'] ) . '/' . wp_basename( $files['thumbnail'], '.jpg' ) . '.bk.jpg', $files['thumbnail'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- as LiteSpeed does it.
		$this->others_made[] = $parked;
		file_put_contents( dirname( $files['medium'] ) . '/' . wp_basename( $files['medium'], '.jpg' ) . '.bk.jpg', 'x', FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test file.

		/* The plugin's own copy beside the thumbnail, which never reads as LiteSpeed's. */
		file_put_contents( $files['thumbnail'] . '.avif', 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		$this->others_made[] = $files['thumbnail'] . '.avif';
		Records::update(
			$id,
			array(
				'copies' => array(
					$thumb . '.avif' => array(
						'for'    => $thumb,
						'format' => 'avif',
						'bytes'  => 4,
						'origin' => Records::ORIGIN,
					),
				),
			)
		);
		Others::forget();

		$trace  = (array) Others::trace( $id );
		$source = Originals::find( $id, wp_get_attachment_metadata( $id ), null );

		$this->assertSame( 'litespeed', $trace['plugin'] );
		$this->assertEqualsCanonicalizing( array( $main, $medium ), $trace['outputs'] );
		$this->assertSame( $numbers['full']['before'], $trace['files'][ $main ]['before'] );
		$this->assertSame( array( $main ), array_keys( $trace['backups'] ), 'Only a `.bk.` with the bytes WordPress recorded is the original.' );
		$this->assertSame( Originals::OTHER, $source['kind'] );
		$this->assertSame( (int) wp_get_attachment_metadata( $id )['filesize'], (int) filesize( $source['path'] ) );
		$this->assertContains( $medium . '.webp', $trace['copies'] );
		$this->assertNotContains( $thumb . '.avif', $trace['copies'] );
		$this->assertSame( 'other_plugin', Optimizer::why_not( $id )['code'] );

		/* Its backups turned off, or removed by its "Remove Original Image Backups": its output stands alone, with no original. */
		$bk        = dirname( $files['full'] ) . '/' . wp_basename( $files['full'], '.jpg' ) . '.bk.jpg';
		$untouched = (string) file_get_contents( $bk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test file.
		unlink( $bk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- as LiteSpeed does it.
		Others::forget();

		$this->assertContains( $main, Others::trace( $id )['outputs'] );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );

		/* Results with WebP alone, its "Optimize Original Images" off: it wrote no original. */
		update_post_meta( $id, 'litespeed-optimize-size', array( 'webp_total' => 1 ) );
		Others::forget();

		$this->assertNull( Others::verdict( $id ) );

		/* A file with the bytes WordPress recorded is no file it wrote, results or not. */
		update_post_meta( $id, 'litespeed-optimize-size', array( 'ori_total' => 1 ) );
		file_put_contents( $files['full'], $untouched ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test file.
		Others::forget();

		$this->assertNull( Others::verdict( $id ) );
	}

	public function test_elementor_converted_image_is_its_output_with_its_jpeg_kept_and_restoring_it_is_elementors(): void {
		$id     = $this->plain_upload();
		$files  = $this->named( $id );
		$placed = $this->as_elementor( $id, true );
		$main   = (string) Paths::relative( $placed['full'] );
		$trace  = (array) Others::trace( $id );
		$why    = Restorer::restore( $id );

		$this->assertSame( 'elementor', $trace['plugin'] );
		$this->assertContains( $main, $trace['outputs'] );
		$this->assertSame( $files['full'], $trace['backups'][ $main ], 'The JPEG it kept at its own name is the original.' );
		$this->assertNotSame( '', $trace['elsewhere'] );
		$this->assertTrue( Others::verdict( $id )['original'] );
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $id ) );
		$this->assertWPError( $why );
		$this->assertSame( 'restore_elsewhere', $why->get_error_code() );
		$this->assertStringContainsString( 'Elementor', $why->get_error_message() );
		$this->assertFileExists( $placed['full'] );
		$this->assertFileExists( $files['full'] );
	}

	public function test_elementor_keeping_the_format_backs_each_file_up_and_a_modern_backup_beside_a_jpeg_is_never_trusted(): void {
		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_elementor( $id, false );

		$main  = (string) Paths::relative( $files['full'] );
		$trace = (array) Others::trace( $id );

		$this->assertSame( 'elementor', $trace['plugin'] );
		$this->assertSame( '', $trace['elsewhere'] );
		$this->assertSame( dirname( $files['full'] ) . '/' . wp_basename( $files['full'], '.jpg' ) . '.backup.jpg', $trace['backups'][ $main ] );
		$this->assertSame( Restorer::AVAILABLE, Restorer::availability( $id ) );

		/* Its bulk "Reoptimize" of a converted image backs the compressed WebP up beside the JPEG it came from. */
		$webp = $this->plain_upload();
		$this->as_elementor( $webp, true );
		$named = $this->named( $webp );
		copy( $named['full'], dirname( $named['full'] ) . '/' . wp_basename( $named['full'], '.webp' ) . '.backup.webp' );
		$this->others_made[]       = dirname( $named['full'] ) . '/' . wp_basename( $named['full'], '.webp' ) . '.backup.webp';
		$record                    = get_post_meta( $webp, 'image_optimizer_metadata', true );
		$record['original_data']   = array(
			'sizes' => array(
				'full' => array(
					'file'     => get_post_meta( $webp, '_wp_attached_file', true ),
					'filesize' => (int) filesize( $named['full'] ),
				),
			),
		);
		$record['sizes_optimized'] = array( 'full' );
		update_post_meta( $webp, 'image_optimizer_metadata', $record );
		$this->squeeze( $named['full'] );
		Others::forget();

		$this->assertSame( array(), Others::trace( $webp )['backups'] );
		$this->assertSame( Restorer::NO_ORIGINAL, Restorer::availability( $webp ) );
	}

	public function test_modern_formats_captured_running_its_avif_files_are_its_output_and_its_fallback_copies_only_copies(): void {
		$capture = $this->captured( 'modern-formats' );
		$plain   = $this->replay( $capture['modern-only']['plain'] );
		$large   = $this->replay( $capture['modern-only']['large'] );
		$kept    = $this->replay( $capture['fallback']['large'] );
		$trace   = (array) Others::trace( $plain );
		$main    = (string) $capture['modern-only']['plain']['_wp_attached_file'];
		$folder  = dirname( $main ) . '/';

		$this->assertSame( 'modern-formats', $trace['plugin'] );
		$this->assertCount( 5, $trace['outputs'] );
		$this->assertSame( Paths::absolute( $folder . 'mifonly-plain.jpg' ), $trace['backups'][ $main ], 'WordPress keeps the upload, which it was made from.' );
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $plain ) );
		$this->assertSame( 'other_plugin', Optimizer::why_not( $plain )['code'], 'An AVIF, which the service never takes, still says whose it is.' );
		$this->assertContains( $capture['modern-only']['large']['_wp_attached_file'], Others::trace( $large )['outputs'] );
		$this->assertNull( Others::verdict( $kept ), 'With its fallback the JPEGs are the uploads as they were.' );
		$this->assertContains( $folder . 'miffall-large-scaled-jpg.avif', ( new Modern_Formats() )->read( $kept, wp_get_attachment_metadata( $kept ) )['copies'] );

		/* A file changed since it recorded it is no output of its. */
		file_put_contents( Paths::absolute( $folder . 'mifonly-plain-300x200.avif' ), 'changed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test file.
		Others::forget();

		$this->assertCount( 4, Others::trace( $plain )['outputs'] );

		/* An AVIF upload is the upload, whatever its sources say. */
		wp_update_post(
			array(
				'ID'             => $plain,
				'post_mime_type' => 'image/avif',
			)
		);
		Others::forget();

		$this->assertNull( Others::verdict( $plain ) );

		/* A look kept from before its last conversions never stops a send reading them. */
		set_transient( Modern_Formats::FOUND, '0', DAY_IN_SECONDS );
		Others::forget();

		$this->assertSame( 'modern-formats', Others::verdict( $large )['plugin'] );
	}

	public function test_converter_captured_running_answers_its_copies_outside_the_uploads_folder_and_never_an_optimization(): void {
		$capture = $this->captured( 'converter' );
		$id      = $this->replay( $capture['upload'], $capture['copies'] );
		$main    = (string) $capture['upload']['_wp_attached_file'];
		$crashed = WP_CONTENT_DIR . '/uploads-webpc/uploads/' . dirname( $main ) . '/webpc-large-150x150.jpg.webp';

		touch( $crashed . '.crashed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- the marker Converter for Media leaves.

		$found = ( new Converter() )->read( $id, wp_get_attachment_metadata( $id ) );

		$this->assertNull( Others::trace( $id ) );
		$this->assertSame( array(), $found['outputs'] );
		$this->assertSame( array( WP_CONTENT_DIR . '/uploads-webpc/uploads/' . $main . '.webp' ), $found['outside'][ $main ] );
		$this->assertCount( 7, $found['outside'], 'Every file but the one whose conversion crashed.' );
		$this->assertArrayNotHasKey( dirname( $main ) . '/webpc-large-150x150.jpg', $found['outside'] );
		$this->assertNull( Others::why( $id ) );
	}

	public function test_optimole_offloaded_image_is_away_until_rolled_back_and_never_sent(): void {
		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_optimole_offloaded( $id );

		$this->assertSame( 'optimole', Others::away( $id )->slug() );
		$this->assertNull( Others::verdict( $id ) );
		$this->assertStringContainsString( 'Optimole', Optimizer::why_not( $id )['message'] );
		$this->assertStringContainsString( 'Offloaded by Optimole', Media_View::cell( $id ) );
		$this->assertArrayNotHasKey( 'iminify_optimize', Media_View::row_actions( array(), get_post( $id ) ) );

		/* Its rollback brings the file back before it takes its marks off. */
		copy( DIR_TESTDATA . '/images/canola.jpg', $files['full'] );
		Others::forget();

		$this->assertNull( Others::away( $id ) );
	}

	public function test_ewww_rows_a_folder_scan_wrote_for_a_librarys_files_are_found_by_their_paths_and_its_cloud_originals_named(): void {
		global $wpdb;

		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_ewww( $id );

		/* As "Include Media Folders" writes them: no attachment, no gallery, no size name; and the main file's original on EWWW's servers. */
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ewwwio_images SET attachment_id = NULL, gallery = '', resize = NULL, backup = IF( path LIKE %s, 'cloudhash', '' ) WHERE attachment_id = %d", '%' . $wpdb->esc_like( wp_basename( $files['full'] ) ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
		$this->rmdir( WP_CONTENT_DIR . '/ewww/image-backup' );
		Others::forget();

		$trace = (array) Others::trace( $id );

		$this->assertSame( 'ewww', $trace['plugin'] );
		$this->assertContains( (string) Paths::relative( $files['full'] ), $trace['outputs'] );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
		$this->assertStringContainsString( 'EWWW', $trace['elsewhere'] );
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $id ) );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ewww_paths_written_from_a_sites_own_folder_are_read(): void {
		global $wpdb;

		define( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER', untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- EWWW's own constant, as a site sets it.

		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->as_ewww( $id );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ewwwio_images SET path = REPLACE( path, %s, %s ), attachment_id = NULL, gallery = '' WHERE attachment_id = %d", 'ABSPATHwp-content', 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
		Others::forget();

		$this->assertSame( 'ewww', Others::verdict( $id )['plugin'] );
		$this->assertContains( (string) Paths::relative( $files['full'] ), Others::trace( $id )['outputs'] );
	}

	public function test_shortpixel_conversion_is_its_output_whether_it_gained_nothing_or_isnt_compressed_yet(): void {
		global $wpdb;

		$id    = $this->plain_upload();
		$files = $this->named( $id );

		$this->shortpixel_table();
		$bytes = (int) filesize( $files['full'] );
		$row   = array(
			'attach_id'        => $id,
			'parent'           => 0,
			'image_type'       => 0,
			'status'           => 2,
			'compression_type' => 1,
			'compressed_size'  => $bytes,
			'original_size'    => $bytes,
			'extra_info'       => wp_json_encode(
				array(
					'convertMeta' => array(
						'fileFormat'           => 'png',
						'isConverted'          => true,
						'replacementImageBase' => 'canola',
					),
				)
			),
		);

		/* Its conversion's JPEG, on which its compression then gained nothing. */
		$wpdb->insert( $wpdb->prefix . 'shortpixel_postmeta', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
		Others::forget();

		$this->assertSame( 'shortpixel', Others::verdict( $id )['plugin'] );
		$this->assertSame( array( (string) Paths::relative( $files['full'] ) => $bytes ), Others::verdict( $id )['outputs'], 'The bytes it recorded writing are kept, which stand in once its records are gone.' );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );

		/* Its conversion at upload, its compression still on its way. */
		$wpdb->update( $wpdb->prefix . 'shortpixel_postmeta', array( 'status' => 1 ), array( 'attach_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
		Others::forget();

		$this->assertSame( 'shortpixel', Others::verdict( $id )['plugin'] );
		$this->assertFalse( Others::verdict( $id )['original'] );
		$this->assertSame( 'other_plugin', Optimizer::why_not( $id )['code'] );
	}

	public function test_a_smush_run_it_never_finished_holds_the_image_as_its_with_no_original(): void {
		$id    = $this->scaled_upload();
		$files = $this->named( $id );

		$this->as_smush( $id );
		delete_post_meta( $id, 'wp-smpro-smush-data' );

		/* Its pointer alone is what tells the site held Smush. */
		$verdict = Others::verdict( $id );

		$this->assertSame( 'smush', $verdict['plugin'] );
		$this->assertFalse( $verdict['original'] );
		$this->assertNull( Originals::find( $id, wp_get_attachment_metadata( $id ), null ) );
		$this->assertFileExists( dirname( $files['original'] ) . '/' . wp_basename( $files['original'], '.jpg' ) . '.bak.jpg' );

		/* A pointer that names the file itself means Smush left it as it was. */
		update_post_meta( $id, '_wp_attachment_backup_sizes', array( 'smush-full' => array( 'file' => wp_basename( $files['original'] ) ) ) );
		Others::forget();

		$this->assertNull( Others::verdict( $id ) );
	}
}
