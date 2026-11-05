<?php
/**
 * The plugin's few writes to the uploads folder.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain PHP file calls, kept in one place. WP_Filesystem's direct `move()`
 * deletes the destination before it renames, which would leave a site without
 * an image if the rename failed, so a replacement is a temporary file in the
 * same folder, checked and then renamed over the old one, which no reader ever
 * sees half-written.
 */
class Files {

	/**
	 * Writes a file in one step: a temporary file beside it, filled, checked, then
	 * renamed over it with the old file's permissions. Nothing changes when the fill
	 * or the check refuses.
	 *
	 * @param string                   $path  The file to write.
	 * @param callable(string): ?int   $fill  Writes the temporary file, and answers the bytes written or null.
	 * @param callable(string): bool   $check Asked of the temporary file before it is put in place.
	 * @return int|null The bytes written, or null when nothing changed.
	 */
	public static function replace( string $path, callable $fill, callable $check ): ?int {
		$temporary = self::temporary_beside( $path );

		if ( null === $temporary ) {
			return null;
		}

		$written = $fill( $temporary );

		if ( null === $written || ! $check( $temporary ) || ! self::move( $temporary, $path ) ) {
			wp_delete_file( $temporary );

			return null;
		}

		return $written;
	}

	/**
	 * Copies a file in one step, through a temporary file beside the copy.
	 *
	 * @param string $from The file to copy.
	 * @param string $to   Where the copy goes.
	 */
	public static function copy( string $from, string $to ): bool {
		$temporary = self::temporary_beside( $to );

		if ( null === $temporary ) {
			return false;
		}

		if ( ! copy( $from, $temporary ) || ! self::move( $temporary, $to ) ) {
			wp_delete_file( $temporary );

			return false;
		}

		return true;
	}

	/**
	 * Renames a file over another, giving it the replaced file's permissions, which the web server reads it with.
	 *
	 * @param string $from The file to move.
	 * @param string $to   Where it goes.
	 */
	public static function move( string $from, string $to ): bool {
		if ( is_file( $to ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- keeps the replaced file's mode, as core sets one for every image it writes.
			chmod( $from, fileperms( $to ) & 0777 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the atomic replacement; WP_Filesystem's move() deletes the target before it renames.
		return rename( $from, $to );
	}

	/**
	 * A new temporary file's path in a file's folder, the folder made, or null when it can't be.
	 *
	 * @param string $path The file it stands beside.
	 */
	protected static function temporary_beside( string $path ): ?string {
		$folder = dirname( $path );

		return wp_mkdir_p( $folder ) ? $folder . '/.iminify-' . wp_generate_password( 12, false ) . '.tmp' : null;
	}
}
