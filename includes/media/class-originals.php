<?php
/**
 * Finds the untouched image an attachment's outputs are made from.
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
 * something deleted since has only its backup. A file another optimizer
 * compressed in place is never an original either: only the backup that plugin
 * kept of it, where that backup passes its size check, and otherwise nothing. An attached file in a format the
 * service doesn't take (an AVIF, an SVG) has none: it is kept as it was uploaded.
 *
 * A file only in WP Offload Media's bucket is answered from what the metadata
 * says of it, which a send or a restore brings back and asks again of the file
 * itself (`Compat\Offload_Media::bring_back()`), so only the disk's bytes ever
 * decide that a file the plugin wrote is an original.
 */
class Originals {

	const BACKUP = 'backup';

	const ORIGINAL = 'original';

	const ATTACHED = 'attached';

	/**
	 * The backup another plugin kept of a file it compressed (`Switching\Others`).
	 */
	const OTHER = 'other';

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
		$type                         = self::type( $attachment, $attached );

		if ( ! is_string( $type ) || ! isset( Declaration::FORMATS[ $type ] ) ) {
			return null;
		}

		foreach ( $candidates as $kind => $path ) {
			$relative = Paths::relative( $path );

			if ( null === $relative ) {
				continue;
			}

			$here = is_file( $path );
			$gone = ! $here && ! Offload_Media::only_in_bucket( $attachment, $path );

			/* The service makes every output in its source's format, so a source in another one (a HEIC WordPress turned into a JPEG) would be written over the JPEGs. One only in the bucket is read by its name. */
			if ( $here ? wp_get_image_mime( $path ) !== $type : ( ! $gone && wp_check_filetype( $path )['type'] !== $type ) ) {
				continue;
			}

			if ( $gone || Records::wrote( $attachment, $path ) ) {
				$backup = Paths::backup( $relative );

				if ( is_file( $backup ) && wp_get_image_mime( $backup ) === $type && self::fits( $attachment, $backup, $max_bytes ) ) {
					return array(
						'path' => $backup,
						'kind' => self::BACKUP,
						'file' => $relative,
					);
				}

				continue;
			}

			/* A file another plugin compressed is never an original; the backup that plugin kept of it is, once it passes that plugin's size check (`Switching\Others`). */
			$theirs = Others::output( $attachment, $relative );

			if ( null !== $theirs ) {
				if ( null !== $theirs['backup'] && wp_get_image_mime( $theirs['backup'] ) === $type && self::fits( $attachment, $theirs['backup'], $max_bytes ) ) {
					return array(
						'path' => $theirs['backup'],
						'kind' => self::OTHER,
						'file' => $relative,
					);
				}

				continue;
			}

			/* A backup of this file that another attachment's record keeps means the file is that one's output (a translation left on its holder's old file when the holder was edited), never an original. A file only in WP Offload Media's bucket is asked once it is brought back. */
			if ( $here && self::backed_up_elsewhere( $attachment, $relative, $path ) ) {
				continue;
			}

			if ( self::fits( $attachment, $path, $max_bytes ) ) {
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
	 * The original as `find()` answers it, on the disk: a file only in WP Offload
	 * Media's bucket is brought back and asked again of its own bytes, which may
	 * pass it over for the next one (an original past the plan's file size gives
	 * way to WordPress's `-scaled` copy), brought back in its turn.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @param int|null             $max_bytes  The largest source the plan takes, or null when the service didn't say.
	 * @return array{path: string, kind: string, file: string}|WP_Error|null The original, the bucket's refusal, or null when there is none.
	 */
	public static function fetch( int $attachment, array $metadata, ?int $max_bytes ) {
		/* Two files can be brought back, the original WordPress kept and the attached file, so the third look only reads. */
		for ( $look = 0; $look < 3; $look++ ) {
			$source = self::find( $attachment, $metadata, $max_bytes );

			if ( null === $source || is_file( $source['path'] ) ) {
				return $source;
			}

			if ( ! Offload_Media::bring_back( $attachment, $source['path'] ) ) {
				return Offload_Media::unreachable();
			}

			/* What another optimizer left is read again with the file on the disk, where its bytes can be measured. */
			Others::trace( $attachment, true );
		}

		return null;
	}

	/**
	 * The file WordPress makes an attachment's sizes from, on the disk: the
	 * original it keeps beside a `-scaled` or `-rotated` copy, else the attached
	 * file. Core's `wp_get_original_image_path()` passes its answer through a
	 * filter even when asked for the unfiltered path, and WP Offload Media answers
	 * a file only in its bucket there with its address.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function kept( int $attachment ): string {
		$attached = (string) get_attached_file( $attachment, true );
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$original = is_array( $metadata ) && isset( $metadata['original_image'] ) ? wp_basename( (string) $metadata['original_image'] ) : '';

		return '' === $attached || '' === $original ? $attached : dirname( $attached ) . '/' . $original;
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
	 * Whether a file is within the plan's file size. A file only in WP Offload
	 * Media's bucket whose size the metadata doesn't give is measured once it is
	 * brought back.
	 *
	 * @param int      $attachment The attachment's id.
	 * @param string   $path       The file.
	 * @param int|null $max_bytes  The plan's file size, or null for no check.
	 */
	protected static function fits( int $attachment, string $path, ?int $max_bytes ): bool {
		return null === $max_bytes || (int) Offload_Media::bytes( $attachment, $path ) <= $max_bytes;
	}

	/**
	 * The MIME type of the attached file, read from it, or from its name while it
	 * is only in WP Offload Media's bucket, or false.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $attached   The attached file.
	 * @return string|false
	 */
	public static function type( int $attachment, string $attached ) {
		if ( is_file( $attached ) ) {
			return wp_get_image_mime( $attached );
		}

		return Offload_Media::only_in_bucket( $attachment, $attached ) ? wp_check_filetype( $attached )['type'] : false;
	}
}
