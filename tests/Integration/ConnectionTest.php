<?php
/**
 * Connecting the site, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Sealer;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers the pairing, each answer a claim can get, the background claim, a
 * pasted key, disconnecting and a copy of the site at another address.
 */
class ConnectionTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
	}

	public function tear_down(): void {
		$this->api->stop();
		( new Connection() )->cancel();
		parent::tear_down();
	}

	public function test_connect_opens_a_pairing_keeping_its_secret_sealed_and_claims_in_the_background(): void {
		global $wpdb;

		update_option( 'blogname', 'Tom &amp; Jerry\'s' );
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );

		$pairing  = ( new Connection() )->start();
		$stored   = get_option( Connection::PAIRING_OPTION );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Connection::PAIRING_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the row's autoload flag, which no API exposes.

		$this->assertIsArray( $pairing );
		$this->assertSame( 'https://www.iminify.com/connect/wordpress/0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00', $pairing['connect_url'] );
		$this->assertSame( strtotime( '2099-11-04T10:15:00+00:00' ), $pairing['expires_at'] );
		$this->assertSame(
			array(
				'url'  => home_url(),
				'name' => 'Tom & Jerry\'s',
			),
			json_decode( $this->api->last()['args']['body'], true )
		);
		$this->assertStringNotContainsString( 'kC4p0dQm9yZ2Vt3sL8aX1rN6wB5hJ7eF0gT2uY4iO6q', (string) wp_json_encode( $stored ) );
		$this->assertSame( 'kC4p0dQm9yZ2Vt3sL8aX1rN6wB5hJ7eF0gT2uY4iO6q', Sealer::open( $stored['secret'] ) );
		$this->assertSame( 'off', $autoload );
		$this->assertNotFalse( as_next_scheduled_action( Connection::CLAIM_HOOK, array( '0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00' ), Connection::GROUP ) );
	}

	public function test_connect_refuses_a_page_outside_the_service(): void {
		$answer                        = Api_Stub::fixture( 'connect-start' );
		$answer['data']['connect_url'] = 'https://phish.example/connect';
		$this->api->on( 'POST', 'connect/start', 201, $answer );

		$pairing = ( new Connection() )->start();

		$this->assertInstanceOf( WP_Error::class, $pairing );
		$this->assertSame( 'unexpected_response', $pairing->get_error_code() );
		$this->assertFalse( get_option( Connection::PAIRING_OPTION ) );
	}

	public function test_a_start_the_service_refuses_keeps_nothing(): void {
		$this->api->on(
			'POST',
			'connect/start',
			429,
			array(
				'message' => 'Too many connections started from here in the last hour. Try again later.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '600' )
		);

		$pairing = ( new Connection() )->start();

		$this->assertInstanceOf( WP_Error::class, $pairing );
		$this->assertSame( 'rate_limited', $pairing->get_error_code() );
		$this->assertNull( ( new Connection() )->pairing() );
	}

	public function test_a_claim_waits_while_the_pairing_waits_for_its_approval(): void {
		$this->start();
		$this->api->on(
			'POST',
			'connect/claim',
			409,
			array(
				'message' => 'Nothing to collect yet.',
				'code'    => 'pairing_pending',
			)
		);

		$this->assertSame( 'pending', ( new Connection() )->claim()['status'] );
		$this->assertSame(
			array(
				'pairing' => '0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00',
				'secret'  => 'kC4p0dQm9yZ2Vt3sL8aX1rN6wB5hJ7eF0gT2uY4iO6q',
			),
			json_decode( $this->api->last()['args']['body'], true )
		);
		$this->assertArrayNotHasKey( 'Authorization', $this->api->last()['args']['headers'] );
		$this->assertNotNull( ( new Connection() )->pairing() );
	}

	public function test_an_approved_claim_keeps_the_key_and_ends_the_pairing(): void {
		$this->start();
		update_option( Connection::REVOKED_OPTION, time(), false );
		$this->api->on( 'POST', 'connect/claim', 200, Api_Stub::fixture( 'connect-claim' ) );

		$this->assertSame( array( 'status' => 'connected' ), ( new Connection() )->claim() );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
		$this->assertFalse( get_option( Connection::PAIRING_OPTION ) );
		$this->assertFalse( Connection::was_revoked() );
		$this->assertFalse( as_next_scheduled_action( Connection::CLAIM_HOOK ) );
	}

	public function test_a_claim_after_another_kept_the_key_reports_connected_without_asking_again(): void {
		( new Key_Store() )->save( self::KEY );

		$this->assertSame( array( 'status' => 'connected' ), ( new Connection() )->claim() );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_a_closed_pairing_ends_it_unless_the_other_claim_kept_the_key(): void {
		$this->start();
		$this->closes();

		$this->assertSame( 'closed', ( new Connection() )->claim()['status'] );
		$this->assertNull( ( new Connection() )->pairing() );

		$this->start();
		( new Key_Store() )->save( self::KEY );

		$this->assertSame( 'connected', ( new Connection() )->claim()['status'] );
	}

	public function test_an_expired_pairing_is_dropped_without_asking(): void {
		$this->start();
		$pairing               = get_option( Connection::PAIRING_OPTION );
		$pairing['expires_at'] = time() - 1;
		update_option( Connection::PAIRING_OPTION, $pairing, false );
		$asked = count( $this->api->requests );

		$this->assertSame( array( 'status' => 'expired' ), ( new Connection() )->claim() );
		$this->assertCount( $asked, $this->api->requests );
		$this->assertFalse( get_option( Connection::PAIRING_OPTION ) );
	}

	public function test_a_busy_or_unreachable_service_keeps_the_pairing_waiting_with_its_wait(): void {
		$this->start();
		$this->api->on(
			'POST',
			'connect/claim',
			429,
			array(
				'message' => 'Too many requests for this connection.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '42' )
		);

		$this->assertSame(
			array(
				'status'      => 'pending',
				'retry_after' => 42,
			),
			( new Connection() )->claim()
		);

		$this->api->fail( 'POST', 'connect/claim' );

		$this->assertSame( 'pending', ( new Connection() )->claim()['status'] );
		$this->assertNotNull( ( new Connection() )->pairing() );
	}

	public function test_an_account_the_service_refuses_ends_the_pairing_with_its_reason(): void {
		$this->start();
		$this->api->on(
			'POST',
			'connect/claim',
			403,
			array(
				'message' => 'This account is banned.',
				'code'    => 'account_banned',
			)
		);

		$result = ( new Connection() )->claim();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'account_banned', $result['error']->get_error_code() );
		$this->assertNull( ( new Connection() )->pairing() );
	}

	public function test_a_claim_already_running_elsewhere_waits_its_turn(): void {
		global $wpdb;

		$this->start();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- holds the lock as another request would.
			$wpdb->options,
			array(
				'option_name'  => Connection::LOCK_OPTION,
				'option_value' => (string) ( time() + 20 ),
				'autoload'     => 'off',
			)
		);
		$asked = count( $this->api->requests );

		$this->assertSame( array( 'status' => 'pending' ), ( new Connection() )->claim() );
		$this->assertCount( $asked, $this->api->requests );

		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 1 ) ), array( 'option_name' => Connection::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock left by a request that died.
		$this->api->on( 'POST', 'connect/claim', 200, Api_Stub::fixture( 'connect-claim' ) );

		$this->assertSame( 'connected', ( new Connection() )->claim()['status'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Connection::LOCK_OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock row is never read through the options API.
	}

	public function test_the_background_claim_asks_again_until_the_key_comes(): void {
		$this->start();
		as_unschedule_all_actions( Connection::CLAIM_HOOK );
		$this->api->on(
			'POST',
			'connect/claim',
			409,
			array(
				'message' => 'Nothing to collect yet.',
				'code'    => 'pairing_pending',
			)
		);

		( new Connection() )->run_background_claim( '0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00' );

		$next = as_next_scheduled_action( Connection::CLAIM_HOOK, array( '0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00' ), Connection::GROUP );
		$this->assertIsInt( $next );
		$this->assertGreaterThanOrEqual( time() + Connection::BACKGROUND_SECONDS - 1, $next );

		$this->api->on( 'POST', 'connect/claim', 200, Api_Stub::fixture( 'connect-claim' ) );
		( new Connection() )->run_background_claim( '0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00' );

		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
		$this->assertFalse( as_next_scheduled_action( Connection::CLAIM_HOOK ) );
	}

	public function test_a_background_claim_for_an_older_pairing_does_nothing(): void {
		$this->start();
		$asked = count( $this->api->requests );

		( new Connection() )->run_background_claim( 'an-older-pairing' );

		$this->assertCount( $asked, $this->api->requests );
	}

	public function test_a_pasted_key_connects_once_the_service_confirms_it(): void {
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		update_option( Connection::REVOKED_OPTION, time(), false );

		$site = ( new Connection() )->connect_with_key( '  ' . self::KEY . "\n" );

		$this->assertIsArray( $site );
		$this->assertSame( 'Bearer ' . self::KEY, $this->api->last()['args']['headers']['Authorization'] );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
		$this->assertSame( 'smart', Site_Record::cached()['settings']['level'] );
		$this->assertFalse( Connection::was_revoked() );
	}

	public function test_a_pasted_key_the_service_refuses_is_never_kept(): void {
		foreach ( array(
			'unauthenticated'   => 401,
			'site_key_required' => 403,
		) as $code => $status ) {
			$this->api->on(
				'GET',
				'site',
				$status,
				array(
					'message' => 'Refused.',
					'code'    => $code,
				)
			);

			$result = ( new Connection() )->connect_with_key( self::KEY );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertFalse( get_option( Key_Store::OPTION ) );
		}

		$this->assertSame( 'key_missing', ( new Connection() )->connect_with_key( '   ' )->get_error_code() );
	}

	public function test_a_pasted_key_made_for_another_address_is_kept_for_the_move_or_a_new_connection(): void {
		update_option( Connection::REVOKED_OPTION, time(), false );
		$this->api->on(
			'GET',
			'site',
			409,
			array(
				'message' => 'This key belongs to the site at https://shop.example.com.',
				'code'    => 'site_moved',
			)
		);

		$result = ( new Connection() )->connect_with_key( self::KEY );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'site_moved', $result->get_error_code() );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
		$this->assertFalse( Connection::was_revoked() );
	}

	public function test_a_claim_ending_after_connect_was_pressed_again_leaves_the_newer_pairing(): void {
		$this->start();
		$this->closes();

		$newer = array(
			'id'          => 'the-newer-pairing',
			'secret'      => (string) Sealer::seal( 'newer-secret' ),
			'connect_url' => 'https://www.iminify.com/connect/wordpress/the-newer-pairing',
			'expires_at'  => time() + 600,
		);
		$press = static function ( $preempt, array $args, string $url ) use ( $newer ) {
			if ( false !== strpos( $url, '/connect/claim' ) ) {
				update_option( Connection::PAIRING_OPTION, $newer, false );
			}

			return $preempt;
		};
		add_filter( 'pre_http_request', $press, 5, 3 );

		$result = ( new Connection() )->claim();

		remove_filter( 'pre_http_request', $press, 5 );

		$this->assertSame( 'closed', $result['status'] );
		$this->assertSame( 'the-newer-pairing', ( new Connection() )->pairing()['id'] ?? null );
	}

	public function test_disconnect_stops_the_key_on_the_service_and_forgets_it_here(): void {
		( new Key_Store() )->save( self::KEY );
		Site_Record::remember( Api_Stub::fixture( 'site' )['data'] );
		$this->api->on( 'DELETE', 'site', 204, '' );

		$this->assertSame( array( 'revoked' => true ), ( new Connection() )->disconnect() );
		$this->assertSame( 'Bearer ' . self::KEY, $this->api->last()['args']['headers']['Authorization'] );
		$this->assertNull( ( new Key_Store() )->get() );
		$this->assertNull( Site_Record::cached() );
	}

	public function test_disconnect_forgets_the_key_whatever_the_service_answers(): void {
		$answers = array(
			array( 409, 'site_moved', false ),
			array( 401, 'unauthenticated', true ),
			array( 503, 'http_503', false ),
		);

		foreach ( $answers as $answer ) {
			( new Key_Store() )->save( self::KEY );
			$this->api->on(
				'DELETE',
				'site',
				$answer[0],
				array(
					'message' => 'Refused.',
					'code'    => $answer[1],
				)
			);

			$this->assertSame( array( 'revoked' => $answer[2] ), ( new Connection() )->disconnect(), $answer[1] );
			$this->assertNull( ( new Key_Store() )->get() );
		}
	}

	public function test_moving_the_connection_here_keeps_the_record_it_answers(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'POST', 'site/move', 200, Api_Stub::fixture( 'site' ) );

		$this->assertIsArray( ( new Connection() )->move() );
		$this->assertSame( 'POST', $this->api->last()['method'] );
		$this->assertIsArray( Site_Record::cached() );
	}

	public function test_a_copy_connects_as_its_own_site_without_stopping_the_others_key(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );

		$this->assertIsArray( ( new Connection() )->connect_copy() );
		$this->assertSame( array( 'connect/start' ), array_column( $this->api->requests, 'path' ) );
		$this->assertNull( ( new Key_Store() )->get() );
		$this->assertNotNull( ( new Connection() )->pairing() );
	}

	public function test_a_key_the_service_stopped_disconnects_the_site_and_says_why(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on(
			'GET',
			'site',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		$this->assertInstanceOf( WP_Error::class, Site_Record::fetch() );
		$this->assertNull( ( new Key_Store() )->get() );
		$this->assertTrue( Connection::was_revoked() );

		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		$this->assertIsArray( Site_Record::fetch() );
		$this->assertFalse( Connection::was_revoked() );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_wp_config_key_the_service_stopped_stays_and_never_reads_as_disconnected_by_it(): void {
		define( 'IMINIFY_API_KEY', self::KEY );
		$this->api->on(
			'GET',
			'site',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		$this->assertInstanceOf( WP_Error::class, Site_Record::fetch() );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
		$this->assertFalse( Connection::was_revoked() );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_in_wp_config_is_never_replaced_or_dropped_from_the_page(): void {
		define( 'IMINIFY_API_KEY', self::KEY );

		$connection = new Connection();

		foreach ( array( $connection->start(), $connection->connect_with_key( 'another' ), $connection->disconnect(), $connection->connect_copy() ) as $refusal ) {
			$this->assertInstanceOf( WP_Error::class, $refusal );
			$this->assertSame( 'key_in_config', $refusal->get_error_code() );
		}

		$this->assertSame( array(), $this->api->requests );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
	}

	/**
	 * Opens the fixture's pairing.
	 */
	protected function start(): void {
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );
		$this->assertIsArray( ( new Connection() )->start() );
	}

	/**
	 * Makes the service answer that the pairing can no longer give a key.
	 */
	protected function closes(): void {
		$this->api->on(
			'POST',
			'connect/claim',
			410,
			array(
				'message' => 'This connection can no longer be used.',
				'code'    => 'pairing_closed',
			)
		);
	}
}
