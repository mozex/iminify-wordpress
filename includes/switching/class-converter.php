<?php
/**
 * What Converter for Media left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converter for Media (read from 6.7.2) never changes an image or its metadata:
 * it writes WebP (and, with its paid plan, AVIF) copies of the original WordPress
 * keeps, the attached file and each size into `wp-content/uploads-webpc/`, at the
 * file's path from the folder holding the uploads folder plus the format
 * (`uploads-webpc/uploads/2026/11/photo.jpg.webp`), and serves them through its
 * own rules. Nothing in the database names them: a copy is there or it isn't, and
 * an empty `<copy>.crashed` beside one marks a conversion that failed (a copy that
 * came out larger is deleted, `<copy>.deleted` left in its place). So it optimized
 * nothing, and its answer is only its copies, kept outside the uploads folder.
 *
 * While active with "Conversion of new images" on (the default) it converts every
 * new or changed image, the plugin's output included, and its rules serve those
 * copies in place of the plugin's.
 */
class Converter extends Reader {

	/**
	 * Its folder under `wp-content`, at the default name, the only one `Places` allows.
	 */
	const FOLDER = 'uploads-webpc';

	/**
	 * The formats it makes, in the order it makes them.
	 */
	const FORMATS = array( 'avif', 'webp' );

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

	public function compresses(): bool {
		return false;
	}

	/**
	 * Its folder, which its uninstall deletes with every copy.
	 */
	protected function holds_records(): bool {
		return is_dir( self::root() );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$parent  = dirname( Places::network_uploads() ?? Paths::uploads() );
		$outside = array();

		foreach ( self::files( $attachment, $metadata ) as $relative ) {
			$path = Paths::absolute( $relative );

			if ( 0 !== strpos( $path, $parent . '/' ) ) {
				continue;
			}

			$from = self::root() . '/' . substr( $path, strlen( $parent ) + 1 );

			foreach ( self::FORMATS as $format ) {
				$copy = $from . '.' . $format;

				if ( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) !== $format && ! file_exists( $copy . '.crashed' ) ) {
					$outside[ $relative ][] = $copy;
				}
			}
		}

		return self::answer( $attachment, array(), array(), array(), '', $outside );
	}

	/**
	 * Its folder: `uploads-webpc` in `wp-content`.
	 */
	protected static function root(): string {
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' . self::FOLDER;
	}
}
