<?php
/**
 * Keeps the WebP and AVIF copies beside their images when WP Offload Media
 * serves the media library from a bucket.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Compat;

use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Writer;
use WP_Error;

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
 * With WP Offload Media's "Remove Local Media" on, an attachment's files are
 * only in the bucket once they went up. Every read of such a file answers from
 * what the metadata says of it (`only_in_bucket()`, `bytes()`), which costs no
 * call to the bucket; the one file a send or a restore reads, its source, is
 * brought back first (`bring_back()`), through WP Offload Media's own copy-back
 * filter, and goes again once nothing needs it (`put_away()`), unless the plugin
 * wrote over it, which WP Offload Media's next upload takes. While a restore has
 * WordPress make the sizes again, WP Offload Media's upload waits (`hold()`), so
 * the files go up once, at the restore's own save of the metadata.
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
	 * The attachment whose uploads wait while WordPress makes its sizes, or null.
	 *
	 * @var int|null
	 */
	protected static $holding = null;

	/**
	 * The one file the copy-back is asked for: the attachment and the file's path.
	 *
	 * @var array{0: int, 1: string}|null
	 */
	protected static $wanted = null;

	/**
	 * Hooks the filters in. They run only when WP Offload Media calls them.
	 */
	public static function register(): void {
		add_filter( 'as3cf_attachment_file_paths', array( self::class, 'file_paths' ), 10, 2 );
		add_filter( 'as3cf_object_meta', array( self::class, 'object_meta' ), 10, 5 );
		add_action( 'as3cf_post_handle_item_upload', array( self::class, 'uploaded' ) );
		add_filter( 'as3cf_pre_update_attachment_metadata', array( self::class, 'held' ), 10, 3 );
		add_filter( 'as3cf_get_attached_file_copy_back_to_local', array( self::class, 'copy_back' ), 10, 3 );
	}

	/**
	 * Whether WP Offload Media serves an attachment from its bucket.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function serves( int $attachment ): bool {
		$uploads = (string) preg_replace( '#^https?:#i', '', (string) wp_get_upload_dir()['baseurl'] );

		return '' !== $uploads && null !== self::folder( $attachment, $uploads );
	}

	/**
	 * Whether one of an attachment's files is only in WP Offload Media's bucket:
	 * it isn't on the disk, WP Offload Media serves the attachment, and the
	 * metadata names the file (the main file, the original WordPress kept, or a
	 * size). WP Offload Media removes a file from the disk only after it went up.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function only_in_bucket( int $attachment, string $path ): bool {
		if ( '' === $path || ! self::active() ) {
			return false;
		}

		clearstatcache( true, $path );

		return ! file_exists( $path ) && self::names( $attachment, $path ) && self::serves( $attachment );
	}

	/**
	 * The bytes of one of an attachment's files: on the disk, its size; only in
	 * the bucket, the size the metadata gives it (WordPress records each file's
	 * since 6.0, and the plugin each one it declared); null when it is in neither,
	 * or the metadata doesn't say.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function bytes( int $attachment, string $path ): ?int {
		clearstatcache( true, $path );

		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}

		return self::only_in_bucket( $attachment, $path ) ? self::metadata_bytes( $attachment, $path ) : null;
	}

	/**
	 * The size the attachment's metadata gives one of its files, or null when it
	 * names none: the original WordPress kept has none recorded.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function metadata_bytes( int $attachment, string $path ): ?int {
		$metadata = self::metadata( $attachment );
		$attached = wp_normalize_path( (string) get_attached_file( $attachment, true ) );
		$path     = wp_normalize_path( $path );

		if ( '' === $attached ) {
			return null;
		}

		if ( $path === $attached ) {
			return isset( $metadata['filesize'] ) && (int) $metadata['filesize'] > 0 ? (int) $metadata['filesize'] : null;
		}

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && dirname( $attached ) . '/' . wp_basename( (string) $size['file'] ) === $path ) {
				return isset( $size['filesize'] ) && (int) $size['filesize'] > 0 ? (int) $size['filesize'] : null;
			}
		}

		return null;
	}

	/**
	 * Brings one of an attachment's files back from the bucket onto the disk,
	 * through WP Offload Media's copy-back filter, asked for that file alone.
	 * Answers whether the file is on the disk now. What it brought back is noted
	 * on the record, for `put_away()`.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path: the attached file or the original WordPress kept.
	 */
	public static function bring_back( int $attachment, string $path ): bool {
		clearstatcache( true, $path );

		if ( is_file( $path ) ) {
			return true;
		}

		if ( ! self::only_in_bucket( $attachment, $path ) ) {
			return false;
		}

		$normal = wp_normalize_path( $path );

		self::$wanted = array( $attachment, $normal );

		try {
			/* Core's own filters, as get_attached_file() and wp_get_original_image_path() apply them, given the file's path on the disk as WordPress writes it (WP Offload Media compares it with its own): it answers them with the file brought back. */
			if ( wp_normalize_path( (string) get_attached_file( $attachment, true ) ) === $normal ) {
				$got = apply_filters( 'get_attached_file', $path, $attachment ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as core applies it.
			} else {
				$got = apply_filters( 'wp_get_original_image_path', $path, $attachment ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as core applies it.
			}
		} finally {
			self::$wanted = null;
		}

		clearstatcache( true, $path );

		if ( ! is_string( $got ) || wp_normalize_path( $got ) !== $normal || ! is_file( $path ) ) {
			/* A download that failed part of the way may leave a piece behind. */
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}

			return false;
		}

		$record   = Records::get( $attachment );
		$relative = (string) Paths::relative( $path );

		$record['fetched'][ $relative ] = array(
			'bytes'   => (int) filesize( $path ),
			'written' => $record['written'][ $relative ] ?? null,
		);
		Records::update( $attachment, array( 'fetched' => $record['fetched'] ) );

		return true;
	}

	/**
	 * Deletes the files `bring_back()` brought onto the disk that nothing needs
	 * now: each still has the bytes it came with and the plugin hasn't written
	 * over it, so it is the bucket's copy. The source of an open group that hasn't
	 * been sent stays, and nothing goes while WP Offload Media no longer serves the
	 * attachment, since the disk's file is then the one the site shows.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function put_away( int $attachment ): void {
		$record = Records::get( $attachment );

		if ( array() === $record['fetched'] ) {
			return;
		}

		$group   = $record['group'];
		$waiting = null !== $group && empty( $group['sent'] ) ? (string) ( $group['source'] ?? '' ) : '';
		$serves  = self::serves( $attachment );
		$kept    = array();

		foreach ( $record['fetched'] as $relative => $fetched ) {
			$path = Paths::absolute( (string) $relative );

			if ( $relative === $waiting ) {
				$kept[ $relative ] = $fetched;
				continue;
			}

			clearstatcache( true, $path );

			$unchanged = is_file( $path ) && (int) filesize( $path ) === (int) ( $fetched['bytes'] ?? -1 ) && ( $record['written'][ $relative ] ?? null ) === ( $fetched['written'] ?? null );

			if ( $serves && $unchanged && null !== Paths::relative( $path ) ) {
				wp_delete_file( $path );
			}
		}

		Records::update( $attachment, array( 'fetched' => $kept ) );
	}

	/**
	 * Holds WP Offload Media's uploads of an attachment while WordPress makes its
	 * sizes again for a restore, which saves the metadata after each one: the files
	 * go up once, at the restore's own save, which always follows. Every hold is
	 * released (`release()`).
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function hold( int $attachment ): void {
		self::$holding = $attachment;
	}

	/**
	 * Lets WP Offload Media upload again.
	 */
	public static function release(): void {
		self::$holding = null;
	}

	/**
	 * The `as3cf_pre_update_attachment_metadata` filter: an upload of the attachment held waits.
	 *
	 * @param mixed $cancel     Whether the upload is cancelled.
	 * @param mixed $metadata   The metadata being saved.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function held( $cancel, $metadata, $attachment ) {
		return null !== self::$holding && self::$holding === (int) $attachment ? true : $cancel;
	}

	/**
	 * The `as3cf_get_attached_file_copy_back_to_local` filter: the file
	 * `bring_back()` asks for is copied back, and nothing else.
	 *
	 * @param mixed $copy       Whether to copy it back.
	 * @param mixed $file       The file's path on the disk.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function copy_back( $copy, $file, $attachment ) {
		if ( null === self::$wanted || ! is_string( $file ) || (int) $attachment !== self::$wanted[0] ) {
			return $copy;
		}

		return wp_normalize_path( $file ) === self::$wanted[1] ? true : $copy;
	}

	/**
	 * Runs one of core's calls that reads the file WordPress makes an attachment's
	 * sizes from (finding the sizes it lacks, making them) without reaching WP
	 * Offload Media's bucket. Core builds that file's path on the filtered
	 * attached file, which WP Offload Media answers with a path in its bucket while
	 * the main file is only there, so the attached file is read where it is on the
	 * disk. While the original WordPress kept beside a `-scaled` copy isn't on the
	 * disk, the call sees metadata without it and takes the metadata's
	 * dimensions instead, as WP Offload Media's own check of an upload's sizes
	 * does; a size between the scaled copy's width and the original's then reads
	 * as one the image can't have.
	 *
	 * @template T
	 * @param int                $attachment The attachment's id.
	 * @param callable(int): T   $read       Core's call, given the attachment's id.
	 * @return T
	 */
	public static function locally( int $attachment, callable $read ) {
		if ( ! self::active() ) {
			return $read( $attachment );
		}

		$kept    = Originals::kept( $attachment );
		$local   = static function ( $file, $id ) use ( $attachment ) {
			return (int) $id === $attachment ? get_attached_file( $attachment, true ) : $file;
		};
		$without = static function ( $metadata, $id ) use ( $attachment, $kept ) {
			/* Asked at each read: WP Offload Media's upload at a save during the call takes the original off the disk, and its own check at the next save reads it. */
			if ( (int) $id === $attachment && is_array( $metadata ) && isset( $metadata['original_image'] ) && '' !== $kept && ! file_exists( $kept ) ) {
				unset( $metadata['original_image'] );
			}

			return $metadata;
		};

		add_filter( 'get_attached_file', $local, PHP_INT_MAX, 2 );
		add_filter( 'wp_get_attachment_metadata', $without, PHP_INT_MAX, 2 );

		try {
			return $read( $attachment );
		} finally {
			remove_filter( 'get_attached_file', $local, PHP_INT_MAX );
			remove_filter( 'wp_get_attachment_metadata', $without, PHP_INT_MAX );
		}
	}

	/**
	 * Whether one of an attachment's files is only in the bucket with no size in
	 * the metadata, so nothing can say whether it is the file the plugin wrote
	 * until it is brought back.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function unmeasured( int $attachment, string $path ): bool {
		return null === self::metadata_bytes( $attachment, $path ) && self::only_in_bucket( $attachment, $path );
	}

	/**
	 * The error a file that couldn't be brought back from the bucket shows, which
	 * the optimizer tries again later as it does an outage.
	 */
	public static function unreachable(): WP_Error {
		return new WP_Error( 'bucket_unreachable', __( 'The image couldn\'t be copied back from WP Offload Media\'s bucket to send it.', 'iminify-image-optimizer' ), array( 'status' => 503 ) );
	}

	/**
	 * An attachment's metadata as it is saved, which another plugin may have written anything into.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array<string, mixed>
	 */
	protected static function metadata( int $attachment ): array {
		$metadata = wp_get_attachment_metadata( $attachment, true );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Whether the attachment's metadata names a file: the attached file, the original WordPress kept, or a size.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	protected static function names( int $attachment, string $path ): bool {
		$metadata = self::metadata( $attachment );
		$attached = wp_normalize_path( (string) get_attached_file( $attachment, true ) );
		$path     = wp_normalize_path( $path );

		if ( '' === $attached || dirname( $path ) !== dirname( $attached ) ) {
			return false;
		}

		if ( $path === $attached ) {
			return true;
		}

		$files = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? array_column( $metadata['sizes'], 'file' ) : array();

		if ( isset( $metadata['original_image'] ) ) {
			$files[] = $metadata['original_image'];
		}

		foreach ( $files as $file ) {
			if ( is_string( $file ) && wp_basename( $file ) === wp_basename( $path ) ) {
				return true;
			}
		}

		return false;
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
