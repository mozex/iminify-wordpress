<?php
/**
 * The plugin's settings page.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > Iminify: connecting the site, the account's plan and counts,
 * the site's settings kept on www.iminify.com, and the two kept here. The
 * page is a script built with @wordpress/scripts from `src/` (shipped with
 * the plugin) into `assets/build/`, drawn from the state the plugin's REST
 * routes answer.
 */
class Settings_Page {

	const SLUG = 'iminify-image-optimizer';

	const HANDLE = 'iminify-settings';

	/**
	 * The page's hook suffix, once its menu item is added.
	 *
	 * @var string|null
	 */
	protected static $hook;

	/**
	 * Adds the page under Settings.
	 */
	public static function register(): void {
		$hook = add_options_page(
			__( 'Iminify Image Optimizer', 'iminify-image-optimizer' ),
			__( 'Iminify', 'iminify-image-optimizer' ),
			Capabilities::required( Capabilities::SETTINGS ),
			self::SLUG,
			array( self::class, 'render' )
		);

		self::$hook = is_string( $hook ) ? $hook : null;
	}

	/**
	 * The page's address.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array<int|string, string> $links The row's links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'iminify-image-optimizer' ) ) );

		return $links;
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

		if ( self::enqueue_entry( self::HANDLE, 'settings' ) && is_readable( IMINIFY_DIR . '/assets/build/settings.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/settings.css', IMINIFY_FILE ), array( 'wp-components' ), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			Scheme::attach( self::HANDLE );
		}
	}

	/**
	 * Enqueues one of the built scripts with the dependencies the build wrote beside it.
	 *
	 * @param non-empty-string $handle The script's handle.
	 * @param string           $entry  The build's entry name.
	 * @param list<non-empty-string> $needs  What it needs besides what the build found.
	 * @return bool Whether the built script exists.
	 */
	public static function enqueue_entry( string $handle, string $entry, array $needs = array() ): bool {
		$asset = self::asset( $entry );

		if ( null === $asset ) {
			return false;
		}

		wp_enqueue_script( $handle, plugins_url( 'assets/build/' . $entry . '.js', IMINIFY_FILE ), array_merge( $asset['dependencies'], $needs ), $asset['version'], true );
		wp_set_script_translations( $handle, 'iminify-image-optimizer' );

		return true;
	}

	/**
	 * Prints the page's frame, which the script fills.
	 */
	public static function render(): void {
		Page_Header::open( __( 'Iminify Image Optimizer', 'iminify-image-optimizer' ), __( 'Connect this site and choose how its images are optimized.', 'iminify-image-optimizer' ) );

		if ( null === self::asset( 'settings' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The settings page\'s script is missing from the plugin\'s folder. Reinstall the plugin to bring it back.', 'iminify-image-optimizer' ) . '</p></div>';
		} else {
			echo '<div id="iminify-settings" class="iminify-app"></div><noscript><p>' . esc_html__( 'The settings page needs JavaScript.', 'iminify-image-optimizer' ) . '</p></noscript>';
		}

		Page_Header::close();
	}

	/**
	 * The dependencies and version the build wrote for an entry, or null when it isn't built.
	 *
	 * @param string $entry The build's entry name.
	 * @return array{dependencies: list<non-empty-string>, version: string}|null
	 */
	protected static function asset( string $entry ): ?array {
		$file = IMINIFY_DIR . '/assets/build/' . $entry . '.asset.php';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$asset = require $file;

		if ( ! is_array( $asset ) ) {
			return null;
		}

		$dependencies = array();

		foreach ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() as $dependency ) {
			if ( is_string( $dependency ) && '' !== $dependency ) {
				$dependencies[] = $dependency;
			}
		}

		return array(
			'dependencies' => $dependencies,
			'version'      => isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : IMINIFY_VERSION,
		);
	}
}
