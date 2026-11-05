<?php
/**
 * The WebP and AVIF copies the plugin made of an attachment.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only a copy whose recorded origin is the plugin's is deleted, and only while
 * its file still has the bytes the plugin wrote: anything else at that name is
 * another plugin's, and stays. Restoring, "delete the copies", deleting an
 * attachment and uninstalling all come through here.
 */
class Copies {

	/**
	 * The copies in a record that are the plugin's own.
	 *
	 * @param array<string, mixed> $copies A record's `copies`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function own( array $copies ): array {
		return array_filter(
			$copies,
			static function ( $about ): bool {
				return is_array( $about ) && Records::ORIGIN === ( $about['origin'] ?? null );
			}
		);
	}

	/**
	 * Deletes the plugin's own copies of an attachment, and forgets them.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function delete_own( int $attachment ): void {
		$record = Records::get( $attachment );

		foreach ( self::own( $record['copies'] ) as $copy => $about ) {
			if ( null === Paths::relative( Paths::absolute( (string) $copy ) ) ) {
				continue;
			}

			$path = Paths::absolute( (string) $copy );

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 ) ) {
				wp_delete_file( $path );
			}
		}

		$result = $record['result'];

		if ( null !== $result ) {
			$result['formats'] = array();

			foreach ( isset( $result['outputs'] ) && is_array( $result['outputs'] ) ? array_keys( $result['outputs'] ) : array() as $output ) {
				$result['outputs'][ $output ]['formats'] = array();
			}
		}

		Records::update(
			$attachment,
			array(
				'copies' => array_diff_key( $record['copies'], self::own( $record['copies'] ) ),
				'result' => $result,
			)
		);
	}
}
