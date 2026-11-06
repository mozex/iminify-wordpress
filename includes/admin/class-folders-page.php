<?php
/**
 * Media > Other Folders.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Folders\Folders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page that chooses the folders outside the media library (the active theme,
 * its parent, any folder in wp-content), looks through them, and lists their
 * images with what each can do. A script built from `src/folders`, drawn from what
 * Folders_State answers through Folders_Controller. On a network it is the main
 * site's alone, since every site shares `wp-content`.
 */
class Folders_Page {

	const SLUG = 'iminify-folders';

	const HANDLE = 'iminify-folders';

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
		if ( ! Folders::available() ) {
			return;
		}

		$hook = add_media_page(
			__( 'Other Folders', 'iminify-image-optimizer' ),
			__( 'Other Folders', 'iminify-image-optimizer' ),
			Capabilities::required( Capabilities::FOLDERS ),
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

		if ( Settings_Page::enqueue_entry( self::HANDLE, 'folders' ) && is_readable( IMINIFY_DIR . '/assets/build/folders.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/folders.css', IMINIFY_FILE ), array(), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			Scheme::attach( self::HANDLE );
		}
	}

	/**
	 * Prints the page's frame, which the script fills.
	 */
	public static function render(): void {
		Page_Header::open( __( 'Other Folders', 'iminify-image-optimizer' ), __( 'The images in your theme and other folders outside the media library.', 'iminify-image-optimizer' ) );

		if ( ! is_readable( IMINIFY_DIR . '/assets/build/folders.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The page\'s script is missing from the plugin\'s folder. Reinstall the plugin to bring it back.', 'iminify-image-optimizer' ) . '</p></div>';
		} else {
			echo '<div id="iminify-folders" class="iminify-app"></div><noscript><p>' . esc_html__( 'This page needs JavaScript.', 'iminify-image-optimizer' ) . '</p></noscript>';
		}

		Page_Header::close();
	}
}
