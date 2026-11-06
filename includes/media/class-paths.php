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
 * The plugin keeps every path it records relative to a folder, so a site moved
 * to another server or folder still finds its files: the uploads folder for the
 * media library and every backup, `wp-content` for a file in another folder
 * (`Folders\Folders`). It never writes into its own folder, which every update
 * replaces.
 */
class Paths {

	const BACKUP_FOLDER = 'iminify-backup';

	/**
	 * Where the backups of the files in other folders are kept, beside the media
	 * library's rather than inside them: a path in the uploads folder can be
	 * anything, so no name inside `iminify-backup/` is safe from an attachment's.
	 */
	const FOLDERS_BACKUP_FOLDER = 'iminify-backup-folders';

	/**
	 * Every folder the plugin keeps backups in, under the uploads folder.
	 */
	const BACKUP_FOLDERS = array( self::BACKUP_FOLDER, self::FOLDERS_BACKUP_FOLDER );

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
		return self::under( self::uploads(), $relative );
	}

	/**
	 * The absolute path of a file kept relative to a folder.
	 *
	 * @param string $root     The folder, without a trailing slash.
	 * @param string $relative A path relative to it.
	 */
	public static function under( string $root, string $relative ): string {
		return $root . '/' . ltrim( wp_normalize_path( $relative ), '/' );
	}

	/**
	 * Where the backup of a file is kept: the same path under a backup folder,
	 * `iminify-backup/` for the media library's, `iminify-backup-folders/` for a
	 * file in another folder.
	 *
	 * @param string $relative The file's path relative to the uploads folder, or for another folder's file to `wp-content`.
	 * @param string $folder   One of BACKUP_FOLDERS.
	 */
	public static function backup( string $relative, string $folder = self::BACKUP_FOLDER ): string {
		return self::uploads() . '/' . $folder . '/' . ltrim( wp_normalize_path( $relative ), '/' );
	}
}
