<?php
/**
 * Turning the plugin off, on again, and deleting it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers parking what was on its way, picking it up again, and what a deletion
 * removes and keeps by default and with each tick.
 *
 * The integration suite loads the plugin before WordPress, so `uninstall.php`
 * itself (which WordPress loads without the plugin) is run on a development
 * site instead; these tests cover `Lifecycle::uninstall()`, which it calls.
 */
class LifecycleTest extends WP_UnitTestCase {

	/**
	 * The stand-in API.
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
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
	}

	public function tear_down(): void {
		$this->api->stop();
		Plugin::keys()->forget();
		Pause::lift();
		delete_option( Local_Settings::OPTION );
		delete_option( Lifecycle::ACTIVATED_OPTION );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		as_unschedule_all_actions( 'iminify_tests_other_plugin' );

		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}

		parent::tear_down();
	}

	public function test_turning_it_off_parks_what_was_on_its_way_and_leaves_nothing_scheduled(): void {
		$queued  = $this->attachment();
		$waiting = $this->attachment();
		$done    = $this->attachment();

		Records::update( $queued, array( 'status' => Records::QUEUED ) );
		as_schedule_single_action( time() + 60, Optimizer::OPTIMIZE_HOOK, array( $queued, Optimizer::AUTOMATIC ), Optimizer::GROUP );
		Records::update(
			$waiting,
			array(
				'status' => Records::WAITING,
				'group'  => array( 'id' => 'grp_open' ),
			)
		);
		as_schedule_single_action( time() + 60, Optimizer::CHECK_HOOK, array( $waiting ), Optimizer::GROUP );
		Records::update( $done, array( 'status' => Records::OPTIMIZED ) );

		update_option( Connection::PAIRING_OPTION, array( 'id' => 'pair_1' ), false );
		as_schedule_single_action( time() + 30, Connection::CLAIM_HOOK, array( 'pair_1' ), Connection::GROUP );

		Lifecycle::deactivate();

		$this->assertSame( Records::PARKED, Records::get( $queued )['status'] );
		$this->assertSame( 'deactivated', Records::get( $queued )['error']['code'] );
		$this->assertSame( Records::PARKED, Records::get( $waiting )['status'] );
		$this->assertSame( array( 'id' => 'grp_open' ), Records::get( $waiting )['group'], 'Its image is counted, so the group is finished later.' );
		$this->assertSame( Records::OPTIMIZED, Records::get( $done )['status'] );
		$this->assertFalse( get_option( Connection::PAIRING_OPTION ) );
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'group'  => Optimizer::GROUP,
					'status' => 'pending',
				),
				'ids'
			)
		);
	}

	public function test_turning_it_on_again_gives_a_pause_its_timer_back_and_sends_the_parked_images_on(): void {
		$parked = $this->attachment();
		Records::wait( $parked, Records::PARKED, 'deactivated', 'Waiting.' );
		Plugin::keys()->save( '7|site-key' );
		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_SITE, 'free' );
		as_unschedule_all_actions( Queue::OPENS_HOOK );

		Lifecycle::resume();
		$this->assertFalse( Optimizer::pending( Queue::OPENS_HOOK, array() ), 'Nothing happens until the plugin was turned on.' );

		Lifecycle::activate();
		Lifecycle::resume();

		$this->assertTrue( Optimizer::pending( Queue::OPENS_HOOK, array() ) );
		$this->assertTrue( Optimizer::pending( Queue::RESUME_HOOK, array( 0 ) ) );
		$this->assertEmpty( get_option( Lifecycle::ACTIVATED_OPTION ) );
	}

	public function test_turning_it_on_again_parks_an_image_whose_run_was_lost_and_leaves_one_that_has_its_run(): void {
		$lost    = $this->attachment();
		$running = $this->attachment();

		Plugin::keys()->save( '7|site-key' );
		Records::update( $lost, array( 'status' => Records::QUEUED ) );
		Records::update( $running, array( 'status' => Records::WAITING ) );
		as_schedule_single_action( time() + 60, Optimizer::CHECK_HOOK, array( $running ), Optimizer::GROUP );

		Lifecycle::activate();
		Lifecycle::resume();

		$this->assertSame( Records::PARKED, Records::get( $lost )['status'] );
		$this->assertSame( Records::WAITING, Records::get( $running )['status'] );
		$this->assertTrue( Optimizer::pending( Queue::RESUME_HOOK, array( 0 ) ) );
	}

	public function test_turning_it_off_ends_even_when_a_status_cant_be_written(): void {
		$id = $this->attachment();
		Records::update( $id, array( 'status' => Records::QUEUED ) );

		$refuse = static function ( $check, $object_id, $key ) {
			return Records::STATUS_META === $key ? false : $check;
		};
		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		Lifecycle::deactivate();

		remove_filter( 'update_post_metadata', $refuse, 10 );

		$this->assertSame( Records::PARKED, Records::get( $id )['status'] );
		$this->assertSame( Records::QUEUED, get_post_meta( $id, Records::STATUS_META, true ), 'The write the filter refused.' );
	}

	public function test_deleting_it_keeps_the_copies_backups_and_records_and_removes_the_rest(): void {
		$id = $this->optimized();

		Plugin::keys()->save( '7|site-key' );
		$this->api->on( 'DELETE', 'site', 204, '' );
		update_option( Connection::REVOKED_OPTION, 1, false );
		update_option( Site_Record::MESSAGE_OPTION, array( 'code' => 'day_spent' ), false );
		update_option( Site_Record::FORMATS_OPTION, array( 'webp' ), false );
		set_transient( Site_Record::TRANSIENT, array( 'plan' => 'free' ), 300 );
		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_SITE, 'free' );
		Local_Settings::update( array( 'backups' => true ) );
		update_user_meta( self::factory()->user->create(), Notice::META, array( 'day_spent' ) );
		as_schedule_single_action( time() + 60, Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP );
		as_schedule_single_action( time() + 60, 'iminify_tests_other_plugin' );

		$copy   = $this->copy_of( $id );
		$backup = Paths::absolute( Records::get( $id )['backups'][0] );
		$purges = $this->purges();

		Lifecycle::uninstall();

		$this->assertSame( array(), $purges->asked, 'The copies stay, so the page caches naming them stay too.' );
		$this->assertCount( 1, $this->api->requests );
		$this->assertSame( 'DELETE', $this->api->requests[0]['method'] );
		$this->assertSame( Lifecycle::REVOKE_TIMEOUT, $this->api->requests[0]['args']['timeout'] );

		foreach ( Lifecycle::OPTIONS as $option ) {
			$this->assertSame( 'gone', get_option( $option, 'gone' ), $option );
		}

		$this->assertFalse( get_transient( Site_Record::TRANSIENT ) );
		$this->assertSame( 0, $this->meta_rows( 'usermeta', Notice::META ) );
		$this->assertFileExists( $copy );
		$this->assertFileExists( $backup );
		$this->assertNotEmpty( Records::get( $id )['copies'] );
		$this->assertSame( Records::OPTIMIZED, get_post_meta( $id, Records::STATUS_META, true ) );
		$this->assertSame( 0, $this->action_rows( Optimizer::OPTIMIZE_HOOK ) );
		$this->assertSame( 1, $this->action_rows( 'iminify_tests_other_plugin' ), 'Another plugin\'s runs stay.' );
	}

	public function test_deleting_it_with_both_ticked_removes_only_its_own_files_and_then_its_records(): void {
		$id      = $this->optimized();
		$copy    = $this->copy_of( $id );
		$foreign = $this->copy_of( $id, 'avif', 'another-plugin' );
		$backup  = Paths::absolute( Records::get( $id )['backups'][0] );
		$stray   = Paths::uploads() . '/' . Paths::BACKUP_FOLDER . '/someone-elses/notes.txt';

		wp_mkdir_p( dirname( $stray ) );
		file_put_contents( $stray, 'not the plugin\'s' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.

		Local_Settings::update(
			array(
				'uninstall_copies'  => true,
				'uninstall_backups' => true,
			)
		);

		Lifecycle::uninstall();

		$this->assertSame( array(), $this->api->requests, 'Not connected, so nothing is sent.' );
		$this->assertFileDoesNotExist( $copy );
		$this->assertFileExists( $foreign );
		$this->assertFileDoesNotExist( $backup );
		$this->assertFileExists( $stray, 'A file the records don\'t name stays, with its folder.' );
		$this->assertFileExists( Paths::uploads() . '/' . Paths::BACKUP_FOLDER . '/index.php' );
		$this->assertSame( '', get_post_meta( $id, Records::META, true ) );
		$this->assertSame( '', get_post_meta( $id, Records::STATUS_META, true ) );

		wp_delete_file( $stray );
		wp_delete_file( $foreign );
		Backups::remove_folder();

		$this->assertDirectoryDoesNotExist( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
	}

	public function test_deleting_it_with_only_the_copies_ticked_keeps_the_records_for_the_backups(): void {
		$id     = $this->optimized();
		$copy   = $this->copy_of( $id );
		$backup = Paths::absolute( Records::get( $id )['backups'][0] );

		Local_Settings::update( array( 'uninstall_copies' => true ) );
		$purges = $this->purges();

		Lifecycle::uninstall();

		$this->assertSame( array( 'uninstall' ), $purges->asked, 'A page a cache kept would name the copies just deleted.' );
		$this->assertFileDoesNotExist( $copy );
		$this->assertFileExists( $backup );
		$this->assertSame( array(), Records::get( $id )['copies'] );
		$this->assertSame( array( (string) Paths::relative( $backup ) ), Records::get( $id )['backups'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_set_in_wp_config_is_never_stopped_by_a_deletion(): void {
		define( 'IMINIFY_API_KEY', '7|from-wp-config' );

		$this->assertTrue( ( new Key_Store() )->from_constant() );

		Lifecycle::uninstall();

		$this->assertSame( array(), $this->api->requests );
	}

	/**
	 * A 640 by 480 JPEG uploaded to the media library.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * An attachment the plugin optimized, with the backup of its original.
	 */
	protected function optimized(): int {
		$id   = $this->attachment();
		$file = (string) get_attached_file( $id );

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'backups' => (array) Backups::keep( $file, (string) Paths::relative( $file ), array() ),
			)
		);

		return $id;
	}

	/**
	 * Writes a copy beside the attachment's main file and records it.
	 *
	 * @param int    $id     The attachment's id.
	 * @param string $format The copy's format.
	 * @param string $origin Who made it.
	 * @return string The copy's path.
	 */
	protected function copy_of( int $id, string $format = 'webp', string $origin = Records::ORIGIN ): string {
		$file   = (string) get_attached_file( $id );
		$path   = $file . '.' . $format;
		$record = Records::get( $id );

		file_put_contents( $path, $format . ' bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		clearstatcache( true, $path );

		$record['copies'][ (string) Paths::relative( $path ) ] = array(
			'for'       => (string) Paths::relative( $file ),
			'format'    => $format,
			'bytes'     => (int) filesize( $path ),
			'origin'    => $origin,
			'for_bytes' => (int) filesize( $file ),
		);

		Records::update( $id, array( 'copies' => $record['copies'] ) );

		return $path;
	}

	/**
	 * How many rows Action Scheduler keeps for a hook, whatever their status.
	 *
	 * @param string $hook The hook.
	 */
	protected function action_rows( string $hook ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", $hook ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads Action Scheduler's table in a test.
	}

	/**
	 * How many rows of a meta key a meta table holds.
	 *
	 * @param string $table The table, without its prefix.
	 * @param string $key   The meta key.
	 */
	protected function meta_rows( string $table, string $key ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE meta_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reads a meta table in a test.
	}

	/**
	 * Records each purge of the page caches the plugin asks for, and lets none
	 * through, for the rest of the test.
	 *
	 * @return object{asked: list<string>}
	 */
	protected function purges(): object {
		$purges        = new \stdClass();
		$purges->asked = array();

		add_filter(
			'iminify_purge_caches',
			static function ( $purge, $why ) use ( $purges ) {
				$purges->asked[] = $why;

				return false;
			},
			10,
			2
		);

		return $purges;
	}
}
