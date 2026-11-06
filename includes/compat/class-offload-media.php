<?php
/**
 * Keeps the WebP and AVIF copies beside their images when WP Offload Media
 * serves the media library from a bucket.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Compat;

use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Offload Media copies each attachment's files to a bucket whenever its
 * metadata is saved, and serves them from there. It knows only the files
 * WordPress names, so the plugin adds its copies to the files it uploads
 * (`as3cf_attachment_file_paths`), each under a key of its own, and sets their
 * type on the way (`as3cf_object_meta`). The writer saves the metadata after it
 * records the copies, so they go up with the files they stand beside.
 *
 * A copy's record notes when it went to the bucket, once WP Offload Media says
 * the whole upload went well (`as3cf_post_handle_item_upload`), and
 * `<picture>` serves an attachment WP Offload Media serves a copy only then: a
 * copy made while WP Offload Media was off, or whose upload failed, isn't
 * there, and a `<source>` that fails shows a broken image.
 *
 * Only WP Offload Media's documented hooks are used, never its classes, which
 * change between its releases.
 */
class Offload_Media {

	/**
	 * What the copies' keys begin with among an item's files.
	 */
	const KEY_PREFIX = 'iminify-';

	/**
	 * The copies on their way to the bucket in the upload under way, by
	 * attachment, then by file, with their bytes: WP Offload Media asks for each
	 * file's meta before it uploads any, and says only afterwards whether all of
	 * them went up.
	 *
	 * @var array<int, array<string, int>>
	 */
	protected static $going = array();

	/**
	 * Hooks the filters in. They run only when WP Offload Media calls them.
	 */
	public static function register(): void {
		add_filter( 'as3cf_attachment_file_paths', array( self::class, 'file_paths' ), 10, 2 );
		add_filter( 'as3cf_object_meta', array( self::class, 'object_meta' ), 10, 5 );
		add_action( 'as3cf_post_handle_item_upload', array( self::class, 'uploaded' ) );
	}

	/**
	 * Whether WP Offload Media is running on this site.
	 */
	public static function active(): bool {
		return isset( $GLOBALS['as3cf'] ) && is_object( $GLOBALS['as3cf'] );
	}

	/**
	 * The `as3cf_attachment_file_paths` filter: the plugin's own copies join the
	 * files WP Offload Media uploads for an attachment, those on the disk.
	 *
	 * @param mixed $paths      The files, by key.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function file_paths( $paths, $attachment ) {
		if ( ! is_array( $paths ) || ! is_numeric( $attachment ) || (int) $attachment <= 0 ) {
			return $paths;
		}

		foreach ( Copies::own( Records::get( (int) $attachment )['copies'] ) as $copy => $about ) {
			$path = Paths::absolute( (string) $copy );

			if ( null !== Paths::relative( $path ) && is_file( $path ) ) {
				$paths[ self::key( (string) $copy ) ] = $path;
			}
		}

		return $paths;
	}

	/**
	 * The `as3cf_object_meta` filter: a copy goes up with its own type, and is
	 * noted as on its way, for `uploaded()` to record once the upload went well.
	 *
	 * @param mixed $args        What WP Offload Media sends to the bucket.
	 * @param mixed $attachment  The attachment's id.
	 * @param mixed $key         The file's key among the item's.
	 * @param mixed $copy        Whether the file is copied between buckets.
	 * @param mixed $item_source What the item is.
	 * @return mixed
	 */
	public static function object_meta( $args, $attachment, $key, $copy = false, $item_source = array() ) {
		if ( ! is_array( $args ) || ! is_string( $key ) || 0 !== strpos( $key, self::KEY_PREFIX ) || ! is_numeric( $attachment ) || (int) $attachment <= 0 ) {
			return $args;
		}

		if ( is_array( $item_source ) && isset( $item_source['source_type'] ) && 'media-library' !== $item_source['source_type'] ) {
			return $args;
		}

		$file   = isset( $args['SourceFile'] ) && is_string( $args['SourceFile'] ) ? Paths::relative( $args['SourceFile'] ) : null;
		$record = Records::get( (int) $attachment );
		$own    = Copies::own( $record['copies'] );

		if ( null === $file || ! isset( $own[ $file ] ) ) {
			return $args;
		}

		$format = (string) ( $own[ $file ]['format'] ?? '' );

		if ( isset( Writer::COPIES[ $format ] ) ) {
			$args['ContentType'] = Writer::COPIES[ $format ];
		}

		self::$going[ (int) $attachment ][ $file ] = (int) ( $own[ $file ]['bytes'] ?? 0 );

		return $args;
	}

	/**
	 * The `as3cf_post_handle_item_upload` action: once every file of the upload
	 * went up, each copy it carried is recorded as in the bucket, while the record
	 * still names it with the bytes that went. An upload that failed, or never
	 * reached the bucket, records nothing.
	 *
	 * @param mixed $result True when every file went up, or the failure.
	 */
	public static function uploaded( $result ): void {
		$going       = self::$going;
		self::$going = array();

		if ( true !== $result ) {
			return;
		}

		foreach ( $going as $attachment => $files ) {
			$record = Records::get( $attachment );
			$own    = Copies::own( $record['copies'] );
			$marked = false;

			foreach ( $files as $file => $bytes ) {
				if ( isset( $own[ $file ] ) && (int) ( $own[ $file ]['bytes'] ?? -1 ) === $bytes ) {
					$record['copies'][ $file ]['offloaded'] = $bytes;
					$marked                                 = true;
				}
			}

			if ( $marked ) {
				Records::update( $attachment, array( 'copies' => $record['copies'] ) );
			}
		}
	}

	/**
	 * The folder WP Offload Media serves an attachment's files from, without its
	 * scheme, or null when it doesn't serve them (the files are served from the
	 * uploads folder, or WP Offload Media isn't running).
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $uploads    The uploads folder's address, without its scheme.
	 */
	public static function folder( int $attachment, string $uploads ): ?string {
		if ( ! self::active() ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment );

		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$bare = (string) preg_replace( '#^https?:#i', '', substr( $url, 0, strcspn( $url, '?#' ) ) );
		$cut  = strrpos( $bare, '/' );

		if ( false === $cut || 0 === strpos( $bare, trailingslashit( $uploads ) ) ) {
			return null;
		}

		return substr( $bare, 0, $cut + 1 );
	}

	/**
	 * Whether a copy's record says it is in the bucket as it is now: the copy
	 * went up with the bytes it still has.
	 *
	 * @param array<string, mixed> $about The record's entry for the copy.
	 */
	public static function in_bucket( array $about ): bool {
		return isset( $about['offloaded'], $about['bytes'] ) && (int) $about['offloaded'] === (int) $about['bytes'];
	}

	/**
	 * A copy's key among the item's files.
	 *
	 * @param string $copy The copy, relative to the uploads folder.
	 */
	protected static function key( string $copy ): string {
		return self::KEY_PREFIX . wp_basename( $copy );
	}
}
