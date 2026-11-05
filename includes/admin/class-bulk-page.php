<?php
/**
 * The bulk page under Media.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media > Bulk Optimization: the library's counts and savings, the service's
 * estimate, and the runs that optimize or restore the library. A script built
 * from `src/bulk`, drawn from what Bulk_State answers through Bulk_Controller.
 */
class Bulk_Page {

	const SLUG = 'iminify-bulk';

	const HANDLE = 'iminify-bulk';

	/**
	 * The page's hook suffix, once its menu item is added.
	 *
	 * @var string|null
	 */
	protected static $hook;

	/**
	 * Adds the page under Media.
	 */
	public static function register(): void {
		$hook = add_media_page(
			__( 'Bulk Optimization', 'iminify-image-optimizer' ),
			__( 'Bulk Optimization', 'iminify-image-optimizer' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);

		self::$hook = is_string( $hook ) ? $hook : null;
	}

	/**
	 * The page's address.
	 */
	public static function url(): string {
		return admin_url( 'upload.php?page=' . self::SLUG );
	}

	/**
	 * Loads the page's script and styles on the page alone.
	 *
	 * @param string $hook The screen's hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( null === self::$hook || self::$hook !== $hook ) {
			return;
		}

		if ( Settings_Page::enqueue_entry( self::HANDLE, 'bulk' ) && is_readable( IMINIFY_DIR . '/assets/build/bulk.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/bulk.css', IMINIFY_FILE ), array( 'wp-components' ), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Prints the page's frame, which the script fills.
	 */
	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Optimization', 'iminify-image-optimizer' ) . '</h1>';

		if ( ! is_readable( IMINIFY_DIR . '/assets/build/bulk.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The bulk page\'s script is missing from the plugin\'s folder. Reinstall the plugin to bring it back.', 'iminify-image-optimizer' ) . '</p></div>';
		} else {
			echo '<div id="iminify-bulk"></div><noscript><p>' . esc_html__( 'The bulk page needs JavaScript.', 'iminify-image-optimizer' ) . '</p></noscript>';
		}

		echo '</div>';
	}
}
