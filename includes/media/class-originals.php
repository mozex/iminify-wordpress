<?php
/**
 * Finds the untouched image an attachment's outputs are made from.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one lookup every caller asks for an attachment's original (an upload, an
 * edit, regenerated sizes, optimizing again): a file, or none, and on none
 * nothing is sent, so the plugin never compresses its own output a second time.
 *
 * It looks first at the original WordPress keeps when it scaled or turned the
 * upload (`original_image`), then at the attached file. A file the plugin wrote
 * over is never an original; the backup the plugin kept of it is. A file it
 * didn't write is the original as it stands, so an edited image (core names it
 * `photo-e1699999999999.jpg` and keeps the old `original_image`) is sent as
 * edited, and the pre-edit original is never sent in its place. An original
 * something deleted since has only its backup. An attached file in a format the
 * service doesn't take (an AVIF, an SVG) has none: it is kept as it was uploaded.
 */
class Originals {

	const BACKUP = 'backup';

	const ORIGINAL = 'original';

	const ATTACHED = 'attached';

	/**
	 * The file an attachment's outputs are made from, or null when it has none.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @param int|null             $max_bytes  The largest source the plan takes, or null when the service didn't say.
	 * @return array{path: string, kind: string, file: string}|null The file, where it came from, and the path it stands for, relative to the uploads folder.
	 */
	public static function find( int $attachment, array $metadata, ?int $max_bytes ): ?array {
		$attached = get_attached_file( $attachment, true );

		/* A translation's files are its holder's, whose record alone knows which of them the plugin wrote. */
		if ( ! is_string( $attached ) || '' === $attached || Shared::holder( $attachment ) !== $attachment ) {
			return null;
		}

		$candidates = array();
		$original   = self::original_image( $attached, $metadata );

		if ( null !== $original ) {
			$candidates[ self::ORIGINAL ] = $original;
		}

		$candidates[ self::ATTACHED ] = $attached;
		$type                         = is_file( $attached ) ? wp_get_image_mime( $attached ) : false;

		if ( ! is_string( $type ) || ! isset( Declaration::FORMATS[ $type ] ) ) {
			return null;
		}

		foreach ( $candidates as $kind => $path ) {
			$relative = Paths::relative( $path );

			if ( null === $relative ) {
				continue;
			}

			$gone = ! is_file( $path );

			/* The service makes every output in its source's format, so a source in another one (a HEIC WordPress turned into a JPEG) would be written over the JPEGs. */
			if ( ! $gone && wp_get_image_mime( $path ) !== $type ) {
				continue;
			}

			if ( $gone || Records::wrote( $attachment, $path ) ) {
				$backup = Paths::backup( $relative );

				if ( is_file( $backup ) && wp_get_image_mime( $backup ) === $type && self::fits( $backup, $max_bytes ) ) {
					return array(
						'path' => $backup,
						'kind' => self::BACKUP,
						'file' => $relative,
					);
				}

				continue;
			}

			/* A backup of this file that another attachment's record keeps means the file is that one's output (a translation left on its holder's old file when the holder was edited), never an original. */
			if ( self::backed_up_elsewhere( $attachment, $relative, $path ) ) {
				continue;
			}

			if ( self::fits( $path, $max_bytes ) ) {
				return array(
					'path' => $path,
					'kind' => $kind,
					'file' => $relative,
				);
			}
		}

		return null;
	}

	/**
	 * The original WordPress keeps when the attached file is its `-scaled` or
	 * `-rotated` copy, or null: once an edit names another file, that original
	 * is no longer the picture the attachment shows.
	 *
	 * @param string               $attached The attached file's path.
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 */
	protected static function original_image( string $attached, array $metadata ): ?string {
		$original = isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ? wp_basename( $metadata['original_image'] ) : '';

		if ( '' === $original ) {
			return null;
		}

		$name = pathinfo( $original, PATHINFO_FILENAME );
		$own  = pathinfo( $attached, PATHINFO_FILENAME );

		return in_array( $own, array( $name . '-scaled', $name . '-rotated' ), true ) ? dirname( $attached ) . '/' . $original : null;
	}

	/**
	 * Whether a backup of this file exists that the attachment's own record doesn't
	 * name, and that differs from the file: another attachment kept it of the file
	 * it wrote here.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $relative   The file, relative to the uploads folder.
	 * @param string $path       The file.
	 */
	protected static function backed_up_elsewhere( int $attachment, string $relative, string $path ): bool {
		$backup = Paths::backup( $relative );

		if ( ! is_file( $backup ) || in_array( Paths::relative( $backup ), Records::get( $attachment )['backups'], true ) ) {
			return false;
		}

		return ! Backups::same( $path, $backup );
	}

	/**
	 * Whether a file is within the plan's file size.
	 *
	 * @param string   $path      The file.
	 * @param int|null $max_bytes The plan's file size, or null for no check.
	 */
	protected static function fits( string $path, ?int $max_bytes ): bool {
		return null === $max_bytes || (int) filesize( $path ) <= $max_bytes;
	}
}
