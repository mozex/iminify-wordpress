<?php
/**
 * The header of the plugin's own pages.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iminify's mark and wordmark, the page's title and what it is for, and the
 * site's connection, printed on the server so they show before the page's
 * script runs. Core moves other plugins' notices below `.wp-header-end`, so
 * they land under the header rather than inside it. The page's script keeps
 * the connection pill in step afterwards (`useHeaderStatus()` in `src/ui`).
 */
class Page_Header {

	/**
	 * Opens a page: its wrapper, the header and the notices' marker. The page closes the wrapper.
	 *
	 * @param string $title    The page's title.
	 * @param string $subtitle What the page is for, in one line.
	 */
	public static function open( string $title, string $subtitle ): void {
		$connected = Plugin::connected();

		printf(
			'<div class="wrap iminify-ui iminify-page"><div class="iminify-header"><span class="iminify-brand"><img src="%2$s" alt="" width="34" height="34"><img src="%3$s" alt="%4$s" width="61" height="19"></span><span class="iminify-header__divider" aria-hidden="true"></span><div class="iminify-header__title"><h1>%1$s</h1><p>%5$s</p></div><span class="iminify-conn" id="iminify-conn" data-state="%6$s"><span class="iminify-conn__dot" aria-hidden="true"></span><span class="iminify-conn__text">%7$s</span></span></div><hr class="wp-header-end">',
			esc_html( $title ),
			esc_url( plugins_url( 'assets/images/mark.svg', IMINIFY_FILE ) ),
			esc_url( plugins_url( 'assets/images/wordmark.svg', IMINIFY_FILE ) ),
			esc_attr__( 'Iminify', 'iminify-image-optimizer' ),
			esc_html( $subtitle ),
			$connected ? 'on' : 'off',
			$connected ? esc_html__( 'Connected', 'iminify-image-optimizer' ) : esc_html__( 'Not connected', 'iminify-image-optimizer' )
		);
	}

	/**
	 * Closes the page's wrapper.
	 */
	public static function close(): void {
		echo '</div>';
	}
}
