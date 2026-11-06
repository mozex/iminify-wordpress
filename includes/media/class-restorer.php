<?php
/**
 * Puts an attachment back as it was before the plugin touched it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs here alone, with no call to Iminify, so it works disconnected and with
 * the day spent (directory guideline 5). The original comes from the same
 * lookup a group's source does (`Originals::find()`), and only the source is
 * kept, so WordPress makes the sizes again under the names posts already link to.
 *
 * Nothing goes missing on the way: the main file is swapped in one step, and a
 * size stays as the plugin wrote it until WordPress has made its new one.
 */
class Restorer {

	/**
	 * The plugin changed nothing here.
	 */
	const NOTHING = 'nothing';

	const AVAILABLE = 'available';

	/**
	 * The plugin wrote over the main file and no original is left: backups were off, or the backup is gone.
	 */
	const NO_ORIGINAL = 'no_original';

	/**
	 * Something else replaced the file the plugin wrote, so the file there now isn't the plugin's to overwrite.
	 */
	const CHANGED = 'changed';

	/**
	 * Whether an attachment can be restored. Only the files it has now count: an
	 * older edit's files stay in the record, so they are never taken for originals,
	 * but there is nothing to put back for them.
	 *
	 * @param int $attachment The attachment's id.
	 * @return string One of the constants above.
	 */
	public static function availability( int $attachment ): string {
		$record   = Records::get( $attachment );
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$attached = (string) get_attached_file( $attachment, true );

		if ( array() === array_intersect_key( $record['written'], array_flip( self::files_of( $attached, $metadata ) ) ) && array() === Copies::own( $record['copies'] ) ) {
			return self::NOTHING;
		}

		$main = Paths::relative( $attached );

		if ( null !== $main && isset( $record['written'][ $main ] ) && ! Records::wrote( $attachment, $attached ) ) {
			return self::CHANGED;
		}

		return null === Originals::find( $attachment, $metadata, null ) ? self::NO_ORIGINAL : self::AVAILABLE;
	}

	/**
	 * Restores an attachment: its original back, the sizes made again by WordPress,
	 * the plugin's copies gone. Anything waiting to be sent for it is dropped.
	 *
	 * @param int $attachment The attachment's id.
	 * @return true|WP_Error
	 */
	public static function restore( int $attachment ) {
		$availability = self::availability( $attachment );

		if ( self::AVAILABLE !== $availability ) {
			return self::refusal( $availability );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$metadata = wp_get_attachment_metadata( $attachment, true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$attached = (string) get_attached_file( $attachment, true );
		$source   = Originals::find( $attachment, $metadata, null );

		if ( null === $source ) {
			return self::refusal( self::NO_ORIGINAL );
		}

		Optimizer::cancel( $attachment );
		Records::bump( $attachment );

		$target = Paths::absolute( $source['file'] );

		if ( Originals::BACKUP === $source['kind'] && ( ! Files::copy( $source['path'], $target ) || ! Backups::same( $source['path'], $target ) ) ) {
			Records::fail( $attachment, Records::ERROR, 'restore_failed', __( 'The original couldn\'t be put back, so the image stays as it was.', 'iminify-image-optimizer' ) );

			return self::failed();
		}

		$written = Records::get( $attachment )['written'];
		unset( $written[ $source['file'] ] );
		Records::update( $attachment, array( 'written' => $written ) );

		/* The copies were made from the files going now, and go first: an offload plugin uploads every file of the attachment on the disk at each save of its metadata that follows. */
		Copies::delete_own( $attachment );

		/* What was there before WordPress starts, so a file it makes can be told from one it only wrote over. */
		$existing = glob( dirname( $target ) . '/' . pathinfo( $target, PATHINFO_FILENAME ) . '-*' );
		$fresh    = self::make_sizes( $attachment, $target, $attached );

		if ( null === $fresh ) {
			Records::fail( $attachment, Records::ERROR, 'restore_failed', __( 'WordPress couldn\'t read the original, so the image\'s sizes stay as they were. Try again.', 'iminify-image-optimizer' ) );

			/** This action is documented in includes/admin/class-media-controller.php */
			do_action( 'iminify_copies_deleted', $attachment );

			return self::failed();
		}

		$fresh = self::keep_size_names( $metadata, $fresh, $written, false === $existing ? array() : array_map( 'wp_normalize_path', $existing ), dirname( $target ) );

		self::save_metadata( $attachment, $metadata, $fresh );

		/* A bulk run looking for what images lack could take this one while its copies were gone and its sizes were being made. */
		Optimizer::cancel( $attachment );

		Records::update(
			$attachment,
			array(
				'fill'    => null,
				'status'  => Records::NOT_OPTIMIZED,
				'error'   => array(
					'code'    => 'restored',
					'message' => __( 'Restored: the original is back.', 'iminify-image-optimizer' ),
				),
				'group'   => null,
				/* Only what this restore replaced: a file it didn't touch is still the plugin's output, and must never pass for an original. */
				'written' => array_diff_key( $written, array_flip( self::files_of( (string) get_attached_file( $attachment, true ), $fresh ) ) ),
				'result'  => null,
			)
		);

		/**
		 * An image's original is back, its sizes made again and its WebP and AVIF copies gone.
		 *
		 * @param int $attachment The attachment's id.
		 */
		do_action( 'iminify_image_restored', $attachment );

		return true;
	}

	/**
	 * The files an attachment's metadata names, relative to the uploads folder.
	 *
	 * @param string               $attached The attached file.
	 * @param array<string, mixed> $metadata Its metadata.
	 * @return list<string>
	 */
	protected static function files_of( string $attached, array $metadata ): array {
		$files = array( Paths::relative( $attached ) );

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) ) {
				$files[] = Paths::relative( dirname( $attached ) . '/' . wp_basename( (string) $size['file'] ) );
			}
		}

		return array_values( array_filter( $files, 'is_string' ) );
	}

	/**
	 * Makes the sizes again from the file WordPress should treat as the upload,
	 * which also makes `-scaled` or `-rotated` again where it applies. The plugin's
	 * own hooks ignore every save this makes.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $file       The file.
	 * @param string $attached   The attached file before this restore.
	 * @return array<string, mixed>|null The new metadata, or null when WordPress couldn't read the file.
	 */
	protected static function make_sizes( int $attachment, string $file, string $attached ): ?array {
		Writer::$writing = $attachment;

		try {
			/* WordPress changes the attached file only when it scales or turns the image, so a file it leaves as it is has to be the attached one already. */
			update_attached_file( $attachment, $file );

			$fresh = wp_generate_attachment_metadata( $attachment, $file );

			if ( empty( $fresh['file'] ) ) {
				update_attached_file( $attachment, $attached );

				return null;
			}

			return $fresh;
		} finally {
			Writer::$writing = null;
		}
	}

	/**
	 * Posts link to a size by its file name, so a size keeps the name it had.
	 * WordPress names sizes after the file it was given, which isn't the name they
	 * had when that file is a `-scaled` or `-rotated` copy (an iPhone upload, or an
	 * original deleted since). A file WordPress just made goes over the plugin's
	 * output under the old name, or is deleted where the old file was never the
	 * plugin's, as a thumbnail someone cropped by hand. A file that was already there
	 * stays, since core's own "Restore image" may need it.
	 *
	 * @param array<string, mixed> $old      The metadata before the restore.
	 * @param array<string, mixed> $fresh    What WordPress made.
	 * @param array<string, int>   $written  The files the plugin wrote.
	 * @param list<string>         $existing The files like these that were there before.
	 * @param string               $folder   The attachment's folder.
	 * @return array<string, mixed>
	 */
	protected static function keep_size_names( array $old, array $fresh, array $written, array $existing, string $folder ): array {
		foreach ( isset( $fresh['sizes'] ) && is_array( $fresh['sizes'] ) ? $fresh['sizes'] : array() as $name => $size ) {
			$was = $old['sizes'][ $name ] ?? null;

			if ( ! is_array( $was ) || ! isset( $was['file'], $was['width'], $was['height'] ) || wp_basename( (string) $was['file'] ) === $size['file'] || (int) $was['width'] !== (int) $size['width'] || (int) $was['height'] !== (int) $size['height'] ) {
				continue;
			}

			$made = $folder . '/' . $size['file'];
			$kept = $folder . '/' . wp_basename( (string) $was['file'] );

			if ( ! in_array( $made, $existing, true ) ) {
				if ( ! isset( $written[ (string) Paths::relative( $kept ) ] ) ) {
					wp_delete_file( $made );
				} elseif ( ! Files::move( $made, $kept ) ) {
					continue;
				}
			}

			clearstatcache( true, $kept );

			$fresh['sizes'][ $name ]['file']     = wp_basename( (string) $was['file'] );
			$fresh['sizes'][ $name ]['filesize'] = (int) filesize( $kept );
		}

		return $fresh;
	}

	/**
	 * Saves the new metadata over the old, keeping every key WordPress didn't make
	 * again, at the top and in each size, as other plugins keep their own there. A
	 * size WordPress didn't make keeps its entry too, since its file is still there:
	 * the site stopped making it, or the image editor failed.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $old        The metadata before the restore.
	 * @param array<string, mixed> $fresh      What WordPress made.
	 */
	protected static function save_metadata( int $attachment, array $old, array $fresh ): void {
		$sizes = isset( $old['sizes'] ) && is_array( $old['sizes'] ) ? $old['sizes'] : array();

		foreach ( isset( $fresh['sizes'] ) && is_array( $fresh['sizes'] ) ? $fresh['sizes'] : array() as $name => $size ) {
			$kept           = isset( $sizes[ $name ] ) && is_array( $sizes[ $name ] ) ? $sizes[ $name ] : array();
			$sizes[ $name ] = array_merge( $kept, (array) $size );
		}

		$fresh['sizes'] = $sizes;

		Writer::$writing = $attachment;

		try {
			wp_update_attachment_metadata( $attachment, array_merge( $old, $fresh ) );
		} finally {
			Writer::$writing = null;
		}
	}

	/**
	 * The refusal for an attachment that can't be restored.
	 *
	 * @param string $availability Why not.
	 */
	protected static function refusal( string $availability ): WP_Error {
		switch ( $availability ) {
			case self::NOTHING:
				return new WP_Error( 'nothing_to_restore', __( 'Iminify hasn\'t changed this image, so there\'s nothing to restore.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
			case self::CHANGED:
				return new WP_Error( 'image_changed', __( 'This image has changed since Iminify optimized it, so restoring it would overwrite the new one.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
			default:
				return new WP_Error( 'no_original', __( 'No original of this image was kept, so it can\'t be restored. Backups were off when it was optimized, or the backup was deleted.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}
	}

	/**
	 * The refusal for a restore that failed on the way.
	 */
	protected static function failed(): WP_Error {
		return new WP_Error( 'restore_failed', __( 'The image couldn\'t be restored.', 'iminify-image-optimizer' ), array( 'status' => 500 ) );
	}
}
