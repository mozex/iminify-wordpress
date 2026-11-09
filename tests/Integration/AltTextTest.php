<?php
/**
 * AI alt text for images optimized earlier, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers a press and a bulk run asking for an alt text from a small copy, which
 * file goes, what a refusal does, writing it only where the alt is empty, what the
 * bulk run counts and how it ends, and picking it up after the plugin was off.
 */
class AltTextTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const REQUEST = '3f5a7b9c-1e24-4a6b-8d2c-9d3c5c024e1f';

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
		delete_option( Bulk::OPTION );
		$this->writes_alt_text( true );
		$this->service_takes_it();
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
	}

	public function tear_down(): void {
		$this->api->stop();
		delete_option( Bulk::OPTION );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();
		parent::tear_down();
	}

	public function test_a_press_sends_a_small_copy_and_the_text_is_written_where_the_alt_is_empty_and_the_record_left_to_the_service(): void {
		$id = $this->attachment();

		$this->assertTrue( Alt_Text::ask( $id ) );
		$this->assertSame( Alt_Text::QUEUED, Records::get( $id )['alt']['status'] );
		$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );

		Alt_Text::send( $id );

		$this->assertCount( 1, $this->api->calls( 'POST', 'site/alt-text' ) );
		$alt = Records::get( $id )['alt'];
		$this->assertSame( Alt_Text::WAITING, $alt['status'] );
		$this->assertSame( self::REQUEST, $alt['request'] );
		$this->assertSame( 'fr', $alt['language'] );
		$this->assertTrue( Optimizer::pending( Alt_Text::REQUEST_HOOK, array( $id, self::REQUEST ) ) );

		$this->text_says( array( 'status' => 'pending' ) );
		Alt_Text::check_request( $id, self::REQUEST );
		$this->assertSame( Alt_Text::WAITING, Records::get( $id )['alt']['status'] );

		$written = array();
		$hook    = static function ( int $attachment, string $text ) use ( &$written ): void {
			$written = array( $attachment, $text );
		};
		add_action( 'iminify_alt_text_written', $hook, 10, 2 );

		$this->text_says(
			array(
				'status'   => 'written',
				'language' => 'fr',
				'text'     => 'Une table en bois.',
			)
		);
		Alt_Text::check_request( $id, self::REQUEST );
		remove_action( 'iminify_alt_text_written', $hook );

		$this->assertSame( 'Une table en bois.', get_post_meta( $id, Alt_Text::META, true ) );
		$alt = Records::get( $id )['alt'];
		$this->assertSame( Alt_Text::WRITTEN, $alt['status'] );
		$this->assertNull( $alt['request'] );
		$this->assertSame( array( $id, 'Une table en bois.' ), $written );
		$this->assertSame( 0, Records::count_waiting_for_alt_text() );
		$this->assertSame( array(), $this->api->calls( 'DELETE', 'site/alt-text/' . self::REQUEST ) );
	}

	public function test_a_text_already_written_for_the_same_bytes_comes_at_once_with_nothing_to_poll(): void {
		$id = $this->attachment();
		$this->api->on(
			'POST',
			'site/alt-text',
			200,
			array(
				'data' => array(
					'id'       => self::REQUEST,
					'status'   => 'written',
					'language' => 'fr',
					'text'     => 'Une table en bois.',
				),
			)
		);

		Alt_Text::ask( $id );
		Alt_Text::send( $id );

		$this->assertSame( 'Une table en bois.', get_post_meta( $id, Alt_Text::META, true ) );
		$this->assertSame( Alt_Text::WRITTEN, Records::get( $id )['alt']['status'] );
		$this->assertFalse( Optimizer::pending( Alt_Text::REQUEST_HOOK, array( $id, self::REQUEST ) ) );
	}

	public function test_an_alt_someone_writes_while_the_text_is_on_its_way_stays(): void {
		$id = $this->attachment();

		Alt_Text::ask( $id );
		Alt_Text::send( $id );

		global $wpdb;

		/* Written by another request, past this request's object cache. */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => Alt_Text::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'Written by hand', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$this->text_says(
			array(
				'status' => 'written',
				'text'   => 'Une table.',
			)
		);
		Alt_Text::check_request( $id, self::REQUEST );

		$this->assertSame( 'Written by hand', get_post_meta( $id, Alt_Text::META, true ) );
		$this->assertSame( Alt_Text::KEPT, Records::get( $id )['alt']['status'] );
	}

	/**
	 * The service's bounds, and the file each makes the plugin send.
	 *
	 * @return array<string, array{array<string, int>, string|null}>
	 */
	public function small_copies(): array {
		return array(
			'the smallest large enough'             => array(
				array( 'side' => 200 ),
				'medium',
			),
			'none large enough: the largest'        => array(
				array( 'side' => 2000 ),
				'full',
			),
			'the largest past the side it may have' => array(
				array(
					'side'     => 2000,
					'max_side' => 400,
				),
				'medium',
			),
			'every file past the bytes it may have' => array(
				array(
					'side'      => 200,
					'max_bytes' => 10,
				),
				null,
			),
		);
	}

	/**
	 * @dataProvider small_copies
	 *
	 * @param array<string, int> $limits   The service's bounds.
	 * @param string|null        $expected Which file goes, or null for none.
	 */
	public function test_the_smallest_file_large_enough_goes_within_what_the_service_takes( array $limits, ?string $expected ): void {
		$this->writes_alt_text( true, $limits );
		$id       = $this->attachment();
		$metadata = wp_get_attachment_metadata( $id );

		Alt_Text::ask( $id );
		Alt_Text::send( $id );

		$calls = $this->api->calls( 'POST', 'site/alt-text' );

		if ( null === $expected ) {
			$this->assertSame( array(), $calls );
			$this->assertSame( Alt_Text::FAILED, Records::get( $id )['alt']['status'] );

			return;
		}

		$file = 'full' === $expected ? wp_basename( (string) get_attached_file( $id ) ) : $metadata['sizes'][ $expected ]['file'];

		$this->assertSame( $file, wp_basename( $calls[0]['args'][ Upload::ARGUMENT ]['path'] ) );
		$this->assertSame( 'image/jpeg', $calls[0]['args'][ Upload::ARGUMENT ]['type'] );
	}

	/**
	 * Why a press is refused, with nothing sent.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function refused_presses(): array {
		return array(
			'the setting off'           => array( 'off', Alt_Text::OFF ),
			'not connected'             => array( 'disconnected', 'not_connected' ),
			'an alt someone wrote'      => array( 'written', 'alt_text_not_needed' ),
			'its text on the way'       => array( 'queued', 'alt_text_not_needed' ),
			'its own group coming'      => array( 'optimizing', 'alt_text_not_needed' ),
			'a translation of an image' => array( 'translation', 'alt_text_not_needed' ),
		);
	}

	/**
	 * @dataProvider refused_presses
	 *
	 * @param string $what What stands in the way.
	 * @param string $code The refusal's code.
	 */
	public function test_a_press_is_refused_with_nothing_sent( string $what, string $code ): void {
		$id = $this->attachment();

		switch ( $what ) {
			case 'off':
				$this->writes_alt_text( false );
				break;
			case 'disconnected':
				Plugin::keys()->forget();
				break;
			case 'written':
				update_post_meta( $id, Alt_Text::META, 'Written by hand' );
				break;
			case 'queued':
				Alt_Text::queue( $id );
				break;
			case 'optimizing':
				Records::update( $id, array( 'status' => Records::WAITING ) );
				break;
			case 'translation':
				$id = $this->translation_of( $id );
				break;
		}

		$answer = Alt_Text::ask( $id );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( $code, $answer->get_error_code() );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/alt-text' ) );
	}

	public function test_a_copy_queued_for_what_turned_out_to_be_a_translation_is_never_sent(): void {
		$translation = $this->translation_of( $this->attachment() );

		Alt_Text::queue( $translation );
		Alt_Text::send( $translation );

		$this->assertNull( Records::get( $translation )['alt'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/alt-text' ) );
	}

	public function test_a_copy_still_queued_when_its_reason_is_gone_is_never_sent_and_counts_nothing(): void {
		$id = $this->attachment();

		Alt_Text::ask( $id );
		update_post_meta( $id, Alt_Text::META, 'Written by hand' );

		Alt_Text::send( $id );

		$this->assertNull( Records::get( $id )['alt'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/alt-text' ) );
	}

	/**
	 * What a refused send does to the image.
	 *
	 * @return array<string, array{int, string, array<string, string>, string|null}>
	 */
	public function refused_sends(): array {
		return array(
			'the day spent, on a press'  => array( 429, 'daily_limit_reached', array( 'Retry-After' => '3600' ), Alt_Text::SKIPPED ),
			'the setting turned off'     => array( 409, Alt_Text::OFF, array(), null ),
			'a site that moved'          => array( 409, 'site_moved', array(), null ),
			'an image it would not read' => array( 422, 'validation_failed', array(), Alt_Text::FAILED ),
		);
	}

	/**
	 * @dataProvider refused_sends
	 *
	 * @param int                   $status   The refusal's status.
	 * @param string                $code     Its code.
	 * @param array<string, string> $headers  Its headers.
	 * @param string|null           $expected The image's alt status after it.
	 */
	public function test_a_refused_send_ends_as_its_refusal_says( int $status, string $code, array $headers, ?string $expected ): void {
		$id = $this->attachment();
		$this->api->on(
			'POST',
			'site/alt-text',
			$status,
			array(
				'message' => 'No.',
				'code'    => $code,
			),
			$headers
		);

		Alt_Text::ask( $id );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );
		Alt_Text::send( $id );

		$alt = Records::get( $id )['alt'];

		$this->assertSame( $expected, null === $alt ? null : $alt['status'] );
		$this->assertFalse( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
	}

	public function test_a_rate_limit_waits_the_time_asked_without_counting_as_a_failed_try(): void {
		$id = $this->attachment();
		$this->api->on(
			'POST',
			'site/alt-text',
			429,
			array(
				'message' => 'Slow.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '30' )
		);

		Alt_Text::ask( $id );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );
		Alt_Text::send( $id );

		$alt = Records::get( $id )['alt'];

		$this->assertSame( Alt_Text::QUEUED, $alt['status'] );
		$this->assertSame( 0, $alt['tries'] );
		$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
	}

	public function test_a_service_out_of_reach_is_tried_again_and_then_fails(): void {
		$id = $this->attachment();
		$this->api->fail( 'POST', 'site/alt-text' );

		Alt_Text::ask( $id );

		foreach ( range( 1, Alt_Text::MAX_TRIES ) as $try ) {
			as_unschedule_all_actions( Alt_Text::SEND_HOOK );
			Alt_Text::send( $id );
			$this->assertSame( Alt_Text::MAX_TRIES === $try ? Alt_Text::FAILED : Alt_Text::QUEUED, Records::get( $id )['alt']['status'] );
		}
	}

	public function test_what_the_run_counts_and_takes_leaves_out_what_has_an_alt_what_is_on_its_way_and_a_translation_once_seen(): void {
		$empty       = $this->attachment();
		$blank       = $this->attachment();
		$written     = $this->attachment();
		$on_its_way  = $this->attachment();
		$optimizing  = $this->attachment();
		$translation = $this->translation_of( $empty );

		update_post_meta( $blank, Alt_Text::META, '   ' );
		update_post_meta( $written, Alt_Text::META, 'Written by hand' );
		Alt_Text::queue( $on_its_way );
		Records::update( $optimizing, array( 'status' => Records::PARKED ) );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );

		$this->assertSame( 3, Bulk::counts()['alt_text'] );

		$run = Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );

		$this->assertIsArray( $run );
		$this->assertSame( array( $empty, $blank ), array_values( array_intersect( array( $empty, $blank, $translation ), $this->queued() ) ) );

		$run = (array) Bulk::run();

		$this->assertSame( 2, $run['total'] );
		$this->assertSame( 2, $run['sent'] );
		$this->assertSame( 1, $run['passed'] );
		$this->assertSame( Alt_Text::SHARED, Records::get( $translation )['alt']['status'] );
		$this->assertSame( 0, Bulk::counts()['alt_text'] );
	}

	public function test_nothing_is_counted_while_the_site_doesnt_write_them_and_a_run_is_refused(): void {
		$this->attachment();
		$this->writes_alt_text( false );

		$this->assertSame( 0, Bulk::counts()['alt_text'] );
		$this->assertFalse( Bulk_State::build()['alt_text']['on'] );

		$run = Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );

		$this->assertInstanceOf( WP_Error::class, $run );
		$this->assertSame( Alt_Text::OFF, $run->get_error_code() );
	}

	public function test_a_run_keeps_only_a_window_on_its_way(): void {
		foreach ( range( 1, Bulk::ALT_WINDOW + 2 ) as $image ) {
			$this->attachment();
		}

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );

		$this->assertCount( Bulk::ALT_WINDOW, $this->queued() );

		/* Nothing more goes while the window is full. */
		Bulk::feed();
		$this->assertCount( Bulk::ALT_WINDOW, $this->queued() );
	}

	public function test_a_run_ends_only_once_every_text_has_settled(): void {
		$sent  = $this->attachment();
		$still = $this->attachment();

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		Alt_Text::send( $sent );

		/* Nothing is left to send, one text is with the service and one copy still to go. */
		Bulk::feed();
		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );

		foreach ( array( $sent, $still ) as $id ) {
			Records::update(
				$id,
				array(
					'alt' => array(
						'status' => Alt_Text::WRITTEN,
						'since'  => time(),
					),
				)
			);
			update_post_meta( $id, Alt_Text::META, 'Written.' );
		}

		Bulk::feed();

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
	}

	public function test_a_run_holds_when_the_days_alt_texts_are_spent_and_the_image_waits_for_the_day_not_failing(): void {
		$id = $this->attachment();

		$this->api->on(
			'POST',
			'site/alt-text',
			429,
			array(
				'message' => 'Spent.',
				'code'    => 'daily_limit_reached',
			),
			array( 'Retry-After' => '3600' )
		);

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );
		Alt_Text::send( $id );

		$run = (array) Bulk::run();

		$this->assertGreaterThan( time() + 3500, $run['hold'] );
		$this->assertSame( Alt_Text::QUEUED, Records::get( $id )['alt']['status'] );
		$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
		$this->assertNull( Pause::until() );

		/* The wait starts when the hold ends, so it never reads as a text that didn't come. */
		$this->assertGreaterThanOrEqual( $run['hold'], Records::get( $id )['alt']['since'] );
		$this->assertSame( Alt_Text::QUEUED, Alt_Text::status( Records::get( $id )['alt'] ) );
		$this->assertSame( 'alt_text', Bulk_State::build()['run']['action'] );
		$this->assertNotNull( Bulk_State::build()['run']['held'] );
	}

	public function test_a_run_holds_for_a_key_the_service_turned_away_rather_than_forgetting_the_library(): void {
		$id = $this->attachment();

		$this->api->on(
			'POST',
			'site/alt-text',
			409,
			array(
				'message' => 'Moved.',
				'code'    => 'site_moved',
			)
		);

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );
		Alt_Text::send( $id );

		$run = (array) Bulk::run();

		$this->assertSame( Alt_Text::CONNECTION, $run['hold_reason'] );
		$this->assertGreaterThan( time(), $run['hold'] );
		$this->assertSame( Alt_Text::QUEUED, Records::get( $id )['alt']['status'] );
		$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
	}

	public function test_a_key_the_service_stopped_holds_the_run_and_forgets_no_image(): void {
		$one = $this->attachment();
		$two = $this->attachment();

		$this->api->on(
			'POST',
			'site/alt-text',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );

		Alt_Text::send( $one );

		/* The stored key went with that refusal, and the site's record with it. */
		$this->assertFalse( Plugin::connected() );

		Alt_Text::send( $two );

		foreach ( array( $one, $two ) as $id ) {
			$this->assertSame( Alt_Text::QUEUED, Records::get( $id )['alt']['status'] );
			$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
		}

		$this->assertSame( Alt_Text::CONNECTION, Bulk::run()['hold_reason'] );

		/* A Pause and a Resume leave what the hold is for. */
		Bulk::pause();
		Bulk::resume();
		$this->assertSame( Alt_Text::CONNECTION, Bulk::run()['hold_reason'] );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_in_wp_config_the_service_stopped_holds_the_run_rather_than_walking_the_library(): void {
		define( 'IMINIFY_API_KEY', self::KEY );

		$one = $this->attachment();
		$two = $this->attachment();

		$stopped = array(
			'message' => 'Unauthenticated.',
			'code'    => 'unauthenticated',
		);

		$this->api->on( 'POST', 'site/alt-text', 401, $stopped );
		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );
		$this->api->on( 'GET', 'site', 401, $stopped );

		Alt_Text::send( $one );
		Alt_Text::send( $two );

		$this->assertTrue( Plugin::connected() );
		$this->assertSame( Alt_Text::QUEUED, Records::get( $one )['alt']['status'] );
		$this->assertSame( Alt_Text::QUEUED, Records::get( $two )['alt']['status'] );
		$this->assertSame( Alt_Text::CONNECTION, Bulk::run()['hold_reason'] );
	}

	public function test_a_full_window_of_lost_sends_is_picked_up_by_the_run_rather_than_stalling_it(): void {
		$ids = array();

		foreach ( range( 1, Bulk::ALT_WINDOW ) as $image ) {
			$ids[] = $this->attachment();
		}

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );

		foreach ( $ids as $id ) {
			$alt          = Records::get( $id )['alt'];
			$alt['since'] = time() - Bulk::STALL_SECONDS - 1;
			Records::update( $id, array( 'alt' => $alt ) );
		}

		Bulk::feed();

		foreach ( $ids as $id ) {
			$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $id ) ) );
		}
	}

	public function test_a_lost_send_is_picked_up_and_a_wait_that_outlived_its_checks_fails_so_the_page_stops_stepping(): void {
		$lost = $this->attachment();
		$dead = $this->attachment();

		Alt_Text::ask( $lost );
		Alt_Text::ask( $dead );
		as_unschedule_all_actions( Alt_Text::SEND_HOOK );

		$alt          = Records::get( $lost )['alt'];
		$alt['since'] = time() - Bulk::STALL_SECONDS - 1;
		Records::update( $lost, array( 'alt' => $alt ) );

		$alt          = Records::get( $dead )['alt'];
		$alt['since'] = time() - Alt_Text::MAX_WAIT - 2 * HOUR_IN_SECONDS;
		Records::update( $dead, array( 'alt' => $alt ) );

		update_option(
			Bulk::OPTION,
			array(
				'action'  => Bulk::OPTIMIZE,
				'scope'   => Bulk::LIBRARY,
				'mode'    => Bulk::PAGE,
				'status'  => Bulk::DONE,
				'total'   => 1,
				'started' => time(),
				'updated' => time(),
			),
			false
		);

		/* Runs the picked-up send too, against the stand-in service. */
		Bulk::step();

		$this->assertSame( Alt_Text::WAITING, Records::get( $lost )['alt']['status'] );
		$this->assertSame( Alt_Text::FAILED, Records::get( $dead )['alt']['status'] );
		$this->assertCount( 1, $this->api->calls( 'POST', 'site/alt-text' ) );
	}

	public function test_stopping_takes_back_what_hasnt_gone_and_leaves_what_the_service_has(): void {
		$sent  = $this->attachment();
		$still = $this->attachment();

		Bulk::start( Bulk::ALT_TEXT, Bulk::BACKGROUND );
		Alt_Text::send( $sent );

		Bulk::stop();

		$this->assertSame( Alt_Text::WAITING, Records::get( $sent )['alt']['status'] );
		$this->assertNull( Records::get( $still )['alt'] );
		$this->assertFalse( Optimizer::pending( Alt_Text::SEND_HOOK, array( $still ) ) );
		$this->assertTrue( Optimizer::pending( Alt_Text::REQUEST_HOOK, array( $sent, self::REQUEST ) ) );
	}

	/**
	 * Runs that have ended, which the page still steps for a text on its way.
	 *
	 * @return array<string, array{string}>
	 */
	public function ended_runs(): array {
		return array(
			'an optimize' => array( Bulk::OPTIMIZE ),
			'a restore'   => array( Bulk::RESTORE ),
		);
	}

	/**
	 * @dataProvider ended_runs
	 *
	 * @param string $action The run that ended.
	 */
	public function test_the_page_keeps_stepping_for_a_text_on_its_way_after_a_run_for_another_action( string $action ): void {
		$id = $this->attachment();

		Alt_Text::ask( $id );
		Alt_Text::send( $id );

		$this->assertSame( 1, Queue::summary()['alt_texts'] );

		$this->text_says(
			array(
				'status' => 'written',
				'text'   => 'Une table.',
			)
		);
		as_unschedule_all_actions( Alt_Text::REQUEST_HOOK );
		as_schedule_single_action( time() - 1, Alt_Text::REQUEST_HOOK, array( $id, self::REQUEST ), Optimizer::GROUP );
		update_option(
			Bulk::OPTION,
			array(
				'action'  => $action,
				'scope'   => Bulk::LIBRARY,
				'mode'    => Bulk::PAGE,
				'status'  => Bulk::DONE,
				'total'   => 1,
				'started' => time(),
				'updated' => time(),
			),
			false
		);

		Bulk::step();

		$this->assertSame( 'Une table.', get_post_meta( $id, Alt_Text::META, true ) );
		$this->assertSame( 0, Queue::summary()['alt_texts'] );
	}

	public function test_turning_the_plugin_off_and_on_picks_up_a_copy_still_to_go_and_a_text_on_its_way(): void {
		$queued  = $this->attachment();
		$waiting = $this->attachment();

		Alt_Text::ask( $queued );
		Alt_Text::ask( $waiting );
		Alt_Text::send( $waiting );

		Lifecycle::activate();
		Lifecycle::deactivate();

		$this->assertFalse( Optimizer::pending( Alt_Text::SEND_HOOK, array( $queued ) ) );
		$this->assertFalse( Optimizer::pending( Alt_Text::REQUEST_HOOK, array( $waiting, self::REQUEST ) ) );

		Lifecycle::resume();

		$this->assertTrue( Optimizer::pending( Alt_Text::SEND_HOOK, array( $queued ) ) );
		$this->assertTrue( Optimizer::pending( Alt_Text::REQUEST_HOOK, array( $waiting, self::REQUEST ) ) );
	}

	public function test_the_panel_offers_the_press_only_where_it_can_go_and_says_where_a_text_stands(): void {
		$id = $this->attachment();

		$this->assertStringContainsString( 'data-iminify-action="alt-text"', Media_View::panel( $id ) );

		Alt_Text::ask( $id );

		$panel = Media_View::panel( $id );

		$this->assertStringNotContainsString( 'data-iminify-action="alt-text"', $panel );
		$this->assertStringContainsString( __( 'Alt text: being written.', 'iminify-image-optimizer' ), $panel );
		$this->assertStringContainsString( 'data-iminify-busy="1"', $panel );

		Records::update( $id, array( 'alt' => null ) );
		update_post_meta( $id, Alt_Text::META, 'Written by hand' );
		$this->assertStringNotContainsString( 'data-iminify-action="alt-text"', Media_View::panel( $id ) );

		delete_post_meta( $id, Alt_Text::META );
		$this->writes_alt_text( false );
		$this->assertStringNotContainsString( 'data-iminify-action="alt-text"', Media_View::panel( $id ) );
	}

	public function test_the_route_is_for_someone_who_can_change_the_image_and_answers_its_markup(): void {
		rest_get_server();

		$id      = $this->attachment();
		$request = new WP_REST_Request( 'POST', '/iminify/v1/media/' . $id . '/alt-text' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( rest_authorization_required_code(), rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$answer = rest_do_request( $request );

		$this->assertSame( 200, $answer->get_status() );
		$this->assertStringContainsString( __( 'Alt text: being written.', 'iminify-image-optimizer' ), $answer->get_data()['panel'] );
		$this->assertSame( '', $answer->get_data()['alt'] );

		/* The panel's poll carries the text, for the edit screen's own alt field. */
		update_post_meta( $id, Alt_Text::META, 'Une table.' );
		$poll = new WP_REST_Request( 'GET', '/iminify/v1/media' );
		$poll->set_param( 'ids', array( $id ) );
		$this->assertSame( 'Une table.', rest_do_request( $poll )->get_data()['items'][0]['alt'] );
	}

	/**
	 * The site's record on the service, with its alt text setting as given.
	 *
	 * @param bool               $on     Whether the site writes alt text.
	 * @param array<string, int> $limits The small copy's bounds.
	 */
	protected function writes_alt_text( bool $on, array $limits = array() ): void {
		$site = Api_Stub::fixture( 'site' );

		$site['data']['settings']['alt_text']           = $on;
		$site['data']['settings']['alt_text_language']  = 'fr';
		$site['data']['settings']['optimize_on_upload'] = false;
		$site['data']['alt_text_source']                = array_merge( $site['data']['alt_text_source'], $limits );

		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();
	}

	/**
	 * Answers a small copy with a record to poll.
	 */
	protected function service_takes_it(): void {
		$this->api->on(
			'POST',
			'site/alt-text',
			202,
			array(
				'data' => array(
					'id'       => self::REQUEST,
					'status'   => 'pending',
					'language' => 'fr',
					'text'     => null,
				),
			)
		);
	}

	/**
	 * Answers the record of a small copy.
	 *
	 * @param array<string, mixed> $answer The record.
	 */
	protected function text_says( array $answer ): void {
		$this->api->on( 'GET', 'site/alt-text/' . self::REQUEST, 200, array( 'data' => $answer ) );
	}

	/**
	 * The attachments whose small copy waits to go out.
	 *
	 * @return list<int>
	 */
	protected function queued(): array {
		return Records::waiting_for_alt_text( 100 );
	}

	/**
	 * A 640 by 480 JPEG in the media library, with its thumbnail and medium size, optimized before the site wrote alt text.
	 */
	protected function attachment(): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		Optimizer::cancel( $id );
		Records::update( $id, array( 'status' => Records::OPTIMIZED ) );

		return $id;
	}

	/**
	 * An attachment naming the same file as another, as WPML and Polylang make a translation.
	 *
	 * @param int $holder The attachment it translates.
	 */
	protected function translation_of( int $holder ): int {
		$translation = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);

		add_post_meta( $translation, '_wp_attachment_metadata', wp_get_attachment_metadata( $holder, true ) );
		add_post_meta( $translation, '_wp_attached_file', (string) get_post_meta( $holder, '_wp_attached_file', true ) );

		return $translation;
	}
}
