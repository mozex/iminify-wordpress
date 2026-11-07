<?php
/**
 * What Smush left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smush (read from the free 4.3.6) records an attachment in
 * `wp-smpro-smush-data`, whose `sizes` map holds each file's bytes before and
 * after: `full` is the original WordPress keeps beside a `-scaled` copy (or the
 * attached file when there is none), `wp_scaled` the `-scaled` copy, then each
 * size, as objects or arrays. It compresses in place, the original included by
 * default. It backs up that original once, beside it as `photo.bak.jpg`, named
 * in core's `_wp_attachment_backup_sizes['smush-full']`; the backup passes when
 * it has the bytes Smush recorded before its first step on that file (`full`'s,
 * or its resize's or PNG conversion's, which run earlier). The free plugin
 * makes no WebP or AVIF copies. Files outside the library are rows of
 * `{base_prefix}smush_dir_images` by absolute path, with no backups. A run it
 * never finished leaves its backup with no record (W249).
 *
 * @phpstan-import-type Found from Reader
 */
class Smush extends Reader {

	const DIR_TABLE = 'smush_dir_images';

	public function slug(): string {
		return 'smush';
	}

	public function name(): string {
		return 'Smush';
	}

	public function switch_off(): string {
		return __( 'Deactivate Smush, or turn off "Automatic compression" in its settings.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'wp-smushit/wp-smush.php', 'wp-smush-pro/wp-smush.php' );
	}

	protected function loaded(): bool {
		return defined( 'WP_SMUSH_VERSION' );
	}

	/**
	 * "Automatic compression", on while its settings say nothing.
	 */
	protected function optimizes_uploads(): bool {
		$network = is_multisite() ? get_site_option( 'wp-smush-settings' ) : false;
		$auto    = is_array( $network ) && array_key_exists( 'auto', $network ) ? $network['auto'] : self::setting( 'wp-smush-settings', 'auto', true );

		return ! empty( $auto );
	}

	/**
	 * Its record of any image, or the pointer to a backup it takes before its first
	 * step, which a run it never finished leaves alone (W249).
	 */
	protected function holds_records(): bool {
		global $wpdb;

		if ( self::meta_exists( array( 'wp-smpro-smush-data' ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request, by the meta_key index, at the few images WordPress's editor or Smush backed up.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", '_wp_attachment_backup_sizes', '%' . $wpdb->esc_like( '"smush-full"' ) . '%' ) );
	}

	public function stamp(): string {
		return self::meta_stamp( array( 'wp-smpro-smush-data' ) );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$data  = get_post_meta( $attachment, 'wp-smpro-smush-data', true );
		$names = self::files( $attachment, $metadata );

		if ( ! isset( $names['full'] ) ) {
			return null;
		}

		if ( ! is_array( $data ) || empty( $data['stats'] ) || ! isset( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
			return $this->unfinished( $attachment, $names );
		}

		$scaled = isset( $names['original'] );
		$files  = array();

		foreach ( $data['sizes'] as $size => $entry ) {
			$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
			$path  = self::path( (string) $size, $names, $scaled );

			if ( ! is_array( $entry ) || null === $path ) {
				continue;
			}

			$before = (int) ( $entry['size_before'] ?? 0 );
			$after  = (int) ( $entry['size_after'] ?? 0 );

			/* A file it gained nothing on was never written. */
			if ( $after <= 0 || $after >= $before ) {
				continue;
			}

			$files[ $path ] = array(
				'size'   => (string) $size,
				'before' => $before,
				'after'  => $after,
			);
		}

		$original = $scaled ? $names['original'] : $names['full'];
		$backups  = array();
		$backup   = $this->backup( $attachment, $original, $files[ $original ]['before'] ?? 0 );

		if ( null !== $backup ) {
			$backups[ $original ] = $backup;
		}

		return self::answer( $attachment, $files, $backups, array() );
	}

	/**
	 * A run Smush never finished: it backs the original up before any step and
	 * writes its record only once every size went through, so a backup of its own
	 * with no record means it wrote some of the files in place and recorded no
	 * bytes. Its main file (and the original it compresses beside a `-scaled` copy)
	 * is taken as its output, with no original, while its backup is there (its own
	 * restore removes it with the pointer), so nothing it may have compressed is sent
	 * for a second lossy pass. Its uninstall with "Keep Data" off leaves the same,
	 * which this reads the same way.
	 *
	 * @param int                   $attachment The attachment's id.
	 * @param array<string, string> $names      Its files by WordPress's names.
	 * @return Found|null
	 */
	protected function unfinished( int $attachment, array $names ): ?array {
		$original = $names['original'] ?? $names['full'];
		$sizes    = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		$backup   = is_array( $sizes ) && isset( $sizes['smush-full']['file'] ) && is_string( $sizes['smush-full']['file'] ) ? wp_basename( $sizes['smush-full']['file'] ) : '';
		$folder   = dirname( $original );

		if ( '' === $backup || null === Places::upload( ( '.' === $folder ? '' : $folder . '/' ) . $backup ) || wp_basename( $original ) === $backup ) {
			return null;
		}

		return self::answer(
			$attachment,
			$this->held(
				$attachment,
				array_unique(
					array(
						'full'     => $names['full'],
						'original' => $original,
					)
				)
			),
			array(),
			array()
		);
	}

	public function optimized_files( array $paths ): array {
		global $wpdb;

		$table = $wpdb->base_prefix . self::DIR_TABLE;

		if ( array() === $paths || ! self::table_exists( $table ) ) {
			return array();
		}

		$hashes = array();

		foreach ( $paths as $path ) {
			$hashes[ md5( $path ) ] = $path;
		}

		$in = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the files of a folder's page in one query; one placeholder per path, built above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT path_hash, image_size FROM {$table} WHERE image_size > 0 AND path_hash IN ({$in})", array_keys( $hashes ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$path = $hashes[ (string) $row['path_hash'] ] ?? null;

			if ( null === $path ) {
				continue;
			}

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) filesize( $path ) === (int) $row['image_size'] ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * The file a size key stands for, relative to the uploads folder, or null.
	 *
	 * @param string                $size   The key.
	 * @param array<string, string> $names  The attachment's files by WordPress's names.
	 * @param bool                  $scaled Whether WordPress keeps an original beside a `-scaled` copy.
	 */
	protected static function path( string $size, array $names, bool $scaled ): ?string {
		if ( 'full' === $size ) {
			return $scaled ? $names['original'] : $names['full'];
		}

		if ( 'wp_scaled' === $size ) {
			return $scaled ? $names['full'] : null;
		}

		return 'original' === $size ? null : ( $names[ $size ] ?? null );
	}

	/**
	 * The backup Smush kept of the original, when it passes the size check: the
	 * bytes it recorded before its first step on that file. A pointer that names
	 * the file itself means Smush left it as it was, and has no backup.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $original   The file it backed up, relative to the uploads folder.
	 * @param int    $before     The bytes Smush recorded before compressing it.
	 */
	protected function backup( int $attachment, string $original, int $before ): ?string {
		$sizes  = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		$backup = is_array( $sizes ) && isset( $sizes['smush-full']['file'] ) && is_string( $sizes['smush-full']['file'] ) ? wp_basename( $sizes['smush-full']['file'] ) : '';
		$folder = dirname( $original );

		if ( '' === $backup ) {
			return null;
		}

		$path = Paths::absolute( ( '.' === $folder ? '' : $folder . '/' ) . $backup );

		/* Its first step on the file recorded the bytes the file had before Smush, the most of them, since every step after it shrank the file. */
		$first = (int) max( array_merge( array( $before ), self::earlier( $attachment ) ) );

		return Paths::absolute( $original ) === $path ? null : self::verified( $path, $first );
	}

	/**
	 * The bytes the original had before Smush's earlier steps on it, its resize and
	 * its PNG conversion, which it records apart.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<int>
	 */
	protected static function earlier( int $attachment ): array {
		$bytes  = array();
		$resize = get_post_meta( $attachment, 'wp-smush-resize_savings', true );
		$png    = get_post_meta( $attachment, 'wp-smush-pngjpg_savings', true );

		if ( is_array( $resize ) && isset( $resize['size_before'] ) ) {
			$bytes[] = (int) $resize['size_before'];
		}

		if ( is_array( $png ) && isset( $png['full']['size_before'] ) ) {
			$bytes[] = (int) $png['full']['size_before'];
		}

		return $bytes;
	}
}
