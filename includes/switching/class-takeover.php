<?php
/**
 * The WebP and AVIF copies another optimizer made, taken over.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Media\Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A copy another plugin's records name (W137) is taken over when it reads as its
 * format and is no older than the file it stands beside, so a copy of a picture
 * replaced since is never taken: it is recorded with that plugin's short name as
 * its origin, the bytes it has and the bytes of the file beside it, and
 * `Media\Picture` serves it under the same proof as the plugin's own until the
 * plugin's own copy of that file and format takes its place. Nothing here writes
 * a file; every path that deletes copies only forgets a taken one (`Copies`).
 *
 * The look over the library takes them (`Others::walk()`), on images that are
 * not the plugin's own work, never at render time.
 */
class Takeover {

	/**
	 * The plugins whose copies are never taken over: LiteSpeed Cache's, which no
	 * record of its names, so they are found only by being there (W247).
	 */
	const UNNAMED = array( 'litespeed' );

	/**
	 * Every WebP or AVIF copy another plugin's records name for an attachment's
	 * files, by the key a record keeps it under (`Copies::IN_CONTENT` for one
	 * outside the uploads folder): the plugin, the file it stands beside and the
	 * copy's path. The first reader to name a copy is its plugin.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array<string, array{plugin: string, for: string, path: string}>
	 */
	public static function named( int $attachment ): array {
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$content  = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/';
		$named    = array();

		foreach ( Others::readers() as $reader ) {
			if ( ! $reader->in_metadata() && ! $reader->present() ) {
				continue;
			}

			$found = $reader->read( $attachment, $metadata );

			if ( null === $found ) {
				continue;
			}

			foreach ( $found['copies'] as $copy => $for ) {
				$path = Places::upload( (string) $copy );

				if ( null !== $path && ! isset( $named[ (string) $copy ] ) ) {
					$named[ (string) $copy ] = array(
						'plugin' => $reader->slug(),
						'for'    => $for,
						'path'   => $path,
					);
				}
			}

			foreach ( $found['outside'] as $for => $paths ) {
				foreach ( $paths as $path ) {
					$path = wp_normalize_path( $path );
					$key  = Copies::IN_CONTENT . substr( $path, strlen( $content ) );

					if ( 0 === strpos( $path, $content ) && ! isset( $named[ $key ] ) ) {
						$named[ $key ] = array(
							'plugin' => $reader->slug(),
							'for'    => (string) $for,
							'path'   => $path,
						);
					}
				}
			}
		}

		/* A file an attachment holds as its own (someone uploaded `photo.jpg.webp`, a name WordPress keeps) is never another plugin's copy, whatever a reader that lists copies by their names says: taken over it would serve another picture, written over it would destroy one. */
		$beside = array_values(
			array_filter(
				array_keys( $named ),
				static function ( string $key ): bool {
					return 0 !== strpos( $key, Copies::IN_CONTENT );
				}
			)
		);

		foreach ( array() === $beside ? array() : Shared::named( $beside ) as $file => $ids ) {
			if ( array() !== $ids ) {
				unset( $named[ $file ] );
			}
		}

		return $named;
	}

	/**
	 * Takes over the copies another plugin made of an attachment's files, and
	 * forgets those taken before that no longer pass: an image that is the
	 * plugin's own work (optimized, or on its way) takes none, since its files are
	 * the plugin's. One copy is taken per file and format, and none where the
	 * plugin has its own.
	 *
	 * @param int $attachment The attachment's id.
	 * @return bool Whether the record changed.
	 */
	public static function take( int $attachment ): bool {
		$record = Records::get( $attachment );
		$copies = $record['copies'];
		$before = Copies::taken( $copies );

		if ( ! in_array( $record['status'], array( null, Records::NOT_OPTIMIZED, Records::OTHER ), true ) ) {
			return false;
		}

		$own   = Copies::own( $copies );
		$pairs = array();
		$taken = array();

		foreach ( $own as $about ) {
			$pairs[ (string) ( $about['for'] ?? '' ) . '|' . (string) ( $about['format'] ?? '' ) ] = true;
		}

		foreach ( self::named( $attachment ) as $key => $about ) {
			$format = strtolower( pathinfo( $key, PATHINFO_EXTENSION ) );
			$pair   = $about['for'] . '|' . $format;
			$beside = Places::upload( $about['for'] );

			/* A copy is served at its file's address with its own name, so one a record places in another folder (left behind by a plugin that moved the media) is never taken. */
			$elsewhere = 0 !== strpos( $key, Copies::IN_CONTENT ) && dirname( $key ) !== dirname( $about['for'] );

			if ( null === $beside || $elsewhere || isset( $own[ $key ] ) || isset( $pairs[ $pair ] ) || ! isset( Writer::COPIES[ $format ] ) || in_array( $about['plugin'], self::UNNAMED, true ) ) {
				continue;
			}

			clearstatcache( true, $about['path'] );
			clearstatcache( true, $beside );

			/* A copy older than its file is of a picture replaced since, and a file that doesn't read as its format is no copy at all. */
			if ( wp_get_image_mime( $about['path'] ) !== Writer::COPIES[ $format ] || (int) filemtime( $about['path'] ) < (int) filemtime( $beside ) ) {
				continue;
			}

			$bytes     = (int) filesize( $about['path'] );
			$for_bytes = (int) filesize( $beside );
			$earlier   = $before[ $key ] ?? null;

			/* The same copy, taken before against other bytes of its file, is of the picture that was there then, however close their times. */
			if ( null !== $earlier && (int) ( $earlier['bytes'] ?? -1 ) === $bytes && (int) ( $earlier['for_bytes'] ?? -1 ) !== $for_bytes ) {
				continue;
			}

			$pairs[ $pair ] = true;
			$taken[ $key ]  = array(
				'for'       => $about['for'],
				'format'    => $format,
				'bytes'     => $bytes,
				'origin'    => $about['plugin'],
				'for_bytes' => $for_bytes,
			);
		}

		ksort( $taken );
		ksort( $before );

		if ( $taken === $before ) {
			return false;
		}

		Records::update( $attachment, array( 'copies' => array_diff_key( $copies, $before ) + $taken ) );

		return true;
	}
}
