<?php
/**
 * Picture tags for every image on a page, from the page as it is sent.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core's filters reach only the images WordPress names by attachment
 * (`Media\Picture`). With picture tags over the whole page on, the page itself is
 * read as it is sent, through core's template output buffer (the
 * `wp_template_enhancement_output_buffer` filter, which hands over the whole page
 * and only an HTML answer), and every `<img>` those filters didn't wrap gets the
 * same `<picture>`: raw HTML, and a page builder's or a theme's own markup.
 *
 * An image is matched to its copies by the `wp-image-N` class it carries, else by
 * the address of its files under the uploads folder, a page's images in one query
 * (`Shared::named()`), where the attachment with copies to serve wins over a
 * translation that shares its file; an image in a folder the plugin optimizes
 * (`Folders`) is matched by its row, in one query too. The proof is the records':
 * a copy the plugin made, whose bytes and whose file's are still those it
 * recorded. An address WP Offload Media serves from its bucket is matched only
 * through the class, by `Picture::files()`, never by a pattern of the buffer's
 * own.
 *
 * An image already wrapped (`Picture::MARKER`) or inside a `<picture>` someone
 * wrote is left as it is, so nothing is wrapped twice.
 */
class Buffer {

	/**
	 * The suffix WordPress puts on the file of an image's size (`photo-300x200.jpg`).
	 */
	const SIZE_SUFFIX = '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/';

	/**
	 * The suffixes WordPress puts on a main file that isn't the upload itself: an
	 * image too large scaled down, or one turned upright by its EXIF. Its sizes
	 * keep the upload's name.
	 */
	const MAIN_SUFFIXES = array( '-scaled', '-rotated' );

	/**
	 * Hooks the buffer in once the request knows what it is.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'start' ) );
	}

	/**
	 * Asks core to buffer this page for the plugin, when the page gets picture
	 * tags over the whole of it. Core starts its buffer right before the template
	 * only when a filter is on it by then, so any other page streams as before.
	 */
	public static function start(): void {
		if ( self::reads() ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( self::class, 'rewrite' ) );
		}
	}

	/**
	 * Whether this request's page is read: in the whole-page mode, wherever
	 * `Picture` serves (never a wp-admin screen, a feed or an AMP page), unless a
	 * developer says otherwise for the page.
	 */
	public static function reads(): bool {
		if ( Local_Settings::DELIVERY_PAGE !== Local_Settings::get()['delivery'] || ! Picture::serves() ) {
			return false;
		}

		/**
		 * Whether to read this page as it is sent, to give the images WordPress's
		 * own filters didn't reach their picture tags. It runs on
		 * `template_redirect`, so conditional tags work.
		 *
		 * @param bool $read Whether to read the page. Default true.
		 */
		return (bool) apply_filters( 'iminify_page_buffer', true );
	}

	/**
	 * The `wp_template_enhancement_output_buffer` filter: the page with every
	 * `<img>` that has proven copies wrapped, and every other byte as it came.
	 *
	 * @param mixed $html The page.
	 * @return mixed
	 */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$images = self::images( $html );

		if ( array() === $images ) {
			return $html;
		}

		$attachments = self::attachments( $images );
		$folders     = self::folders( $images );
		$parts       = array();
		$at          = 0;

		foreach ( $images as $index => $image ) {
			list( $start, $length ) = $image['span'];

			$tag     = substr( $html, $start, $length );
			$wrapped = $tag;

			/* The class first, which also matches an address WP Offload Media serves; then the attachment the address names, for a class left from another image. */
			foreach ( array_unique( array_filter( array( $image['attachment'], $attachments[ $index ] ?? 0 ) ) ) as $attachment ) {
				$wrapped = Picture::wrap( $tag, $attachment );

				if ( $wrapped !== $tag ) {
					break;
				}
			}

			if ( $wrapped === $tag && isset( $folders[ $index ] ) ) {
				$wrapped = Picture::wrap_with( $tag, $folders[ $index ], array( self::class, 'folder_files' ) );
			}

			if ( $wrapped === $tag ) {
				continue;
			}

			$parts[] = substr( $html, $at, $start - $at );
			$parts[] = $wrapped;
			$at      = $start + $length;
		}

		if ( array() === $parts ) {
			return $html;
		}

		$parts[] = substr( $html, $at );

		return implode( '', $parts );
	}

	/**
	 * The main files an image's file can be a size of, the file itself first:
	 * without the size suffix, and with each suffix WordPress puts on a main file
	 * that isn't the upload.
	 *
	 * @param string $file A file relative to the uploads folder.
	 * @return list<string>
	 */
	public static function main_files( string $file ): array {
		$main  = (string) preg_replace( self::SIZE_SUFFIX, '', $file );
		$dot   = strrpos( $main, '.' );
		$files = array( $file, $main );

		if ( false !== $dot && false === strpos( substr( $main, $dot ), '/' ) ) {
			foreach ( self::MAIN_SUFFIXES as $suffix ) {
				$files[] = substr( $main, 0, $dot ) . $suffix . substr( $main, $dot );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Maps an `<img>`'s candidates to the files in the chosen folders they name,
	 * relative to `wp-content`, as `Picture::wrap_with()` asks.
	 *
	 * @param list<array{0: string, 1: string}> $candidates The `<img>`'s candidates: each address and its descriptor.
	 * @return list<array{file: string, bucket: bool}|null>
	 */
	public static function folder_files( array $candidates ): array {
		$files = array();

		foreach ( $candidates as $candidate ) {
			$file    = self::folder_file( $candidate[0] );
			$files[] = null === $file ? null : array(
				'file'   => $file,
				'bucket' => false,
			);
		}

		return $files;
	}

	/**
	 * Every `<img>` of the page neither wrapped nor inside a `<picture>`: where it
	 * lies, the attachment its class names (0 for none), and the addresses its
	 * `srcset` and `src` name.
	 *
	 * @param string $html The page.
	 * @return list<array{span: array{0: int, 1: int}, attachment: int, urls: list<string>}>
	 */
	protected static function images( string $html ): array {
		$markup = new Markup( $html );
		$depth  = 0;
		$images = array();

		while ( $markup->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $markup->get_tag();

			if ( 'PICTURE' === $tag ) {
				$depth = max( 0, $depth + ( $markup->is_tag_closer() ? -1 : 1 ) );
				continue;
			}

			/* A wrapped `<img>` is inside its `<picture>`, so this leaves it too. */
			if ( 'IMG' !== $tag || $markup->is_tag_closer() || $depth > 0 ) {
				continue;
			}

			$span = $markup->span();

			if ( null === $span ) {
				continue;
			}

			$src    = $markup->get_attribute( 'src' );
			$srcset = $markup->get_attribute( 'srcset' );
			$class  = $markup->get_attribute( 'class' );
			$urls   = is_string( $srcset ) ? array_column( Picture::candidates( $srcset ), 0 ) : array();

			if ( is_string( $src ) && '' !== $src ) {
				$urls[] = $src;
			}

			$images[] = array(
				'span'       => $span,
				'attachment' => is_string( $class ) && 1 === preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $id ) ? (int) $id[1] : 0,
				'urls'       => array_values( array_unique( $urls ) ),
			);
		}

		return $images;
	}

	/**
	 * The attachment each image names by the address of one of its files under
	 * the uploads folder: one that names the file and has copies to serve, found
	 * with every other image's in one query. Every attachment the page names is
	 * read here, its class's too.
	 *
	 * @param list<array{span: array{0: int, 1: int}, attachment: int, urls: list<string>}> $images The page's images.
	 * @return array<int, int> The attachment's id by the image's place in the list.
	 */
	protected static function attachments( array $images ): array {
		$files   = array();
		$guesses = array();

		foreach ( $images as $index => $image ) {
			foreach ( $image['urls'] as $url ) {
				$file = Picture::uploaded_file( $url );

				if ( null === $file ) {
					continue;
				}

				$files[ $index ][] = $file;

				foreach ( self::main_files( $file ) as $main ) {
					$guesses[ $main ] = true;
				}
			}
		}

		$named = array() === $guesses ? array() : Shared::named( array_map( 'strval', array_keys( $guesses ) ) );
		$ids   = array_values( array_filter( array_unique( array_merge( array_column( $images, 'attachment' ), ...array_values( $named ) ) ) ) );

		/* Every attachment the page names, by its class or its address, with its record and metadata, in one read of each. */
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		if ( array() === $guesses ) {
			return array();
		}

		$found = array();

		foreach ( $files as $index => $paths ) {
			foreach ( $paths as $file ) {
				$attachment = self::attachment_of( $file, $named );

				if ( null !== $attachment ) {
					$found[ $index ] = $attachment;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * The first attachment naming one of the file's possible main files that has
	 * copies to serve: among attachments sharing a file (a translation's), the one
	 * holding the plugin's work. One whose files don't include the image's serves
	 * it nothing (`Picture::wrap()` matches each address to its own files).
	 *
	 * @param string                   $file  The file, relative to the uploads folder.
	 * @param array<string, list<int>> $named The attachments naming each main file.
	 */
	protected static function attachment_of( string $file, array $named ): ?int {
		foreach ( self::main_files( $file ) as $main ) {
			foreach ( $named[ $main ] ?? array() as $attachment ) {
				if ( Picture::has_copies( $attachment ) ) {
					return $attachment;
				}
			}
		}

		return null;
	}

	/**
	 * The proven copies of the files in the chosen folders each image names by
	 * its address, found with every other image's in one query. The folders are
	 * a single site's, or a network's main site's (`Folders::listed()`).
	 *
	 * @param list<array{span: array{0: int, 1: int}, attachment: int, urls: list<string>}> $images The page's images.
	 * @return array<int, array<string, array<string, array{name: string, bucket: bool, local: bool}>>> The copies by the image's place in the list.
	 */
	protected static function folders( array $images ): array {
		$files = array();
		$all   = array();

		foreach ( $images as $index => $image ) {
			foreach ( $image['urls'] as $url ) {
				$file = self::folder_file( $url );

				if ( null !== $file ) {
					$files[ $index ][] = $file;
					$all[ $file ]      = true;
				}
			}
		}

		/* The table's mark is autoloaded where it exists, and a site that never chose a folder pays no query for it; `Folders::guard()` asks the list again of each file. */
		if ( array() === $all || ! isset( wp_load_alloptions()[ Table::VERSION_OPTION ] ) || array() === Folders::listed() ) {
			return array();
		}

		$rows   = Table::by_paths( array_map( 'strval', array_keys( $all ) ) );
		$copies = array();

		foreach ( $files as $index => $paths ) {
			foreach ( $paths as $file ) {
				if ( isset( $rows[ $file ] ) ) {
					$copies[ $index ] = array_merge( $copies[ $index ] ?? array(), self::proven( $rows[ $file ] ) );
				}
			}

			if ( empty( $copies[ $index ] ) ) {
				unset( $copies[ $index ] );
			}
		}

		return $copies;
	}

	/**
	 * A row's copies that can be served, as `Picture::copies()` answers an
	 * attachment's: each one the plugin made beside its file, in a chosen folder,
	 * with the bytes it wrote, beside a file that still has the bytes it had then.
	 * Each path is read through `Folders::guard()`, since a row is data.
	 *
	 * @param array<string, mixed> $row The file's row.
	 * @return array<string, array<string, array{name: string, bucket: bool, local: bool}>>
	 */
	protected static function proven( array $row ): array {
		$path   = (string) $row['path'];
		$served = array();

		foreach ( Copies::own( is_array( $row['copies'] ) ? $row['copies'] : array() ) as $copy => $about ) {
			$copy   = (string) $copy;
			$format = isset( $about['format'] ) ? (string) $about['format'] : '';

			if ( ! isset( Picture::FORMATS[ $format ] ) || ( $about['for'] ?? null ) !== $path || ! isset( $about['for_bytes'] ) || dirname( $copy ) !== dirname( $path ) ) {
				continue;
			}

			$file   = Folders::guard( $copy );
			$beside = Folders::guard( $path );

			if ( null === $file || null === $beside || (int) filesize( $file ) !== (int) ( $about['bytes'] ?? -1 ) || (int) filesize( $beside ) !== (int) $about['for_bytes'] ) {
				continue;
			}

			$served[ $path ][ $format ] = array(
				'name'   => wp_basename( $copy ),
				'bucket' => false,
				'local'  => true,
			);
		}

		return $served;
	}

	/**
	 * The path, relative to `wp-content`, of the file an address names outside
	 * the uploads folder, or null.
	 *
	 * @param string $url An image's address.
	 */
	protected static function folder_file( string $url ): ?string {
		if ( null !== Picture::uploaded_file( $url ) ) {
			return null;
		}

		return Picture::file_under( $url, content_url() );
	}
}
