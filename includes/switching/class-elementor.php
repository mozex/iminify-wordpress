<?php
/**
 * What Image Optimizer by Elementor left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image Optimizer by Elementor (read from 1.7.7). Its default converts each upload
 * into a WebP that takes the attachment's place, the JPEG kept beside it; its
 * records are read in a later step (W135). While active with "Optimize new
 * uploads" on (the default) it acts on every upload.
 */
class Elementor extends Reader {

	public function slug(): string {
		return 'elementor';
	}

	public function name(): string {
		return 'Image Optimizer by Elementor';
	}

	public function switch_off(): string {
		return __( 'Deactivate Image Optimizer by Elementor, or turn off "Optimize new uploads" under Elementor, Image Optimization, Settings.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'image-optimization/image-optimization.php' );
	}

	protected function loaded(): bool {
		return defined( 'IMAGE_OPTIMIZATION_VERSION' ) || class_exists( 'ImageOptimization\Plugin', false );
	}

	/**
	 * "Optimize new uploads", on while its settings say nothing, once it is
	 * connected, without which it does nothing to an upload: its access token is
	 * the site's (`image_optimizer_access_token`) or a person's (the same key in
	 * user meta), or, connected the older way, in `image_optimizer_connect_data`.
	 */
	protected function optimizes_uploads(): bool {
		global $wpdb;

		if ( empty( get_option( 'image_optimizer_optimize_on_upload', true ) ) ) {
			return false;
		}

		if ( ! empty( get_option( 'image_optimizer_access_token' ) ) || ! empty( self::setting( 'image_optimizer_connect_data', 'access_token', '' ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed look a request, while it is active.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1", 'image_optimizer_access_token' ) );
	}
}
