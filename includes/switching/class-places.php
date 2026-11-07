<?php
/**
 * The only places a path taken from another plugin's records may point.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Another plugin's records are data anyone with the database could have
 * written, so a path read from them is used only inside the uploads folder (and
 * on a site of a network, the network's, which holds it),
 * EWWW's backup folder (`wp-content/ewww/image-backup`) and Converter for
 * Media's copies (`wp-content/uploads-webpc`), and only when the file really is
 * there, with no link on the way out of the place. The plugin only reads and
 * copies what is found there, never moves, renames or deletes it.
 */
class Places {

	/**
	 * The folders under `wp-content` beside the uploads folder that another plugin keeps its files in.
	 */
	const CONTENT_FOLDERS = array( 'ewww/image-backup', 'uploads-webpc' );

	/**
	 * A file's absolute path when it is a file inside one of the places, reached
	 * with no link on the way, or null.
	 *
	 * @param string $path An absolute path, as another plugin's record gives it.
	 */
	public static function file( string $path ): ?string {
		$path = wp_normalize_path( $path );

		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( '/' . $path . '/', '/../' ) || false !== strpos( '/' . $path . '/', '/./' ) ) {
			return null;
		}

		foreach ( self::roots() as $root ) {
			if ( 0 !== strpos( $path, $root . '/' ) ) {
				continue;
			}

			$relative = (string) substr( $path, strlen( $root ) + 1 );
			$real     = realpath( $path );
			$base     = realpath( $root );

			if ( '' === $relative || is_link( $path ) || ! is_file( $path ) || false === $real || false === $base ) {
				return null;
			}

			/* The place itself may be a link (a host that keeps uploads elsewhere), so the file is compared with the place's own real path. */
			return wp_normalize_path( $real ) === untrailingslashit( wp_normalize_path( $base ) ) . '/' . $relative ? $path : null;
		}

		return null;
	}

	/**
	 * A file's path relative to the uploads folder, when it is a file there that
	 * passes `file()`, or null.
	 *
	 * @param string $relative A path relative to the uploads folder.
	 */
	public static function upload( string $relative ): ?string {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );

		return '' === $relative ? null : self::file( Paths::absolute( $relative ) );
	}

	/**
	 * The network's uploads folder, which holds this site's, on a site of a
	 * network that keeps its sites' uploads under `sites/N`; null elsewhere.
	 */
	public static function network_uploads(): ?string {
		if ( ! is_multisite() ) {
			return null;
		}

		$uploads = Paths::uploads();
		$main    = (string) preg_replace( '#/sites/\d+$#', '', $uploads );

		return $main === $uploads ? null : $main;
	}

	/**
	 * The places, without a trailing slash: the uploads folder first.
	 *
	 * @return list<string>
	 */
	public static function roots(): array {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$roots   = array( Paths::uploads() );
		$network = self::network_uploads();

		if ( null !== $network ) {
			$roots[] = $network;
		}

		foreach ( self::CONTENT_FOLDERS as $folder ) {
			$roots[] = $content . '/' . $folder;
		}

		return $roots;
	}
}
