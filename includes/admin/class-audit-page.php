<?php
/**
 * Media > Page Audit.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page that has Iminify audit the images on one of the site's pages and
 * shows each image beside the attachment and size it is, with what is worth
 * fixing. A script built from `src/audit`, drawn from what Audit_State answers
 * through Audit_Controller. Each audit spends one of the account's page scans,
 * so it asks what `Capabilities::AUDIT` names.
 */
class Audit_Page {

	const SLUG = 'iminify-audit';

	const HANDLE = 'iminify-audit';

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
			__( 'Page Audit', 'iminify-image-optimizer' ),
			__( 'Page Audit', 'iminify-image-optimizer' ),
			Capabilities::required( Capabilities::AUDIT ),
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

		if ( Settings_Page::enqueue_entry( self::HANDLE, 'audit' ) && is_readable( IMINIFY_DIR . '/assets/build/audit.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/audit.css', IMINIFY_FILE ), array(), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			Scheme::attach( self::HANDLE );
		}
	}

	/**
	 * Prints the page's frame, which the script fills.
	 */
	public static function render(): void {
		Page_Header::open( __( 'Page Audit', 'iminify-image-optimizer' ), __( 'Find the images that slow your pages down, each beside the image in your library it is.', 'iminify-image-optimizer' ) );

		if ( ! is_readable( IMINIFY_DIR . '/assets/build/audit.asset.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The page\'s script is missing from the plugin\'s folder. Reinstall the plugin to bring it back.', 'iminify-image-optimizer' ) . '</p></div>';
		} else {
			echo '<div id="iminify-audit" class="iminify-app"></div><noscript><p>' . esc_html__( 'This page needs JavaScript.', 'iminify-image-optimizer' ) . '</p></noscript>';
		}

		Page_Header::close();
	}
}
