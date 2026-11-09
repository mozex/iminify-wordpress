<?php
/**
 * The two settings kept on the site itself.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every other setting lives on www.iminify.com with the site's record, since
 * the service applies it. These act only inside WordPress and keep working
 * without a connection: whether the plugin keeps a backup of each image it
 * replaces, how the site's pages serve the WebP and AVIF copies, and whether
 * deleting the plugin also deletes the copies and the backups (both kept unless
 * ticked: the backups are the only originals of the images it optimized).
 */
class Local_Settings {

	const OPTION = 'iminify_settings';

	const GROUP = 'iminify';

	const DELIVERY_PICTURE = 'picture';

	/**
	 * `<picture>` markup for every image on a page, through a buffer over the
	 * whole page (`Delivery\Buffer`), beside core's filters.
	 */
	const DELIVERY_PAGE = 'page';

	const DELIVERY_OFF = 'off';

	/**
	 * The pages stay as they are and the server sends a copy in place of the image
	 * itself, through rules of its own (`Delivery\Rules`).
	 */
	const DELIVERY_RULES = 'rules';

	/**
	 * Registers the option with its sanitizer, so every write goes through it.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Backups on, and `<picture>` markup for the copies that exist: a site that
	 * never opens the settings page gets both, and deleting the plugin keeps
	 * every file it made. Filling in what an image's markup lacks (`Complete`)
	 * changes every page's markup, so it waits for someone to turn it on.
	 *
	 * @return array{backups: bool, delivery: string, complete_images: bool, uninstall_copies: bool, uninstall_backups: bool}
	 */
	public static function defaults(): array {
		return array(
			'backups'           => true,
			'delivery'          => self::DELIVERY_PICTURE,
			'complete_images'   => false,
			'uninstall_copies'  => false,
			'uninstall_backups' => false,
		);
	}

	/**
	 * The ways the site can serve the copies.
	 *
	 * @return list<string>
	 */
	public static function deliveries(): array {
		return array( self::DELIVERY_PICTURE, self::DELIVERY_PAGE, self::DELIVERY_RULES, self::DELIVERY_OFF );
	}

	/**
	 * The settings, each one missing or unreadable taking its default.
	 *
	 * @return array{backups: bool, delivery: string, complete_images: bool, uninstall_copies: bool, uninstall_backups: bool}
	 */
	public static function get(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Changes some of the settings.
	 *
	 * @param array<string, mixed> $changes The settings to change.
	 * @return array{backups: bool, delivery: string, complete_images: bool, uninstall_copies: bool, uninstall_backups: bool}
	 */
	public static function update( array $changes ): array {
		$settings = self::sanitize( array_merge( self::get(), array_intersect_key( $changes, self::defaults() ) ) );

		update_option( self::OPTION, $settings );

		return $settings;
	}

	/**
	 * Keeps only the known settings, each as its type, and a delivery the plugin knows.
	 *
	 * @param mixed $value The value to store.
	 * @return array{backups: bool, delivery: string, complete_images: bool, uninstall_copies: bool, uninstall_backups: bool}
	 */
	public static function sanitize( $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$defaults = self::defaults();
		$delivery = isset( $value['delivery'] ) && is_string( $value['delivery'] ) ? $value['delivery'] : '';

		return array(
			'backups'           => self::flag( $value, 'backups', $defaults['backups'] ),
			'delivery'          => in_array( $delivery, self::deliveries(), true ) ? $delivery : $defaults['delivery'],
			'complete_images'   => self::flag( $value, 'complete_images', $defaults['complete_images'] ),
			'uninstall_copies'  => self::flag( $value, 'uninstall_copies', $defaults['uninstall_copies'] ),
			'uninstall_backups' => self::flag( $value, 'uninstall_backups', $defaults['uninstall_backups'] ),
		);
	}

	/**
	 * A yes or no setting as a boolean, or its default when missing.
	 *
	 * @param array<mixed> $value   The settings given.
	 * @param string       $key     The setting.
	 * @param bool         $fallback Its default.
	 */
	protected static function flag( array $value, string $key, bool $fallback ): bool {
		return isset( $value[ $key ] ) ? filter_var( $value[ $key ], FILTER_VALIDATE_BOOLEAN ) : $fallback;
	}
}
