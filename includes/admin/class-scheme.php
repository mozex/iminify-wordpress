<?php
/**
 * The person's admin colour scheme, for keyboard focus.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The design keeps its sky accent on every admin colour scheme, while keyboard
 * focus takes the person's scheme colour. WordPress 7.0 and later declare that
 * colour on the admin's body (`--wp-admin-theme-color`); WordPress 6.9 declares
 * it only inside the block editor, so the plugin's stylesheets get it here, as
 * `--iminify-scheme`, from the same colours core gives each scheme.
 */
class Scheme {

	/**
	 * The design system's root classes, the only elements that read the colour
	 * (their tokens turn it into `--iminify-focus`), so it is declared on them
	 * rather than on the document, where it would sit on every WordPress screen.
	 */
	const ROOTS = '.iminify-ui,.iminify-media,.iminify-notice,.iminify-toasts';

	/**
	 * Each core scheme's colour, as core's base styles name it.
	 */
	const COLORS = array(
		'fresh'     => '#2271b1',
		'light'     => '#007cba',
		'modern'    => '#3858e9',
		'blue'      => '#437aa8',
		'coffee'    => '#916745',
		'ectoplasm' => '#646c3e',
		'midnight'  => '#cf4339',
		'ocean'     => '#567958',
		'sunrise'   => '#ad631e',
	);

	/**
	 * Adds the person's scheme colour to one of the plugin's stylesheets, before
	 * WordPress 7.0, once however often the stylesheet is queued (the media
	 * library's grid queues the media styles for its screen and for the modal).
	 * A scheme another plugin added keeps the default focus colour.
	 *
	 * @param string $handle The stylesheet's handle.
	 */
	public static function attach( string $handle ): void {
		/* From 7.0, core's own variable follows the scheme, custom ones included. */
		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0-alpha', '>=' ) ) {
			return;
		}

		$scheme = get_user_option( 'admin_color' );
		$scheme = is_string( $scheme ) && '' !== $scheme ? $scheme : 'fresh';

		if ( ! isset( self::COLORS[ $scheme ] ) ) {
			return;
		}

		$rule  = self::ROOTS . '{--iminify-scheme:' . self::COLORS[ $scheme ] . '}';
		$added = wp_styles()->get_data( $handle, 'after' );

		if ( ! is_array( $added ) || ! in_array( $rule, $added, true ) ) {
			wp_add_inline_style( $handle, $rule );
		}
	}
}
