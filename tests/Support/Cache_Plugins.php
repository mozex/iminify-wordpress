<?php
/**
 * Stand-ins for the purge calls of the cache plugins the plugin empties after a
 * bulk run, each recording that it was called.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- each stands in for another plugin's own function, under its name.

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	/**
	 * WP Rocket's purge of the whole site.
	 */
	function rocket_clean_domain(): bool {
		$GLOBALS['iminify_test_purged'][] = 'wp-rocket';

		return true;
	}
}

if ( ! function_exists( 'w3tc_flush_all' ) ) {
	/**
	 * W3 Total Cache's purge of every cache.
	 */
	function w3tc_flush_all(): void {
		$GLOBALS['iminify_test_purged'][] = 'w3tc';
	}
}
