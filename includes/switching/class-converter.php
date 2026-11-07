<?php
/**
 * What Converter for Media left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converter for Media (read from 6.7.1) never changes an image: it writes WebP
 * copies into `wp-content/uploads-webpc/` and serves them through its own rules.
 * Its records are read in a later step. While active with "Conversion of new
 * images" on (the default) it converts every new or changed image, the plugin's
 * output included, and its rules serve those copies in place of the plugin's.
 */
class Converter extends Reader {

	public function slug(): string {
		return 'converter';
	}

	public function name(): string {
		return 'Converter for Media';
	}

	public function switch_off(): string {
		return __( 'Deactivate Converter for Media, or turn off "Conversion of new images" in its General Settings, under Settings, Converter for Media.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'webp-converter-for-media/webp-converter-for-media.php' );
	}

	protected function loaded(): bool {
		return class_exists( 'WebpConverter\WebpConverter', false );
	}

	/**
	 * "Conversion of new images", on while its settings say nothing (`yes`).
	 */
	protected function optimizes_uploads(): bool {
		$settings = is_multisite() ? get_site_option( 'webpc_settings' ) : get_option( 'webpc_settings' );

		return ! is_array( $settings ) || ! array_key_exists( 'auto_conversion', $settings ) || ! empty( $settings['auto_conversion'] );
	}
}
