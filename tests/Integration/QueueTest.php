<?php
/**
 * A spent day, a lost connection and a low disk, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Hooks;
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
 * Covers the pause and how it ends, parking the queue when the key stops
 * working, and what a low disk leaves undone.
 */
class QueueTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

	const SPENT = array(
		'code'    => 'daily_limit_reached',
		'message' => 'You\'ve used your 40 images for today.',
	);

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
		delete_option( Connection::REVOKED_OPTION );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( Queue::RESUME_HOOK );
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		Plugin::keys()->forget();
		Site_Record::forget();
		remove_all_filters( 'iminify_disk_free_space' );
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_a_spent_day_read_from_the_site_opens_no_group_and_keeps_the_upload_waiting_with_nothing_scheduled(): void {
		$until = $this->spent_site( 3600 );
		$id    = $this->attachment();

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertFalse( $this->queued( $id ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( Records::PAUSED, Records::get( $id )['status'] );
		$this->assertSame( 'day_spent', Records::get( $id )['error']['code'] );
		$this->assertSame( strtotime( $until ), Pause::until() );
		$this->assertSame( array( $id ), Records::with_status( array( Records::PAUSED ), 10 ) );
		$this->assertGreaterThanOrEqual( Pause::until(), as_next_scheduled_action( Queue::OPENS_HOOK, array(), Optimizer::GROUP ) );

		/* While it holds, an image that comes along asks nothing of the service, whatever the cached record says. */
		$reads = count( $this->api->calls( 'GET', 'site' ) );
		Site_Record::forget();
		$other = $this->attachment();

		Optimizer::optimize( $other, Optimizer::AUTOMATIC );

		$this->assertCount( $reads, $this->api->calls( 'GET', 'site' ) );
		$this->assertSame( Records::PAUSED, Records::get( $other )['status'] );
	}

	public function test_a_refused_group_stops_every_new_group_for_its_wait_with_nothing_failed(): void {
		$first  = $this->attachment();
		$second = $this->attachment();
		$this->api->on( 'POST', 'groups', 429, self::SPENT, array( 'Retry-After' => '7200' ) );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::optimize( $first, Optimizer::AUTOMATIC );
		Optimizer::optimize( $second, Optimizer::AUTOMATIC );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( array( Records::PAUSED, Records::PAUSED ), array( Records::get( $first )['status'], Records::get( $second )['status'] ) );
		$this->assertEqualsWithDelta( time() + 7200, Pause::until(), 5 );
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $first, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		/* A minute's limit, or a full queue on the service, stays one attachment's wait. */
		Pause::lift();
		$third = $this->attachment();
		$this->api->on(
			'POST',
			'groups',
			429,
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many.',
			),
			array( 'Retry-After' => '30' )
		);
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::optimize( $third, Optimizer::AUTOMATIC );

		$this->assertNull( Pause::until() );
		$this->assertSame( Records::QUEUED, Records::get( $third )['status'] );
		$this->assertIsInt( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $third, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
	}

	public function test_an_open_group_goes_on_during_a_pause_since_its_image_is_counted(): void {
		$id = $this->attachment();
		Optimizer::optimize( $id, Optimizer::AUTOMATIC );
		$this->spent_site( 3600 );
		Site_Record::fetch();

		$answer           = Api_Stub::fixture( 'group' )['data'];
		$answer['status'] = 'in_progress';
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );

		Optimizer::check( $id );

		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );
		$this->assertCount( 1, $this->api->calls( 'GET', 'groups/' . self::GROUP ) );
	}

	public function test_when_the_batch_opens_the_waiting_images_go_again_new_uploads_ahead_of_older_work(): void {
		$old = $this->attachment();
		$new = $this->attachment();

		wp_update_post(
			array(
				'ID'            => $old,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		$this->api->on( 'POST', 'groups', 429, self::SPENT, array( 'Retry-After' => '3600' ) );

		/* Held from the moment they were queued, however long the pause lasts. */
		Records::bump( $old );
		Records::bump( $new );
		Optimizer::queue( $old );
		Optimizer::queue( $new );
		Optimizer::optimize( $old, Optimizer::AUTOMATIC );
		Optimizer::optimize( $new, Optimizer::AUTOMATIC );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		$this->assertSame( Records::PAUSED, Records::get( $new )['status'] );

		/* The wait outlasts what counts as recent, and the upload is still an upload. */
		wp_update_post(
			array(
				'ID'            => $new,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			)
		);

		/* A timer firing inside a pause moved later keeps them waiting, and leaves a timer for the new time. */
		as_unschedule_all_actions( Queue::OPENS_HOOK );
		Queue::batch_opens();
		$this->assertSame( Records::PAUSED, Records::get( $new )['status'] );
		$this->assertGreaterThan( Pause::until(), as_next_scheduled_action( Queue::OPENS_HOOK, array(), Optimizer::GROUP ) );

		update_option(
			Pause::OPTION,
			array(
				'until' => time() - 1,
				'from'  => Pause::FROM_REFUSAL,
				'plan'  => 'free',
			)
		);

		Queue::batch_opens();

		$priorities = array();

		foreach ( as_get_scheduled_actions(
			array(
				'hook'   => Optimizer::OPTIMIZE_HOOK,
				'status' => 'pending',
			)
		) as $action ) {
			$priorities[ $action->get_args()[0] ] = $action->get_priority();
		}

		$this->assertSame( Records::QUEUED, Records::get( $new )['status'] );
		$this->assertSame( Records::QUEUED, Records::get( $old )['status'] );
		$this->assertLessThan( $priorities[ $old ], $priorities[ $new ] );
		$this->assertFalse( get_option( Pause::OPTION ) );

		/* The same goes for the lane: the upload that waited goes on the normal one, and older work on the slower. */
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::optimize( $new, Optimizer::AUTOMATIC );
		Optimizer::optimize( $old, Optimizer::AUTOMATIC );

		$sent = $this->api->calls( 'POST', 'groups' );

		$this->assertArrayNotHasKey( 'priority', json_decode( $sent[ count( $sent ) - 2 ]['args']['body'], true ) );
		$this->assertSame( 'background', json_decode( $sent[ count( $sent ) - 1 ]['args']['body'], true )['priority'] );
	}

	public function test_an_uploads_place_survives_the_second_event_of_a_browser_made_upload(): void {
		$id = $this->attachment();

		$this->assertTrue( $this->queued( $id ) );

		/* WordPress 7.1's second event for the sizes the browser made, while the first run still waits. */
		Hooks::generated( wp_get_attachment_metadata( $id ), $id );

		$this->assertSame( Optimizer::PRIORITY_UPLOAD, Records::get( $id )['priority'] );
	}

	public function test_a_long_queue_is_sent_on_in_pages_and_each_run_ends(): void {
		$ids = self::factory()->attachment->create_many( Queue::PAGE + 1, array( 'post_mime_type' => 'image/jpeg' ) );
		sort( $ids );

		foreach ( $ids as $id ) {
			Records::wait( $id, Records::PAUSED, 'day_spent', '' );
		}

		$this->assertSame( Queue::PAGE + 1, Records::count_with_status( array( Records::PAUSED ) ) );

		Queue::resume();

		$last = $ids[ Queue::PAGE - 1 ];

		$this->assertSame( array( end( $ids ) ), Records::with_status( array( Records::PAUSED ), 10 ) );
		$this->assertIsInt( as_next_scheduled_action( Queue::RESUME_HOOK, array( $last ), Optimizer::GROUP ) );

		as_unschedule_all_actions( Queue::RESUME_HOOK );
		Queue::resume( $last );

		$this->assertSame( array(), Records::with_status( array( Records::PAUSED ), 10 ) );
		$this->assertFalse( as_next_scheduled_action( Queue::RESUME_HOOK, null, Optimizer::GROUP ) );

		/* A row a run already queued isn't read again, so images that go straight back to waiting never loop. */
		Records::wait( $ids[0], Records::PAUSED, 'day_spent', '' );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Queue::resume( $last );

		$this->assertSame( Records::PAUSED, Records::get( $ids[0] )['status'] );
	}

	public function test_images_paused_when_the_batch_opened_with_the_site_disconnected_go_on_once_it_connects(): void {
		$id = $this->attachment();

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Records::wait( $id, Records::PAUSED, 'day_spent', '' );
		Plugin::keys()->forget();

		Queue::batch_opens();

		$this->assertSame( Records::PAUSED, Records::get( $id )['status'] );

		Plugin::keys()->save( self::KEY );
		Site_Record::fetch();

		$this->assertIsInt( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );

		/* While the day is spent, a working read leaves them be. */
		as_unschedule_all_actions( Queue::RESUME_HOOK );
		$this->spent_site( 3600 );
		Site_Record::fetch();

		$this->assertFalse( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );
	}

	public function test_start_now_refuses_while_the_day_is_spent_and_sends_what_waits_once_a_batch_can_start(): void {
		$id = $this->attachment();
		Records::wait( $id, Records::PAUSED, 'day_spent', '' );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		$until = $this->spent_site( 3600 );

		$this->assertFalse( Queue::start_now() );
		$this->assertSame( strtotime( $until ), Pause::until() );
		$this->assertSame( Records::PAUSED, Records::get( $id )['status'] );
		$this->assertFalse( State::build()['queue']['can_start'] );

		/* The day came back (or the plan changed), and the page's read already says so. */
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		$this->assertTrue( Queue::start_now() );
		$this->assertNull( Pause::until() );
		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertTrue( $this->queued( $id ) );

		/* Not connected, there is nothing to start. */
		Plugin::keys()->forget();
		$this->assertSame( 'not_connected', Queue::start_now()->get_error_code() );
	}

	public function test_a_pause_from_the_site_ends_with_the_site_saying_so_and_one_from_a_refusal_holds_until_the_plan_changes(): void {
		$this->spent_site( 3600 );
		Site_Record::fetch();
		$this->assertNotNull( Pause::until() );

		/* The trial or a plan bought on iminify.com: the next read lifts it and sends the queue on. */
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		Site_Record::fetch();

		$this->assertNull( Pause::until() );
		$this->assertIsInt( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );

		/* A group with more files than are left: `/site` shows files remaining, which can't lift the refusal's pause. */
		Pause::refused( 7200, 'free' );
		Site_Record::fetch();
		$this->assertNotNull( Pause::until() );

		$site                 = Api_Stub::fixture( 'site' );
		$site['data']['plan'] = 'pro';
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::fetch();

		$this->assertNull( Pause::until() );
	}

	public function test_a_key_the_service_stopped_parks_the_queue_keeping_an_open_group_and_connecting_again_sends_it_on(): void {
		$open = $this->attachment();
		Optimizer::optimize( $open, Optimizer::AUTOMATIC );
		$group = Records::get( $open )['group'];

		$this->assertNotNull( $group );

		$this->api->on(
			'GET',
			'groups/' . self::GROUP,
			401,
			array(
				'code'    => 'unauthenticated',
				'message' => 'Unauthenticated.',
			)
		);
		as_unschedule_all_actions( Optimizer::CHECK_HOOK );

		Optimizer::check( $open );

		$this->assertSame( Records::PARKED, Records::get( $open )['status'] );
		$this->assertSame( 'unauthenticated', Records::get( $open )['error']['code'] );
		$this->assertSame( $group, Records::get( $open )['group'] );
		$this->assertNull( Plugin::keys()->get() );

		/* Queued images park as their run comes up, and nothing is scheduled for any of them. */
		$queued = $this->attachment();
		Plugin::keys()->save( self::KEY );
		Plugin::keys()->forget();
		Optimizer::optimize( $queued, Optimizer::AUTOMATIC );

		$this->assertSame( Records::PARKED, Records::get( $queued )['status'] );

		/* An upload made while disconnected is left for the bulk page, and doesn't go out on reconnecting. */
		$uploaded = $this->attachment();
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $uploaded )['status'] );

		/* The first read that works sends the parked ones on, the open group finished rather than opened again. */
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		as_unschedule_all_actions( Queue::RESUME_HOOK );
		$opened = count( $this->api->calls( 'POST', 'groups' ) );
		Plugin::keys()->save( self::KEY );
		Site_Record::fetch();

		$this->assertIsInt( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );

		Queue::resume();

		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $uploaded )['status'] );
		$this->assertTrue( $this->queued( $open ) );
		$this->assertTrue( $this->queued( $queued ) );

		$answer           = Api_Stub::fixture( 'group' )['data'];
		$answer['status'] = 'in_progress';
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );

		Optimizer::optimize( $open, Optimizer::AUTOMATIC );

		$this->assertCount( $opened, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( $group['id'], Records::get( $open )['group']['id'] );
		$this->assertIsInt( as_next_scheduled_action( Optimizer::CHECK_HOOK, array( $open ), Optimizer::GROUP ) );
	}

	public function test_a_copy_of_the_site_parks_its_uploads_until_its_connection_is_settled(): void {
		$id = $this->attachment();
		$this->api->on(
			'GET',
			'site',
			409,
			array(
				'code'    => 'site_moved',
				'message' => 'This key belongs to the site at another address.',
			)
		);

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array( Records::PARKED, 'site_moved' ), array( Records::get( $id )['status'], Records::get( $id )['error']['code'] ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertNotNull( Plugin::keys()->get() );
	}

	public function test_a_low_disk_leaves_the_image_as_it_is_with_nothing_counted_and_never_without_its_backup(): void {
		$id = $this->attachment();
		$this->free_space( 50 * MB_IN_BYTES );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array( Records::NOT_OPTIMIZED, 'disk_full' ), array( Records::get( $id )['status'], Records::get( $id )['error']['code'] ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertTrue( State::build()['disk']['low'] );

		/* Past the reserve but with no room for the backup: backups on, nothing is written over the only copy. */
		$size = (int) filesize( (string) get_attached_file( $id ) );
		$this->free_space( 100 * MB_IN_BYTES + $size - 1 );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );

		/* A host that doesn't say how much room it has reads as having enough. */
		$this->free_space( null );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertFalse( State::build()['disk']['low'] );
	}

	public function test_a_backup_that_cant_be_saved_stops_the_image_with_nothing_counted(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$folder   = 'iminify-blocked-' . wp_generate_password( 6, false );
		$moved    = Paths::uploads() . '/' . $folder . '/' . wp_basename( $attached );

		wp_mkdir_p( dirname( $moved ) );
		copy( $attached, $moved );
		update_attached_file( $id, $moved );

		/* A file where the backup's folder has to go. */
		wp_mkdir_p( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		file_put_contents( Paths::uploads() . '/' . Paths::BACKUP_FOLDER . '/' . $folder, 'in the way' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- blocks the folder a backup needs.

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array( Records::ERROR, 'backup_failed' ), array( Records::get( $id )['status'], Records::get( $id )['error']['code'] ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );

		/* With backups off, the person chose to go without one. */
		Local_Settings::update( array( 'backups' => false ) );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		Local_Settings::update( array( 'backups' => true ) );

		$this->rmdir( dirname( $moved ) );
		rmdir( dirname( $moved ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the folder the test made.
	}

	public function test_the_notice_tells_of_a_refused_day_when_the_site_record_has_no_message_for_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'upload' );
		Pause::refused( 3600, 'free' );

		$notice = Notice::current();

		$this->assertSame( 0, strpos( (string) ( $notice['key'] ?? '' ), 'day_spent|' ) );

		set_current_screen( 'front' );
	}

	/**
	 * Answers the site's record with its day spent until a time from now.
	 *
	 * @param int $seconds How long from now the next batch opens.
	 * @return string The time, as the service answers it.
	 */
	protected function spent_site( int $seconds ): string {
		$site = Api_Stub::fixture( 'site' );
		$next = gmdate( 'c', time() + $seconds );

		$site['data']['next_batch_at'] = $next;
		$site['data']['message']       = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up.',
		);
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();

		return $next;
	}

	/**
	 * Makes the disk report this much free.
	 *
	 * @param int|null $bytes The free bytes, or null when the host doesn't say.
	 */
	protected function free_space( ?int $bytes ): void {
		remove_all_filters( 'iminify_disk_free_space' );
		add_filter(
			'iminify_disk_free_space',
			static function () use ( $bytes ) {
				return $bytes;
			}
		);
	}

	/**
	 * A 640 by 480 JPEG uploaded to the media library, which makes its thumbnail and medium size.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Whether the attachment waits to be optimized.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function queued( int $id ): bool {
		return false !== as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP );
	}
}
