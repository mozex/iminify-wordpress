<?php
/**
 * What TinyPNG's plugin left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TinyPNG's plugin, "TinyPNG - JPEG, PNG & WebP image compression" (read from
 * 3.8.1), records an attachment in `_tiny_compress_images` (before 3.7.0
 * `tiny_compress_images`): key `0` is the attached file, `original_unscaled` the
 * original WordPress keeps beside a `-scaled` copy, then each size. A compressed
 * entry has its service's `input.size` and `output.size` with a `ratio`; one it
 * only marked as compressed has no `ratio` and was never changed. It always
 * writes its service's file over the old one, the original included by default.
 * Its one backup, off by default, is of the original (else the attached file)
 * under `uploads/tinify_backup/`, taken at the start of a pass and never written
 * over, so it passes when it has that entry's `input.size` and none of the bytes
 * the plugin recorded as output. Its copies are named in each entry's
 * `convert.path` (`photo.webp`).
 */
class Tinypng extends Reader {

	const META = '_tiny_compress_images';

	const LEGACY_META = 'tiny_compress_images';

	const BACKUP_FOLDER = 'tinify_backup';

	public function slug(): string {
		return 'tinypng';
	}

	public function name(): string {
		return 'TinyPNG';
	}

	public function switch_off(): string {
		return __( 'Deactivate TinyPNG, or set it to compress new images manually in its settings.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'tiny-compress-images/tiny-compress-images.php' );
	}

	protected function loaded(): bool {
		return class_exists( 'Tiny_Plugin', false );
	}

	/**
	 * A key it can send with, and new images compressed during upload or in the background.
	 */
	protected function optimizes_uploads(): bool {
		$key    = defined( 'TINY_API_KEY' ) || '' !== (string) get_option( 'tinypng_api_key', '' );
		$timing = (string) get_option( 'tinypng_compression_timing', '' );

		return $key && 'manual' !== $timing;
	}

	/**
	 * One option per setting; its API key is another.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'tinypng_sizes'           => null,
			'tinypng_resize_original' => array( 'enabled', 'width' ),
			'tinypng_preserve_data'   => array( 'copyright', 'creation', 'location' ),
			'tinypng_convert_format'  => array( 'convert', 'convert_to' ),
			'tinypng_backup'          => array( 'enabled' ),
		);
	}

	protected function holds_records(): bool {
		return self::meta_exists( array( self::META, self::LEGACY_META ) );
	}

	public function stamp(): string {
		return self::meta_stamp( array( self::META, self::LEGACY_META ) );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$data = get_post_meta( $attachment, self::META, true );

		if ( ! is_array( $data ) || array() === $data ) {
			$data = get_post_meta( $attachment, self::LEGACY_META, true );
		}

		$names = self::files( $attachment, $metadata );

		if ( ! is_array( $data ) || array() === $data || ! isset( $names['full'] ) ) {
			return null;
		}

		$files   = array();
		$outputs = array();
		$copies  = array();

		foreach ( $data as $size => $entry ) {
			$path = self::path( (string) $size, $names );

			if ( null === $path || ! is_array( $entry ) ) {
				continue;
			}

			$copy = self::copy_of( $entry, $path );

			if ( null !== $copy ) {
				$copies[ $copy ] = $path;
			}

			/* "Mark as compressed" records sizes with no ratio and changes nothing. */
			if ( ! isset( $entry['input']['size'], $entry['output']['size'], $entry['output']['ratio'] ) ) {
				continue;
			}

			$files[ $path ] = array(
				'size'   => (string) $size,
				'before' => (int) $entry['input']['size'],
				'after'  => (int) $entry['output']['size'],
			);
			$outputs[]      = (int) $entry['output']['size'];
		}

		$original = $names['original'] ?? $names['full'];
		$backups  = array();

		if ( isset( $files[ $original ] ) && ! in_array( $files[ $original ]['before'], $outputs, true ) ) {
			$backup = self::verified( Paths::uploads() . '/' . self::BACKUP_FOLDER . '/' . $original, $files[ $original ]['before'] );

			if ( null !== $backup ) {
				$backups[ $original ] = $backup;
			}
		}

		return self::answer( $attachment, $files, $backups, $copies );
	}

	/**
	 * The file a size key stands for, relative to the uploads folder, or null for a retina copy.
	 *
	 * @param string                $size  The key: `0`, `original_unscaled` or a size's name.
	 * @param array<string, string> $names The attachment's files by WordPress's names.
	 */
	protected static function path( string $size, array $names ): ?string {
		if ( '0' === $size ) {
			return $names['full'];
		}

		if ( 'original_unscaled' === $size ) {
			return $names['original'] ?? null;
		}

		return in_array( $size, array( 'full', 'original' ), true ) || '_wr2x' === substr( $size, -5 ) ? null : ( $names[ $size ] ?? null );
	}

	/**
	 * A WebP or AVIF copy an entry names, relative to the uploads folder, or null.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param string               $path  The file it stands for, relative to the uploads folder.
	 */
	protected static function copy_of( array $entry, string $path ): ?string {
		$convert = isset( $entry['convert'] ) && is_array( $entry['convert'] ) ? $entry['convert'] : array();
		$type    = (string) ( $convert['type'] ?? '' );
		$copy    = isset( $convert['path'] ) && is_string( $convert['path'] ) ? Paths::relative( $convert['path'] ) : null;

		return null !== $copy && $copy !== $path && in_array( $type, array( 'image/webp', 'image/avif' ), true ) ? $copy : null;
	}
}
