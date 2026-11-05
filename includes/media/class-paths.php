<?php
/**
 * Where the plugin's files live under the uploads folder.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin keeps every path it records relative to the uploads folder, so a
 * site moved to another server or folder still finds its files, and it writes
 * only under that folder: never into its own, which every update replaces.
 */
class Paths {

	const BACKUP_FOLDER = 'iminify-backup';

	/**
	 * The uploads folder, without a trailing slash.
	 */
	public static function uploads(): string {
		$uploads = wp_get_upload_dir();

		return untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
	}

	/**
	 * A file's path relative to the uploads folder, or null for one outside it.
	 *
	 * @param string $path An absolute path.
	 */
	public static function relative( string $path ): ?string {
		$base = self::uploads() . '/';
		$path = wp_normalize_path( $path );

		if ( 0 !== strpos( $path, $base ) ) {
			return null;
		}

		$relative = (string) substr( $path, strlen( $base ) );

		return '' === $relative || false !== strpos( '/' . $relative . '/', '/../' ) ? null : $relative;
	}

	/**
	 * The absolute path of a file kept relative to the uploads folder.
	 *
	 * @param string $relative A path relative to the uploads folder.
	 */
	public static function absolute( string $relative ): string {
		return self::uploads() . '/' . ltrim( wp_normalize_path( $relative ), '/' );
	}

	/**
	 * Where the backup of a file is kept: the same path under `iminify-backup/`.
	 *
	 * @param string $relative The file's path relative to the uploads folder.
	 */
	public static function backup( string $relative ): string {
		return self::uploads() . '/' . self::BACKUP_FOLDER . '/' . ltrim( wp_normalize_path( $relative ), '/' );
	}
}
