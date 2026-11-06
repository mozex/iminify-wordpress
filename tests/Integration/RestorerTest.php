<?php
/**
 * Restoring an attachment, which runs with no call to Iminify.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Media\Writer;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers each kind of original, the names and metadata a restore has to keep, the
 * copies it deletes, and the cases that have to refuse.
 */
class RestorerTest extends WP_UnitTestCase {

	/**
	 * The stand-in API, which only the group's deletion may reach.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->forget();
		Pause::lift();
		Site_Record::forget();
	}

	public function tear_down(): void {
		$this->api->stop();
		Local_Settings::update( array( 'backups' => true ) );
		Pause::lift();
		remove_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_a_plain_image_gets_its_original_and_its_sizes_back_under_the_same_names_with_nothing_sent(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );
		$original = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.
		$before   = wp_get_attachment_metadata( $id );
		$folder   = dirname( $attached );
		$medium   = $folder . '/' . $before['sizes']['medium']['file'];
		$made     = (string) file_get_contents( $medium ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test size.

		$this->optimized( $id, array( $attached, $medium ) );

		/* A key other plugins keep in the metadata, on the file and on a size, and a size the site's maximum width made smaller. */
		$metadata                               = wp_get_attachment_metadata( $id );
		$metadata['ewww_noresize']              = true;
		$metadata['sizes']['medium']['sources'] = array( 'kept' => true );
		$metadata['width']                      = 320;
		$metadata['height']                     = 240;
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		$own = $attached . '.webp';

		file_put_contents( $own, 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		$theirs = $attached . '.avif';
		file_put_contents( $theirs, 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		Records::update(
			$id,
			array(
				'copies' => array(
					$relative . '.webp' => array(
						'for'    => $relative,
						'format' => 'webp',
						'bytes'  => 4,
						'origin' => Records::ORIGIN,
					),
					$relative . '.avif' => array(
						'for'    => $relative,
						'format' => 'avif',
						'bytes'  => 4,
						'origin' => 'another-plugin',
					),
				),
			)
		);

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		/* WP Offload Media uploads what it finds of the attachment on the disk at each save of its metadata; the copies are gone before any. */
		$on_disk = array();
		$offload = static function ( $data, $attachment ) use ( &$on_disk, $own ) {
			$paths     = apply_filters( 'as3cf_attachment_file_paths', array(), $attachment, $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
			$on_disk[] = array( count( $paths ), file_exists( $own ) );

			return $data;
		};
		add_filter( 'wp_update_attachment_metadata', $offload, 110, 2 );

		$this->assertTrue( Restorer::restore( $id ) );

		remove_filter( 'wp_update_attachment_metadata', $offload, 110 );

		$after  = wp_get_attachment_metadata( $id );
		$record = Records::get( $id );

		$this->assertNotEmpty( $on_disk );
		$this->assertSame( array( array( 0, false ) ), array_values( array_unique( $on_disk, SORT_REGULAR ) ) );

		$this->assertSame( $original, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the restored file.
		$this->assertSame( $made, file_get_contents( $medium ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size made again.
		$this->assertSame( array( 640, 480 ), array( $after['width'], $after['height'] ) );
		$this->assertSame( strlen( $original ), $after['filesize'] );
		$this->assertSame( $before['file'], $after['file'] );
		$this->assertSame( $before['sizes']['medium']['file'], $after['sizes']['medium']['file'] );
		$this->assertTrue( $after['ewww_noresize'] );
		$this->assertSame( array( 'kept' => true ), $after['sizes']['medium']['sources'] );
		$this->assertFileDoesNotExist( $own );
		$this->assertFileExists( $theirs );
		$this->assertSame( array( $relative . '.avif' ), array_keys( $record['copies'] ) );
		$this->assertSame( array(), $record['written'] );
		$this->assertNull( $record['result'] );
		$this->assertSame( array( Records::NOT_OPTIMIZED, 'restored' ), array( $record['status'], $record['error']['code'] ) );
		$this->assertFileExists( Paths::backup( $relative ) );

		/* Making the sizes again queued nothing, and nothing reached the service. */
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		$this->assertNull( Writer::$writing );
		$this->assertSame( array(), $this->api->requests );
		$this->assertSame( Restorer::NOTHING, Restorer::availability( $id ) );

		wp_delete_file( $theirs );
	}

	public function test_a_scaled_upload_is_made_again_from_the_original_wordpress_kept(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$before   = wp_get_attachment_metadata( $id );

		$this->assertStringEndsWith( '-scaled.jpg', $attached );

		$this->optimized( $id, array( $attached ) );

		$written = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads what the plugin wrote.

		$this->assertTrue( Restorer::restore( $id ) );

		$after = wp_get_attachment_metadata( $id );

		$this->assertSame( $attached, get_attached_file( $id ) );
		$this->assertNotSame( $written, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the restored file.
		$this->assertSame( array( 500, 375 ), array( (int) $after['width'], (int) $after['height'] ) );
		$this->assertSame( $before['original_image'], $after['original_image'] );
		$this->assertSame( $before['sizes']['medium']['file'], $after['sizes']['medium']['file'] );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_a_scaled_uploads_original_deleted_since_comes_back_from_its_backup(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$original = dirname( $attached ) . '/' . $metadata['original_image'];
		$bytes    = (string) file_get_contents( $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		$this->assertIsArray( Backups::keep( $original, (string) Paths::relative( $original ), array() ) );
		$this->optimized( $id, array( $attached ), false );
		wp_delete_file( $original );

		$this->assertSame( Restorer::AVAILABLE, Restorer::availability( $id ) );
		$this->assertTrue( Restorer::restore( $id ) );
		$this->assertSame( $bytes, file_get_contents( $original ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the original put back.
		$this->assertSame( $attached, get_attached_file( $id ) );
	}

	public function test_an_edited_uploads_kept_original_stays_named_after_a_restore_of_the_edit(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id = $this->attachment();
		remove_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$attached = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );

		/* An edit under its own name, with core's old original_image still in the metadata. */
		$edited = dirname( $attached ) . '/canola-e1700000000000.jpg';
		copy( $attached, $edited );
		update_attached_file( $id, $edited );
		$this->optimized( $id, array( $edited ) );

		$this->assertTrue( Restorer::restore( $id ) );

		$after = wp_get_attachment_metadata( $id );

		$this->assertSame( $edited, get_attached_file( $id ) );
		$this->assertSame( $metadata['original_image'], $after['original_image'] );
	}

	public function test_it_refuses_with_backups_off_for_a_file_replaced_since_and_when_nothing_changed(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );

		$this->assertSame( Restorer::NOTHING, Restorer::availability( $id ) );
		$this->assertSame( 'nothing_to_restore', Restorer::restore( $id )->get_error_code() );

		/* The plugin wrote over the only copy, with backups off. */
		$this->optimized( $id, array( $attached ), false );

		$this->assertSame( Restorer::NO_ORIGINAL, Restorer::availability( $id ) );
		$this->assertSame( 'no_original', Restorer::restore( $id )->get_error_code() );

		/* Someone put another file there since: it isn't the plugin's to overwrite, even with a backup kept of the old one. */
		$other = $this->attachment();
		$file  = (string) get_attached_file( $other );
		$this->optimized( $other, array( $file ) );
		file_put_contents( $file, 'someone else\'s picture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- replaces a test image behind the plugin's back.

		$this->assertSame( Restorer::CHANGED, Restorer::availability( $other ) );
		$this->assertSame( 'image_changed', Restorer::restore( $other )->get_error_code() );
		$this->assertSame( 'someone else\'s picture', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
	}

	public function test_sizes_made_from_a_scaled_backup_keep_the_names_posts_link_to(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$before   = wp_get_attachment_metadata( $id );
		$folder   = dirname( $attached );
		$medium   = $folder . '/' . $before['sizes']['medium']['file'];
		$thumb    = $folder . '/' . $before['sizes']['thumbnail']['file'];
		$kept     = (string) file_get_contents( $thumb ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size the plugin left alone.

		/* The original is gone, or in a format the service doesn't take, as an iPhone's HEIC is: only the `-scaled` file's backup is left. */
		$this->optimized( $id, array( $attached, $medium ) );
		wp_delete_file( $folder . '/' . $before['original_image'] );

		$written = (string) file_get_contents( $medium ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads what the plugin wrote.

		$this->assertTrue( Restorer::restore( $id ) );

		$after = wp_get_attachment_metadata( $id );

		$this->assertSame( $before['sizes']['medium']['file'], $after['sizes']['medium']['file'] );
		$this->assertSame( $before['sizes']['thumbnail']['file'], $after['sizes']['thumbnail']['file'] );
		$this->assertNotSame( $written, file_get_contents( $medium ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size made again.
		$this->assertSame( (int) filesize( $medium ), $after['sizes']['medium']['filesize'] );
		$this->assertSame( $kept, file_get_contents( $thumb ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size left alone.
		$this->assertSame( array(), glob( $folder . '/' . pathinfo( $attached, PATHINFO_FILENAME ) . '-*' ) );
		$this->assertSame( $attached, get_attached_file( $id ) );
		$this->assertSame( array(), Records::get( $id )['written'] );
	}

	public function test_a_thumbnail_cropped_by_hand_and_the_files_of_an_older_edit_stay_as_they_are(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$folder   = dirname( $attached );
		$relative = (string) Paths::relative( $attached );
		$metadata = wp_get_attachment_metadata( $id );
		$core     = $folder . '/' . $metadata['sizes']['thumbnail']['file'];
		$cropped  = $folder . '/canola-e1700000000000-150x150.jpg';

		copy( $core, $cropped );

		$metadata['sizes']['thumbnail']['file'] = wp_basename( $cropped );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		$bytes = (string) file_get_contents( $cropped ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the hand-cropped size.

		/* The file before an edit, which the plugin wrote; core's "Restore image" may bring it back. */
		$this->optimized( $id, array( $attached ) );

		$edited = $folder . '/canola-e1700000000001.jpg';

		copy( DIR_TESTDATA . '/images/canola.jpg', $edited );
		update_attached_file( $id, $edited );
		$this->optimized( $id, array( $edited ) );

		$this->assertTrue( Restorer::restore( $id ) );

		$after  = wp_get_attachment_metadata( $id );
		$record = Records::get( $id );

		$this->assertSame( wp_basename( $cropped ), $after['sizes']['thumbnail']['file'] );
		$this->assertSame( $bytes, file_get_contents( $cropped ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size left alone.
		$this->assertFileExists( $core );
		$this->assertSame( array( $relative ), array_keys( $record['written'] ) );

		/* Nothing is left to restore on the edit, while the older file still can't pass for an original. */
		$this->assertSame( Restorer::NOTHING, Restorer::availability( $id ) );

		update_attached_file( $id, $attached );

		$this->assertSame( Originals::BACKUP, Originals::find( $id, $after, null )['kind'] ?? null );
	}

	public function test_a_size_wordpress_couldnt_make_again_keeps_its_entry_and_stays_the_plugins(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$medium   = dirname( $attached ) . '/' . $metadata['sizes']['medium']['file'];

		$this->optimized( $id, array( $attached, $medium ) );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$this->assertTrue( Restorer::restore( $id ) );

		remove_filter( 'wp_image_editors', '__return_empty_array' );

		$after = wp_get_attachment_metadata( $id );

		$this->assertSame( $metadata['sizes']['medium']['file'], $after['sizes']['medium']['file'] );
		$this->assertArrayHasKey( (string) Paths::relative( $medium ), Records::get( $id )['written'] );
		$this->assertArrayNotHasKey( (string) Paths::relative( $attached ), Records::get( $id )['written'] );
		$this->assertSame( Restorer::AVAILABLE, Restorer::availability( $id ) );
	}

	public function test_a_restore_wordpress_cant_finish_says_its_copies_are_gone(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );

		$this->optimized( $id, array( $attached ) );
		file_put_contents( $attached . '.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		Records::update(
			$id,
			array(
				'copies' => array(
					$relative . '.webp' => array(
						'for'    => $relative,
						'format' => 'webp',
						'bytes'  => 4,
						'origin' => Records::ORIGIN,
					),
				),
			)
		);

		$deleted    = array();
		$heard      = static function ( $attachment ) use ( &$deleted ) {
			$deleted[] = $attachment;
		};
		$unreadable = static function () {
			return array();
		};
		add_action( 'iminify_copies_deleted', $heard );
		add_filter( 'wp_generate_attachment_metadata', $unreadable, PHP_INT_MAX );

		$restored = Restorer::restore( $id );

		remove_filter( 'wp_generate_attachment_metadata', $unreadable, PHP_INT_MAX );
		remove_action( 'iminify_copies_deleted', $heard );

		/* The copies went before WordPress gave up, so the pages a cache kept naming them are emptied as for a deletion. */
		$this->assertSame( 'restore_failed', is_wp_error( $restored ) ? $restored->get_error_code() : null );
		$this->assertFileDoesNotExist( $attached . '.webp' );
		$this->assertSame( array( $id ), $deleted );
	}

	public function test_a_restore_drops_what_was_on_its_way_and_works_with_the_day_spent(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );

		$this->optimized( $id, array( $attached ) );
		Pause::refused( 3600, 'free' );
		Records::update(
			$id,
			array(
				'group' => array(
					'id'         => 'g-1',
					'generation' => Records::get( $id )['generation'],
				),
			)
		);
		as_schedule_single_action( time() + 60, Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP );
		$generation = Records::get( $id )['generation'];

		$this->assertTrue( Restorer::restore( $id ) );

		$this->assertGreaterThan( $generation, Records::get( $id )['generation'] );
		$this->assertNull( Records::get( $id )['group'] );
		$this->assertFalse( as_next_scheduled_action( Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP ) );
		$this->assertIsInt( as_next_scheduled_action( Optimizer::DELETE_GROUP_HOOK, array( 'g-1', 0 ), Optimizer::GROUP ) );
	}

	/**
	 * Scales an upload wider than 500 pixels, as WordPress scales one past 2,560.
	 */
	public function scale_at_500(): int {
		return 500;
	}

	/**
	 * A 640 by 480 JPEG uploaded to the media library.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Leaves an attachment as the plugin would: its backup kept, these files written
	 * over, and each recorded by the bytes written.
	 *
	 * @param int          $id     The attachment's id.
	 * @param list<string> $files  The files to write over.
	 * @param bool         $backup Whether a backup of the first was kept.
	 */
	protected function optimized( int $id, array $files, bool $backup = true ): void {
		$record = Records::get( $id );

		if ( $backup ) {
			$record['backups'] = (array) Backups::keep( $files[0], (string) Paths::relative( $files[0] ), $record['backups'] );
		}

		foreach ( $files as $file ) {
			$editor = wp_get_image_editor( $file );
			$editor->set_quality( 5 );
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );
			copy( $saved['path'], $file );
			wp_delete_file( $saved['path'] );

			clearstatcache( true, $file );
			$record['written'][ (string) Paths::relative( $file ) ] = (int) filesize( $file );
		}

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => $record['written'],
				'backups' => $record['backups'],
				'result'  => array(
					'level'   => 'smart',
					'formats' => array( 'webp' ),
					'outputs' => array(),
				),
			)
		);
	}
}
