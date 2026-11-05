<?php
/**
 * Keeps a copy of each image's original before the plugin writes over it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The original an attachment's group is made from, copied under
 * `uploads/iminify-backup/` at the same path before the group is opened: it is
 * what a restore puts back and what a later group is made from. On by default
 * (the backups setting). A backup that can't be made stops the optimization, with
 * nothing counted: the plugin never writes over an image someone asked it to keep
 * a copy of without that copy.
 *
 * A backup already at that path is kept only while it holds the same bytes as
 * the source: a file replaced in place since (an image editor overwriting it,
 * a plugin swapping the file) would otherwise leave the old picture as the
 * backup of the new one, and the next run would send it. Such a backup is moved
 * aside under a new name, never deleted, and the new source is backed up.
 */
class Backups {

	/**
	 * Backs up a source, unless an identical backup is already kept.
	 *
	 * @param string       $path     The file to keep.
	 * @param string       $relative The path it stands for, relative to the uploads folder.
	 * @param list<string> $backups  The attachment's backups, relative to the uploads folder.
	 * @return list<string>|WP_Error The attachment's backups with this one, or the reason it couldn't be kept.
	 */
	public static function keep( string $path, string $relative, array $backups ) {
		$backup  = Paths::backup( $relative );
		$backups = self::retire( $path, $relative, $backups );

		if ( is_wp_error( $backups ) ) {
			return $backups;
		}

		self::guard();

		if ( ! is_file( $backup ) && ! Files::copy( $path, $backup ) ) {
			return new WP_Error( 'backup_failed', __( 'The backup of this image couldn\'t be saved, so it wasn\'t optimized. Check that WordPress can write to the uploads folder, or turn backups off in the Iminify settings.', 'iminify-image-optimizer' ) );
		}

		$kept = Paths::relative( $backup );

		return null === $kept || in_array( $kept, $backups, true ) ? $backups : array_merge( $backups, array( $kept ) );
	}

	/**
	 * Moves a backup that no longer holds the source's bytes aside, with backups
	 * on or off: left in place it would later pass for the original of the new
	 * picture, and a restore would put the old one over it.
	 *
	 * @param string       $path     The source.
	 * @param string       $relative The path it stands for, relative to the uploads folder.
	 * @param list<string> $backups  The attachment's backups, relative to the uploads folder.
	 * @return list<string>|WP_Error The attachment's backups, or the reason a stale one couldn't be moved aside.
	 */
	public static function retire( string $path, string $relative, array $backups ) {
		$backup = Paths::backup( $relative );

		if ( ! is_file( $backup ) || self::same( $path, $backup ) ) {
			return $backups;
		}

		$aside = self::aside( $backup );

		if ( null === $aside || ! Files::move( $backup, $aside ) ) {
			return new WP_Error( 'backup_stale', __( 'The image changed since its backup was made, and the old backup couldn\'t be moved aside, so it wasn\'t optimized.', 'iminify-image-optimizer' ) );
		}

		$backups[] = (string) Paths::relative( $aside );

		return $backups;
	}

	/**
	 * Deletes the backups an attachment's record names, all of them under the backups folder.
	 *
	 * @param list<string> $backups Backups relative to the uploads folder.
	 */
	public static function forget( array $backups ): void {
		$folder = Paths::BACKUP_FOLDER . '/';

		foreach ( $backups as $backup ) {
			if ( 0 === strpos( $backup, $folder ) && false === strpos( $backup, '..' ) ) {
				wp_delete_file( Paths::absolute( $backup ) );
			}
		}
	}

	/**
	 * Whether two files hold the same bytes.
	 *
	 * @param string $one A file.
	 * @param string $two Another.
	 */
	public static function same( string $one, string $two ): bool {
		clearstatcache( true, $one );
		clearstatcache( true, $two );

		return filesize( $one ) === filesize( $two ) && hash_file( 'sha256', $one ) === hash_file( 'sha256', $two );
	}

	/**
	 * A free name beside a backup to move it to: `photo.1730800000.jpg`.
	 *
	 * @param string $backup The backup.
	 */
	protected static function aside( string $backup ): ?string {
		$folder    = dirname( $backup );
		$name      = (string) pathinfo( $backup, PATHINFO_FILENAME );
		$extension = (string) pathinfo( $backup, PATHINFO_EXTENSION );

		for ( $suffix = time(), $tries = 0; $tries < 100; $suffix++, $tries++ ) {
			$aside = $folder . '/' . $name . '.' . $suffix . ( '' === $extension ? '' : '.' . $extension );

			if ( ! file_exists( $aside ) ) {
				return $aside;
			}
		}

		return null;
	}

	/**
	 * An empty index file in the backups folder, so a server that lists folders doesn't list it.
	 */
	protected static function guard(): void {
		$index = Paths::uploads() . '/' . Paths::BACKUP_FOLDER . '/index.php';

		if ( ! is_file( $index ) && wp_mkdir_p( dirname( $index ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- an empty index file, as WordPress puts in its own folders.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
