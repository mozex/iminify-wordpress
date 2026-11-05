<?php
/**
 * Reads files out of a group's zip.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the entries the group's answer named, by their exact names, one
 * at a time: a name inside the zip is never used as a path, so nothing in it
 * can write anywhere. It needs PHP's zip extension (ZipArchive), which nearly
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
			return new WP_Error( 'zip_unavailable', __( 'This server\'s PHP can\'t open zip files: ask your host to turn on PHP\'s zip extension.', 'iminify-image-optimizer' ) );
		}

		$archive = new \ZipArchive();

		/* No flags: ZipArchive::RDONLY needs PHP 7.4.3 and a recent libzip, and an archive opened this way is never written unless it is changed. */
		if ( true !== $archive->open( $zip ) ) {
			return new WP_Error( 'zip_unreadable', __( 'Iminify\'s download couldn\'t be opened.', 'iminify-image-optimizer' ) );
		}

		return $archive;
	}

	/**
	 * One entry's bytes, or null when the zip has no entry of that exact name or it is too large.
	 *
	 * @param \ZipArchive $archive The open zip.
	 * @param string      $name    The entry's name.
	 */
	public static function read( \ZipArchive $archive, string $name ): ?string {
		$stat = $archive->statName( $name );

		if ( false === $stat || $stat['size'] > self::MAX_ENTRY_BYTES ) {
			return null;
		}

		$bytes = $archive->getFromName( $name );

		return is_string( $bytes ) ? $bytes : null;
	}
}
