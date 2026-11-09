<?php
/**
 * An image address a page audit found, matched to the attachment and size it is.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Audit;

use Iminify\WordPress\Delivery\Buffer;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iminify's audit names each image by the address the page loaded it from,
 * so the plugin alone says which attachment and which of its sizes that is:
 * the file under the uploads folder the address names, the main file it was
 * made from (`Buffer::main_files()`: a size's `-300x200`, the `-scaled` copy),
 * and the attachments naming that file, in one query for the whole page
 * (`Shared::named()`), the holder of a translation's files answering for it.
 * A WebP or AVIF copy stands for the file it was made from, since a page
 * serving copies through `<picture>` loads the copy: the plugin's own and
 * EWWW's (`photo.jpg.webp`), ShortPixel's (`photo.webp` beside `photo.jpg`)
 * and Modern Image Formats' (`photo-jpg.avif`); an attachment naming the
 * address's own file comes first, so a WebP upload beside a JPEG of the same
 * name is itself. An address outside the uploads folder (a theme's image, a
 * CDN's) has no attachment, and so has one WP Offload Media serves from its
 * bucket, whose address names no file here.
 */
class Resolver {

	/**
	 * The extensions a copy's file stands beside, in the order they are tried.
	 */
	const SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * The attachment and size each address is, or null for one that is none.
	 *
	 * @param list<string> $urls The addresses a page audit found.
	 * @return array<string, array{attachment: int, size: string, width: int, height: int, file: string, copy: string|null}|null>
	 */
	public static function resolve( array $urls ): array {
		$files   = array();
		$guesses = array();

		foreach ( $urls as $url ) {
			$file = Picture::uploaded_file( $url );

			if ( null === $file ) {
				continue;
			}

			$files[ $url ] = self::sources( $file );

			foreach ( $files[ $url ]['files'] as $source ) {
				foreach ( Buffer::main_files( $source ) as $main ) {
					$guesses[ $main ] = true;
				}
			}
		}

		$named    = array() === $guesses ? array() : Shared::named( array_map( 'strval', array_keys( $guesses ) ) );
		$resolved = array();

		foreach ( $urls as $url ) {
			$resolved[ $url ] = isset( $files[ $url ] ) ? self::match( $files[ $url ], $named ) : null;
		}

		return $resolved;
	}

	/**
	 * The files an address may stand for: the file itself, and for a WebP or
	 * AVIF copy the file it was made from.
	 *
	 * @param string $file The file the address names, relative to the uploads folder.
	 * @return array{files: list<string>, copy: string|null, own: string}
	 */
	protected static function sources( string $file ): array {
		$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'webp', 'avif' ), true ) ) {
			return array(
				'files' => array( $file ),
				'copy'  => null,
				'own'   => $file,
			);
		}

		/* An upload that is itself a WebP or AVIF file is no copy, and its exact name is proof where a sibling's is a guess. */
		$files = array( $file );
		$bare  = substr( $file, 0, -1 - strlen( $extension ) );
		$inner = strtolower( (string) pathinfo( $bare, PATHINFO_EXTENSION ) );

		if ( in_array( $inner, self::SOURCE_EXTENSIONS, true ) ) {
			$files[] = $bare;
		}

		if ( 1 === preg_match( '/-(jpe?g|png|gif|webp)$/i', $bare, $named ) ) {
			$files[] = substr( $bare, 0, -strlen( $named[0] ) ) . '.' . $named[1];
		}

		foreach ( self::SOURCE_EXTENSIONS as $source ) {
			if ( $source !== $extension ) {
				$files[] = $bare . '.' . $source;
			}
		}

		return array(
			'files' => array_values( array_unique( $files ) ),
			'copy'  => $extension,
			'own'   => $file,
		);
	}

	/**
	 * The first attachment, of those naming one of the files' main files, one
	 * of whose files the address is, with that file's size.
	 *
	 * @param array{files: list<string>, copy: string|null, own: string} $sources What the address may stand for.
	 * @param array<string, list<int>>                                   $named   The attachments naming each main file.
	 * @return array{attachment: int, size: string, width: int, height: int, file: string, copy: string|null}|null
	 */
	protected static function match( array $sources, array $named ): ?array {
		foreach ( $sources['files'] as $file ) {
			foreach ( Buffer::main_files( $file ) as $main ) {
				foreach ( $named[ $main ] ?? array() as $attachment ) {
					$size = self::size( $attachment, $file );

					if ( null !== $size ) {
						$holder = Shared::holder( $attachment );

						return array_merge(
							$size,
							array(
								'attachment' => $holder,
								'file'       => $file,
								'copy'       => $file === $sources['own'] ? null : $sources['copy'],
							)
						);
					}
				}
			}
		}

		return null;
	}

	/**
	 * Which of the attachment's files this is, as the size name WordPress keeps
	 * it under (`full` for the main file, `original` for the upload a `-scaled`
	 * copy stands in for), with its width and height, or null for none of them.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $file       The file, relative to the uploads folder.
	 * @return array{size: string, width: int, height: int}|null
	 */
	protected static function size( int $attachment, string $file ): ?array {
		$metadata = wp_get_attachment_metadata( $attachment );
		$attached = (string) get_post_meta( $attachment, '_wp_attached_file', true );

		return '' === $attached ? null : self::size_in( is_array( $metadata ) ? $metadata : array(), $attached, $file );
	}

	/**
	 * The size a file is in an attachment's metadata. Metadata another plugin
	 * wrote can hold anything, so nothing in it is taken on trust.
	 *
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 * @param string               $attached The attachment's file, relative to the uploads folder.
	 * @param string               $file     The file, relative to the uploads folder.
	 * @return array{size: string, width: int, height: int}|null
	 */
	protected static function size_in( array $metadata, string $attached, string $file ): ?array {
		$folder = dirname( $attached );
		$folder = '.' === $folder ? '' : trailingslashit( $folder );
		$name   = wp_basename( $file );

		if ( $folder . $name !== $file ) {
			return null;
		}

		if ( wp_basename( $attached ) === $name ) {
			return array(
				'size'   => 'full',
				'width'  => (int) ( $metadata['width'] ?? 0 ),
				'height' => (int) ( $metadata['height'] ?? 0 ),
			);
		}

		if ( isset( $metadata['original_image'] ) && $metadata['original_image'] === $name ) {
			$size = wp_getimagesize( trailingslashit( (string) wp_get_upload_dir()['basedir'] ) . $file );

			return array(
				'size'   => 'original',
				'width'  => is_array( $size ) ? (int) $size[0] : 0,
				'height' => is_array( $size ) ? (int) $size[1] : 0,
			);
		}

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size => $data ) {
			if ( is_array( $data ) && isset( $data['file'] ) && $data['file'] === $name ) {
				return array(
					'size'   => (string) $size,
					'width'  => (int) ( $data['width'] ?? 0 ),
					'height' => (int) ( $data['height'] ?? 0 ),
				);
			}
		}

		return null;
	}
}
