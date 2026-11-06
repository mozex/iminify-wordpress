<?php
/**
 * Starts the plugin and hands out its shared parts.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Admin\Bulk_Controller;
use Iminify\WordPress\Admin\Bulk_Page;
use Iminify\WordPress\Admin\Folders_Controller;
use Iminify\WordPress\Admin\Folders_Page;
use Iminify\WordPress\Admin\Media_Controller;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Admin\Privacy;
use Iminify\WordPress\Admin\Rest_Controller;
use Iminify\WordPress\Admin\Settings_Page;
use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Compat\Woocommerce;
use Iminify\WordPress\Delivery\Buffer;
use Iminify\WordPress\Folders\Hooks as Folder_Hooks;
use Iminify\WordPress\Media\Hooks;
use Iminify\WordPress\Media\Picture;

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
		add_action( 'action_scheduler_init', array( Lifecycle::class, 'resume' ) );
		Hooks::register();
		Folder_Hooks::register();
		Picture::register();
		Buffer::register();
		Caches::register();
		Offload_Media::register();
		Woocommerce::register();
		Upload::register();
		Loopback::register();
		Cli::register();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Privacy::class, 'register' ) );
			add_action( 'admin_menu', array( Settings_Page::class, 'register' ) );
			add_action( 'admin_menu', array( Bulk_Page::class, 'register' ) );
			add_action( 'admin_menu', array( Folders_Page::class, 'register' ) );
			add_action( 'network_admin_menu', array( Settings_Page::class, 'register_network' ) );
			add_action( 'admin_enqueue_scripts', array( Settings_Page::class, 'enqueue' ) );
			add_action( 'admin_enqueue_scripts', array( Bulk_Page::class, 'enqueue' ) );
			add_action( 'admin_enqueue_scripts', array( Folders_Page::class, 'enqueue' ) );
			add_action( 'admin_enqueue_scripts', array( Notice::class, 'enqueue' ) );
			add_action( 'admin_notices', array( Notice::class, 'render' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( IMINIFY_FILE ), array( Settings_Page::class, 'action_links' ) );
			add_filter( 'network_admin_plugin_action_links_' . plugin_basename( IMINIFY_FILE ), array( Settings_Page::class, 'network_action_links' ) );
			Media_View::register();
		}
	}

	/**
	 * Registers the settings page's REST routes.
	 */
	public static function register_routes(): void {
		( new Rest_Controller() )->register_routes();
		( new Media_Controller() )->register_routes();
		( new Bulk_Controller() )->register_routes();
		( new Folders_Controller() )->register_routes();
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
	 * A site of a connected network without a key of its own joins the network
	 * here (`Network::join()`), the first time it needs Iminify: only the
	 * background and the plugin's own pages ask for a client, so no upload and
	 * no other screen waits on the join.
	 */
	public static function client(): ?Client {
		$key = self::keys()->get();

		if ( null === $key && Network::joinable() ) {
			$joined = Network::join();
			$key    = is_string( $joined ) ? $joined : null;
		}

		return null === $key ? null : new Client( $key );
	}

	/**
	 * Whether the site can reach Iminify: it has a key, or it is a site of a
	 * connected network that will join it when it first needs to. Read locally,
	 * so any screen may ask.
	 */
	public static function connected(): bool {
		return null !== self::keys()->get() || Network::joinable();
	}
}
