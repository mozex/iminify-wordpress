<?php
/**
 * The API client against the stub, through WordPress's real HTTP functions.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers what every call carries and how each kind of answer comes back.
 */
class ClientTest extends WP_UnitTestCase {

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
		parent::tear_down();
	}

	public function test_a_site_call_carries_the_key_the_site_and_the_version_over_verified_tls(): void {
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		$site    = ( new Client( '7|site-key' ) )->site();
		$request = $this->api->last();
		$headers = $request['args']['headers'];

		$this->assertIsArray( $site );
		$this->assertSame( 'smart', $site['settings']['level'] );
		$this->assertSame( 37, $site['usage']['images']['remaining'] );
		$this->assertSame( 'https://www.iminify.com/api/v1/site', $request['url'] );
		$this->assertSame( 'Bearer 7|site-key', $headers['Authorization'] );
		$this->assertSame( home_url(), $headers['Iminify-Site'] );
		$this->assertSame( 'wordpress/' . IMINIFY_VERSION, $headers['Iminify-Client'] );
		$this->assertSame( 'application/json', $headers['Accept'] );
		$this->assertTrue( $request['args']['sslverify'] );
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	public function test_the_bulk_estimate_sends_what_is_waiting_as_numbers(): void {
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		( new Client( '7|site-key' ) )->site(
			array(
				'attachments' => 120,
				'files'       => 960,
			)
		);

		$this->assertSame( 'https://www.iminify.com/api/v1/site?waiting=120&waiting_files=960', $this->api->last()['url'] );
	}

	public function test_settings_are_sent_as_json(): void {
		$this->api->on( 'PATCH', 'site', 200, Api_Stub::fixture( 'site' ) );

		( new Client( '7|site-key' ) )->update_site(
			array(
				'level' => 'high',
				'avif'  => true,
			)
		);

		$request = $this->api->last();

		$this->assertSame( 'PATCH', $request['method'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame(
			array(
				'level' => 'high',
				'avif'  => true,
			),
			json_decode( $request['args']['body'], true )
		);
	}

	public function test_an_api_refusal_comes_back_with_its_code_status_and_wait(): void {
		$this->api->on(
			'GET',
			'site',
			429,
			array(
				'message' => 'Too many requests.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '30' )
		);

		$error = ( new Client( '7|site-key' ) )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rate_limited', $error->get_error_code() );
		$this->assertSame( 'Too many requests.', $error->get_error_message() );
		$this->assertSame(
			array(
				'status'      => 429,
				'retry_after' => 30,
			),
			$error->get_error_data()
		);
	}

	public function test_an_answer_without_the_apis_error_shape_still_names_its_status(): void {
		$this->api->on( 'GET', 'site', 502, '<html>Bad gateway</html>' );

		$error = ( new Client( '7|site-key' ) )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'http_502', $error->get_error_code() );
		$this->assertStringContainsString( '502', $error->get_error_message() );
		$this->assertNull( $error->get_error_data()['retry_after'] );
	}

	public function test_a_redirect_is_never_followed(): void {
		$this->api->on( 'GET', 'site', 301, '', array( 'Location' => 'https://elsewhere.example/api/v1/site' ) );

		$error = ( new Client( '7|site-key' ) )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'http_301', $error->get_error_code() );
		$this->assertCount( 1, $this->api->requests );
	}

	public function test_a_lost_connection_reads_as_unreachable(): void {
		$this->api->fail( 'GET', 'site' );

		$error = ( new Client( '7|site-key' ) )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unreachable', $error->get_error_code() );
		$this->assertSame( 0, $error->get_error_data()['status'] );
	}

	public function test_a_success_without_data_is_not_read_as_a_site(): void {
		$this->api->on( 'GET', 'site', 200, array( 'unexpected' => true ) );

		$error = ( new Client( '7|site-key' ) )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unexpected_response', $error->get_error_code() );
	}

	public function test_a_call_that_needs_the_key_sends_nothing_without_one(): void {
		$error = ( new Client() )->site();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'not_connected', $error->get_error_code() );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_connecting_sends_no_key(): void {
		$this->api->on(
			'POST',
			'connect/start',
			201,
			array(
				'data' => array(
					'pairing'     => 'a1b2',
					'secret'      => 'pairing-secret',
					'connect_url' => 'https://www.iminify.com/connect/wordpress/a1b2',
					'expires_at'  => '2026-11-04T10:15:00+00:00',
				),
			)
		);

		$pairing = ( new Client( '7|site-key' ) )->start_pairing( home_url(), 'Test Blog' );
		$request = $this->api->last();

		$this->assertIsArray( $pairing );
		$this->assertSame( 'a1b2', $pairing['pairing'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$this->assertSame( home_url(), $request['args']['headers']['Iminify-Site'] );
		$this->assertSame(
			array(
				'url'  => home_url(),
				'name' => 'Test Blog',
			),
			json_decode( $request['args']['body'], true )
		);
	}
}
