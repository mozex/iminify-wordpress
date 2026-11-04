<?php
/**
 * Starts the plugin and hands out its shared parts.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

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
