<?php
/**
 * What the settings page shows, built on the server.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings page's whole state in one answer, so the page's script only
 * draws it: the connection, the site's record from Iminify with its numbers
 * formatted and its dates in the site's timezone, the message in the site's
 * language, the local settings and the image sizes WordPress makes. It never
 * holds the key.
 */
class State {

	/**
	 * Builds the state, reading the site's record from Iminify when the site is connected.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$state = self::connection();

		/* After the read above, which may have started or ended the pause. */
		$state['queue'] = Queue::summary();
		$state['disk']  = array( 'low' => ! Disk::has_room_for( 0 ) );

		return $state;
	}

	/**
	 * The connection and everything read from Iminify through it.
	 *
	 * @return array<string, mixed>
	 */
	protected static function connection(): array {
		$keys       = Plugin::keys();
		$connection = new Connection();
		$pairing    = $connection->pairing();
		$hidden     = $keys->hides_account();

		$state = array(
			'connection'    => null === $pairing ? 'disconnected' : 'pairing',
			'key_in_config' => $keys->from_constant(),
			'hidden'        => $hidden,
			'revoked'       => Connection::was_revoked(),
			'pairing'       => null === $pairing ? null : array(
				'connect_url' => $pairing['connect_url'],
				'expires_at'  => gmdate( 'c', $pairing['expires_at'] ),
			),
			'error'         => null,
			'site'          => null,
			'local'         => Local_Settings::get(),
			'deliveries'    => Local_Settings::deliveries(),
			'levels'        => self::levels(),
			'sizes'         => self::sizes(),
			'links'         => self::links( $hidden ),
		);

		if ( null === $keys->get() ) {
			return $state;
		}

		$site = Site_Record::fetch();

		if ( null === $site ) {
			return $state;
		}

		if ( $site instanceof WP_Error ) {
			$state['error'] = array(
				'code'    => (string) $site->get_error_code(),
				'message' => Messages::for_error( $site ),
			);

			if ( 'site_moved' === $site->get_error_code() ) {
				$state['connection'] = 'moved';
			} elseif ( 'unauthenticated' !== $site->get_error_code() || $keys->from_constant() ) {
				$state['connection'] = 'error';
			} else {
				$state['revoked'] = true;
				$state['error']   = null;
			}

			return $state;
		}

		$state['connection'] = 'connected';
		$state['site']       = self::site( $site, $hidden );

		return $state;
	}

	/**
	 * The site's record as the page shows it.
	 *
	 * @param array<string, mixed> $site   The record Iminify answered.
	 * @param bool                 $hidden Whether wp-config.php keeps the account out of sight.
	 * @return array<string, mixed>
	 */
	public static function site( array $site, bool $hidden = false ): array {
		$usage   = isset( $site['usage'] ) && is_array( $site['usage'] ) ? $site['usage'] : array();
		$message = Messages::for_site( $site['message'] ?? null, $hidden );

		return array(
			'name'          => isset( $site['name'] ) && is_string( $site['name'] ) ? $site['name'] : '',
			'url'           => isset( $site['url'] ) && is_string( $site['url'] ) ? $site['url'] : '',
			'settings'      => isset( $site['settings'] ) && is_array( $site['settings'] ) ? $site['settings'] : array(),
			'plan'          => $hidden ? null : self::plan( $site ),
			'usage'         => $hidden ? null : array_values(
				array_filter(
					array(
						self::usage( 'images', __( 'Images today', 'iminify-image-optimizer' ), $usage['images'] ?? null ),
						self::usage( 'files', __( 'Files today', 'iminify-image-optimizer' ), $usage['files'] ?? null ),
					)
				)
			),
			'next_batch_at' => self::date( $site['next_batch_at'] ?? null ),
			'message'       => $message,
		);
	}

	/**
	 * The account's plan with its name, and when a paid plan or the trial ends.
	 *
	 * @param array<string, mixed> $site The record Iminify answered.
	 * @return array{code: string, label: string, ends_at: string|null}
	 */
	protected static function plan( array $site ): array {
		$code = isset( $site['plan'] ) && is_string( $site['plan'] ) ? $site['plan'] : '';

		return array(
			'code'    => $code,
			'label'   => self::plan_label( $code ),
			'ends_at' => self::date( $site['plan_ends_at'] ?? null ),
		);
	}

	/**
	 * A plan's name, from its code.
	 *
	 * @param string $code The plan's code.
	 */
	public static function plan_label( string $code ): string {
		$labels = array(
			'guest' => __( 'Guest', 'iminify-image-optimizer' ),
			'free'  => __( 'Free', 'iminify-image-optimizer' ),
			'trial' => __( 'Pro trial', 'iminify-image-optimizer' ),
			'pro'   => __( 'Pro', 'iminify-image-optimizer' ),
			'max'   => __( 'Max', 'iminify-image-optimizer' ),
		);

		return $labels[ $code ] ?? ucfirst( $code );
	}

	/**
	 * One of the day's counts: what was used and what is left, formatted, where a
	 * missing number left means the plan sets no daily limit, and the share of
	 * the day used, which the page draws as a meter.
	 *
	 * @param string $key   The count's name.
	 * @param string $label What the page calls it.
	 * @param mixed  $count The record's count.
	 * @return array{key: string, label: string, used: string, remaining: string|null, share: float|null}|null
	 */
	protected static function usage( string $key, string $label, $count ): ?array {
		if ( ! is_array( $count ) || ! isset( $count['used'] ) || ! is_numeric( $count['used'] ) ) {
			return null;
		}

		$used      = max( 0, (int) $count['used'] );
		$remaining = isset( $count['remaining'] ) && is_numeric( $count['remaining'] ) ? max( 0, (int) $count['remaining'] ) : null;

		return array(
			'key'       => $key,
			'label'     => $label,
			'used'      => number_format_i18n( $used ),
			'remaining' => null === $remaining ? null : number_format_i18n( $remaining ),
			'share'     => null === $remaining ? null : ( 0 === $used + $remaining ? 1.0 : round( $used / ( $used + $remaining ), 3 ) ),
		);
	}

	/**
	 * A date from Iminify in the site's timezone and formats, or null.
	 *
	 * @param mixed $value An ISO 8601 date.
	 */
	public static function date( $value ): ?string {
		$time = is_string( $value ) ? strtotime( $value ) : false;

		if ( false === $time ) {
			return null;
		}

		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );

		return is_string( $formatted ) ? $formatted : null;
	}

	/**
	 * The compression levels, for the setting that picks one.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	protected static function levels(): array {
		$levels = array();

		foreach ( Messages::levels() as $value => $level ) {
			$levels[] = array(
				'value' => $value,
				'label' => $level['label'],
			);
		}

		return $levels;
	}

	/**
	 * The image sizes WordPress makes on this site, for the setting that picks which to send.
	 *
	 * @return list<array{name: string, label: string}>
	 */
	protected static function sizes(): array {
		$defaults = array(
			'thumbnail' => __( 'Thumbnail', 'iminify-image-optimizer' ),
			'medium'    => __( 'Medium', 'iminify-image-optimizer' ),
			'large'     => __( 'Large', 'iminify-image-optimizer' ),
		);
		/** This filter is documented in wp-admin/includes/media.php */
		$names = apply_filters( 'image_size_names_choose', $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, read for the names a site gives its sizes.
		$sizes = array();

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$label = is_array( $names ) && isset( $names[ $name ] ) && is_string( $names[ $name ] ) ? $names[ $name ] : $name;

			$sizes[] = array(
				'name'  => (string) $name,
				'label' => self::size_label( $label, (int) $size['width'], (int) $size['height'] ),
			);
		}

		return $sizes;
	}

	/**
	 * A size's name with its bounds, where 0 leaves a side unbounded, as WordPress's `medium_large` leaves its height.
	 *
	 * @param string $name   The size's name.
	 * @param int    $width  Its width, or 0.
	 * @param int    $height Its height, or 0.
	 */
	protected static function size_label( string $name, int $width, int $height ): string {
		if ( 0 === $height ) {
			/* translators: 1: the size's name, 2: its width in pixels. */
			return sprintf( __( '%1$s (%2$d wide)', 'iminify-image-optimizer' ), $name, $width );
		}

		if ( 0 === $width ) {
			/* translators: 1: the size's name, 2: its height in pixels. */
			return sprintf( __( '%1$s (%2$d high)', 'iminify-image-optimizer' ), $name, $height );
		}

		/* translators: 1: the size's name, 2: its width in pixels, 3: its height in pixels. */
		return sprintf( __( '%1$s (%2$d × %3$d)', 'iminify-image-optimizer' ), $name, $width, $height );
	}

	/**
	 * The pages on www.iminify.com the settings page links to; none of the account's while it is kept out of sight.
	 *
	 * @param bool $hidden Whether wp-config.php keeps the account out of sight.
	 * @return array<string, string>
	 */
	protected static function links( bool $hidden ): array {
		$query = array( 'utm_campaign' => 'settings' );
		$links = array(
			'guide' => Client::website( 'docs/wordpress', $query ),
		);

		if ( ! $hidden ) {
			$links['plans'] = Client::website( 'pricing', $query );
			$links['plan']  = Client::website( 'settings/plan', $query );
			$links['sites'] = Client::website( 'settings/sites', $query );
		}

		return $links;
	}
}
