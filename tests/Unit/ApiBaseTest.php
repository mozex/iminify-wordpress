<?php
/**
 * Which address the client calls.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Iminify\WordPress\Api\Client;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the constant that points the plugin at another copy of the service. Each test
 * runs alone, since a constant can't be undefined for the tests after it.
 */
class ApiBaseTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $value ): string {
				return rtrim( $value, '/' );
			}
		);
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	public function test_without_the_constant_the_plugin_calls_iminify(): void {
		$this->assertSame( 'https://www.iminify.com/api/v1', Client::base() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_https_address_points_the_plugin_at_another_copy(): void {
		define( 'IMINIFY_API_BASE', 'https://api.example.test/api/v1/' );

		$this->assertSame( 'https://api.example.test/api/v1', Client::base() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_address_without_https_is_ignored_so_the_key_never_travels_in_clear(): void {
		define( 'IMINIFY_API_BASE', 'http://api.example.test/api/v1' );

		$this->assertSame( 'https://www.iminify.com/api/v1', Client::base() );
	}
}
