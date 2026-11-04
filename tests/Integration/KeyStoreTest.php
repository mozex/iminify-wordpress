<?php
/**
 * The site key's storage on a real WordPress database.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Plugin;
use WP_UnitTestCase;

/**
 * Covers sealing, reading back, the constant's precedence and what reads as disconnected.
 */
class KeyStoreTest extends WP_UnitTestCase {

	const KEY = '12|abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	public function test_a_saved_key_reads_back_but_is_never_stored_readable_or_autoloaded(): void {
		global $wpdb;

		$store = new Key_Store();

		$this->assertTrue( $store->save( self::KEY ) );

		$stored   = get_option( Key_Store::OPTION );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Key_Store::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the row's autoload flag, which no API exposes.

		$this->assertSame( self::KEY, $store->get() );
		$this->assertIsString( $stored );
		$this->assertStringStartsWith( Key_Store::FORMAT, $stored );
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz', $stored );
		$this->assertSame( 'off', $autoload );
		$this->assertInstanceOf( \Iminify\WordPress\Api\Client::class, Plugin::client() );
	}

	public function test_a_key_sealed_under_other_salts_reads_as_disconnected(): void {
		$store = new Key_Store();
		$store->save( self::KEY );

		add_filter(
			'salt',
			static function ( string $salt, string $scheme ): string {
				return 'auth' === $scheme ? 'the salts were changed' : $salt;
			},
			10,
			2
		);

		$this->assertNull( $store->get() );
		$this->assertNull( Plugin::client() );
	}

	public function test_an_altered_or_foreign_value_reads_as_disconnected(): void {
		$store = new Key_Store();
		$store->save( self::KEY );

		$stored = (string) get_option( Key_Store::OPTION );
		$tamper = substr( $stored, 0, -4 ) . ( 'AAAA' === substr( $stored, -4 ) ? 'BBBB' : 'AAAA' );

		update_option( Key_Store::OPTION, $tamper, false );
		$this->assertNull( $store->get() );

		update_option( Key_Store::OPTION, self::KEY, false );
		$this->assertNull( $store->get() );

		update_option( Key_Store::OPTION, Key_Store::FORMAT . 'not base64 at all!', false );
		$this->assertNull( $store->get() );
	}

	public function test_forgetting_removes_the_stored_key(): void {
		$store = new Key_Store();
		$store->save( self::KEY );
		$store->forget();

		$this->assertFalse( get_option( Key_Store::OPTION ) );
		$this->assertNull( $store->get() );
		$this->assertNull( Plugin::client() );
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_wins_over_a_stored_key(): void {
		$store = new Key_Store();
		$store->save( self::KEY );

		define( 'IMINIFY_API_KEY', ' 13|from-wp-config ' );

		$this->assertTrue( $store->from_constant() );
		$this->assertSame( '13|from-wp-config', $store->get() );
	}
}
