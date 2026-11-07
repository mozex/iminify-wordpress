<?php
/**
 * What Imagify left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imagify (read from 2.3.4) records an attachment in `_imagify_status`
 * (`success`, or `already_optimized` when its service found nothing to gain)
 * and `_imagify_data`, whose `sizes` map holds, for `full` (the attached file,
 * the `-scaled` copy when WordPress made one) and each size, the bytes before and
 * after its pass as its service reported them; `<size>@imagify-webp` and
 * `@imagify-avif` are its copies, `photo.jpg.webp` beside each file. It
 * compresses in place and never touches the original WordPress keeps beside a
 * `-scaled` copy. Its backup of the attached file is `uploads/backup/` plus the
 * file's path: the original's name, and its `-scaled` copy's, on a scaled
 * upload. Files outside the library are rows of `{base_prefix}imagify_files`,
 * their paths written from a placeholder (`{{THEMES}}/`).
 */
class Imagify extends Reader {

	const FOLDER = 'backup';

	const FILES_TABLE = 'imagify_files';

	public function slug(): string {
		return 'imagify';
	}

	public function name(): string {
		return 'Imagify';
	}

	public function switch_off(): string {
		return __( 'Deactivate Imagify, or turn off "Auto-Optimize images on upload" in its settings.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'imagify/imagify.php' );
	}

	protected function loaded(): bool {
		return defined( 'IMAGIFY_VERSION' );
	}

	/**
	 * "Auto-Optimize images on upload", on while its settings say nothing, with a
	 * key to send with, without which it compresses nothing.
	 */
	protected function optimizes_uploads(): bool {
		$network = is_multisite() ? get_site_option( 'imagify_settings' ) : false;
		$from    = is_array( $network ) ? $network : get_option( 'imagify_settings' );
		$from    = is_array( $from ) ? $from : array();
		$key     = ( defined( 'IMAGIFY_API_KEY' ) && '' !== (string) constant( 'IMAGIFY_API_KEY' ) ) || ! empty( $from['api_key'] );

		return $key && ( ! array_key_exists( 'auto_optimize', $from ) || ! empty( $from['auto_optimize'] ) );
	}

	protected function holds_records(): bool {
		return self::meta_exists( array( '_imagify_data' ) );
	}

	public function stamp(): string {
		return self::meta_stamp( array( '_imagify_data', '_imagify_status' ) );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$status = get_post_meta( $attachment, '_imagify_status', true );
		$data   = get_post_meta( $attachment, '_imagify_data', true );
		$names  = self::files( $attachment, $metadata );

		if ( ! in_array( $status, array( 'success', 'already_optimized' ), true ) || ! is_array( $data ) || ! isset( $data['sizes'] ) || ! is_array( $data['sizes'] ) || ! isset( $names['full'] ) ) {
			return null;
		}

		$files   = array();
		$backups = array();
		$copies  = array();

		foreach ( $data['sizes'] as $size => $entry ) {
			$size = (string) $size;

			if ( ! is_array( $entry ) || empty( $entry['success'] ) ) {
				continue;
			}

			$copy = self::copy_of( $size );

			if ( null !== $copy ) {
				if ( isset( $names[ $copy['size'] ] ) ) {
					$copies[ $names[ $copy['size'] ] . '.' . $copy['format'] ] = $names[ $copy['size'] ];
				}

				continue;
			}

			/* The original WordPress keeps is never Imagify's: only the attached file and the sizes are. */
			if ( 'original' === $size || ! isset( $names[ $size ] ) ) {
				continue;
			}

			$before = (int) ( $entry['original_size'] ?? 0 );
			$after  = (int) ( $entry['optimized_size'] ?? 0 );

			if ( $before <= 0 || $after <= 0 ) {
				continue;
			}

			$files[ $names[ $size ] ] = array(
				'size'   => $size,
				'before' => $before,
				'after'  => $after,
			);

			if ( 'full' === $size ) {
				$backup = self::verified( Paths::uploads() . '/' . self::FOLDER . '/' . $names['full'], $before );

				if ( null !== $backup ) {
					$backups[ $names['full'] ] = $backup;
				}
			}
		}

		return self::answer( $attachment, $files, $backups, $copies );
	}

	public function optimized_files( array $paths ): array {
		global $wpdb;

		$table = $wpdb->base_prefix . self::FILES_TABLE;

		if ( array() === $paths || ! self::table_exists( $table ) ) {
			return array();
		}

		$forms = array();

		foreach ( $paths as $path ) {
			foreach ( self::forms( $path ) as $form ) {
				$forms[ $form ] = $path;
			}
		}

		if ( array() === $forms ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $forms ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the files of a folder's page in one query; one placeholder per form, built above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT path, optimized_size FROM {$table} WHERE status IN ('success', 'already_optimized') AND path IN ({$in})", array_keys( $forms ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$path = $forms[ (string) $row['path'] ] ?? null;

			if ( null === $path ) {
				continue;
			}

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) $row['optimized_size'] > 0 && (int) filesize( $path ) === (int) $row['optimized_size'] ) {
				$found[] = $path;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * A copy's size and format, for a size key such as `medium@imagify-webp`, or null for a file's own key.
	 *
	 * @param string $size The key.
	 * @return array{size: string, format: string}|null
	 */
	protected static function copy_of( string $size ): ?array {
		foreach ( array( 'webp', 'avif' ) as $format ) {
			$suffix = '@imagify-' . $format;

			if ( strlen( $size ) > strlen( $suffix ) && substr( $size, -strlen( $suffix ) ) === $suffix ) {
				return array(
					'size'   => substr( $size, 0, -strlen( $suffix ) ),
					'format' => $format,
				);
			}
		}

		return null;
	}

	/**
	 * The ways Imagify may have written a file's path in its table: from each
	 * placeholder whose folder holds the file.
	 *
	 * @param string $path An absolute path.
	 * @return list<string>
	 */
	protected static function forms( string $path ): array {
		$path    = wp_normalize_path( $path );
		$folders = array(
			'{{THEMES}}'     => get_theme_root(),
			'{{PLUGINS}}'    => WP_PLUGIN_DIR,
			'{{MU_PLUGINS}}' => WPMU_PLUGIN_DIR,
			'{{UPLOADS}}'    => Paths::uploads(),
			'{{CONTENT}}'    => WP_CONTENT_DIR,
			'{{ROOT}}'       => ABSPATH,
			'{{ABSPATH}}'    => ABSPATH,
		);
		$forms   = array();

		foreach ( $folders as $placeholder => $folder ) {
			$folder = untrailingslashit( wp_normalize_path( (string) $folder ) );

			if ( '' !== $folder && 0 === strpos( $path, $folder . '/' ) ) {
				$forms[] = $placeholder . '/' . substr( $path, strlen( $folder ) + 1 );
			}
		}

		return $forms;
	}
}
