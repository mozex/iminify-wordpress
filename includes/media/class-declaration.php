<?php
/**
 * Describes an attachment as an upload group.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Compat\Offload_Media;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One group per attachment: its original as the source, and as outputs the main
 * file (`full`) and every size WordPress made, each named as WordPress names it,
 * with its dimensions, the anchors it was cropped at and the bytes of the copy
 * the site has. The service makes every output from the source and decides which
 * of them the site's settings keep, so the plugin declares what is there and
 * nothing else.
 *
 * A file only in WP Offload Media's bucket is declared from the metadata: its
 * width and height, and its bytes where the metadata gives them, else none, so
 * the service's file takes its place. Every output of an attachment WP Offload
 * Media serves is marked `bucket`, since any save of its metadata can take the
 * file off the disk, with the bytes the metadata gives it (`listed`), which the
 * writer compares with the metadata's then for a file gone by then (`Writer`).
 */
class Declaration {

	const MAIN = 'full';

	/**
	 * The formats a group's source may be, by MIME type.
	 */
	const FORMATS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);

	/**
	 * The MIME types an image attachment the plugin handles may have, with the one
	 * WordPress keeps on an iPhone upload it turned into a JPEG.
	 */
	const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic' );

	/**
	 * The names the service takes for an output: they name its files.
	 */
	const OUTPUT_NAME = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/D';

	/**
	 * Uploads younger than this are sent ahead of older work, which is a site
	 * regenerating its sizes or editing an old image.
	 */
	const RECENT_SECONDS = HOUR_IN_SECONDS;

	/**
	 * The declaration of an attachment's group.
	 *
	 * @param int                                            $attachment The attachment's id.
	 * @param array<string, mixed>                           $metadata   Its metadata.
	 * @param array{path: string, kind: string, file: string} $source     The original to send (Originals::find()).
	 * @param string|null                                    $level      The level someone asked for, when it isn't the site's.
	 * @param bool                                           $background Whether it is older work nobody waits on, which takes the slower lane.
	 * @param list<string>|null                              $only       The only sizes to declare beside the main file (those an image lacks), or null for all.
	 * @param list<string>                                   $fresh      The sizes WordPress just made, possibly from the plugin's own output: they name no bytes, so the service's file made from the original always takes their place.
	 * @return array{body: array<string, mixed>, outputs: array<string, array{file: string, size: int, width: int, height: int, bucket?: true, listed?: int|null}>, type: string}|WP_Error The request's body, each output's file relative to the uploads folder (its bytes 0 when they aren't known, and for an attachment WP Offload Media serves the bytes its metadata gives it), and the source's MIME type.
	 */
	public static function build( int $attachment, array $metadata, array $source, ?string $level = null, bool $background = false, ?array $only = null, array $fresh = array() ) {
		$type = wp_get_image_mime( $source['path'] );

		if ( ! is_string( $type ) || ! isset( self::FORMATS[ $type ] ) ) {
			return new WP_Error( 'unsupported_format', __( 'Kept as uploaded: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ) );
		}

		$source_size = self::displayed( $source['path'], $type );
		$attached    = (string) get_attached_file( $attachment, true );
		$served      = Offload_Media::serves( $attachment );
		$bucket      = $served && '' !== $attached && ! is_file( $attached );
		$main_size   = '' === $attached ? null : ( $bucket ? self::measured( $metadata ) : self::displayed( $attached, (string) wp_get_image_mime( $attached ) ) );

		if ( null === $source_size || null === $main_size ) {
			return new WP_Error( 'unreadable_image', __( 'The image couldn\'t be read.', 'iminify-image-optimizer' ) );
		}

		$main_file = Paths::relative( $attached );

		if ( null === $main_file ) {
			return new WP_Error( 'outside_uploads', __( 'The image isn\'t in the uploads folder.', 'iminify-image-optimizer' ) );
		}

		$main_bytes = Offload_Media::bytes( $attachment, $attached );
		$outputs    = array(
			self::MAIN => array_merge(
				array(
					'file'   => $main_file,
					'size'   => (int) $main_bytes,
					'width'  => $main_size[0],
					'height' => $main_size[1],
				),
				$served ? self::listed( $attachment, $attached ) : array()
			),
		);
		$body       = array(
			self::output( self::MAIN, $main_size[0], $main_size[1], null, $main_bytes ),
		);

		foreach ( self::sizes( $attachment, $metadata, $attached, $source_size, $type ) as $name => $size ) {
			if ( null !== $only && ! in_array( (string) $name, $only, true ) ) {
				continue;
			}

			$outputs[ $name ] = array_merge(
				array(
					'file'   => $size['file'],
					'size'   => (int) $size['size'],
					'width'  => $size['width'],
					'height' => $size['height'],
				),
				$served ? self::listed( $attachment, Paths::absolute( $size['file'] ) ) : array()
			);
			$body[]           = self::output( (string) $name, $size['width'], $size['height'], $size['crop'], in_array( (string) $name, $fresh, true ) ? null : $size['size'] );
		}

		$request = array(
			'name'    => mb_substr( (string) pathinfo( $attached, PATHINFO_FILENAME ), 0, 125 ),
			'source'  => array(
				'format' => self::FORMATS[ $type ],
				'size'   => (int) filesize( $source['path'] ),
				'width'  => $source_size[0],
				'height' => $source_size[1],
			),
			'outputs' => $body,
		);

		if ( $background ) {
			$request['priority'] = 'background';
		}

		if ( null !== $level ) {
			$request['level'] = $level;
		}

		return array(
			'body'    => $request,
			'outputs' => $outputs,
			'type'    => $type,
		);
	}

	/**
	 * The mark of an output of an attachment WP Offload Media serves: it may leave
	 * the disk at any save of the metadata, and the bytes the metadata gives it now
	 * are what the writer compares the metadata with then.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The output's file.
	 * @return array{bucket: true, listed: int|null}
	 */
	protected static function listed( int $attachment, string $path ): array {
		return array(
			'bucket' => true,
			'listed' => Offload_Media::metadata_bytes( $attachment, $path ),
		);
	}

	/**
	 * One output as the request declares it: without bytes where they aren't
	 * known, which the service always replaces, and without anchors for a size
	 * that keeps the source's shape.
	 *
	 * @param string                         $name   The output's name.
	 * @param int                            $width  Its width.
	 * @param int                            $height Its height.
	 * @param array{0: string, 1: string}|null $crop   The anchors it was cut at.
	 * @param int|null                       $bytes  Its bytes.
	 * @return array<string, mixed>
	 */
	protected static function output( string $name, int $width, int $height, ?array $crop, ?int $bytes ): array {
		return array_filter(
			array(
				'name'   => $name,
				'width'  => $width,
				'height' => $height,
				'crop'   => $crop,
				'size'   => $bytes,
			),
			static function ( $value ): bool {
				return null !== $value;
			}
		);
	}

	/**
	 * The main file's width and height as the metadata gives them, for a file only
	 * in WP Offload Media's bucket: WordPress turns an upload with a stored
	 * orientation into a `-rotated` copy, so they are as a viewer shows it.
	 *
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 * @return array{int, int}|null
	 */
	protected static function measured( array $metadata ): ?array {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		return $width > 0 && $height > 0 ? array( $width, $height ) : null;
	}

	/**
	 * The sizes WordPress made of the image, one per file: a size whose file is
	 * the main file or another size's is left out, as is one in another format
	 * than the source's (a copy another plugin made), since the service writes
	 * each output in the source's format, or one whose name it can't take. A size
	 * only in WP Offload Media's bucket is read from its name and the metadata.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   The attachment's metadata.
	 * @param string               $attached   The attached file.
	 * @param array{int, int}      $source     The source's displayed width and height.
	 * @param string               $type       The source's MIME type.
	 * @return array<string, array{file: string, size: int|null, width: int, height: int, crop: array{0: string, 1: string}|null}>
	 */
	protected static function sizes( int $attachment, array $metadata, string $attached, array $source, string $type ): array {
		$sizes      = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		$registered = wp_get_registered_image_subsizes();
		$folder     = dirname( $attached );
		$seen       = array( wp_basename( $attached ) => true );
		$names      = array();
		$found      = array();

		foreach ( $sizes as $name => $size ) {
			$name = (string) $name;

			if ( ! is_array( $size ) || ! isset( $size['file'], $size['width'], $size['height'] ) || ! preg_match( self::OUTPUT_NAME, $name ) ) {
				continue;
			}

			$file   = wp_basename( (string) $size['file'] );
			$path   = $folder . '/' . $file;
			$width  = (int) $size['width'];
			$tall   = (int) $size['height'];
			$here   = is_file( $path );
			$bucket = ! $here && Offload_Media::only_in_bucket( $attachment, $path );

			if ( isset( $seen[ $file ] ) || isset( $names[ strtolower( $name ) ] ) || ! ( $here || $bucket ) || $width < 1 || $tall < 1 || $width > $source[0] || $tall > $source[1] ) {
				continue;
			}

			if ( ( $here ? wp_get_image_mime( $path ) : wp_check_filetype( $path )['type'] ) !== $type ) {
				continue;
			}

			$relative = Paths::relative( $path );

			if ( null === $relative ) {
				continue;
			}

			$seen[ $file ]                = true;
			$names[ strtolower( $name ) ] = true;
			$found[ $name ]               = array(
				'file'   => $relative,
				'size'   => Offload_Media::bytes( $attachment, $path ),
				'width'  => $width,
				'height' => $tall,
				'crop'   => self::crop( $registered[ $name ]['crop'] ?? null, $width, $tall, $source ),
			);
		}

		return $found;
	}

	/**
	 * The anchors a size was cut at, across then down, or null for a size that
	 * keeps the source's shape. A size whose shape differs from the source's
	 * with no anchors registered (one no longer registered) was cut at the
	 * center, WordPress's default.
	 *
	 * @param mixed           $crop   The registered size's `crop`.
	 * @param int             $width  The size's width.
	 * @param int             $height The size's height.
	 * @param array{int, int} $source The source's displayed width and height.
	 * @return array{0: string, 1: string}|null
	 */
	protected static function crop( $crop, int $width, int $height, array $source ): ?array {
		$keeps_shape = abs( $height * $source[0] - $width * $source[1] ) <= max( $source[0], $source[1] );

		if ( is_array( $crop ) && 2 === count( $crop ) ) {
			$map = array(
				'left'   => 'start',
				'top'    => 'start',
				'center' => 'center',
				'right'  => 'end',
				'bottom' => 'end',
			);
			$x   = $map[ (string) reset( $crop ) ] ?? 'center';
			$y   = $map[ (string) end( $crop ) ] ?? 'center';

			return array( $x, $y );
		}

		return true === $crop || ! $keeps_shape ? array( 'center', 'center' ) : null;
	}

	/**
	 * A file's width and height as a viewer shows it, with a JPEG's stored
	 * orientation applied, as the service measures the source.
	 *
	 * @param string $path The file.
	 * @param string $type Its MIME type.
	 * @return array{int, int}|null
	 */
	public static function displayed( string $path, string $type ): ?array {
		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return null;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];

		if ( 'image/jpeg' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$exif        = wp_read_image_metadata( $path );
			$orientation = is_array( $exif ) && isset( $exif['orientation'] ) ? (int) $exif['orientation'] : 1;

			if ( $orientation >= 5 && $orientation <= 8 ) {
				return array( $height, $width );
			}
		}

		return array( $width, $height );
	}
}
