<?php
/**
 * Puts an attachment back as it was before the plugin touched it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Switching\Others;
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
 *
 * With WP Offload Media's "Remove Local Media" on, an original only in its bucket
 * is brought back first, its uploads wait while WordPress makes the sizes, and
 * the restored files go up at the one save of the metadata, which takes them off
 * the disk again.
 *
 * An image another optimizer compressed (`Switching\Others`) is restored from
 * the original that plugin kept, when it passes that plugin's size check, the
 * same way: its backup is copied into place, never moved, so that plugin's own
 * restore still finds it; WordPress makes the sizes again over its outputs under
 * their names; and a size WordPress doesn't make any more is put back from that
 * plugin's backup of it, where it kept one. Its copies stay where they are.
 * Where restoring is that plugin's own to do, it says why instead,
 * and keeps saying it once the plugin optimized such a converted image again from
 * the JPEG or PNG that plugin kept (`Originals::converted()`): that original is in
 * another format than the files, and is never put over them.
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
	 * Another optimizer compressed the image, and restoring it is that plugin's own to do.
	 */
	const THEIRS = 'theirs';

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

		/* An image another optimizer converted keeps its format and names: its original, in another format, never goes over its files, before the plugin optimized it again or after. */
		if ( null !== Originals::converted( $attachment ) ) {
			return self::THEIRS;
		}

		/* Another plugin's output is no file the plugin wrote, so it is asked first: such an image is never "nothing to restore". */
		if ( null !== Others::verdict( $attachment ) ) {
			if ( '' !== Others::elsewhere( $attachment ) ) {
				return self::THEIRS;
			}

			return null === Originals::find( $attachment, $metadata, null ) ? self::NO_ORIGINAL : self::AVAILABLE;
		}

		if ( array() === array_intersect_key( $record['written'], array_flip( self::files_of( $attached, $metadata ) ) ) && array() === Copies::own( $record['copies'] ) ) {
			return self::NOTHING;
		}

		$main = Paths::relative( $attached );

		/* A main file only in WP Offload Media's bucket that the metadata doesn't measure is asked once a restore brings it back. */
		if ( null !== $main && isset( $record['written'][ $main ] ) && ! Records::wrote( $attachment, $attached ) && ! Offload_Media::unmeasured( $attachment, $attached ) ) {
			return self::CHANGED;
		}

		return null === Originals::find( $attachment, $metadata, null ) ? self::NO_ORIGINAL : self::AVAILABLE;
	}

	/**
	 * Restores an attachment: its original back, the sizes made again by WordPress,
	 * the plugin's copies gone. Anything waiting to be sent for it is dropped. An
	 * original brought back from WP Offload Media's bucket for it goes again
	 * unless the restore put it in place.
	 *
	 * @param int $attachment The attachment's id.
	 * @return true|WP_Error
	 */
	public static function restore( int $attachment ) {
		/* What another optimizer left is read afresh: a restore writes over files on its word. */
		Others::trace( $attachment, true );

		try {
			return self::put_back( $attachment );
		} finally {
			Offload_Media::put_away( $attachment );
			Others::drop( $attachment );
		}
	}

	/**
	 * Puts the attachment's original back.
	 *
	 * @param int $attachment The attachment's id.
	 * @return true|WP_Error
	 */
	protected static function put_back( int $attachment ) {
		$availability = self::availability( $attachment );

		if ( self::AVAILABLE !== $availability ) {
			return self::refusal( $availability, $attachment );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$theirs = self::theirs( $attachment );

		$metadata = wp_get_attachment_metadata( $attachment, true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$attached = (string) get_attached_file( $attachment, true );
		/* An original only in WP Offload Media's bucket is brought back, then asked again of its own bytes. */
		$source = Originals::fetch( $attachment, $metadata, null );

		if ( $source instanceof WP_Error ) {
			return new WP_Error( 'restore_failed', __( 'The original couldn\'t be copied back from WP Offload Media\'s bucket, so the image stays as it was. Try again.', 'iminify-image-optimizer' ), array( 'status' => 503 ) );
		}

		if ( null === $source ) {
			return self::refusal( self::NO_ORIGINAL, $attachment );
		}

		/* A main file brought back to find the original can turn out to be something else's, which is never written over. */
		if ( self::CHANGED === self::availability( $attachment ) ) {
			return self::refusal( self::CHANGED, $attachment );
		}

		Optimizer::cancel( $attachment );
		Records::bump( $attachment );

		$target = Paths::absolute( $source['file'] );

		/* Another plugin's backup is copied, never moved: its own restore still needs it where it is. */
		if ( in_array( $source['kind'], array( Originals::BACKUP, Originals::OTHER ), true ) && ( ! Files::copy( $source['path'], $target ) || ! Backups::same( $source['path'], $target ) ) ) {
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

		/* A size keeps its name over another plugin's output as over the plugin's own. */
		$fresh  = self::keep_size_names( $metadata, $fresh, $written + $theirs['outputs'], false === $existing ? array() : array_map( 'wp_normalize_path', $existing ), dirname( $target ) );
		$merged = self::put_back_theirs( $attachment, self::merged( $metadata, $fresh ), $theirs );

		self::save_metadata( $attachment, $merged );

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
				'pending' => null,
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
		Offload_Media::hold( $attachment );

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
			Offload_Media::release();
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

			$fresh['sizes'][ $name ]['file'] = wp_basename( (string) $was['file'] );

			/* A file left only in WP Offload Media's bucket keeps the size the metadata gave it. */
			if ( is_file( $kept ) ) {
				$fresh['sizes'][ $name ]['filesize'] = (int) filesize( $kept );
			} elseif ( isset( $was['filesize'] ) ) {
				$fresh['sizes'][ $name ]['filesize'] = (int) $was['filesize'];
			} else {
				unset( $fresh['sizes'][ $name ]['filesize'] );
			}
		}

		return $fresh;
	}

	/**
	 * The new metadata over the old, keeping every key WordPress didn't make again,
	 * at the top and in each size, as other plugins keep their own there. A size
	 * WordPress didn't make keeps its entry too, since its file is still there: the
	 * site stopped making it, or the image editor failed.
	 *
	 * @param array<string, mixed> $old   The metadata before the restore.
	 * @param array<string, mixed> $fresh What WordPress made.
	 * @return array<string, mixed>
	 */
	protected static function merged( array $old, array $fresh ): array {
		$sizes = isset( $old['sizes'] ) && is_array( $old['sizes'] ) ? $old['sizes'] : array();

		foreach ( isset( $fresh['sizes'] ) && is_array( $fresh['sizes'] ) ? $fresh['sizes'] : array() as $name => $size ) {
			$kept           = isset( $sizes[ $name ] ) && is_array( $sizes[ $name ] ) ? $sizes[ $name ] : array();
			$sizes[ $name ] = array_merge( $kept, (array) $size );
		}

		$fresh['sizes'] = $sizes;

		return array_merge( $old, $fresh );
	}

	/**
	 * What another optimizer left of an attachment that its restore uses: each
	 * output's bytes and the backups it kept, verified by its size check, or
	 * nothing for an image no other plugin optimized.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{outputs: array<string, int>, backups: array<string, string>}
	 */
	protected static function theirs( int $attachment ): array {
		$verdict = Others::verdict( $attachment );
		$trace   = null === $verdict ? null : Others::trace( $attachment );

		return array(
			'outputs' => null === $verdict ? array() : $verdict['outputs'],
			'backups' => null === $trace ? array() : $trace['backups'],
		);
	}

	/**
	 * Puts back from another optimizer's backups the sizes WordPress didn't make
	 * again (a size the site stopped making), each only while its file still has
	 * that plugin's output and the copy comes out whole. The sizes WordPress made
	 * again came from the original already.
	 *
	 * @param int                                                                  $attachment The attachment's id.
	 * @param array<string, mixed>                                                 $metadata   The metadata about to be saved.
	 * @param array{outputs: array<string, int>, backups: array<string, string>} $theirs     What `theirs()` answered.
	 * @return array<string, mixed>
	 */
	protected static function put_back_theirs( int $attachment, array $metadata, array $theirs ): array {
		if ( array() === $theirs['backups'] ) {
			return $metadata;
		}

		$folder = dirname( (string) get_attached_file( $attachment, true ) );

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $name => $size ) {
			$relative = is_array( $size ) && isset( $size['file'] ) ? Paths::relative( $folder . '/' . wp_basename( (string) $size['file'] ) ) : null;

			if ( null === $relative || ! isset( $theirs['backups'][ $relative ], $theirs['outputs'][ $relative ] ) ) {
				continue;
			}

			$path = Paths::absolute( $relative );

			clearstatcache( true, $path );

			if ( ! is_file( $path ) || (int) filesize( $path ) !== $theirs['outputs'][ $relative ] ) {
				continue;
			}

			/* Copied, never moved: that plugin's own restore still needs its backup where it is. */
			if ( Files::copy( $theirs['backups'][ $relative ], $path ) && Backups::same( $theirs['backups'][ $relative ], $path ) ) {
				clearstatcache( true, $path );
				$metadata['sizes'][ $name ]['filesize'] = (int) filesize( $path );
			}
		}

		return $metadata;
	}

	/**
	 * Saves the restored metadata, under the writer's guard, so the plugin's own
	 * hooks ignore it.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   The metadata to save.
	 */
	protected static function save_metadata( int $attachment, array $metadata ): void {
		Writer::$writing = $attachment;

		try {
			/* WP Offload Media's upload at this save measures the original through paths in its bucket. */
			Offload_Media::locally(
				$attachment,
				static function ( int $id ) use ( $metadata ) {
					return wp_update_attachment_metadata( $id, $metadata );
				}
			);
		} finally {
			Writer::$writing = null;
		}
	}

	/**
	 * The refusal for an attachment that can't be restored.
	 *
	 * @param string $availability Why not.
	 * @param int    $attachment   The attachment's id.
	 */
	protected static function refusal( string $availability, int $attachment ): WP_Error {
		/* Said from the plugin's own record too once it optimized a converted image again, which no reader claims any more. */
		if ( self::THEIRS === $availability ) {
			return new WP_Error( 'restore_elsewhere', Others::elsewhere( $attachment ), array( 'status' => 409 ) );
		}

		$verdict = self::NO_ORIGINAL === $availability ? Others::verdict( $attachment ) : null;

		if ( null !== $verdict ) {
			return new WP_Error(
				'no_original',
				sprintf(
					/* translators: %s: another image optimizer's name, such as Imagify. */
					__( '%s kept no original of this image that Iminify can check, so it can\'t be restored here.', 'iminify-image-optimizer' ),
					Others::name( $verdict['plugin'] )
				),
				array( 'status' => 409 )
			);
		}

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
