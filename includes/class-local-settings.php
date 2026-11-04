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
 * the service applies it. These two act only inside WordPress and keep working
 * without a connection: whether the plugin keeps a backup of each image it
 * replaces, and how the site's pages serve the WebP and AVIF copies.
 */
class Local_Settings {

	const OPTION = 'iminify_settings';

	const GROUP = 'iminify';

	const DELIVERY_PICTURE = 'picture';

	const DELIVERY_OFF = 'off';

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
	 * never opens the settings page gets both.
	 *
	 * @return array{backups: bool, delivery: string}
	 */
	public static function defaults(): array {
		return array(
			'backups'  => true,
			'delivery' => self::DELIVERY_PICTURE,
		);
	}

	/**
	 * The ways the site can serve the copies.
	 *
	 * @return list<string>
	 */
	public static function deliveries(): array {
		return array( self::DELIVERY_PICTURE, self::DELIVERY_OFF );
	}

	/**
	 * The settings, each one missing or unreadable taking its default.
	 *
	 * @return array{backups: bool, delivery: string}
	 */
	public static function get(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Changes some of the settings.
	 *
	 * @param array<string, mixed> $changes The settings to change.
	 * @return array{backups: bool, delivery: string}
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
	 * @return array{backups: bool, delivery: string}
	 */
	public static function sanitize( $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$defaults = self::defaults();
		$delivery = isset( $value['delivery'] ) && is_string( $value['delivery'] ) ? $value['delivery'] : '';

		return array(
			'backups'  => isset( $value['backups'] ) ? filter_var( $value['backups'], FILTER_VALIDATE_BOOLEAN ) : $defaults['backups'],
			'delivery' => in_array( $delivery, self::deliveries(), true ) ? $delivery : $defaults['delivery'],
		);
	}
}
