<?php
/**
 * An image address a page audit found, matched to the attachment and size it is.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Audit;

use Iminify\WordPress\Compat\Offload_Media;
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
 * name is itself. An address WP Offload Media serves from its bucket is an
 * attachment's when WP Offload Media serves that attachment from the address's
 * folder and the address carries one of its files' names, as `Picture` proves
 * one; the attachment is looked for under the file the address's last folders
 * and name suggest (`2026/11/photo.jpg`, past a version folder WP Offload Media
 * may add), in the same query. A signed address names none, and any other
 * address outside the uploads folder (a theme's image, a CDN's) has no
 * attachment.
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
		$away    = array();
		$guesses = array();

		foreach ( $urls as $url ) {
			$file = Picture::uploaded_file( $url );

			if ( null === $file ) {
				$bucket = self::bucket( $url );

				if ( null !== $bucket ) {
					$away[ $url ] = $bucket;

					foreach ( self::guesses( $bucket ) as $guess ) {
						$guesses[ $guess['main'] ] = true;
					}
				}

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
			if ( isset( $files[ $url ] ) ) {
				$resolved[ $url ] = self::match( $files[ $url ], $named );
			} else {
				$resolved[ $url ] = isset( $away[ $url ] ) ? self::match_bucket( $away[ $url ], $named ) : null;
			}
		}

		return $resolved;
	}

	/**
	 * What an address may be in WP Offload Media's bucket: its folder, without
	 * its scheme, the names it may stand for and the folders the attachment's
	 * file may be in, or null while WP Offload Media isn't running, and for a
	 * signed address, whose signature is for that one file.
	 *
	 * @param string $url The address.
	 * @return array{folder: string, sources: array{files: list<string>, copy: string|null, own: string}, folders: list<string>}|null
	 */
	protected static function bucket( string $url ): ?array {
		$end = strcspn( $url, '?#' );

		if ( ! Offload_Media::active() || strlen( $url ) !== $end ) {
			return null;
		}

		$bare = (string) preg_replace( '#^https?:#i', '', substr( $url, 0, $end ) );
		$cut  = strrpos( $bare, '/' );
		$name = false === $cut ? '' : rawurldecode( substr( $bare, $cut + 1 ) );

		if ( false === $cut || '' === $name ) {
			return null;
		}

		$parts   = explode( '/', trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		$folders = array( '' );

		array_pop( $parts );

		/* The year and month folders, the last two, or the two before a version folder. */
		foreach ( array( 2, 3 ) as $back ) {
			$pair = array_slice( $parts, -$back, 2 );

			if ( count( $parts ) >= $back && 2 === count( $pair ) && 1 === preg_match( '/^\d{4}$/', $pair[0] ) && 1 === preg_match( '/^\d{2}$/', $pair[1] ) ) {
				array_unshift( $folders, implode( '/', $pair ) );
			}
		}

		return array(
			'folder'  => substr( $bare, 0, $cut + 1 ),
			'sources' => self::sources( $name ),
			'folders' => array_values( array_unique( $folders ) ),
		);
	}

	/**
	 * The files, relative to the uploads folder, a bucket's address may be, each
	 * with the main file the attachment would name.
	 *
	 * @param array{folder: string, sources: array{files: list<string>, copy: string|null, own: string}, folders: list<string>} $bucket What the address may be.
	 * @return list<array{file: string, main: string, name: string}>
	 */
	protected static function guesses( array $bucket ): array {
		$guesses = array();

		foreach ( $bucket['sources']['files'] as $name ) {
			foreach ( $bucket['folders'] as $folder ) {
				$file = '' === $folder ? $name : $folder . '/' . $name;

				foreach ( Buffer::main_files( $file ) as $main ) {
					$guesses[] = array(
						'file' => $file,
						'main' => $main,
						'name' => $name,
					);
				}
			}
		}

		return $guesses;
	}

	/**
	 * The first attachment a bucket's address is one of the files of: one WP
	 * Offload Media serves from the address's folder, with that file's size.
	 *
	 * @param array{folder: string, sources: array{files: list<string>, copy: string|null, own: string}, folders: list<string>} $bucket What the address may be.
	 * @param array<string, list<int>>                                                                                           $named  The attachments naming each main file.
	 * @return array{attachment: int, size: string, width: int, height: int, file: string, copy: string|null}|null
	 */
	protected static function match_bucket( array $bucket, array $named ): ?array {
		$uploads = (string) preg_replace( '#^https?:#i', '', (string) wp_get_upload_dir()['baseurl'] );

		foreach ( self::guesses( $bucket ) as $guess ) {
			foreach ( $named[ $guess['main'] ] ?? array() as $attachment ) {
				if ( Offload_Media::folder( $attachment, $uploads ) !== $bucket['folder'] ) {
					continue;
				}

				$size = self::size( $attachment, $guess['file'] );

				if ( null !== $size ) {
					return array_merge(
						$size,
						array(
							'attachment' => Shared::holder( $attachment ),
							'file'       => $guess['file'],
							'copy'       => $guess['name'] === $bucket['sources']['own'] ? null : $bucket['sources']['copy'],
						)
					);
				}
			}
		}

		return null;
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
