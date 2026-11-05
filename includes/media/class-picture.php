<?php
/**
 * Serves the WebP and AVIF copies through `<picture>` markup.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Local_Settings;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps an attachment's `<img>` in a `<picture>` whose sources list its AVIF and
 * WebP copies, through core's own filters: `wp_content_img_tag` for images in
 * posts and templates, and `wp_get_attachment_image` for images a theme prints.
 * The markup is the same for every visitor, so page caches and CDNs keep working,
 * and a browser that reads neither format falls back to the `<img>`.
 *
 * Only copies the attachment's record names as the plugin's own are served, and
 * only while the copy and the file it stands beside still hold the bytes the
 * plugin recorded: never a file that merely sits beside an image, one that is
 * gone, or one left from a picture replaced since.
 *
 * Each source lists the same candidates as the `<img>`'s `srcset`, so the browser
 * picks the same width it would have; a width with no copy in that format keeps
 * the best file it has (the WebP copy in the AVIF source, else its own file), so
 * a missing copy never makes the browser fetch a larger width.
 *
 * Core filters the same markup more than once (a block theme's template runs the
 * content filter over posts already filtered), so a wrapped `<img>` carries a
 * marker and is never wrapped again, and neither is one already inside a
 * `<picture>` someone wrote.
 */
class Picture {

	const MARKER = 'data-iminify-picture';

	/**
	 * The copies served, by format, in the order a browser should try them.
	 */
	const FORMATS = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	);

	/**
	 * What each attachment's copies are, kept for the request.
	 *
	 * @var array<int, array<string, array<string, string>>>
	 */
	protected static $served = array();

	/**
	 * Hooks the markup filters in.
	 */
	public static function register(): void {
		add_filter( 'wp_content_img_tag', array( self::class, 'content_image' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( self::class, 'attachment_image' ), 20, 2 );

		foreach ( array( 'the_content', 'the_excerpt', 'widget_text_content', 'widget_block_content' ) as $hook ) {
			add_filter( $hook, array( self::class, 'mark_pictures' ), 11 );
		}

		add_filter( 'render_block', array( self::class, 'mark_pictures' ) );
	}

	/**
	 * The `wp_content_img_tag` filter.
	 *
	 * @param mixed $tag        The `<img>` tag.
	 * @param mixed $context    Where it is printed.
	 * @param mixed $attachment The attachment's id, 0 when the tag names none.
	 * @return mixed
	 */
	public static function content_image( $tag, $context, $attachment ) {
		if ( ! is_string( $tag ) || ! is_numeric( $attachment ) || (int) $attachment <= 0 || ! self::serves() ) {
			return $tag;
		}

		return self::wrap( $tag, (int) $attachment );
	}

	/**
	 * The `wp_get_attachment_image` filter. A type's icon has no copies, so it
	 * comes back as it is.
	 *
	 * @param mixed $html       The `<img>` markup.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function attachment_image( $html, $attachment ) {
		if ( ! is_string( $html ) || '' === $html || ! is_numeric( $attachment ) || ! self::serves() ) {
			return $html;
		}

		return self::wrap( $html, (int) $attachment );
	}

	/**
	 * Marks every `<img>` already inside a `<picture>` in a piece of markup, so it
	 * is left as its author wrote it: a second set of sources inside would take
	 * over from the author's own.
	 *
	 * @param mixed $html The markup.
	 * @return mixed
	 */
	public static function mark_pictures( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<picture' ) || ! self::serves() ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<picture\b.*?</picture>#is',
			static function ( array $picture ): string {
				return (string) preg_replace_callback(
					'#<img\b[^>]*>#i',
					static function ( array $image ): string {
						return self::marked( $image[0] );
					},
					$picture[0]
				);
			},
			$html
		);
	}

	/**
	 * Whether this request's pages get `<picture>` markup: the delivery setting,
	 * and never in wp-admin (the media screens print attachment images too), in a
	 * feed or on an AMP page, which allows no `<picture>` of this kind.
	 */
	public static function serves(): bool {
		if ( Local_Settings::DELIVERY_PICTURE !== Local_Settings::get()['delivery'] ) {
			return false;
		}

		if ( ( is_admin() && ! wp_doing_ajax() ) || is_feed() ) {
			return false;
		}

		if ( function_exists( 'amp_is_request' ) && did_action( 'wp' ) && amp_is_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * Forgets the copies read during this request.
	 */
	public static function flush(): void {
		self::$served = array();
	}

	/**
	 * An `<img>` tag wrapped in a `<picture>` with a source per format it has
	 * copies in, or as it came when it has none.
	 *
	 * @param string $tag        The `<img>` tag.
	 * @param int    $attachment The attachment's id.
	 */
	protected static function wrap( string $tag, int $attachment ): string {
		if ( 0 !== stripos( ltrim( $tag ), '<img' ) || false !== stripos( $tag, self::MARKER ) || false !== stripos( $tag, '<picture' ) ) {
			return $tag;
		}

		$copies = self::copies( $attachment );

		if ( array() === $copies ) {
			return $tag;
		}

		$image = new WP_HTML_Tag_Processor( $tag );

		if ( ! $image->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return $tag;
		}

		$src    = $image->get_attribute( 'src' );
		$srcset = $image->get_attribute( 'srcset' );
		$sizes  = $image->get_attribute( 'sizes' );

		$candidates = is_string( $srcset ) && '' !== trim( $srcset ) ? self::candidates( $srcset ) : array();

		if ( array() === $candidates ) {
			$candidates = is_string( $src ) && '' !== $src ? array( array( $src, '' ) ) : array();
		}

		$sources = '';

		foreach ( array_keys( self::FORMATS ) as $format ) {
			$set = self::source_set( $candidates, $copies, $format );

			if ( null === $set ) {
				continue;
			}

			$sources .= '<source type="' . esc_attr( self::FORMATS[ $format ] ) . '" srcset="' . esc_attr( $set ) . '"'
				. ( is_string( $sizes ) && '' !== $sizes && is_string( $srcset ) && '' !== trim( $srcset ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '' )
				. '>';
		}

		if ( '' === $sources ) {
			return $tag;
		}

		return '<picture>' . $sources . self::marked( $tag ) . '</picture>';
	}

	/**
	 * A source's `srcset`: each candidate's copy in that format, or the best it
	 * has instead, or null when no candidate has a copy in that format.
	 *
	 * @param list<array{0: string, 1: string}>    $candidates The `<img>`'s candidates: each address and its descriptor.
	 * @param array<string, array<string, string>> $copies     The copies' file names by the name of the file they stand beside, then by format.
	 * @param string                               $format     The source's format.
	 */
	protected static function source_set( array $candidates, array $copies, string $format ): ?string {
		$fallbacks = array_slice( array_keys( self::FORMATS ), (int) array_search( $format, array_keys( self::FORMATS ), true ) );
		$entries   = array();
		$in_format = false;

		foreach ( $candidates as $candidate ) {
			list( $url, $descriptor ) = $candidate;

			$name = self::file_name( $url );
			$copy = null;

			foreach ( $fallbacks as $fallback ) {
				if ( isset( $copies[ $name ][ $fallback ] ) ) {
					$copy      = $copies[ $name ][ $fallback ];
					$in_format = $in_format || $fallback === $format;
					break;
				}
			}

			$entries[] = ( null === $copy ? $url : self::beside( $url, $copy ) ) . ( '' === $descriptor ? '' : ' ' . $descriptor );
		}

		return $in_format ? implode( ', ', $entries ) : null;
	}

	/**
	 * The attachment's copies that can be served, by the name of the file each
	 * stands beside, then by format.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array<string, array<string, string>>
	 */
	protected static function copies( int $attachment ): array {
		if ( isset( self::$served[ $attachment ] ) ) {
			return self::$served[ $attachment ];
		}

		$served = array();

		foreach ( Copies::own( Records::get( $attachment )['copies'] ) as $copy => $about ) {
			$format = isset( $about['format'] ) ? (string) $about['format'] : '';
			$for    = isset( $about['for'] ) ? (string) $about['for'] : '';

			if ( ! isset( self::FORMATS[ $format ] ) || '' === $for || ! isset( $about['for_bytes'] ) ) {
				continue;
			}

			$path   = Paths::absolute( (string) $copy );
			$beside = Paths::absolute( $for );

			if ( null === Paths::relative( $path ) || null === Paths::relative( $beside ) ) {
				continue;
			}

			if ( ! is_file( $path ) || (int) filesize( $path ) !== (int) ( $about['bytes'] ?? -1 ) ) {
				continue;
			}

			if ( ! is_file( $beside ) || (int) filesize( $beside ) !== (int) $about['for_bytes'] ) {
				continue;
			}

			$served[ wp_basename( $for ) ][ $format ] = wp_basename( (string) $copy );
		}

		self::$served[ $attachment ] = $served;

		return $served;
	}

	/**
	 * A `srcset`'s candidates, each its address and its descriptor.
	 *
	 * @param string $srcset The attribute's value.
	 * @return list<array{0: string, 1: string}>
	 */
	protected static function candidates( string $srcset ): array {
		$candidates = array();

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );

			if ( ! is_array( $parts ) || '' === $parts[0] ) {
				continue;
			}

			$candidates[] = array( $parts[0], isset( $parts[1] ) ? trim( $parts[1] ) : '' );
		}

		return $candidates;
	}

	/**
	 * The file name an address ends in, without its query or fragment.
	 *
	 * @param string $url An image's address.
	 */
	protected static function file_name( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? rawurldecode( wp_basename( $path ) ) : '';
	}

	/**
	 * The address of a copy beside the file an address names: the same address
	 * with the copy's file name, its query and fragment kept.
	 *
	 * @param string $url  The file's address.
	 * @param string $copy The copy's file name.
	 */
	protected static function beside( string $url, string $copy ): string {
		$end  = strcspn( $url, '?#' );
		$path = substr( $url, 0, $end );
		$cut  = strrpos( $path, '/' );

		return ( false === $cut ? '' : substr( $path, 0, $cut + 1 ) ) . rawurlencode( $copy ) . substr( $url, $end );
	}

	/**
	 * An `<img>` tag with the marker on it.
	 *
	 * @param string $tag The tag.
	 */
	protected static function marked( string $tag ): string {
		if ( false !== stripos( $tag, self::MARKER ) ) {
			return $tag;
		}

		$image = new WP_HTML_Tag_Processor( $tag );

		if ( ! $image->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return $tag;
		}

		$image->set_attribute( self::MARKER, true );

		return $image->get_updated_html();
	}
}
