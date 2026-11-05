<?php
/**
 * How much room the uploads folder has left.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A host that can't say how much room it has reads as having enough, since a
 * write that then fails changes nothing (`Files` writes a temporary file first).
 */
class Disk {

	/**
	 * What the plugin leaves free for the site itself: below this it writes nothing and opens no group.
	 */
	const RESERVE = 100 * MB_IN_BYTES;

	/**
	 * The free bytes on the uploads folder's disk, or null when the host doesn't say.
	 */
	public static function free(): ?float {
		$folder = Paths::uploads();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a host's open_basedir or disabled function warns here, and null is the answer.
		$free = function_exists( 'disk_free_space' ) && is_dir( $folder ) ? @disk_free_space( $folder ) : false;

		/**
		 * The free bytes the plugin reads for the uploads folder, for a host whose real limit is a quota the disk doesn't show.
		 *
		 * @param float|null $free The free bytes, or null when the host doesn't say.
		 */
		$free = apply_filters( 'iminify_disk_free_space', false === $free ? null : (float) $free );

		return is_numeric( $free ) ? (float) $free : null;
	}

	/**
	 * Whether a write of this many bytes leaves the reserve free.
	 *
	 * @param int $bytes What is about to be written.
	 */
	public static function has_room_for( int $bytes ): bool {
		$free = self::free();

		return null === $free || $free >= $bytes + self::RESERVE;
	}
}
