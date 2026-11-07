<?php
/**
 * What Modern Image Formats left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern Image Formats (read from 2.7.1), the WordPress performance team's plugin,
 * makes AVIF or WebP versions of every upload while it is active, with no setting
 * that stops it, and without its fallback replaces the main file with them. Its
 * records are read in a later step (W135).
 */
class Modern_Formats extends Reader {

	public function slug(): string {
		return 'modern-formats';
	}

	public function name(): string {
		return 'Modern Image Formats';
	}

	public function switch_off(): string {
		return __( 'Deactivate Modern Image Formats: no setting of its own stops it.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'webp-uploads/load.php' );
	}

	protected function loaded(): bool {
		return defined( 'WEBP_UPLOADS_VERSION' );
	}

	/**
	 * Always, while it is active: no setting stops it.
	 */
	protected function optimizes_uploads(): bool {
		return true;
	}
}
