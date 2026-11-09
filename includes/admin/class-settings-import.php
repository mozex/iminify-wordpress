<?php
/**
 * "Use <plugin>'s settings": another optimizer's settings as the site's own.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Settings_Copy;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings import: the press sends the copy of another
 * optimizer's settings (`Settings_Copy`, taken again first while that plugin's
 * options are still there), its short name and the image sizes the site
 * registers to Iminify (`POST /site/import`), which maps them and writes
 * nothing; the page shows what that proposes beside the current values, and a
 * second press sends the proposal as it is through `PATCH /site`. Whether that
 * plugin kept backups is applied here, and only when it did: another plugin's
 * "off" never turns off the plugin's own backups.
 */
class Settings_Import {

	/**
	 * The most image sizes a site sends, as many as Iminify takes from a site; a
	 * size past them isn't matched by name.
	 */
	const MAX_SIZES = 100;

	/**
	 * Asks Iminify what another plugin's settings match, and keeps the proposal.
	 *
	 * @param string $slug The plugin's short name.
	 * @return array{settings: array<string, mixed>, backups: bool|null}|WP_Error
	 */
	public static function preview( string $slug ) {
		$reader = Others::reader( $slug );

		if ( null !== $reader ) {
			Settings_Copy::take( $reader );
		}

		$options = Settings_Copy::options( $slug );

		if ( null === $options ) {
			return new WP_Error( 'no_settings', __( 'The plugin found no settings of that optimizer on this site.', 'iminify-image-optimizer' ), array( 'status' => 404 ) );
		}

		$client = Plugin::client();

		if ( null === $client ) {
			return self::not_connected();
		}

		$answer = $client->import_settings(
			array(
				'plugin'   => $slug,
				'settings' => $options,
				'sizes'    => self::sizes(),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$settings = isset( $answer['settings'] ) && is_array( $answer['settings'] ) ? $answer['settings'] : array();
		$proposal = array(
			'settings' => array_intersect_key( $settings, array_flip( Rest_Controller::SITE_SETTINGS ) ),
			'backups'  => isset( $answer['backups'] ) && is_bool( $answer['backups'] ) ? $answer['backups'] : null,
		);

		Settings_Copy::propose( $slug, $proposal );

		return $proposal;
	}

	/**
	 * Uses the last proposal: the site's settings through `PATCH /site`, then the
	 * backups turned on here when that plugin kept them.
	 *
	 * @param string $slug The plugin's short name.
	 * @return true|WP_Error
	 */
	public static function apply( string $slug ) {
		$reader = Others::reader( $slug );

		/* A copy taken afresh drops the proposal when that plugin's settings changed since it was shown. */
		if ( null !== $reader ) {
			Settings_Copy::take( $reader );
		}

		$proposal = Settings_Copy::proposal( $slug );

		if ( null === $proposal ) {
			return new WP_Error( 'no_proposal', __( 'Those settings changed since they were shown. Look at them again before using them.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( array() !== $proposal['settings'] ) {
			$client = Plugin::client();

			if ( null === $client ) {
				return self::not_connected();
			}

			$record = $client->update_site( $proposal['settings'] );

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			Site_Record::remember( $record );
		}

		if ( true === $proposal['backups'] ) {
			Local_Settings::update( array( 'backups' => true ) );
		}

		Settings_Copy::answer( $slug, 'used' );

		return true;
	}

	/**
	 * Turns the offer down, so the page stops making it.
	 *
	 * @param string $slug The plugin's short name.
	 */
	public static function decline( string $slug ): void {
		Settings_Copy::answer( $slug, 'declined' );
	}

	/**
	 * What the page shows of a proposal: each setting it names with its value now
	 * and the one proposed, in the settings form's words, and whether it changes.
	 *
	 * @param string                                                    $slug     The plugin's short name.
	 * @param array{settings: array<string, mixed>, backups: bool|null} $proposal The proposal.
	 * @param array<string, mixed>                                      $state    The page's state, for the values now.
	 * @return array{plugin: string, name: string, rows: list<array{label: string, now: string, then: string, changes: bool}>}
	 */
	public static function view( string $slug, array $proposal, array $state ): array {
		$current = isset( $state['site']['settings'] ) && is_array( $state['site']['settings'] ) ? $state['site']['settings'] : array();
		$rows    = array();

		foreach ( Rest_Controller::SITE_SETTINGS as $setting ) {
			if ( ! array_key_exists( $setting, $proposal['settings'] ) ) {
				continue;
			}

			$now  = $current[ $setting ] ?? null;
			$then = $proposal['settings'][ $setting ];

			$rows[] = array(
				'label'   => self::label( $setting ),
				'now'     => self::value( $setting, $now ),
				'then'    => self::value( $setting, $then ),
				'changes' => $now !== $then,
			);
		}

		if ( true === $proposal['backups'] ) {
			$now = ! empty( $state['local']['backups'] );

			$rows[] = array(
				'label'   => self::label( 'backups' ),
				'now'     => self::value( 'backups', $now ),
				'then'    => self::value( 'backups', true ),
				'changes' => ! $now,
			);
		}

		return array(
			'plugin' => $slug,
			'name'   => Others::name( $slug ),
			'rows'   => $rows,
		);
	}

	/**
	 * The names of the image sizes the site registers, as many as Iminify takes.
	 *
	 * @return list<string>
	 */
	protected static function sizes(): array {
		return array_slice( array_map( 'strval', array_keys( wp_get_registered_image_subsizes() ) ), 0, self::MAX_SIZES );
	}

	/**
	 * A setting's name, as the settings form calls it.
	 *
	 * @param string $setting The setting.
	 */
	protected static function label( string $setting ): string {
		switch ( $setting ) {
			case 'optimize_on_upload':
				return __( 'Optimize new uploads', 'iminify-image-optimizer' );
			case 'level':
				return __( 'Compression level', 'iminify-image-optimizer' );
			case 'webp':
				return __( 'Make WebP copies', 'iminify-image-optimizer' );
			case 'avif':
				return __( 'Make AVIF copies', 'iminify-image-optimizer' );
			case 'max_width':
				return __( 'Maximum width, in pixels', 'iminify-image-optimizer' );
			case 'size_limit':
				return __( 'Size limit, in KB', 'iminify-image-optimizer' );
			case 'keep_metadata':
				return __( 'Keep metadata', 'iminify-image-optimizer' );
			case 'sizes':
				return __( 'Sizes to optimize', 'iminify-image-optimizer' );
			default:
				return __( 'Keep a backup of each original', 'iminify-image-optimizer' );
		}
	}

	/**
	 * A setting's value in words.
	 *
	 * @param string $setting The setting.
	 * @param mixed  $value   Its value.
	 */
	protected static function value( string $setting, $value ): string {
		switch ( $setting ) {
			case 'level':
				$levels = Messages::levels();

				return is_string( $value ) && isset( $levels[ $value ] ) ? $levels[ $value ]['name'] : '';
			case 'max_width':
			case 'size_limit':
				return is_int( $value ) ? number_format_i18n( $value ) : __( 'No limit', 'iminify-image-optimizer' );
			case 'sizes':
				if ( ! is_array( $value ) ) {
					return __( 'Every size', 'iminify-image-optimizer' );
				}

				if ( array() === $value ) {
					return __( 'Only the full image', 'iminify-image-optimizer' );
				}

				$names = State::size_names();

				return implode(
					wp_get_list_item_separator(),
					array_map(
						static function ( $size ) use ( $names ): string {
							return $names[ (string) $size ] ?? (string) $size;
						},
						$value
					)
				);
			default:
				return $value ? __( 'On', 'iminify-image-optimizer' ) : __( 'Off', 'iminify-image-optimizer' );
		}
	}

	/**
	 * Why nothing can be asked of Iminify.
	 */
	protected static function not_connected(): WP_Error {
		return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
	}
}
