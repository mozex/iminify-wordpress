<?php
/**
 * The WebP and AVIF copies the plugin made of an attachment.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Switching\Converter;
use Iminify\WordPress\Switching\Places;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only a copy whose recorded origin is the plugin's is deleted, and only while
 * its file still has the bytes the plugin wrote: anything else at that name is
 * another plugin's, and stays. Restoring, "delete the copies", deleting an
 * attachment and uninstalling all come through here.
 *
 * A copy another optimizer made and the plugin took over (`Switching\Takeover`)
 * is recorded with that plugin's short name as its origin, and served
 * like the plugin's own while it is proven the same way. It is never the
 * plugin's to delete: every path here only forgets it, and its file stays.
 */
class Copies {

	/**
	 * The start of a record's key for a copy kept under `wp-content` outside the
	 * uploads folder (Converter for Media's), followed by its path from there.
	 */
	const IN_CONTENT = 'wp-content:';

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
	 * The copies in a record another plugin made that the plugin took over: their
	 * origin is that plugin's short name.
	 *
	 * @param array<string, mixed> $copies A record's `copies`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function taken( array $copies ): array {
		return array_filter(
			$copies,
			static function ( $about ): bool {
				return is_array( $about ) && isset( $about['origin'] ) && is_string( $about['origin'] ) && '' !== $about['origin'] && Records::ORIGIN !== $about['origin'];
			}
		);
	}

	/**
	 * A taken copy's file by its key in a record, when it is there with no link on
	 * the way (`Places`): under the uploads folder, or in Converter for Media's
	 * folder under `wp-content` for a key that starts with IN_CONTENT. Null otherwise.
	 *
	 * @param string $key The copy's key in a record.
	 */
	public static function file( string $key ): ?string {
		if ( 0 === strpos( $key, self::IN_CONTENT ) ) {
			$relative = ltrim( substr( $key, strlen( self::IN_CONTENT ) ), '/' );

			return 0 === strpos( $relative, Converter::FOLDER . '/' ) ? Places::file( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' . $relative ) : null;
		}

		return Places::upload( $key );
	}

	/**
	 * The address of a copy kept under `wp-content` outside the uploads folder, or
	 * empty for one beside its file, whose address is its file's with its own name.
	 *
	 * @param string $key The copy's key in a record.
	 */
	public static function address( string $key ): string {
		return 0 === strpos( $key, self::IN_CONTENT ) ? content_url( implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( substr( $key, strlen( self::IN_CONTENT ) ), '/' ) ) ) ) ) : '';
	}

	/**
	 * The keys of the taken copies that can't be served any more: a copy no longer
	 * there with the bytes it had when taken, or beside a file that changed since
	 * (its bytes are no longer `for_bytes`), or is gone.
	 *
	 * @param array<string, mixed> $copies A record's `copies`.
	 * @return array<string, true>
	 */
	public static function unproven( array $copies ): array {
		$unproven = array();

		foreach ( self::taken( $copies ) as $copy => $about ) {
			$path   = self::file( (string) $copy );
			$beside = Places::upload( (string) ( $about['for'] ?? '' ) );

			if ( null !== $path && null !== $beside ) {
				clearstatcache( true, $path );
				clearstatcache( true, $beside );

				if ( (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 ) && (int) filesize( $beside ) === (int) ( $about['for_bytes'] ?? -1 ) ) {
					continue;
				}
			}

			$unproven[ (string) $copy ] = true;
		}

		return $unproven;
	}

	/**
	 * Deletes the plugin's own copies of an attachment whose file beside them has
	 * changed since they were made (its bytes on the disk are no longer the
	 * `for_bytes` written with the copy), and forgets them; the copies of every
	 * other file stay. A copy whose file isn't on the disk (it may be only in WP
	 * Offload Media's bucket) is left as it is. A copy taken over from another
	 * plugin that can't be served any more is forgotten, its file left. Run while
	 * the site serves through the server's rules (`Delivery\Rules`), which can't
	 * ask the record.
	 *
	 * @param int $attachment The attachment's id.
	 * @return int How many copies it forgot.
	 */
	public static function delete_stale( int $attachment ): int {
		$record = Records::get( $attachment );
		$stale  = array();

		foreach ( self::own( $record['copies'] ) as $copy => $about ) {
			$for = isset( $about['for'] ) ? (string) $about['for'] : '';

			if ( '' === $for || ! isset( $about['for_bytes'] ) || null === Paths::relative( Paths::absolute( $for ) ) || null === Paths::relative( Paths::absolute( (string) $copy ) ) ) {
				continue;
			}

			$beside = Paths::absolute( $for );

			clearstatcache( true, $beside );

			if ( ! is_file( $beside ) || (int) filesize( $beside ) === (int) $about['for_bytes'] ) {
				continue;
			}

			$path = Paths::absolute( (string) $copy );

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 ) ) {
				wp_delete_file( $path );
			}

			$stale[ $copy ] = true;
		}

		/* Another plugin's copy the plugin took over is only forgotten: the rules go on serving it beside its file, which isn't the plugin's to delete. */
		$stale += self::unproven( $record['copies'] );

		if ( array() !== $stale ) {
			Records::update( $attachment, array( 'copies' => array_diff_key( $record['copies'], $stale ) ) );
		}

		return count( $stale );
	}

	/**
	 * Forgets the plugin's own copies of an attachment that are no longer on the
	 * disk with the bytes written: LiteSpeed Cache, active, deletes every WebP and
	 * AVIF beside an attachment's files when WordPress makes its metadata in the
	 * `create` context (a regeneration, a replaced image), the plugin's own copies
	 * among them. A format with a copy gone is taken off the result, so the image
	 * reads as lacking it (`Missing`). A copy WP Offload Media keeps in its bucket
	 * is left as it is. A copy taken over from another plugin that can't be served
	 * any more (gone, or beside a file put back) is forgotten too.
	 *
	 * @param int $attachment The attachment's id.
	 * @return int How many copies it forgot.
	 */
	public static function forget_gone( int $attachment ): int {
		$record   = Records::get( $attachment );
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$names    = self::output_names( $attachment, is_array( $metadata ) ? $metadata : array() );
		$gone     = array();
		$lost     = array();

		foreach ( self::own( $record['copies'] ) as $copy => $about ) {
			$relative = (string) $copy;

			if ( null === Paths::relative( Paths::absolute( $relative ) ) || Offload_Media::in_bucket( $about ) ) {
				continue;
			}

			$path = Paths::absolute( $relative );

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 ) ) {
				continue;
			}

			$format        = (string) ( $about['format'] ?? '' );
			$gone[ $copy ] = true;
			$lost[]        = array(
				'format' => $format,
				'output' => $names[ wp_basename( (string) ( $about['for'] ?? '' ) ) ] ?? null,
			);
		}

		/* A copy taken over from another plugin is only forgotten, and never stood in the plugin's result. */
		$gone += self::unproven( $record['copies'] );

		if ( array() === $gone ) {
			return 0;
		}

		$result = $record['result'];

		if ( null !== $result && array() !== $lost ) {
			$result['formats'] = array_values( array_diff( isset( $result['formats'] ) && is_array( $result['formats'] ) ? $result['formats'] : array(), array_column( $lost, 'format' ) ) );

			foreach ( $lost as $copy ) {
				$output = $copy['output'];

				if ( null !== $output && isset( $result['outputs'][ $output ]['formats'] ) && is_array( $result['outputs'][ $output ]['formats'] ) ) {
					$result['outputs'][ $output ]['formats'] = array_values( array_diff( $result['outputs'][ $output ]['formats'], array( $copy['format'] ) ) );
				}
			}
		}

		Records::update(
			$attachment,
			array(
				'copies' => array_diff_key( $record['copies'], $gone ),
				'result' => $result,
			)
		);

		return count( $gone );
	}

	/**
	 * The output names a group gives an attachment's files, by file name: `full`
	 * for the attached file, and each size by its name.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @return array<string, string>
	 */
	protected static function output_names( int $attachment, array $metadata ): array {
		$names = array( wp_basename( (string) get_attached_file( $attachment, true ) ) => Declaration::MAIN );

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $name => $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && ! isset( $names[ wp_basename( (string) $size['file'] ) ] ) ) {
				$names[ wp_basename( (string) $size['file'] ) ] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * Deletes the plugin's own copies of an attachment, and forgets them with the
	 * copies it took over from another plugin, whose files stay.
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
				'copies' => array_diff_key( $record['copies'], self::own( $record['copies'] ), self::taken( $record['copies'] ) ),
				'result' => $result,
			)
		);
	}
}
