<?php
/**
 * Keeps WooCommerce's variation images working inside `<picture>`.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Compat;

use Iminify\WordPress\Media\Picture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Choosing a variation makes WooCommerce's script rewrite the product image's
 * `src` and `srcset`, and Reset puts them back. Inside a `<picture>` the browser
 * keeps showing the sources, so the product's own WebP or AVIF would stay on
 * screen. A few lines with WooCommerce's own script set the sources aside while
 * the image shows something else, and give them back once it shows its own
 * again. The script goes out only with WooCommerce's variation script, which
 * WooCommerce prints on a variable product's page alone.
 *
 * The sources never take the image's `wp-post-image` class instead: WooCommerce
 * then stores and puts back every attribute from the first source for all of
 * them, so Reset empties the image's `src` and its zoom image.
 */
class Woocommerce {

	/**
	 * WooCommerce's variation script.
	 */
	const HANDLE = 'wc-add-to-cart-variation';

	/**
	 * Watches every product gallery's wrapped images, those WooCommerce puts in
	 * later too (a variation's own gallery replaces the gallery's markup). An
	 * image shows its own picture until WooCommerce stores its first `src` in
	 * `data-o_src` to swap it, and again once both are back; a change nothing
	 * stored (a lazy loader's) leaves the sources alone.
	 */
	const SCRIPT = <<<'JS'
( function () {
	var gallery = '.woocommerce-product-gallery picture > img[data-iminify-picture]';

	function own( image ) {
		return ! image.hasAttribute( 'data-o_src' ) || ( image.getAttribute( 'src' ) === image.getAttribute( 'data-o_src' ) && ( image.getAttribute( 'srcset' ) || '' ) === ( image.getAttribute( 'data-o_srcset' ) || '' ) );
	}

	new MutationObserver( function ( records ) {
		records.forEach( function ( record ) {
			var image = record.target;

			if ( ! image.matches || ! image.matches( gallery ) ) {
				return;
			}

			var shown = own( image );

			image.parentNode.querySelectorAll( 'source' ).forEach( function ( source ) {
				if ( shown && source.hasAttribute( 'data-iminify-srcset' ) ) {
					source.setAttribute( 'srcset', source.getAttribute( 'data-iminify-srcset' ) );
					source.removeAttribute( 'data-iminify-srcset' );
				} else if ( ! shown && source.hasAttribute( 'srcset' ) ) {
					source.setAttribute( 'data-iminify-srcset', source.getAttribute( 'srcset' ) );
					source.removeAttribute( 'srcset' );
				}
			} );
		} );
	} ).observe( document.documentElement, { attributes: true, subtree: true, attributeFilter: [ 'src', 'srcset' ] } );
} )();
JS;

	/**
	 * Hooks the script in, after WooCommerce registers its own.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'attach' ), 20 );
	}

	/**
	 * Adds the script to WooCommerce's variation script when the site serves
	 * its copies through `<picture>`. Without WooCommerce the handle isn't
	 * registered, and WordPress keeps nothing for it.
	 */
	public static function attach(): void {
		if ( ! Picture::serves() ) {
			return;
		}

		/* Before WooCommerce's script, never after it: WordPress takes `defer` off a script with an inline script after it, and off its dependencies. */
		wp_add_inline_script( self::HANDLE, self::SCRIPT, 'before' );
	}
}
