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
 * (W139): `om_image_offloaded` (or, from older versions, `optimole_offload`
 * alone) and an attachment's metadata naming `/id:` say so, while its attached
 * file is gone (a rollback in progress brings it back with the marks still on).
 * Such an image isn't Iminify's to touch until Optimole rolls it back. It changes
 * no other file, so it optimized nothing here.
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

	/**
	 * One array option, which also holds its API key.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'optml_settings' => array( 'autoquality', 'quality', 'limit_dimensions', 'limit_width', 'strip_metadata', 'best_format', 'avif' ),
		);
	}

	public function compresses(): bool {
		return false;
	}

	protected function holds_records(): bool {
		return self::meta_exists( array( 'om_image_offloaded', 'optimole_offload' ) );
	}

	public function stamp(): string {
		return self::meta_stamp( array( 'om_image_offloaded', 'optimole_offload' ) );
	}

	public function away( int $attachment ): bool {
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$attached = (string) get_attached_file( $attachment, true );
		$marked   = '' !== (string) get_post_meta( $attachment, 'om_image_offloaded', true ) || 'true' === get_post_meta( $attachment, 'optimole_offload', true );

		return $marked && is_array( $metadata ) && false !== strpos( (string) ( $metadata['file'] ?? '' ), '/id:' ) && ( '' === $attached || ! file_exists( $attached ) );
	}

	public function away_message(): string {
		return __( 'Optimole moved this image to its cloud and deleted its files here. Roll it back in Optimole first (it needs Optimole active and connected), and Iminify can optimize it then.', 'iminify-image-optimizer' );
	}
}
