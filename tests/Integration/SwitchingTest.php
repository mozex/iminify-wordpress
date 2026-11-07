<?php
/**
 * Images another optimizer optimized, and another optimizer still acting, across the plugin.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Optimizer as Folder_Optimizer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Hooks;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Offload_Stub;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_Query;
use WP_UnitTestCase;

/**
 * Covers what decision 39 and W134 make of another optimizer's work: such an
 * image is never sent again (a regeneration, a bulk run, a press), reads as that
 * plugin's in the media library, its filter and the bulk page's counts, and its
 * files in other folders are set aside (W140); while another optimizer acts on
 * uploads, uploads, presses and bulk runs send nothing and the pages say which
 * plugin to switch off, while restoring goes on.
 */
class SwitchingTest extends WP_UnitTestCase {

	use Others_Fixture;

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

	const FOLDER = 'iminify-switching';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Others::STAMP_OPTION );
		update_option( 'active_plugins', array() );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Others::forget();
	}

	public function tear_down(): void {
		$this->api->stop();
		$this->forget_others();
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Others::STAMP_OPTION );
		update_option( 'active_plugins', array() );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();

		if ( is_dir( WP_CONTENT_DIR . '/' . self::FOLDER ) ) {
			$this->rmdir( WP_CONTENT_DIR . '/' . self::FOLDER );
		}

		foreach ( Paths::BACKUP_FOLDERS as $folder ) {
			if ( is_dir( Paths::uploads() . '/' . $folder ) ) {
				$this->rmdir( Paths::uploads() . '/' . $folder );
			}
		}

		parent::tear_down();
	}

	public function test_a_regeneration_of_an_image_another_plugin_optimized_sends_nothing_and_records_it_as_its(): void {
		$id = $this->fresh( $this->scaled_upload() );

		$this->as_shortpixel( $id );

		/* Regenerate Thumbnails, or an edit through WordPress's hooks: the image's files changed. */
		Hooks::generated( wp_get_attachment_metadata( $id ), $id );

		$record = Records::get( $id );

		$this->assertFalse( Optimizer::has_run( $id ), 'Its output is never sent for a second lossy pass.' );
		$this->assertSame( Records::OTHER, $record['status'] );
		$this->assertSame( 'shortpixel', $record['other']['plugin'] );
		$this->assertTrue( $record['other']['original'] );
		$this->assertSame( 'shortpixel:1', get_post_meta( $id, Records::OTHER_META, true ) );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );

		/* Someone's press is refused too, until optimizing it again from its original comes. */
		$this->assertSame( 'not_optimizable', Optimizer::ask( $id )->get_error_code() );
	}

	public function test_the_bulk_run_and_its_counts_leave_out_images_another_plugin_optimized_found_by_the_look_or_at_send_time(): void {
		$smush  = $this->fresh( $this->scaled_upload() );
		$later  = $this->fresh( $this->plain_upload() );
		$fresh  = $this->fresh( $this->plain_upload() );
		$before = Bulk::counts()['not_optimized'];

		$this->as_smush( $smush );
		Others::look();

		$counts = Bulk::counts();

		$this->assertSame( $before - 1, $counts['not_optimized'] );
		$this->assertSame( 1, $counts['other'] );
		$this->assertSame(
			array(
				'smush' => array(
					'with'    => 1,
					'without' => 0,
				),
			),
			Others::counts()
		);
		$this->assertNotContains( $smush, Bulk::candidates( Bulk::OPTIMIZE, 100, 0 )['ids'] );
		$this->assertNotContains( $smush, Bulk::candidates( Bulk::OPTIMIZE, 100, 0 )['passed'], 'The query leaves it out, not only its check.' );

		/* One another plugin optimized after the look: found as the run reaches it, recorded, never sent. */
		$this->as_imagify( $later );
		Site_Record::fetch();
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertSame( Records::OTHER, Records::get( $later )['status'] );
		$this->assertSame( 'imagify', Records::get( $later )['other']['plugin'] );
		$this->assertTrue( Optimizer::has_run( $fresh ) );
		$this->assertFalse( Optimizer::has_run( $later ) );
		$this->assertFalse( Optimizer::has_run( $smush ) );

		/* Smush restored it and dropped its records: no reader claims it, and its files no longer have the bytes remembered, so it goes back to not optimized on the next look. */
		delete_post_meta( $smush, 'wp-smpro-smush-data' );
		copy( DIR_TESTDATA . '/images/canola.jpg', (string) get_attached_file( $smush ) );
		Others::forget();
		Others::look();

		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $smush )['status'] );
		$this->assertSame( '', get_post_meta( $smush, Records::OTHER_META, true ) );
	}

	public function test_the_media_library_shows_whose_an_image_is_with_its_filter_and_no_action_on_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id    = $this->fresh( $this->plain_upload() );
		$other = $this->fresh( $this->plain_upload() );

		$this->as_ewww( $id );

		/* Before any look, the column reads the records it finds. */
		$this->assertStringContainsString( 'Optimized by EWWW Image Optimizer', Media_View::cell( $id ) );
		$this->assertStringContainsString( 'Its original is kept.', Media_View::cell( $id ) );
		$this->assertSame( array(), Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertStringNotContainsString( 'data-iminify-action', Media_View::panel( $id ) );
		$this->assertArrayHasKey( 'iminify_optimize', Media_View::row_actions( array(), get_post( $other ) ) );

		Others::look();

		$listed = new WP_Query(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 50,
				),
				Media_View::query_for( Media_View::FILTER_OTHER )
			)
		);

		$this->assertSame( array( $id ), array_map( 'intval', $listed->posts ) );
		$this->assertSame( 'EWWW Image Optimizer', Bulk_State::build()['others'][0]['name'] );
	}

	public function test_an_active_optimizer_holds_uploads_presses_and_bulk_runs_and_the_pages_name_it_while_restoring_goes_on(): void {
		$upload = $this->fresh( $this->plain_upload() );
		$queued = $this->fresh( $this->plain_upload() );

		Site_Record::fetch();
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertNull( Bulk::run()['reason'] );

		/* Smush, active with its automatic compression on by default (it needs no key at its basic level). */
		update_option( 'active_plugins', array( 'wp-smushit/wp-smush.php' ) );
		Others::forget();

		$this->assertSame( 'smush', Others::acting()->slug() );

		$refused = Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertSame( 'run_active', $refused->get_error_code() );

		Bulk::stop();
		$refused = Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertSame( Others::ACTING, $refused->get_error_code() );
		$this->assertStringContainsString( 'Smush', $refused->get_error_message() );
		$this->assertStringContainsString( 'Automatic compression', $refused->get_error_message() );
		$this->assertSame( 'nothing_to_do', Bulk::start( Bulk::RESTORE, Bulk::BACKGROUND )->get_error_code(), 'Restoring is never held.' );
		$this->assertSame( Others::ACTING, Optimizer::ask( $upload )->get_error_code() );

		/* An upload that reaches its run sends nothing, and reads why. */
		$calls = count( $this->api->calls( 'POST', 'groups' ) );
		Optimizer::optimize( $queued, Optimizer::AUTOMATIC );

		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $queued )['status'] );
		$this->assertSame( Others::ACTING, Records::get( $queued )['error']['code'] );
		$this->assertCount( $calls, $this->api->calls( 'POST', 'groups' ) );

		/* The pages say which plugin, and what to switch off. */
		$this->assertSame( 'Smush', Bulk_State::conflict()['name'] );
		$this->assertSame( 'Smush', State::build()['conflict']['name'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		$this->assertSame( Others::ACTING . '|smush', Notice::current()['key'] );

		/* Turned off in Smush: everything goes on. */
		update_option( 'wp-smush-settings', array( 'auto' => false ) );
		Others::forget();

		$this->assertNull( Others::acting() );
		$this->assertNull( Bulk_State::conflict() );
		$this->assertIsArray( Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND ) );
		delete_option( 'wp-smush-settings' );
		set_current_screen( 'front' );
	}

	public function test_a_running_bulk_run_holds_while_another_optimizer_acts_and_looks_again_from_the_start_once_it_stops(): void {
		$first  = $this->fresh( $this->plain_upload() );
		$second = $this->fresh( $this->plain_upload() );

		Site_Record::fetch();
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertTrue( Optimizer::has_run( $first ) );

		/* The run sent its window, and its images meet the other optimizer as their runs come. */
		update_option( 'active_plugins', array( 'imagify/imagify.php' ) );
		update_option( 'imagify_settings', array( 'api_key' => 'abc' ) );
		Others::forget();

		foreach ( array( $first, $second ) as $id ) {
			as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ), Optimizer::GROUP );
			Optimizer::optimize( $id, Optimizer::BULK );

			$this->assertSame( Others::ACTING, Records::get( $id )['error']['code'] );
		}

		Bulk::feed();

		$this->assertSame( Others::ACTING, Bulk::run()['reason'] );
		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );
		$this->assertFalse( Optimizer::has_run( $first ) );

		update_option( 'active_plugins', array() );
		delete_option( 'imagify_settings' );
		Others::forget();
		Bulk::feed();

		$this->assertNull( Bulk::run()['reason'] );
		$this->assertTrue( Optimizer::has_run( $first ), 'The run looks again from the start, so an image it had sent goes again.' );
		$this->assertTrue( Optimizer::has_run( $second ) );
	}

	public function test_an_image_whose_files_wp_offload_media_removed_is_read_from_its_metadata_and_never_sent(): void {
		$tiny    = $this->fresh( $this->plain_upload() );
		$imagify = $this->fresh( $this->plain_upload() );

		$this->as_tinypng( $tiny, false );
		$this->as_imagify( $imagify );

		/* TinyPNG writes the main file's bytes into the metadata, which is all that is left of it once the files go. */
		$metadata             = wp_get_attachment_metadata( $tiny );
		$metadata['filesize'] = (int) filesize( (string) get_attached_file( $tiny ) );
		update_post_meta( $tiny, '_wp_attachment_metadata', $metadata );

		$offload = ( new Offload_Stub() )->start();

		try {
			$offload->offload( $tiny );
			$offload->offload( $imagify );
			Others::forget();

			$this->assertFileDoesNotExist( (string) get_attached_file( $tiny, true ) );
			$this->assertSame( 'tinypng', Others::verdict( $tiny )['plugin'] );

			/* Imagify never writes the metadata's sizes, so its file reads as the upload until it is back on the disk, and is measured then. */
			$calls = count( $this->api->calls( 'POST', 'groups' ) );
			Optimizer::optimize( $imagify, Optimizer::MANUAL );

			$this->assertSame( Records::OTHER, Records::get( $imagify )['status'] );
			$this->assertCount( $calls, $this->api->calls( 'POST', 'groups' ), 'Its output is never sent.' );
		} finally {
			$offload->stop();
		}
	}

	public function test_a_look_runs_once_at_a_time_and_stamps_what_it_found_only_at_its_end(): void {
		$id = $this->fresh( $this->plain_upload() );

		$this->as_smush( $id );

		/* A look left from before, cancelled by its hook whatever page it was on. */
		as_schedule_single_action( time() + HOUR_IN_SECONDS, Others::SWEEP_HOOK, array( 4242 ), Optimizer::GROUP );
		Others::look( true );

		$this->assertFalse( as_has_scheduled_action( Others::SWEEP_HOOK, null, Optimizer::GROUP ) );
		$this->assertSame( Others::stamp(), get_option( Others::STAMP_OPTION ), 'At its end it stamps what it found, its own marks included.' );
		$this->assertSame( Records::OTHER, Records::get( $id )['status'] );

		/* While a look is under way, another isn't started beside it. */
		delete_option( Others::STAMP_OPTION );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, Others::SWEEP_HOOK, array( 4242 ), Optimizer::GROUP );
		Others::look();

		$this->assertFalse( get_option( Others::STAMP_OPTION ) );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Others::SWEEP_HOOK,
					'status' => 'pending',
				),
				'ids'
			)
		);
	}

	public function test_a_held_run_still_looks_again_from_its_start_after_a_pause_and_a_resume(): void {
		$first = $this->fresh( $this->plain_upload() );

		Site_Record::fetch();
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$sent = Bulk::run()['sent'];

		update_option( 'active_plugins', array( 'imagify/imagify.php' ) );
		update_option( 'imagify_settings', array( 'api_key' => 'abc' ) );
		Others::forget();
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $first, Optimizer::BULK ), Optimizer::GROUP );
		Optimizer::optimize( $first, Optimizer::BULK );
		Bulk::feed();

		$this->assertSame( $sent - 1, Bulk::run()['sent'], 'An image handed back isn\'t counted as sent.' );

		Bulk::pause();
		update_option( 'active_plugins', array() );
		Others::forget();
		Bulk::resume();

		$this->assertTrue( Optimizer::has_run( $first ), 'The rewind owed from the hold outlives the pause.' );
		$this->assertFalse( Bulk::run()['rewind'] );
		delete_option( 'imagify_settings' );
	}

	public function test_imagify_without_a_key_and_elementor_unconnected_hold_nothing(): void {
		update_option( 'active_plugins', array( 'imagify/imagify.php', 'image-optimization/image-optimization.php' ) );
		Others::forget();

		$this->assertNull( Others::acting() );

		update_option( 'image_optimizer_access_token', 'token' );
		Others::forget();

		$this->assertSame( 'elementor', Others::acting()->slug() );

		delete_option( 'image_optimizer_access_token' );
		update_option( 'imagify_settings', array( 'api_key' => 'abc' ) );
		Others::forget();

		$this->assertSame( 'imagify', Others::acting()->slug() );
		delete_option( 'imagify_settings' );
	}

	public function test_a_file_another_plugin_optimized_through_its_own_folders_is_set_aside_as_its(): void {
		global $wpdb;

		$folder = WP_CONTENT_DIR . '/' . self::FOLDER;

		wp_mkdir_p( $folder );
		copy( DIR_TESTDATA . '/images/canola.jpg', $folder . '/theirs.jpg' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $folder . '/mine.jpg' );

		$numbers = $this->squeeze( $folder . '/theirs.jpg' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- another plugin's table, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shortpixel_meta ( id mediumint(10) NOT NULL AUTO_INCREMENT, folder_id mediumint(9) NOT NULL, ext_meta_id int(10), path varchar(512) NOT NULL, name varchar(150), path_md5 char(32), compression_type tinyint, compressed_size int, keep_exif tinyint DEFAULT 0, cmyk2rgb tinyint DEFAULT 0, resize tinyint, resize_width smallint, resize_height smallint, backup tinyint DEFAULT 0, status SMALLINT DEFAULT 0, retries tinyint DEFAULT 0, message varchar(255), ts_added timestamp NULL, ts_optimized timestamp NULL, extra_info LONGTEXT, PRIMARY KEY id (id) )" );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, made for a test.
			$wpdb->prefix . 'shortpixel_meta',
			array(
				'folder_id'       => 1,
				'path'            => wp_normalize_path( $folder . '/theirs.jpg' ),
				'status'          => 2,
				'compressed_size' => $numbers['after'],
			)
		);
		Table::drop();
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$ids = array();

		foreach ( Table::page( null, 1, 100 )['rows'] as $row ) {
			$ids[ $row['path'] ] = $row['id'];
		}

		$this->assertSame( Records::OTHER, Table::get( $ids[ self::FOLDER . '/theirs.jpg' ] )['status'] );
		$this->assertSame( Records::NOT_OPTIMIZED, Table::get( $ids[ self::FOLDER . '/mine.jpg' ] )['status'] );
		$this->assertSame( 1, Table::counts()['other'] );
		$this->assertSame( Others::CODE, Folder_Optimizer::why_not( $ids[ self::FOLDER . '/theirs.jpg' ] )['code'] );
		$this->assertNull( Folder_Optimizer::why_not( $ids[ self::FOLDER . '/mine.jpg' ] ) );

		Table::drop();
		delete_option( Folders::OPTION );
		delete_option( Scanner::OPTION );
	}

	/**
	 * An upload with no record of the plugin's and nothing queued, as before any run.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function fresh( int $id ): int {
		Optimizer::cancel( $id );

		foreach ( Records::ALL_META as $key ) {
			delete_post_meta( $id, $key );
		}

		return $id;
	}
}
