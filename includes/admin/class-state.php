<?php
/**
 * What the settings page shows, built on the server.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Network;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Leftovers;
use Iminify\WordPress\Switching\Settings_Copy;
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
		$state['rules'] = Rules::active() ? self::rules() : null;

		/* Another optimizer acting on uploads holds every optimization back, so the page says which and what to switch off. */
		$state['conflict'] = Bulk_State::conflict();

		/* Rules another optimizer left behind still serve its old copies; they go only on the person's press. */
		$state['leftovers'] = Leftovers::found();

		/* Another optimizer's settings the page offers to use, once the site is connected. */
		$state['imports'] = Settings_Copy::offers();

		return $state;
	}

	/**
	 * The server's rules as the page shows them, with the time of the last look
	 * at them in the site's timezone.
	 *
	 * @return array<string, mixed>
	 */
	protected static function rules(): array {
		$rules            = Rules::state();
		$rules['checked'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $rules['checked'] );

		return $rules;
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
			'network'       => self::network(),
		);

		/* A site of a connected network without a key yet joins it here (`Site_Record::fetch()`), on the plugin's own page. */
		if ( null === $keys->get() && ! Network::joinable() ) {
			return $state;
		}

		$site = Site_Record::fetch();

		if ( null === $site ) {
			$state['network'] = self::network();

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
			'languages'     => self::alt_languages( $site['alt_text_languages'] ?? null ),
			'language'      => self::alt_language( self::alt_languages( $site['alt_text_languages'] ?? null ), (string) get_locale() ),
			'plan'          => $hidden ? null : self::plan( $site ),
			'usage'         => $hidden ? null : array_values(
				array_filter(
					array(
						self::usage( 'images', __( 'Images today', 'iminify-image-optimizer' ), $usage['images'] ?? null ),
						self::usage( 'files', __( 'Files today', 'iminify-image-optimizer' ), $usage['files'] ?? null ),
						empty( $site['settings']['alt_text'] ) ? null : self::usage( 'alt_texts', __( 'AI alt texts today', 'iminify-image-optimizer' ), $usage['alt_texts'] ?? null ),
					)
				)
			),
			'stats'         => self::stats( $site['stats'] ?? null, $hidden ),
			'next_batch_at' => self::date( $site['next_batch_at'] ?? null ),
			'message'       => $message,
			'network'       => self::site_network( $site['network'] ?? null ),
		);
	}

	/**
	 * The network the site is part of on Iminify, as `GET /site` answered it: whether
	 * this is its main site, and how many sites it holds of how many it can.
	 *
	 * @param mixed $network The record's `network`.
	 * @return array{main: bool, sites: string, max_sites: string}|null
	 */
	protected static function site_network( $network ): ?array {
		if ( ! is_array( $network ) || ! isset( $network['sites'], $network['max_sites'] ) || ! is_numeric( $network['sites'] ) || ! is_numeric( $network['max_sites'] ) ) {
			return null;
		}

		return array(
			'main'      => ! empty( $network['main'] ),
			'sites'     => number_format_i18n( (int) $network['sites'] ),
			'max_sites' => number_format_i18n( (int) $network['max_sites'] ),
		);
	}

	/**
	 * The network this site belongs to, on a network the plugin is active for:
	 * whether this is its main site, whether the network is connected, who may
	 * connect it and where, and, for another site, whether its key came from the
	 * network and what an earlier join was refused with. Null elsewhere.
	 *
	 * @return array<string, mixed>|null
	 */
	protected static function network(): ?array {
		if ( ! Network::active() ) {
			return null;
		}

		$main = is_main_site();

		return array(
			'main'        => $main,
			'name'        => Network::name(),
			'connected'   => null !== Network::key(),
			'manage'      => Capabilities::can( Capabilities::NETWORK ),
			'admin_url'   => network_admin_url( 'settings.php?page=' . Settings_Page::SLUG ),
			'site_url'    => get_admin_url( get_main_site_id(), 'options-general.php?page=' . Settings_Page::SLUG ),
			'folders_url' => get_admin_url( get_main_site_id(), 'upload.php?page=' . Folders_Page::SLUG ),
			'joined'      => ! $main && Network::joined(),
			'refusal'     => $main ? null : Network::refusal(),
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
	 * @return array{key: string, label: string, used: string, remaining: string|null, note: string, share: float|null}|null
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
			'note'      => self::usage_note( $key, $used, $remaining ),
			'share'     => null === $remaining ? null : ( 0 === $used + $remaining ? 1.0 : round( $used / ( $used + $remaining ), 3 ) ),
		);
	}

	/**
	 * The line under one of the day's counts: what was used and what is left, or
	 * that the plan sets no limit. Each number has words of its own, since many
	 * languages word "used" and "left" by the number and by what is counted.
	 *
	 * @param string   $key       The count's name: `images`, `files` or `alt_texts`.
	 * @param int      $used      How many were used today.
	 * @param int|null $remaining How many are left, or null with no daily limit.
	 */
	protected static function usage_note( string $key, int $used, ?int $remaining ): string {
		$used_words = number_format_i18n( $used );
		$left_words = number_format_i18n( (int) $remaining );

		switch ( $key ) {
			case 'files':
				/* translators: %s: how many files Iminify made today. */
				$unlimited = _nx( '%s used, no daily limit', '%s used, no daily limit', $used, 'files today', 'iminify-image-optimizer' );
				/* translators: %s: how many files Iminify made today. */
				$spent = _nx( '%s used', '%s used', $used, 'files today', 'iminify-image-optimizer' );
				/* translators: %s: how many files are left today. */
				$left = _nx( '%s left', '%s left', (int) $remaining, 'files today', 'iminify-image-optimizer' );
				break;
			case 'alt_texts':
				/* translators: %s: how many AI alt texts were written today. */
				$unlimited = _nx( '%s used, no daily limit', '%s used, no daily limit', $used, 'AI alt texts today', 'iminify-image-optimizer' );
				/* translators: %s: how many AI alt texts were written today. */
				$spent = _nx( '%s used', '%s used', $used, 'AI alt texts today', 'iminify-image-optimizer' );
				/* translators: %s: how many AI alt texts are left today. */
				$left = _nx( '%s left', '%s left', (int) $remaining, 'AI alt texts today', 'iminify-image-optimizer' );
				break;
			default:
				/* translators: %s: how many images were optimized today. */
				$unlimited = _nx( '%s used, no daily limit', '%s used, no daily limit', $used, 'images today', 'iminify-image-optimizer' );
				/* translators: %s: how many images were optimized today. */
				$spent = _nx( '%s used', '%s used', $used, 'images today', 'iminify-image-optimizer' );
				/* translators: %s: how many images are left today. */
				$left = _nx( '%s left', '%s left', (int) $remaining, 'images today', 'iminify-image-optimizer' );
		}

		if ( null === $remaining ) {
			return sprintf( $unlimited, $used_words );
		}

		return sprintf( $spent, $used_words ) . wp_get_list_item_separator() . sprintf( $left, $left_words );
	}

	/**
	 * What Iminify has saved, as `GET /site` answered it: for this site, and for
	 * the whole account through every way it uses Iminify, unless wp-config.php
	 * keeps the account out of sight.
	 *
	 * @param mixed $stats  The record's `stats`.
	 * @param bool  $hidden Whether wp-config.php keeps the account out of sight.
	 * @return list<array{key: string, label: string, count: int, images: string, saved: string}>
	 */
	protected static function stats( $stats, bool $hidden ): array {
		$rows   = array();
		$labels = array(
			'site'    => __( 'This site', 'iminify-image-optimizer' ),
			'account' => __( 'Your whole account', 'iminify-image-optimizer' ),
		);

		foreach ( $labels as $key => $label ) {
			$row = is_array( $stats ) && isset( $stats[ $key ] ) && is_array( $stats[ $key ] ) ? $stats[ $key ] : array();

			if ( ( 'account' === $key && $hidden ) || ! is_numeric( $row['images'] ?? null ) || ! is_numeric( $row['bytes_saved'] ?? null ) ) {
				continue;
			}

			$count  = max( 0, (int) $row['images'] );
			$rows[] = array(
				'key'    => $key,
				'label'  => $label,
				'count'  => $count,
				'images' => number_format_i18n( $count ),
				'saved'  => (string) size_format( max( 0, (int) $row['bytes_saved'] ), 1 ),
			);
		}

		return $rows;
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
	 * The languages the service writes alt text in, for the setting that picks one,
	 * as `GET /site` answered them, so a language it adds needs no change here;
	 * each named in the admin's language where the plugin knows its tag.
	 *
	 * @param mixed $languages The answer's `alt_text_languages`.
	 * @return list<array{value: string, label: string}>
	 */
	protected static function alt_languages( $languages ): array {
		$options = array();

		foreach ( is_array( $languages ) ? $languages : array() as $language ) {
			if ( is_array( $language ) && isset( $language['code'], $language['name'] ) ) {
				$options[] = array(
					'value' => (string) $language['code'],
					'label' => Messages::language( (string) $language['code'], (string) $language['name'] ),
				);
			}
		}

		return $options;
	}

	/**
	 * The language alt text starts on the first time it is turned on: the site's,
	 * where the service writes it, or null.
	 *
	 * @param list<array{value: string, label: string}> $languages The languages the service writes.
	 * @param string                                    $locale    The site's WordPress locale, such as `pt_BR`.
	 */
	public static function alt_language( array $languages, string $locale ): ?string {
		$codes = array_column( $languages, 'value' );
		$base  = strtolower( substr( $locale, 0, 2 ) );
		$code  = 'zh' === $base ? ( in_array( $locale, array( 'zh_TW', 'zh_HK' ), true ) ? 'zh-Hant' : 'zh-Hans' ) : $base;

		return in_array( $code, $codes, true ) ? $code : null;
	}

	/**
	 * The compression levels, for the setting that picks one: each as a card
	 * with its name and what it does.
	 *
	 * @return list<array{value: string, label: string, name: string, about: string}>
	 */
	protected static function levels(): array {
		$levels = array();

		foreach ( Messages::levels() as $value => $level ) {
			$levels[] = array(
				'value' => $value,
				'label' => $level['label'],
				'name'  => $level['name'],
				'about' => $level['about'],
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
		$names = self::size_names();
		$sizes = array();

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$sizes[] = array(
				'name'  => (string) $name,
				'label' => self::size_label( $names[ $name ] ?? (string) $name, (int) $size['width'], (int) $size['height'] ),
			);
		}

		return $sizes;
	}

	/**
	 * What each registered size is called where WordPress names it to a person (the
	 * editor's size menu), else its own name.
	 *
	 * @return array<string, string>
	 */
	public static function size_names(): array {
		$defaults = array(
			'thumbnail' => __( 'Thumbnail', 'iminify-image-optimizer' ),
			'medium'    => __( 'Medium', 'iminify-image-optimizer' ),
			'large'     => __( 'Large', 'iminify-image-optimizer' ),
		);
		/** This filter is documented in wp-admin/includes/media.php */
		$given = apply_filters( 'image_size_names_choose', $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, read for the names a site gives its sizes.
		$names = array();

		foreach ( array_keys( wp_get_registered_image_subsizes() ) as $name ) {
			$names[ (string) $name ] = is_array( $given ) && isset( $given[ $name ] ) && is_string( $given[ $name ] ) ? $given[ $name ] : (string) $name;
		}

		return $names;
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
