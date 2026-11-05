<?php
/**
 * Describes an attachment as an upload group.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

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
	 * @return array{body: array<string, mixed>, outputs: array<string, array{file: string, size: int, width: int, height: int}>, type: string}|WP_Error The request's body, each output's file relative to the uploads folder, and the source's MIME type.
	 */
	public static function build( int $attachment, array $metadata, array $source ) {
		$type = wp_get_image_mime( $source['path'] );

		if ( ! is_string( $type ) || ! isset( self::FORMATS[ $type ] ) ) {
			return new WP_Error( 'unsupported_format', __( 'Kept as uploaded: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ) );
		}

		$source_size = self::displayed( $source['path'], $type );
		$attached    = (string) get_attached_file( $attachment, true );
		$main_size   = '' === $attached ? null : self::displayed( $attached, (string) wp_get_image_mime( $attached ) );

		if ( null === $source_size || null === $main_size ) {
			return new WP_Error( 'unreadable_image', __( 'The image couldn\'t be read.', 'iminify-image-optimizer' ) );
		}

		$main_file = Paths::relative( $attached );

		if ( null === $main_file ) {
			return new WP_Error( 'outside_uploads', __( 'The image isn\'t in the uploads folder.', 'iminify-image-optimizer' ) );
		}

		$outputs = array(
			self::MAIN => array(
				'file'   => $main_file,
				'size'   => (int) filesize( $attached ),
				'width'  => $main_size[0],
				'height' => $main_size[1],
			),
		);
		$body    = array(
			array(
				'name'   => self::MAIN,
				'width'  => $main_size[0],
				'height' => $main_size[1],
				'size'   => (int) filesize( $attached ),
			),
		);

		foreach ( self::sizes( $metadata, $attached, $source_size, $type ) as $name => $size ) {
			$outputs[ $name ] = array(
				'file'   => $size['file'],
				'size'   => $size['size'],
				'width'  => $size['width'],
				'height' => $size['height'],
			);
			$body[]           = array_filter(
				array(
					'name'   => $name,
					'width'  => $size['width'],
					'height' => $size['height'],
					'crop'   => $size['crop'],
					'size'   => $size['size'],
				),
				static function ( $value ): bool {
					return null !== $value;
				}
			);
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

		$posted = get_post_time( 'U', true, $attachment );

		if ( is_int( $posted ) && time() - $posted > self::RECENT_SECONDS ) {
			$request['priority'] = 'background';
		}

		return array(
			'body'    => $request,
			'outputs' => $outputs,
			'type'    => $type,
		);
	}

	/**
	 * The sizes WordPress made of the image, one per file: a size whose file is
	 * the main file or another size's is left out, as is one in another format
	 * than the source's (a copy another plugin made), since the service writes
	 * each output in the source's format, or one whose name it can't take.
	 *
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 * @param string               $attached The attached file.
	 * @param array{int, int}      $source   The source's displayed width and height.
	 * @param string               $type     The source's MIME type.
	 * @return array<string, array{file: string, size: int, width: int, height: int, crop: array{0: string, 1: string}|null}>
	 */
	protected static function sizes( array $metadata, string $attached, array $source, string $type ): array {
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

			$file  = wp_basename( (string) $size['file'] );
			$path  = $folder . '/' . $file;
			$width = (int) $size['width'];
			$tall  = (int) $size['height'];

			if ( isset( $seen[ $file ] ) || isset( $names[ strtolower( $name ) ] ) || ! is_file( $path ) || $width < 1 || $tall < 1 || $width > $source[0] || $tall > $source[1] ) {
				continue;
			}

			if ( wp_get_image_mime( $path ) !== $type ) {
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
				'size'   => (int) filesize( $path ),
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
	protected static function displayed( string $path, string $type ): ?array {
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
