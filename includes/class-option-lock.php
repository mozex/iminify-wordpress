<?php
/**
 * A lock held in an options row.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lock any request on the site can take, as an options row inserted in one
 * statement: `add_option()` overwrites a row another request just added. A lock
 * past its expiry is taken over, so a request that died holding it frees it.
 */
class Option_Lock {

	/**
	 * Takes the lock, or answers false while another request holds it.
	 *
	 * @param string $name    The lock's option name.
	 * @param int    $seconds How long it is held at most.
	 */
	public static function take( string $name, int $seconds ): bool {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the lock.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) ( $now + $seconds ) ) );

		if ( 1 === $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- takes over a lock past its expiry in one conditional update.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) ( $now + $seconds ), $name, $now ) );

		return 1 === $taken;
	}

	/**
	 * Frees the lock.
	 *
	 * @param string $name The lock's option name.
	 */
	public static function release( string $name ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock row is never read through the options API.
	}
}
