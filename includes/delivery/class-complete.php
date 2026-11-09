<?php
/**
 * What an image's markup lacks, filled in from the media library.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Picture;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives an `<img>` that names an attachment what its markup leaves out, as the
 * page is sent, when the site turns it on (`Local_Settings`' `complete_images`):
 * its width and height through core's own `wp_img_tag_add_width_and_height_attr()`
 * when it gives neither, and the one it lacks in proportion when it gives the
 * other, so the browser holds the image's space; and the attachment's alt text
 * where the tag has no `alt` at all, only when its `src` is one of that
 * attachment's files, since a class can be left from another image. An empty
 * `alt` says the image is decorative and stays as it is, and nothing already on
 * the tag is changed.
 *
 * Core already gives an image in a post's content its width and height when its
 * class names the attachment; what it can't reach is markup pasted in or printed
 * by a theme, which the page's buffer finds by its address (`Buffer`), and an
 * `alt` left out anywhere, which core never fills.
 */
class Complete {

	/**
	 * Whether this request's images are filled in: turned on, and a page a
	 * visitor sees (never a wp-admin screen, a feed or an AMP page).
	 */
	public static function on(): bool {
		return Local_Settings::get()['complete_images'] && Picture::front_end();
	}

	/**
	 * The tag with what it lacks, or as it came.
	 *
	 * @param string $tag        The `<img>` tag.
	 * @param int    $attachment The attachment it names.
	 * @param bool   $alt        Whether the attachment's alt text may be used: never
	 *                           when the address alone names a file translations
	 *                           share, since each keeps an alt in its own language.
	 * @param string $context    Where the tag is printed, as core's filters name it.
	 */
	public static function tag( string $tag, int $attachment, bool $alt = true, string $context = 'the_content' ): string {
		if ( $attachment <= 0 || ! wp_attachment_is_image( $attachment ) ) {
			return $tag;
		}

		$markup = new WP_HTML_Tag_Processor( $tag );

		if ( ! $markup->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return $tag;
		}

		$width  = $markup->get_attribute( 'width' );
		$height = $markup->get_attribute( 'height' );

		if ( null === $width && null === $height ) {
			$sized = wp_img_tag_add_width_and_height_attr( $tag, $context, $attachment );

			if ( $sized !== $tag ) {
				$markup = new WP_HTML_Tag_Processor( $sized );
				$markup->next_tag( array( 'tag_name' => 'img' ) );
			}
		} elseif ( null === $width || null === $height ) {
			self::other_side( $markup, $attachment );
		}

		if ( $alt && null === $markup->get_attribute( 'alt' ) && self::shows( $markup, $attachment ) ) {
			$text = trim( wp_strip_all_tags( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) ) );

			if ( '' !== $text ) {
				$markup->set_attribute( 'alt', $text );
			}
		}

		return $markup->get_updated_html();
	}

	/**
	 * Gives a tag with only its width, or only its height, the other in the
	 * proportion of the file its `src` names, read from the attachment's metadata
	 * as core reads it.
	 *
	 * @param WP_HTML_Tag_Processor $markup     The tag.
	 * @param int                   $attachment The attachment it names.
	 */
	protected static function other_side( WP_HTML_Tag_Processor $markup, int $attachment ): void {
		$src  = $markup->get_attribute( 'src' );
		$meta = wp_get_attachment_metadata( $attachment );

		if ( ! is_string( $src ) || ! is_array( $meta ) ) {
			return;
		}

		$size = wp_image_src_get_dimensions( substr( $src, 0, strcspn( $src, '?#' ) ), $meta, $attachment );

		if ( ! is_array( $size ) || $size[0] <= 0 || $size[1] <= 0 ) {
			return;
		}

		$width  = $markup->get_attribute( 'width' );
		$height = $markup->get_attribute( 'height' );

		if ( is_string( $width ) && ctype_digit( $width ) && (int) $width > 0 ) {
			$markup->set_attribute( 'height', (string) max( 1, (int) round( (int) $width * $size[1] / $size[0] ) ) );
		} elseif ( is_string( $height ) && ctype_digit( $height ) && (int) $height > 0 ) {
			$markup->set_attribute( 'width', (string) max( 1, (int) round( (int) $height * $size[0] / $size[1] ) ) );
		}
	}

	/**
	 * Whether the tag's `src` is one of the attachment's files, by its name: the
	 * main file, the upload a `-scaled` copy stands in for, or a size.
	 *
	 * @param WP_HTML_Tag_Processor $markup     The tag.
	 * @param int                   $attachment The attachment it names.
	 */
	protected static function shows( WP_HTML_Tag_Processor $markup, int $attachment ): bool {
		$src  = $markup->get_attribute( 'src' );
		$meta = wp_get_attachment_metadata( $attachment );

		if ( ! is_string( $src ) || '' === $src || ! is_array( $meta ) ) {
			return false;
		}

		return in_array( rawurldecode( wp_basename( substr( $src, 0, strcspn( $src, '?#' ) ) ) ), self::names( $meta, $attachment ), true );
	}

	/**
	 * An attachment's files by name. Metadata another plugin wrote can hold
	 * anything, so nothing in it is taken on trust.
	 *
	 * @param array<string, mixed> $meta       The attachment's metadata.
	 * @param int                  $attachment The attachment's id.
	 * @return list<string>
	 */
	protected static function names( array $meta, int $attachment ): array {
		$names = array( wp_basename( (string) get_post_meta( $attachment, '_wp_attached_file', true ) ) );

		if ( isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$names[] = $meta['original_image'];
		}

		foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
				$names[] = $size['file'];
			}
		}

		return $names;
	}
}
