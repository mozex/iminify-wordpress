<?php
/**
 * What LiteSpeed Cache's image optimization left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeed Cache (read from 7.9.3) usually stays active for its cache. Its image
 * optimization acts when its "Auto Request Cron" sends the library to QUIC.cloud,
 * which needs the site's QUIC.cloud account, or while images it already sent are
 * on their way back (rows of `{prefix}litespeed_img_optming`): each that comes back
 * replaces its file. Its records are read in a later step.
 */
class Litespeed extends Reader {

	public function slug(): string {
		return 'litespeed';
	}

	public function name(): string {
		return 'LiteSpeed Cache';
	}

	public function switch_off(): string {
		return __( 'Turn off "Auto Request Cron" under LiteSpeed Cache, Image Optimization, and let the images it already sent come back, or deactivate LiteSpeed Cache.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'litespeed-cache/litespeed-cache.php' );
	}

	protected function loaded(): bool {
		return defined( 'LSCWP_V' );
	}

	/**
	 * Its "Auto Request Cron" on with a QUIC.cloud account to send with, or images
	 * still on their way back (W134), read only while LiteSpeed is loaded, since its
	 * settings filter answers its own name otherwise.
	 */
	protected function optimizes_uploads(): bool {
		global $wpdb;

		if ( ! $this->loaded() ) {
			return false;
		}

		$auto    = apply_filters( 'litespeed_conf', 'img_optm-auto' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own documented filter, which answers its setting after every override.
		$summary = get_option( 'litespeed.cloud._summary' );
		$account = is_array( $summary ) && ! empty( $summary['sk_b64'] ) && ! empty( $summary['qc_activated'] );

		if ( 'img_optm-auto' !== $auto && ! empty( $auto ) && $account ) {
			return true;
		}

		if ( ! self::table_exists( $wpdb->prefix . 'litespeed_img_optming' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request at another plugin's table.
		return null !== $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}litespeed_img_optming LIMIT 1" );
	}
}
