<?php
/**
 * The settings page's REST routes, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers who may call the routes, the state each answers, that no answer
 * carries the key, and the settings both here and on the service.
 */
class RestControllerTest extends WP_UnitTestCase {

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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		( new Connection() )->cancel();
		parent::tear_down();
	}

	public function test_only_people_who_manage_options_reach_the_routes(): void {
		$routes = array(
			array( 'GET', '/state' ),
			array( 'POST', '/connect' ),
			array( 'DELETE', '/connect' ),
			array( 'POST', '/connect/claim' ),
			array( 'POST', '/key' ),
			array( 'DELETE', '/key' ),
			array( 'POST', '/move' ),
			array( 'POST', '/copy' ),
			array( 'POST', '/settings' ),
			array( 'POST', '/notice' ),
		);

		foreach ( array(
			'editor' => 403,
			''       => 401,
		) as $role => $status ) {
			wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

			foreach ( $routes as $route ) {
				$this->assertSame(
					$status,
					$this->call(
						$route[0],
						$route[1],
						array(
							'key'    => self::KEY,
							'notice' => 'connect',
						)
					)->get_status(),
					$role . ' ' . implode( ' ', $route )
				);
			}
		}

		$this->assertSame( array(), $this->api->requests );
	}

	public function test_a_site_that_isnt_connected_calls_nothing(): void {
		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'disconnected', $state['connection'] );
		$this->assertNull( $state['site'] );
		$this->assertFalse( $state['key_in_config'] );
		$this->assertSame( Local_Settings::defaults(), $state['local'] );
		$this->assertContains( 'Thumbnail (150 × 150)', array_column( $state['sizes'], 'label' ) );
		$this->assertContains( 'medium_large (768 wide)', array_column( $state['sizes'], 'label' ) );
		$this->assertStringStartsWith( 'https://www.iminify.com/settings/sites?', $state['links']['sites'] );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_a_connected_site_shows_its_plan_counts_and_settings_without_the_key(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		$response = $this->call( 'GET', '/state' );
		$state    = $response->get_data();

		$this->assertSame( 'connected', $state['connection'] );
		$this->assertSame( 'Free', $state['site']['plan']['label'] );
		$this->assertSame(
			array(
				'key'       => 'images',
				'label'     => 'Images today',
				'used'      => '3',
				'remaining' => '37',
			),
			$state['site']['usage'][0]
		);
		$this->assertSame( '1,476', $state['site']['usage'][1]['remaining'] );
		$this->assertCount( 2, $state['site']['usage'] );
		$this->assertSame( 'smart', $state['site']['settings']['level'] );
		$this->assertNull( $state['site']['message'] );
		$this->assertStringNotContainsString( 'Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r', (string) wp_json_encode( $state ) );
	}

	public function test_a_plan_without_daily_limits_shows_its_counts_as_unlimited(): void {
		( new Key_Store() )->save( self::KEY );
		$site                         = Api_Stub::fixture( 'site' );
		$site['data']['plan']         = 'max';
		$site['data']['plan_ends_at'] = '2026-12-04T09:00:00+00:00';
		$site['data']['usage']['images']['remaining'] = null;
		$site['data']['usage']['files']['remaining']  = null;
		$this->api->on( 'GET', 'site', 200, $site );

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'Max', $state['site']['plan']['label'] );
		$this->assertNotNull( $state['site']['plan']['ends_at'] );
		$this->assertNull( $state['site']['usage'][0]['remaining'] );
		$this->assertNull( $state['site']['usage'][1]['remaining'] );
	}

	public function test_a_spent_day_shows_the_message_and_when_the_next_batch_opens_in_the_sites_timezone(): void {
		( new Key_Store() )->save( self::KEY );
		update_option( 'timezone_string', 'Europe/Berlin' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$site                          = Api_Stub::fixture( 'site' );
		$site['data']['next_batch_at'] = '2026-11-05T09:12:00+00:00';
		$site['data']['message']       = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up. Start the 7-day trial of Pro for 300 images a day.',
		);
		$this->api->on( 'GET', 'site', 200, $site );

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( '2026-11-05 10:12', $state['site']['next_batch_at'] );
		$this->assertSame( $site['data']['message'], $state['site']['message'] );
	}

	public function test_a_copy_at_another_address_offers_its_two_ways_on(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on(
			'GET',
			'site',
			409,
			array(
				'message' => 'This key belongs to the site at https://shop.example.com.',
				'code'    => 'site_moved',
			)
		);

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'moved', $state['connection'] );
		$this->assertSame( 'site_moved', $state['error']['code'] );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
	}

	public function test_an_unreachable_service_says_so_and_keeps_the_key(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->fail( 'GET', 'site' );

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'error', $state['connection'] );
		$this->assertSame( 'unreachable', $state['error']['code'] );
		$this->assertSame( self::KEY, ( new Key_Store() )->get() );
	}

	public function test_a_key_the_service_stopped_reads_as_disconnected_by_it(): void {
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

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'disconnected', $state['connection'] );
		$this->assertTrue( $state['revoked'] );
		$this->assertNull( $state['error'] );
	}

	public function test_connect_answers_the_pairing_and_the_claim_its_status(): void {
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );
		$this->api->on(
			'POST',
			'connect/claim',
			409,
			array(
				'message' => 'Nothing to collect yet.',
				'code'    => 'pairing_pending',
			)
		);

		$state = $this->call( 'POST', '/connect' )->get_data();

		$this->assertSame( 'pairing', $state['connection'] );
		$this->assertSame( 'https://www.iminify.com/connect/wordpress/0d6f7a52-8c3e-4b1f-9a7d-2e5c4b3a1f00', $state['pairing']['connect_url'] );
		$this->assertStringNotContainsString( 'kC4p0dQm9yZ2Vt3sL8aX1rN6wB5hJ7eF0gT2uY4iO6q', (string) wp_json_encode( $state ) );
		$this->assertSame( 'pending', $this->call( 'POST', '/connect/claim' )->get_data()['claim']['status'] );

		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'connect/claim', 200, Api_Stub::fixture( 'connect-claim' ) );
		$state = $this->call( 'POST', '/connect/claim' )->get_data();

		$this->assertSame( 'connected', $state['claim']['status'] );
		$this->assertSame( 'connected', $state['connection'] );
		$this->assertStringNotContainsString( 'Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r', (string) wp_json_encode( $state ) );
	}

	public function test_cancel_drops_the_waiting_pairing(): void {
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );
		$this->call( 'POST', '/connect' );

		$this->assertSame( 'disconnected', $this->call( 'DELETE', '/connect' )->get_data()['connection'] );
		$this->assertNull( ( new Connection() )->pairing() );
	}

	public function test_a_pasted_key_is_refused_in_the_plugins_words(): void {
		$this->api->on(
			'GET',
			'site',
			403,
			array(
				'message' => 'Use a site key.',
				'code'    => 'site_key_required',
			)
		);

		$response = $this->call( 'POST', '/key', array( 'key' => 'plain-api-key' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'site_key_required', $response->get_data()['code'] );
		$this->assertSame( Messages::for_error( new WP_Error( 'site_key_required' ) ), $response->get_data()['message'] );
		$this->assertStringNotContainsString( 'Use a site key.', $response->get_data()['message'] );

		$this->api->on(
			'GET',
			'site',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		$this->assertSame( 422, $this->call( 'POST', '/key', array( 'key' => 'mistyped' ) )->get_status() );
		$this->assertFalse( Connection::was_revoked() );
	}

	public function test_a_pasted_key_made_for_another_address_offers_its_two_ways_on(): void {
		$this->api->on(
			'GET',
			'site',
			409,
			array(
				'message' => 'This key belongs to the site at https://shop.example.com.',
				'code'    => 'site_moved',
			)
		);

		$response = $this->call( 'POST', '/key', array( 'key' => self::KEY ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'moved', $response->get_data()['connection'] );
	}

	public function test_a_wait_the_service_asks_for_is_named(): void {
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

		$response = $this->call( 'POST', '/connect' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertStringContainsString( '10 min', $response->get_data()['message'] );
	}

	public function test_disconnect_answers_whether_the_key_was_stopped(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'DELETE', 'site', 204, '' );

		$state = $this->call( 'DELETE', '/key' )->get_data();

		$this->assertTrue( $state['key_stopped'] );
		$this->assertFalse( $state['revoked'] );
		$this->assertSame( 'disconnected', $state['connection'] );

		( new Key_Store() )->save( self::KEY );
		$this->api->fail( 'DELETE', 'site' );

		$state = $this->call( 'DELETE', '/key' )->get_data();

		$this->assertFalse( $state['key_stopped'] );
		$this->assertFalse( $state['revoked'] );
		$this->assertNull( ( new Key_Store() )->get() );
	}

	public function test_settings_send_only_the_sites_settings_to_the_service_and_keep_the_local_ones_here(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'PATCH', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		$response = $this->call(
			'POST',
			'/settings',
			array(
				'site'  => array(
					'level'      => 'ultra',
					'max_width'  => null,
					'sizes'      => array( 'thumbnail', '1536x1536' ),
					'name'       => 'Renamed',
					'unexpected' => true,
				),
				'local' => array(
					'backups'  => false,
					'delivery' => 'rewrite-everything',
					'other'    => 1,
				),
			)
		);
		$patch    = array_values(
			array_filter(
				$this->api->requests,
				static function ( array $request ): bool {
					return 'PATCH' === $request['method'];
				}
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'level'     => 'ultra',
				'max_width' => null,
				'sizes'     => array( 'thumbnail', '1536x1536' ),
			),
			json_decode( $patch[0]['args']['body'], true )
		);
		$this->assertSame(
			array(
				'backups'           => false,
				'delivery'          => 'picture',
				'uninstall_copies'  => false,
				'uninstall_backups' => false,
			),
			get_option( Local_Settings::OPTION )
		);
	}

	public function test_settings_saved_with_a_key_the_service_stopped_say_so_and_keep_the_local_ones(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on(
			'PATCH',
			'site',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		$state = $this->call(
			'POST',
			'/settings',
			array(
				'site'  => array( 'level' => 'ultra' ),
				'local' => array( 'delivery' => 'off' ),
			)
		)->get_data();

		$this->assertFalse( $state['saved'] );
		$this->assertTrue( $state['revoked'] );
		$this->assertSame( 'disconnected', $state['connection'] );
		$this->assertSame( 'off', Local_Settings::get()['delivery'] );
	}

	public function test_a_value_the_service_refuses_comes_back_with_its_message_by_field(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on(
			'PATCH',
			'site',
			422,
			array(
				'message' => 'The max width field must be at least 640.',
				'code'    => 'validation_failed',
				'errors'  => array( 'max_width' => array( 'The max width field must be at least 640.' ) ),
			)
		);

		$response = $this->call( 'POST', '/settings', array( 'site' => array( 'max_width' => 10 ) ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( array( 'max_width' => 'The max width field must be at least 640.' ), $response->get_data()['data']['errors'] );
	}

	public function test_local_settings_save_without_a_connection(): void {
		$response = $this->call(
			'POST',
			'/settings',
			array(
				'local' => array(
					'delivery'          => 'off',
					'uninstall_backups' => '1',
					'unknown'           => true,
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'off', Local_Settings::get()['delivery'] );
		$this->assertTrue( Local_Settings::get()['backups'] );
		$this->assertTrue( Local_Settings::get()['uninstall_backups'] );
		$this->assertFalse( Local_Settings::get()['uninstall_copies'] );
		$this->assertArrayNotHasKey( 'unknown', Local_Settings::get() );
		$this->assertSame( array(), $this->api->requests );
		$this->assertSame( 409, $this->call( 'POST', '/settings', array( 'site' => array( 'level' => 'ultra' ) ) )->get_status() );
	}

	public function test_dismissing_the_notice_holds_for_the_person_who_dismissed_it(): void {
		$this->assertSame( 204, $this->call( 'POST', '/notice', array( 'notice' => 'day_spent|2026-11-05T09:12:00+00:00' ) )->get_status() );
		$this->assertSame( array( 'day_spent|2026-11-05T09:12:00+00:00' ), get_user_meta( get_current_user_id(), Notice::META, true ) );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_configs_hidden_account_keeps_the_plan_counts_and_account_links_out_of_the_answer(): void {
		define( 'IMINIFY_API_KEY', self::KEY );
		define( 'IMINIFY_HIDE_ACCOUNT', true );
		$site                    = Api_Stub::fixture( 'site' );
		$site['data']['message'] = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up.',
		);
		$this->api->on( 'GET', 'site', 200, $site );

		$state = $this->call( 'GET', '/state' )->get_data();

		$this->assertSame( 'connected', $state['connection'] );
		$this->assertTrue( $state['key_in_config'] );
		$this->assertTrue( $state['hidden'] );
		$this->assertNull( $state['site']['plan'] );
		$this->assertNull( $state['site']['usage'] );
		$this->assertSame( 'day_spent', $state['site']['message']['code'] );
		$this->assertSame( Messages::for_site( array( 'code' => 'day_spent' ), true ), $state['site']['message'] );
		$this->assertStringNotContainsString( 'Your account\'s uploads for the day are used up.', $state['site']['message']['text'] );
		$this->assertSame( array( 'guide' ), array_keys( $state['links'] ) );
		$this->assertSame( 409, $this->call( 'DELETE', '/key' )->get_status() );
	}

	/**
	 * Sends a request to one of the plugin's routes.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The path under the namespace.
	 * @param array<string, mixed> $body   The JSON body.
	 */
	protected function call( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/iminify/v1' . $path );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
