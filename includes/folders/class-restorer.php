<?php
/**
 * Puts a file in a chosen folder back as it was before the plugin optimized it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Files;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Reasons;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A file's backup goes back over it, and the plugin's copies beside it go. Only
 * a file that still has the bytes the plugin wrote is restored: one changed since
 * (a theme update) is someone else's picture now, and its backup the old one's.
 */
class Restorer {

	/**
	 * The plugin wrote nothing over the file and made no copy, so it already is as it was.
	 */
	const NOTHING = 'nothing';

	const AVAILABLE = 'available';

	/**
	 * The plugin wrote over the file, and no backup of it was kept.
	 */
	const NO_ORIGINAL = 'no_original';

	/**
	 * The file changed since the plugin wrote it, so its backup is no longer its original.
	 */
	const CHANGED = 'changed';

	/**
	 * The file is gone, or no longer in a chosen folder.
	 */
	const GONE = 'gone';

	/**
	 * Whether a file can be restored.
	 *
	 * @param array<string, mixed> $row The file's row.
	 * @return string One of the constants above.
	 */
	public static function availability( array $row ): string {
		$path    = (string) ( $row['path'] ?? '' );
		$written = isset( $row['written'] ) && is_array( $row['written'] ) ? $row['written'] : array();
		$copies  = isset( $row['copies'] ) && is_array( $row['copies'] ) ? $row['copies'] : array();

		if ( ! isset( $written[ $path ] ) && array() === Copies::own( $copies ) ) {
			return self::NOTHING;
		}

		$file = Folders::guard( $path );

		if ( null === $file ) {
			return self::GONE;
		}

		if ( ! isset( $written[ $path ] ) ) {
			return self::AVAILABLE;
		}

		clearstatcache( true, $file );

		if ( (int) filesize( $file ) !== (int) $written[ $path ] ) {
			return self::CHANGED;
		}

		return null === self::backup( $row ) ? self::NO_ORIGINAL : self::AVAILABLE;
	}

	/**
	 * Restores a file: its backup over it, the plugin's copies gone. Anything waiting to be sent for it is dropped.
	 *
	 * @param int $id The file's row id.
	 * @return true|WP_Error
	 */
	public static function restore( int $id ) {
		$row = Table::get( $id );

		if ( null === $row ) {
			return new WP_Error( 'not_found', __( 'That image isn\'t in the list.', 'iminify-image-optimizer' ), array( 'status' => 404 ) );
		}

		$availability = self::availability( $row );

		if ( self::AVAILABLE !== $availability ) {
			return self::refusal( $availability );
		}

		Optimizer::cancel( $id );

		$file    = (string) Folders::guard( $row['path'] );
		$written = $row['written'];

		if ( isset( $written[ $row['path'] ] ) ) {
			$backup = (string) self::backup( $row );

			if ( ! Files::copy( $backup, $file ) || ! Backups::same( $backup, $file ) ) {
				Optimizer::stop( $id, Records::ERROR, Reasons::of( 'restore_failed', 'restore_put_back' ) );

				return Reasons::error( 'restore_failed', 'restore_put_back', array(), array( 'status' => 500 ) );
			}

			unset( $written[ $row['path'] ] );
		}

		self::delete_copies( $row );
		clearstatcache( true, $file );

		Table::update(
			$id,
			array(
				'status'     => Records::RESTORED,
				'bytes'      => (int) filesize( $file ),
				'generation' => $row['generation'] + 1,
				'group'      => null,
				'error'      => null,
				'written'    => $written,
				'copies'     => array_diff_key( $row['copies'], Copies::own( $row['copies'] ) ),
				'pending'    => null,
				'result'     => null,
			)
		);

		/**
		 * A file in another folder was restored: its original is back, and the plugin's copies of it are gone.
		 *
		 * @param string $path The file's path relative to `wp-content`.
		 */
		do_action( 'iminify_file_restored', $row['path'] );

		return true;
	}

	/**
	 * Deletes the plugin's own copies of a file, each only while it has the bytes the plugin wrote.
	 *
	 * @param array<string, mixed> $row The file's row.
	 */
	public static function delete_copies( array $row ): void {
		foreach ( Copies::own( isset( $row['copies'] ) && is_array( $row['copies'] ) ? $row['copies'] : array() ) as $copy => $about ) {
			$copy = (string) $copy;

			/* A copy stands beside its file, inside `wp-content`, never anywhere a row could be made to point. */
			if ( null === Folders::relative( Folders::absolute( $copy ) ) || dirname( $copy ) !== dirname( (string) $row['path'] ) ) {
				continue;
			}

			$path = Folders::absolute( $copy );

			clearstatcache( true, $path );

			if ( ! is_link( $path ) && is_file( $path ) && (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * The file's backup, when its row names one that is there.
	 *
	 * @param array<string, mixed> $row The file's row.
	 */
	protected static function backup( array $row ): ?string {
		$backup = Paths::backup( (string) $row['path'], Paths::FOLDERS_BACKUP_FOLDER );
		$kept   = Paths::relative( $backup );

		return null !== $kept && in_array( $kept, (array) ( $row['backups'] ?? array() ), true ) && is_file( $backup ) && ! is_link( $backup ) ? $backup : null;
	}

	/**
	 * Why a file can't be restored.
	 *
	 * @param string $availability What availability() said.
	 */
	public static function refusal( string $availability ): WP_Error {
		switch ( $availability ) {
			case self::NOTHING:
				return Reasons::error( 'nothing_to_restore', 'nothing_to_restore_file', array(), array( 'status' => 409 ) );
			case self::CHANGED:
				return Reasons::error( 'image_changed', 'changed_restore_file', array(), array( 'status' => 409 ) );
			case self::GONE:
				return Reasons::error( 'file_missing', 'gone_restore_file', array(), array( 'status' => 409 ) );
			default:
				return Reasons::error( 'no_original', 'no_original_restore_file', array(), array( 'status' => 409 ) );
		}
	}
}
