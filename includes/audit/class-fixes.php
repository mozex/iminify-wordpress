<?php
/**
 * What the page audit's screen can do about an image it found.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Audit;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Declaration;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beside each image of an audit, the fixes the plugin can carry out for an
 * attachment of the media library. Each goes through what the media library
 * already does (`Optimizer::ask()`, `Alt_Text::ask()` through its routes), or
 * through the setting that fills in what the page's markup lacks
 * (`Delivery\Complete`); a smaller size is the person's to choose in the editor,
 * so the screen names the size and the page to edit. An audit is what Iminify
 * saw then: nothing here hides a finding, and the screen asks for another scan.
 */
class Fixes {

	/**
	 * How far a size's shape may stray from the file the page shows and still be
	 * the same picture: a size cropped to another shape is a different picture.
	 */
	const SHAPE = 0.02;

	/**
	 * The fixes for one image. `fill` says the setting that fills in what the
	 * markup lacks reaches it by its address: one under the uploads folder,
	 * never one WP Offload Media serves from its bucket.
	 *
	 * @param string                                                                $url         The image's address.
	 * @param list<string>                                                          $codes       The image's finding codes.
	 * @param array{attachment: int, size: string, width: int, height: int}|null $found       The attachment and size the image is, or null for one outside the media library.
	 * @param int|null                                                              $serve_width For an oversized image, the width to show.
	 * @return array{optimize: bool, alt_text: string|null, fill: bool, smaller: array{size: string, width: int, height: int}|null}
	 */
	public static function of( string $url, array $codes, ?array $found, ?int $serve_width ): array {
		if ( null === $found ) {
			return array(
				'optimize' => false,
				'alt_text' => null,
				'fill'     => false,
				'smaller'  => null,
			);
		}

		$attachment = $found['attachment'];

		return array(
			'optimize' => self::optimizable( $attachment ),
			'alt_text' => in_array( 'missing_alt', $codes, true ) ? self::alt_text( $attachment ) : null,
			'fill'     => null !== Picture::uploaded_file( $url ),
			'smaller'  => in_array( 'oversized', $codes, true ) && null !== $serve_width ? self::smaller( $found, $serve_width ) : null,
		);
	}

	/**
	 * Whether the screen offers to optimize the attachment: one the plugin would
	 * send that isn't optimized or on its way, for someone who may change it.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function optimizable( int $attachment ): bool {
		return in_array( Records::get( $attachment )['status'] ?? Records::NOT_OPTIMIZED, array( Records::NOT_OPTIMIZED, Records::ERROR ), true )
			&& Capabilities::can_change( $attachment )
			&& null === Optimizer::why_not( $attachment );
	}

	/**
	 * What fills in the alt text the page's markup leaves out: `fill` when the
	 * attachment has one (the setting puts it in the page), `write` when the
	 * service can write one, `settings` while the site's AI alt text is off, `waiting`
	 * while one is on its way, and `translations` for a file translations share,
	 * since the address doesn't say which of them the page showed, and each keeps
	 * an alt in its own language. None for a type the service writes no alt text
	 * for (an SVG, an AVIF).
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function alt_text( int $attachment ): ?string {
		if ( array() !== Shared::others( $attachment ) ) {
			return 'translations';
		}

		$typed = in_array( get_post_mime_type( $attachment ), Declaration::MIME_TYPES, true );

		if ( ! Alt_Text::lacks_alt( $attachment ) ) {
			return 'fill';
		}

		if ( ! $typed || ! Capabilities::can_change( $attachment ) ) {
			return null;
		}

		if ( ! Alt_Text::on() ) {
			return 'settings';
		}

		/* For a type it takes, the service can't be asked only while a text, or a group that asks for one, is on its way. */
		return Alt_Text::can_ask( $attachment ) ? 'write' : 'waiting';
	}

	/**
	 * Whether a post's content holds one of some attachments, as the block editor
	 * and the classic one write it: its `wp-image-N` class or an image block's
	 * `"id"`. The attachments are an image's and those sharing its files, since a
	 * translated post names its own translation. Otherwise a theme, a template or
	 * a widget shows the image, and its size is chosen there, not in the post's
	 * editor.
	 *
	 * @param string    $content     The post's content.
	 * @param list<int> $attachments The attachments' ids.
	 */
	public static function in_content( string $content, array $attachments ): bool {
		$ids = implode( '|', array_map( 'intval', $attachments ) );

		return '' !== $ids && 1 === preg_match( '/(?:wp-image-|"id":)(?:' . $ids . ')(?![0-9])/', $content );
	}

	/**
	 * The sizes the editor offers an image in, by name, with the names its media
	 * screens show: core's four and any a theme adds through core's filter. The
	 * others WordPress makes (`medium_large`, `1536x1536`) are never offered there,
	 * so they are never suggested.
	 *
	 * @return array<string, string>
	 */
	public static function editor_sizes(): array {
		/** This filter is documented in wp-admin/includes/media.php. */
		$names = apply_filters(
			'image_size_names_choose', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, so a size a theme names shows its name here as in the media library.
			array(
				'thumbnail' => __( 'Thumbnail', 'iminify-image-optimizer' ),
				'medium'    => __( 'Medium', 'iminify-image-optimizer' ),
				'large'     => __( 'Large', 'iminify-image-optimizer' ),
				'full'      => __( 'Full Size', 'iminify-image-optimizer' ),
			)
		);

		return is_array( $names ) ? array_filter( $names, 'is_string' ) : array();
	}

	/**
	 * The size of the attachment to show in place of the file the page shows, in
	 * that file's shape and smaller than it, among those the editor offers: the largest no wider than twice the
	 * width it is shown at (Iminify's own line, past which a file is oversized)
	 * and at least as wide as it is shown, so it stays sharp; else the narrowest
	 * wider than that line. Null when the attachment has none.
	 *
	 * @param array{attachment: int, size: string, width: int, height: int} $found       The attachment and size the image is.
	 * @param int                                                           $serve_width Twice the width the page shows it at.
	 * @return array{size: string, width: int, height: int}|null
	 */
	public static function smaller( array $found, int $serve_width ): ?array {
		$meta = wp_get_attachment_metadata( $found['attachment'] );

		return self::choose( is_array( $meta ) ? $meta : array(), $found, $serve_width );
	}

	/**
	 * The size `smaller()` chooses from an attachment's metadata. Metadata another
	 * plugin wrote can hold anything, so nothing in it is taken on trust.
	 *
	 * @param array<string, mixed>                                          $meta        The attachment's metadata.
	 * @param array{attachment: int, size: string, width: int, height: int} $found       The attachment and size the image is.
	 * @param int                                                           $serve_width Twice the width the page shows it at.
	 * @return array{size: string, width: int, height: int}|null
	 */
	protected static function choose( array $meta, array $found, int $serve_width ): ?array {
		if ( $found['width'] <= 0 || $found['height'] <= 0 ) {
			return null;
		}

		$shape = $found['width'] / $found['height'];
		$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();

		if ( isset( $meta['width'], $meta['height'] ) && is_numeric( $meta['width'] ) && is_numeric( $meta['height'] ) ) {
			$sizes['full'] = array(
				'width'  => $meta['width'],
				'height' => $meta['height'],
			);
		}

		$within  = null;
		$above   = null;
		$offered = self::editor_sizes();

		foreach ( array_intersect_key( $sizes, $offered ) as $size => $about ) {
			$width  = is_array( $about ) && isset( $about['width'] ) && is_numeric( $about['width'] ) ? (int) $about['width'] : 0;
			$height = is_array( $about ) && isset( $about['height'] ) && is_numeric( $about['height'] ) ? (int) $about['height'] : 0;

			if ( $width <= 0 || $width * 2 < $serve_width || $width >= $found['width'] || $height <= 0 || abs( $width / $height - $shape ) > $shape * self::SHAPE ) {
				continue;
			}

			$choice = array(
				'size'   => (string) $size,
				'width'  => $width,
				'height' => $height,
			);

			if ( $width <= $serve_width ) {
				$within = null === $within || $width > $within['width'] ? $choice : $within;
			} else {
				$above = null === $above || $width < $above['width'] ? $choice : $above;
			}
		}

		$best = $within ?? $above;

		return $best;
	}

	/**
	 * Where the person edits the page an audit read, or null when it isn't a post
	 * they may edit (a theme's template, an archive).
	 *
	 * @param int $post The post the page's address is, 0 for none.
	 */
	public static function edit_link( int $post ): ?string {
		if ( $post <= 0 || ! current_user_can( 'edit_post', $post ) ) {
			return null;
		}

		$link = get_edit_post_link( $post, 'raw' );

		return is_string( $link ) && '' !== $link ? $link : null;
	}
}
