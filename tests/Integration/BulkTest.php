<?php
/**
 * The bulk run, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers which images a run sends and how many at once, that a bulk image stays
 * one through a spent day, the hold when the service's queue is full, stopping,
 * the run driven by its page, restoring the library, the plugin turned off and
 * on, the end of a run, and the routes with the service's estimate.
 */
class BulkTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

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
		delete_option( Connection::REVOKED_OPTION );
		$this->site( array( 'optimize_on_upload' => false ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		delete_option( Bulk::OPTION );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_a_run_sends_a_window_of_the_images_never_optimized_as_bulk_whatever_the_upload_switch_says(): void {
		Site_Record::fetch();
		$per    = Bulk::files_per_image();
		$window = intdiv( Bulk::START_FILES, $per );
		$fresh  = array();

		for ( $i = 0; $i <= $window + 1; $i++ ) {
			$fresh[] = $this->fresh();
		}

		$optimized = $this->fresh( Records::OPTIMIZED );
		$restored  = $this->fresh( Records::NOT_OPTIMIZED, 'restored' );
		$failed    = $this->fresh( Records::ERROR, 'reopened' );
		$skipped   = $this->fresh( Records::SKIPPED, 'no_original' );
		$off       = $this->fresh( Records::NOT_OPTIMIZED, 'off' );
		$gone      = $this->fresh();
		wp_delete_file( (string) get_attached_file( $gone ) );

		$this->assertSame( count( $fresh ) + 2, Bulk::counts()['not_optimized'] );
		$this->assertSame( Records::RESTORED, get_post_meta( $restored, Records::STATUS_META, true ) );

		$run = Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertIsArray( $run );
		$this->assertSame( count( $fresh ) + 2, $run['total'] );
		$this->assertSame( $window, $run['sent'] );

		$sent = array_slice( $fresh, 0, $window );

		foreach ( $sent as $id ) {
			$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
			$this->assertSame( Optimizer::BULK, Records::get( $id )['trigger'] );
			$this->assertSame( Optimizer::PRIORITY_LATER, Records::get( $id )['priority'] );
			$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );
		}

		foreach ( array_merge( array_slice( $fresh, $window ), array( $optimized, $restored, $failed, $skipped, $off ) ) as $id ) {
			$this->assertFalse( Optimizer::has_run( $id ), (string) $id );
		}

		$this->assertTrue( Optimizer::pending( Bulk::FEED_HOOK, array() ) );

		/* The site doesn't optimize uploads, and a bulk image goes all the same, on the slower lane. */
		Optimizer::optimize( $sent[0], Optimizer::BULK );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( 'background', json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true )['priority'] );
		$this->assertSame( Records::WAITING, Records::get( $sent[0] )['status'] );

		/* Nothing more goes while the window is full; once the queued ones leave it, the next look sends the rest. */
		as_unschedule_all_actions( Bulk::FEED_HOOK );
		Bulk::feed();
		$this->assertSame( $window, Bulk::run()['sent'] );

		foreach ( $sent as $id ) {
			Records::update(
				$id,
				array(
					'status' => Records::OPTIMIZED,
					'group'  => null,
				)
			);
		}

		/* Another request's look under way holds this one back, until its lock is stale. */
		add_option( Bulk::LOCK_OPTION, time() + 60, '', false );
		Bulk::feed();
		$this->assertSame( $window, Bulk::run()['sent'] );
		update_option( Bulk::LOCK_OPTION, time() - 1, false );

		Bulk::feed();

		$this->assertSame( count( $fresh ) + 1, Bulk::run()['sent'] );
		$this->assertFalse( get_option( Bulk::LOCK_OPTION ) );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $off, Optimizer::BULK ) ) );
		$this->assertFalse( Optimizer::has_run( $restored ) );

		/* An image the service can't take is set aside, so it no longer counts as waiting to be optimized. */
		$this->assertFalse( Optimizer::has_run( $gone ) );
		$this->assertSame( Records::SKIPPED, Records::get( $gone )['status'] );
		$this->assertSame( 'unsupported_format', Records::get( $gone )['error']['code'] );
		$this->assertSame( 0, Bulk::counts()['not_optimized'] );
	}

	public function test_a_bulk_image_paused_by_a_spent_day_comes_back_as_bulk_and_goes_with_the_upload_switch_off(): void {
		$id = $this->fresh();
		Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE );
		$this->spent_site();

		Optimizer::optimize( $id, Optimizer::BULK );

		$this->assertSame( Records::PAUSED, Records::get( $id )['status'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );

		/* While the day is spent the feeder sends nothing more. */
		$other = $this->fresh();
		Bulk::step();
		$this->assertFalse( Optimizer::has_run( $other ) );

		Pause::lift();
		$this->site( array( 'optimize_on_upload' => false ) );
		Queue::resume();

		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );

		Optimizer::optimize( $id, Optimizer::BULK );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );
	}

	public function test_a_group_turned_away_for_a_full_queue_holds_the_feeder_and_halves_its_window_once(): void {
		Site_Record::fetch();
		$per = Bulk::files_per_image();
		$ids = array( $this->fresh(), $this->fresh(), $this->fresh() );
		Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE );
		$this->api->on(
			'POST',
			'groups',
			429,
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many images are waiting for your account.',
			),
			array( 'Retry-After' => '30' )
		);

		Optimizer::optimize( $ids[0], Optimizer::BULK );
		$held = Bulk::run();

		$this->assertEqualsWithDelta( time() + 30, $held['hold'], 2 );
		$this->assertSame( max( $per, intdiv( Bulk::START_FILES, 2 ) ), $held['window'] );
		$this->assertSame( Records::QUEUED, Records::get( $ids[0] )['status'] );
		$this->assertNull( Pause::until() );

		/* The burst's next refusal leaves the window as it is. */
		Optimizer::optimize( $ids[1], Optimizer::BULK );
		$this->assertSame( $held['window'], Bulk::run()['window'] );

		/* An upload turned away the same way never holds the bulk run. */
		update_option( Bulk::OPTION, array_merge( (array) get_option( Bulk::OPTION ), array( 'hold' => 0 ) ) );
		$this->site( array( 'optimize_on_upload' => true ) );
		Site_Record::fetch();
		$upload = $this->fresh();
		$opened = count( $this->api->calls( 'POST', 'groups' ) );
		Optimizer::queue( $upload, Optimizer::AUTOMATIC, 0 );
		Optimizer::optimize( $upload, Optimizer::AUTOMATIC );

		$this->assertCount( $opened + 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( 'rate_limited', Records::get( $upload )['error']['code'] );
		$this->assertSame( 0, Bulk::run()['hold'] );

		/* The window grows back only once everything in it was taken, and by one image a look. */
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		$window = Bulk::run()['window'];
		update_option( Bulk::OPTION, array_merge( (array) get_option( Bulk::OPTION ), array( 'window' => $per ) ) );
		Bulk::step();
		$this->assertSame( $per, Bulk::run()['window'] );

		foreach ( array( $ids[0], $ids[1], $ids[2], $upload ) as $id ) {
			Records::update( $id, array( 'status' => Records::WAITING ) );
		}

		Bulk::step();
		Bulk::step();

		$this->assertSame( 2 * $per, Bulk::run()['window'] );
		$this->assertLessThanOrEqual( $window, Bulk::run()['window'] );
	}

	public function test_stop_takes_back_what_the_run_queued_without_a_group_and_lets_an_open_group_finish(): void {
		$ids = array( $this->fresh(), $this->fresh() );
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );
		Optimizer::optimize( $ids[0], Optimizer::BULK );

		Bulk::stop();

		$this->assertSame( Bulk::STOPPED, Bulk::run()['status'] );
		$this->assertFalse( Optimizer::pending( Bulk::FEED_HOOK, array() ) );
		$this->assertSame( Records::WAITING, Records::get( $ids[0] )['status'] );
		$this->assertSame( self::GROUP, Records::get( $ids[0] )['group']['id'] );
		$this->assertTrue( Optimizer::pending( Optimizer::CHECK_HOOK, array( $ids[0] ) ) );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $ids[1] )['status'] );
		$this->assertSame( 'stopped', Records::get( $ids[1] )['error']['code'] );
		$this->assertFalse( Optimizer::has_run( $ids[1] ) );

		/* A stopped image is one never optimized, so the next run sends it. */
		$this->assertSame( 1, Bulk::counts()['not_optimized'] );
	}

	public function test_the_page_runs_the_plugins_due_actions_itself_and_no_other_plugins(): void {
		$id = $this->fresh();
		as_schedule_single_action( time() - 1, 'another_plugins_hook', array(), 'another-plugin' );

		Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE );

		$this->assertFalse( Optimizer::pending( Bulk::FEED_HOOK, array() ) );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );

		/* An action Action Scheduler's own runner claimed is never run here too. */
		$store = \ActionScheduler::store();
		$claim = $store->stake_claim( 10, null, array( Optimizer::OPTIMIZE_HOOK ) );
		Bulk::step();
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$store->release_claim( $claim );

		$wait = Bulk::step();

		$this->assertGreaterThanOrEqual( 1, $wait );
		$this->assertLessThanOrEqual( 60, $wait );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' ) );
		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );
		$this->assertNotSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => 'another_plugins_hook',
					'status' => 'pending',
				),
				'ids'
			)
		);

		as_unschedule_all_actions( 'another_plugins_hook' );

		/* Stopped, the run still has its open group finished from the page, on a host where nothing else runs it. */
		Bulk::stop();
		as_unschedule_all_actions( Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP );
		as_schedule_single_action( time() - 1, Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, Api_Stub::fixture( 'group-open' ) );

		Bulk::step();

		$this->assertCount( 1, $this->api->calls( 'GET', 'groups/' . self::GROUP ) );
	}

	public function test_a_restore_run_puts_every_optimized_image_back_with_no_connection(): void {
		$first   = $this->optimized();
		$second  = $this->optimized();
		$nothing = $this->fresh( Records::OPTIMIZED );
		$saved   = Records::saved();

		$this->assertGreaterThan( 0, $saved['before'] );

		Plugin::keys()->forget();

		$this->assertSame( 'not_connected', Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE )->get_error_code() );

		Bulk::start( Bulk::RESTORE, Bulk::PAGE );
		Bulk::step();
		Bulk::step();

		$run = Bulk::run();

		$this->assertSame( Bulk::DONE, $run['status'] );
		$this->assertSame( 3, $run['sent'] );
		$this->assertSame( 0, $run['failed'] );

		foreach ( array( $first, $second, $nothing ) as $id ) {
			$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $id )['status'] );
			$this->assertSame( Records::RESTORED, get_post_meta( $id, Records::STATUS_META, true ) );
			$this->assertSame( array(), Records::get( $id )['written'] );
		}

		$this->assertSame(
			array(
				'before' => 0,
				'after'  => 0,
			),
			Records::saved()
		);
		$this->assertSame( 3, Bulk::counts()['restored'] );
		$this->assertSame( 0, Bulk::counts()['not_optimized'] );

		$listed = new \WP_Query(
			array_merge(
				Media_View::query_for( Media_View::FILTER_NOT_OPTIMIZED ),
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'fields'      => 'ids',
				)
			)
		);

		$this->assertEqualsCanonicalizing( array( $first, $second, $nothing ), array_map( 'intval', $listed->posts ) );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_turning_the_plugin_off_holds_the_run_and_turning_it_on_goes_on_with_it(): void {
		Site_Record::fetch();
		$window = intdiv( Bulk::START_FILES, Bulk::files_per_image() );

		for ( $i = 0; $i <= $window; $i++ ) {
			$this->fresh();
		}

		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		Lifecycle::deactivate();

		$this->assertSame( Bulk::PAUSED, Bulk::run()['status'] );
		$this->assertSame( 'deactivated', Bulk::run()['reason'] );
		$this->assertFalse( Optimizer::pending( Bulk::FEED_HOOK, array() ) );

		Lifecycle::activate();
		Lifecycle::resume();

		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );
		$this->assertTrue( Optimizer::pending( Bulk::FEED_HOOK, array() ) );

		/* While what it sent waits for the connection, the run sends nothing more. */
		$this->assertSame( $window, Bulk::run()['sent'] );
		$this->assertSame( $window, Records::count_with_status( array( Records::PARKED ) ) );

		/* A run the person paused stays paused across the same. */
		Bulk::pause();
		Lifecycle::deactivate();
		Lifecycle::activate();
		Lifecycle::resume();

		$this->assertSame( Bulk::PAUSED, Bulk::run()['status'] );
		$this->assertNull( Bulk::run()['reason'] );
	}

	public function test_a_run_ends_once_nothing_is_left_and_nothing_is_on_its_way(): void {
		$id = $this->fresh();
		Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE );

		Bulk::step();
		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );

		Records::update(
			$id,
			array(
				'status' => Records::OPTIMIZED,
				'group'  => null,
			)
		);
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Bulk::step();

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( 'nothing_to_do', Bulk::start( Bulk::OPTIMIZE, Bulk::PAGE )->get_error_code() );
	}

	public function test_sending_the_failed_images_again_counts_their_tries_afresh(): void {
		$id = $this->fresh( Records::ERROR, 'unreachable' );
		Records::update(
			$id,
			array(
				'attempts' => Optimizer::MAX_ATTEMPTS + 1,
				'reopens'  => Optimizer::MAX_REOPENS + 1,
			)
		);

		Bulk::start( Bulk::RETRY, Bulk::PAGE );

		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertSame( 0, Records::get( $id )['attempts'] );
		$this->assertSame( 0, Records::get( $id )['reopens'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );
	}

	public function test_a_background_run_whose_actions_nobody_runs_reads_as_stalled_with_what_site_health_says(): void {
		$this->fresh();
		Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertFalse( Bulk::stalled() );

		as_schedule_single_action( time() - Bulk::STALL_SECONDS - 60, Queue::RESUME_HOOK, array( 7 ), Optimizer::GROUP );
		set_transient(
			Bulk_State::LOOPBACK_TRANSIENT,
			array(
				'ok'      => false,
				'message' => 'The loopback request to your site failed.',
			)
		);

		$this->assertTrue( Bulk::stalled() );

		$run = Bulk_State::build()['run'];

		$this->assertTrue( $run['stalled'] );
		$this->assertSame( 'The loopback request to your site failed.', $run['loopback']['message'] );
		$this->assertSame( Bulk_State::COMMAND, $run['command'] );

		/* The page drives a run in its own mode, so it is never stalled. */
		Bulk::switch_mode( Bulk::PAGE );
		$this->assertFalse( Bulk::stalled() );
		$this->assertFalse( Optimizer::pending( Bulk::FEED_HOOK, array() ) );

		delete_transient( Bulk_State::LOOPBACK_TRANSIENT );
	}

	public function test_the_routes_answer_only_someone_who_manages_the_site_and_ask_the_service_only_for_the_estimate(): void {
		Site_Record::fetch();
		$per = Bulk::files_per_image();
		$this->fresh();
		$this->fresh();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( array( 'GET', '' ), array( 'POST', '/start' ), array( 'POST', '/stop' ), array( 'POST', '/step' ), array( 'POST', '/mode' ) ) as $route ) {
			$this->assertSame(
				403,
				$this->call(
					$route[0],
					$route[1],
					array(
						'action' => Bulk::OPTIMIZE,
						'mode'   => Bulk::PAGE,
					)
				)->get_status()
			);
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site                     = Api_Stub::fixture( 'site' );
		$site['data']['estimate'] = array(
			'attachments' => 2,
			'files'       => 2 * $per,
			'days'        => 3,
			'faster'      => array(
				array(
					'plan' => 'trial',
					'days' => 1,
				),
			),
		);
		$this->api->on( 'GET', 'site', 200, $site );
		$this->api->requests = array();

		$poll = $this->call( 'GET', '' )->get_data();

		$this->assertSame( 2, $poll['counts']['not_optimized'] );
		$this->assertNull( $poll['estimate'] );
		$this->assertSame( array(), $this->api->requests );

		$state = $this->call( 'GET', '?estimate=1' )->get_data();
		$asked = $this->api->calls( 'GET', 'site' );

		$this->assertSame(
			array(
				'days'   => 3,
				'faster' => array(
					array(
						'plan' => 'Pro trial',
						'days' => 1,
					),
				),
			),
			$state['estimate']
		);
		$this->assertStringContainsString( 'waiting=2', end( $asked )['url'] );
		$this->assertStringContainsString( 'waiting_files=' . ( 2 * $per ), end( $asked )['url'] );
		$this->assertSame( size_format( 2 * (int) filesize( DIR_TESTDATA . '/images/canola.jpg' ), 1 ), $state['disk']['backups'] );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $state ) );

		$started = $this->call(
			'POST',
			'/start',
			array(
				'action' => Bulk::OPTIMIZE,
				'mode'   => Bulk::PAGE,
			)
		);

		$this->assertSame( 200, $started->get_status() );
		$this->assertSame( Bulk::RUNNING, $started->get_data()['run']['status'] );
		$this->assertSame( 409, $this->call( 'POST', '/start', array( 'action' => Bulk::RESTORE ) )->get_status() );
		$this->assertSame( Bulk::STOPPED, $this->call( 'POST', '/stop' )->get_data()['run']['status'] );
	}

	/**
	 * An image uploaded to the library that the plugin hasn't handled yet, or that has a record with this status.
	 *
	 * @param string|null $status The record's status, or null for none.
	 * @param string|null $code   The record's reason.
	 */
	protected function fresh( ?string $status = null, ?string $code = null ): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		/* The upload queued itself on a connected site; these images wait for the bulk run instead. */
		Optimizer::cancel( $id );

		foreach ( Records::ALL_META as $key ) {
			delete_post_meta( $id, $key );
		}

		if ( null !== $status ) {
			Records::update(
				$id,
				array(
					'status' => $status,
					'error'  => null === $code ? null : array(
						'code'    => $code,
						'message' => $code,
					),
				)
			);
		}

		return $id;
	}

	/**
	 * An image the plugin optimized, with its backup, its main file written over and its savings recorded.
	 */
	protected function optimized(): int {
		$id   = $this->fresh();
		$file = (string) get_attached_file( $id );

		$backups = (array) Backups::keep( $file, (string) Paths::relative( $file ), array() );
		$editor  = wp_get_image_editor( $file );
		$editor->set_quality( 5 );
		$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );
		copy( $saved['path'], $file );
		wp_delete_file( $saved['path'] );
		clearstatcache( true, $file );

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( (string) Paths::relative( $file ) => (int) filesize( $file ) ),
				'backups' => $backups,
				'result'  => array(
					'level'   => 'smart',
					'formats' => array(),
					'outputs' => array(
						'full' => array(
							'before' => 40000,
							'after'  => (int) filesize( $file ),
						),
					),
				),
			)
		);

		return $id;
	}

	/**
	 * The site's record, with these settings.
	 *
	 * @param array<string, mixed> $settings The settings to change.
	 */
	protected function site( array $settings ): void {
		$site                     = Api_Stub::fixture( 'site' );
		$site['data']['settings'] = array_merge( $site['data']['settings'], $settings );
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
	}

	/**
	 * The site's record with the account's day spent.
	 */
	protected function spent_site(): void {
		$site                          = Api_Stub::fixture( 'site' );
		$site['data']['settings']      = array_merge( $site['data']['settings'], array( 'optimize_on_upload' => false ) );
		$site['data']['next_batch_at'] = gmdate( 'c', time() + HOUR_IN_SECONDS );
		$site['data']['message']       = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s images for the day are used up.',
		);
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
	}

	/**
	 * Sends a request to one of the bulk routes.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The path under `/bulk`, with any query.
	 * @param array<string, mixed> $body   The JSON body.
	 */
	protected function call( string $method, string $path, array $body = array() ): WP_REST_Response {
		$parts = explode( '?', $path, 2 );
		$route = $parts[0];
		$query = $parts[1] ?? '';

		$request = new WP_REST_Request( $method, '/iminify/v1/bulk' . $route );

		if ( '' !== $query ) {
			parse_str( $query, $params );
			$request->set_query_params( $params );
		}

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
