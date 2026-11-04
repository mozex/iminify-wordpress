<?php
/**
 * Starts the plugin and hands out its shared parts.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Admin\Rest_Controller;
use Iminify\WordPress\Admin\Settings_Page;
use Iminify\WordPress\Api\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's entry point. Nothing here calls Iminify: the service is reached
 * only once someone connects the site.
 */
class Plugin {

	/**
	 * Hooks the plugin into WordPress.
	 */
	public static function boot(): void {
		add_action( 'init', array( Local_Settings::class, 'register' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( Connection::CLAIM_HOOK, array( self::class, 'claim_in_background' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( Settings_Page::class, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( Settings_Page::class, 'enqueue' ) );
			add_action( 'admin_enqueue_scripts', array( Notice::class, 'enqueue' ) );
			add_action( 'admin_notices', array( Notice::class, 'render' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( IMINIFY_FILE ), array( Settings_Page::class, 'action_links' ) );
		}
	}

	/**
	 * Registers the settings page's REST routes.
	 */
	public static function register_routes(): void {
		( new Rest_Controller() )->register_routes();
	}

	/**
	 * Action Scheduler's background claim for a waiting pairing.
	 *
	 * @param mixed $pairing_id The pairing it was scheduled for.
	 */
	public static function claim_in_background( $pairing_id ): void {
		( new Connection( self::keys() ) )->run_background_claim( is_string( $pairing_id ) ? $pairing_id : '' );
	}

	/**
	 * Where the site key is kept.
	 */
	public static function keys(): Key_Store {
		return new Key_Store();
	}

	/**
	 * A client carrying the site key, or null while the site isn't connected.
	 */
	public static function client(): ?Client {
		$key = self::keys()->get();

		return null === $key ? null : new Client( $key );
	}
}
