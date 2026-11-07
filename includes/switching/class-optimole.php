<?php
/**
 * What Optimole left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimole (read from 4.2.18) serves images through its own service and leaves
 * the files alone, unless "Optimole Cloud only" is chosen for storing them
 * (`offload_media`), which sends each upload away and deletes its local files
 * (W139). Its records are read in a later step.
 */
class Optimole extends Reader {

	public function slug(): string {
		return 'optimole';
	}

	public function name(): string {
		return 'Optimole';
	}

	public function switch_off(): string {
		return __( 'Deactivate Optimole, or choose "Optimole Cloud and your website" under Optimole, Settings, Image Storage.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'optimole-wp/optimole-wp.php' );
	}

	protected function loaded(): bool {
		return function_exists( 'optml' ) || defined( 'OPTML_VERSION' );
	}

	/**
	 * Its offload of new uploads, off by default, and not stopped by its own limit.
	 */
	protected function optimizes_uploads(): bool {
		return 'enabled' === self::setting( 'optml_settings', 'offload_media', 'disabled' ) && 'enabled' !== self::setting( 'optml_settings', 'offload_limit_reached', 'disabled' );
	}
}
