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
 * Image Optimizer by Elementor (read from 1.7.7) records an attachment in
 * `image_optimizer_metadata`: `sizes_optimized` lists the sizes it worked on
 * (`full` being the attached file, the `-scaled` copy when WordPress made one),
 * and `original_data.sizes` keeps each one's WordPress entry from before (its
 * `file` and `filesize`). It writes the metadata's `filesize` from what its
 * service reported, which isn't proven to be the bytes it wrote, so its output
 * is told by what it did to the files rather than by bytes (W248):
 *
 * - converting (its default, to WebP or AVIF) replaces the attachment's files
 *   with `photo.webp` and keeps the untouched `photo.jpg` at its own path, so a
 *   file of that name in a modern format is its output, and the file of the name
 *   `original_data` gives is the original. It refuses to convert where a
 *   `photo.webp` already exists rather than choosing another name, so a file of
 *   another name (an edit WordPress saved as `photo-e1699999999999.webp`) is
 *   not its output. Restoring it would rewrite the
 *   attachment, its address and the posts linking to it, which Iminify never
 *   does (W135): Elementor's own Restore does, while it is installed;
 * - keeping the format, it copies each file first as `photo.backup.jpg` beside
 *   it, so a file with a `.backup.` copy that differs from it is its output (or,
 *   with backups turned off, one whose bytes are no longer `original_data`'s).
 *
 * A backup passes with the bytes `original_data` gives, where it gives any. Its
 * bulk "Reoptimize" doesn't restore first, so its backup and `original_data` can
 * both name a compressed file, which no record tells apart; and a modern-format
 * backup beside a JPEG or PNG of the same name is such a second pass of a
 * converted image, so it is never trusted. Backups are found from the file names
 * `original_data` gives, never from the absolute paths it stores, which a moved
 * site breaks. It makes no copies beside the files.
 *
 * While active with "Optimize new uploads" on (the default) and connected, it
 * acts on every upload.
 */
class Elementor extends Reader {

	const META = 'image_optimizer_metadata';

	/**
	 * The formats a conversion makes.
	 */
	const MODERN = array( 'webp', 'avif' );

	/**
	 * The formats a conversion makes them from.
	 */
	const CLASSIC = array( 'jpg', 'jpeg', 'png', 'gif' );

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

	/**
	 * Seven options of its own, never its access token or its connection's data.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'image_optimizer_compression_level'         => null,
			'image_optimizer_resize_larger_images'      => null,
			'image_optimizer_resize_larger_images_size' => null,
			'image_optimizer_exif_metadata'             => null,
			'image_optimizer_original_images'           => null,
			'image_optimizer_convert_to_format'         => null,
			'image_optimizer_custom_sizes'              => null,
		);
	}

	protected function holds_records(): bool {
		return self::meta_exists( array( self::META ) );
	}

	public function stamp(): string {
		return self::meta_stamp( array( self::META ) );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$record = get_post_meta( $attachment, self::META, true );
		$names  = self::files( $attachment, $metadata );

		if ( ! is_array( $record ) || ! isset( $record['sizes_optimized'], $record['original_data']['sizes'], $names['full'] ) || ! is_array( $record['sizes_optimized'] ) || ! is_array( $record['original_data']['sizes'] ) ) {
			return null;
		}

		$folder    = dirname( $names['full'] );
		$folder    = '.' === $folder ? '' : $folder . '/';
		$files     = array();
		$backups   = array();
		$converted = false;

		foreach ( array_unique( array_map( 'strval', $record['sizes_optimized'] ) ) as $size ) {
			$was = $record['original_data']['sizes'][ $size ] ?? null;

			/* Sizes that share one file are one file, read once. */
			if ( ! isset( $names[ $size ] ) || ! is_array( $was ) || empty( $was['file'] ) || ! is_string( $was['file'] ) || isset( $files[ $names[ $size ] ] ) ) {
				continue;
			}

			$relative = $names[ $size ];
			$bytes    = self::bytes( $attachment, $relative );
			$before   = isset( $was['filesize'] ) ? (int) $was['filesize'] : 0;
			$source   = $folder . wp_basename( $was['file'] );

			if ( $bytes < 0 ) {
				continue;
			}

			if ( $source !== $relative ) {
				/* Converted keeps the old name's stem, so any other file (an edit made since) is not its output. */
				if ( ! self::conversion_of( $source, $relative ) ) {
					continue;
				}

				/* Converted: the attachment's file is its output, and the file of the old name the original it kept. */
				$converted = true;
				$backup    = $this->kept( $source, $before );
			} else {
				$backup = Places::upload( self::backup_of( $relative ) );
				$kept   = null === $backup ? -1 : self::size_of( $backup );

				/* An unchanged file holds no output: its service found nothing to gain, or the backup is all that differs. */
				if ( null !== $backup ? $kept === $bytes : ( $before > 0 && $before === $bytes ) ) {
					continue;
				}

				$backup = null !== $backup && ( $before <= 0 || $kept === $before ) && $this->trusted( $relative ) ? $backup : null;
			}

			$files[ $relative ] = array(
				'size'   => $size,
				'before' => $before > 0 ? $before : ( null === $backup ? $bytes : self::size_of( $backup ) ),
				'after'  => $bytes,
			);

			if ( null !== $backup ) {
				$backups[ $relative ] = $backup;
			}
		}

		return self::answer( $attachment, $files, $backups, array(), $converted ? $this->restore_elsewhere() : '' );
	}

	public function restore_elsewhere(): string {
		return __( 'Image Optimizer by Elementor converted this image, giving it a new address that your posts now link to, and kept its original beside it. Its own Restore puts the original back, with its address, while that plugin is installed; Iminify doesn\'t rewrite your posts, so it doesn\'t restore this image.', 'iminify-image-optimizer' );
	}

	/**
	 * Whether a file is the conversion of another: the same name in WebP or AVIF.
	 *
	 * @param string $source   The file it was made from, relative to the uploads folder.
	 * @param string $relative The file, relative to the uploads folder.
	 */
	protected static function conversion_of( string $source, string $relative ): bool {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::MODERN, true ) && substr( $source, 0, -strlen( pathinfo( $source, PATHINFO_EXTENSION ) ) - 1 ) === substr( $relative, 0, -strlen( $extension ) - 1 );
	}

	/**
	 * The original a conversion kept at its own name, when it is there with the
	 * bytes `original_data` gives (where it gives any).
	 *
	 * @param string $relative The original's path, relative to the uploads folder.
	 * @param int    $before   The bytes `original_data` gives, or 0.
	 */
	protected function kept( string $relative, int $before ): ?string {
		$path = Places::upload( $relative );

		return null !== $path && ( $before <= 0 || self::size_of( $path ) === $before ) ? $path : null;
	}

	/**
	 * Whether a backup of this file can be trusted: a modern-format file whose name
	 * a JPEG or PNG beside it shares was made by converting that one, so its backup
	 * is of a compressed file.
	 *
	 * @param string $relative The file, relative to the uploads folder.
	 */
	protected function trusted( string $relative ): bool {
		if ( ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::MODERN, true ) ) {
			return true;
		}

		$stem = substr( $relative, 0, -strlen( pathinfo( $relative, PATHINFO_EXTENSION ) ) - 1 );

		foreach ( self::CLASSIC as $extension ) {
			if ( null !== Places::upload( $stem . '.' . $extension ) || null !== Places::upload( $stem . '.' . strtoupper( $extension ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The backup it keeps of a file in its own format: `photo.backup.jpg` beside `photo.jpg`.
	 *
	 * @param string $relative The file, relative to the uploads folder.
	 */
	protected static function backup_of( string $relative ): string {
		$extension = pathinfo( $relative, PATHINFO_EXTENSION );

		return substr( $relative, 0, -strlen( $extension ) - 1 ) . '.backup.' . $extension;
	}

	/**
	 * A file's bytes now.
	 *
	 * @param string $path An absolute path.
	 */
	protected static function size_of( string $path ): int {
		clearstatcache( true, $path );

		return (int) filesize( $path );
	}
}
