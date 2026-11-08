<?php
/**
 * Puts the uploads folder back as the integration run found it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * WordPress's test library lists every file under the uploads folder at the start
 * of each PHP process (a run's first test, and each test that runs in a separate
 * process), and every image a test uploads stays there, since the library deletes
 * them only for a test that asks. A folder nobody empties makes every later run
 * slower: tens of thousands of files listed through a container's file sharing
 * cost about half a minute a process before a test ran. So the run lists the
 * folder before its first test and, once it ends, deletes each file and then each
 * emptied folder that wasn't there, never anything that was, since a development
 * site can share the folder. A run cut short leaves its files behind.
 */
class Uploads_Sweep {

	/**
	 * Lists the folder now and puts it back when the process ends.
	 *
	 * @param string $root The uploads folder.
	 */
	public static function start( string $root ): void {
		$before = self::walk( $root );

		register_shutdown_function(
			static function () use ( $root, $before ): void {
				self::sweep( $root, $before );
			}
		);
	}

	/**
	 * Deletes what wasn't under the folder before: the files, then the folders they emptied, deepest first.
	 *
	 * @param string              $root   The uploads folder.
	 * @param array<string, bool> $before What was there, each path true for a folder.
	 */
	public static function sweep( string $root, array $before ): void {
		$folders = array();

		foreach ( self::walk( $root ) as $path => $is_folder ) {
			if ( isset( $before[ $path ] ) ) {
				continue;
			}

			if ( $is_folder ) {
				$folders[] = $path;
				continue;
			}

			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a file the run left, deleted after every hook is gone.
		}

		usort(
			$folders,
			static function ( string $a, string $b ): int {
				return substr_count( $b, DIRECTORY_SEPARATOR ) <=> substr_count( $a, DIRECTORY_SEPARATOR );
			}
		);

		foreach ( $folders as $folder ) {
			if ( ! ( new FilesystemIterator( $folder ) )->valid() ) {
				rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a folder the run made, now empty.
			}
		}
	}

	/**
	 * Every file and folder under the folder, without following a link.
	 *
	 * @param string $root The uploads folder.
	 * @return array<string, bool> Each path, true for a folder.
	 */
	protected static function walk( string $root ): array {
		$found = array();

		if ( ! is_dir( $root ) ) {
			return $found;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $items as $path => $item ) {
			$found[ (string) $path ] = $item->isDir() && ! $item->isLink();
		}

		return $found;
	}
}
