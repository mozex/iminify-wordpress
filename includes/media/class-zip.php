<?php
/**
 * Reads files out of a group's zip.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Reasons;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the entries the group's answer named, by their exact names, one
 * at a time and each straight into a file: a name inside the zip is never used
 * as a path, so nothing in it can write anywhere. It needs PHP's zip extension (ZipArchive), which nearly
 * every host has; WordPress's PclZip extracts only by the names inside the zip.
 */
class Zip {

	/**
	 * The largest file read out of a zip: past the service's own file size.
	 */
	const MAX_ENTRY_BYTES = 104857600;

	/**
	 * Whether this server's PHP can open a zip, asked before a group is opened so a host without it spends nothing.
	 */
	public static function available(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Opens a zip to read.
	 *
	 * @param string $zip The zip file.
	 * @return \ZipArchive|WP_Error
	 */
	public static function open( string $zip ) {
		if ( ! self::available() ) {
			return Reasons::error( 'zip_unavailable', 'zip_extension' );
		}

		$archive = new \ZipArchive();

		/* No flags: ZipArchive::RDONLY needs PHP 7.4.3 and a recent libzip, and an archive opened this way is never written unless it is changed. */
		if ( true !== $archive->open( $zip ) ) {
			return Reasons::error( 'zip_unreadable' );
		}

		return $archive;
	}

	/**
	 * An entry's size as the zip's directory states it, or null when the zip has no
	 * entry of that exact name or it is too large.
	 *
	 * @param \ZipArchive $archive The open zip.
	 * @param string      $name    The entry's name.
	 */
	public static function size( \ZipArchive $archive, string $name ): ?int {
		$stat = $archive->statName( $name );

		return false === $stat || $stat['size'] > self::MAX_ENTRY_BYTES ? null : (int) $stat['size'];
	}

	/**
	 * Copies one entry into a file a piece at a time, so a large image never sits in
	 * memory, and answers the bytes written, or null when the entry is missing, too
	 * large, or not the size the zip's directory states.
	 *
	 * @param \ZipArchive $archive The open zip.
	 * @param string      $name    The entry's name.
	 * @param string      $to      The file to write.
	 */
	public static function extract( \ZipArchive $archive, string $name, string $to ): ?int {
		$size = self::size( $archive, $name );
		$from = null === $size ? false : $archive->getStream( $name );

		if ( false === $from ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writes the temporary file a rename then puts in place.
		$into = fopen( $to, 'wb' );

		/* The directory's size is the zip's own claim, so the copy stops one byte past the most it takes. */
		$copied = false === $into ? false : stream_copy_to_stream( $from, $into, self::MAX_ENTRY_BYTES + 1 );

		fclose( $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the zip entry's stream.

		if ( false !== $into ) {
			fclose( $into ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the temporary file.
		}

		return false === $copied || $copied !== $size ? null : $copied;
	}
}
