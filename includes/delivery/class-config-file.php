<?php
/**
 * A server's configuration file, read and changed as WordPress changes its `.htaccess`.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a configuration file as lines and changes it under the lock WordPress
 * takes when it writes its own rules (`insert_with_markers()`), so neither write
 * is lost: the site's `.htaccess` for the plugin's own rules (`Rules`), and the
 * files another optimizer left rules in (`Switching\Leftovers`).
 */
class Config_File {

	/**
	 * A file's lines, split on the line feed alone, so a line ending in a carriage
	 * return keeps it and a file written back keeps every line it didn't change
	 * byte for byte; compare a line trimmed.
	 *
	 * @param string $file The file.
	 * @return list<string>
	 */
	public static function lines( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, a server's configuration.
		$text = file_get_contents( $file );

		return explode( "\n", false === $text ? '' : $text );
	}

	/**
	 * Changes a file under WordPress's lock, written over from its start and cut
	 * where it ends, as core does, so a write that fails leaves the old text
	 * rather than an empty file.
	 *
	 * @param string                                $file   The file.
	 * @param callable(list<string>): ?list<string> $change The file's lines changed, or null to leave it.
	 * @return bool Whether the file now says what the change wanted.
	 */
	public static function edit( string $file, callable $change ): bool {
		if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read and written under one lock, as core's insert_with_markers() does with this file.
		$handle = fopen( $file, 'r+' );

		if ( false === $handle ) {
			return false;
		}

		flock( $handle, LOCK_EX );

		$lines = explode( "\n", (string) stream_get_contents( $handle ) );
		$new   = $change( $lines );
		$done  = null !== $new;

		if ( null !== $new && $new !== $lines ) {
			$text = implode( "\n", $new );

			rewind( $handle );
			$written = fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- under the lock taken above.
			$done    = strlen( $text ) === $written;

			/* A short write (a full disk) leaves a cut-off file the server would refuse: the old text goes back. */
			if ( ! $done ) {
				$old = implode( "\n", $lines );

				rewind( $handle );
				fwrite( $handle, $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the old text put back, under the same lock.
				fflush( $handle );
				ftruncate( $handle, strlen( $old ) );
			}

			$end = ftell( $handle );

			if ( $done && false !== $end ) {
				$done = fflush( $handle ) && ftruncate( $handle, max( 0, $end ) );
			}
		}

		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the handle opened above.

		return $done;
	}
}
