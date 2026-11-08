<?php
/**
 * A WordPress multisite network connected to Iminify once.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * With the plugin network-active, a super admin connects the whole network
 * once, from the network admin. The network's key is its main site's own
 * connection, kept in a network option (or `IMINIFY_API_KEY`, which on a
 * network is the network's). Every other site joins by itself the first time
 * it needs Iminify: it asks the network's key, from the network's address, for
 * a key of its own, keeps it in its own options, and from then on calls like
 * any connected site. A site may connect on its own instead (Connect or a key
 * on its own page), and never asks the network while it has a key. With the
 * plugin active site by site, every site of a network connects on its own.
 *
 * A site whose key stopped working, or that someone disconnected on its own
 * page, doesn't join again by itself while the network's key is the one it was
 * stopped under (`hold()`), or a site disconnected under Settings, Sites on
 * iminify.com would join again on its next upload; a new network key (the
 * network connected again) lifts the hold, and "Join the network" on the site's
 * page always asks. A refusal holds the site the same way, and a wait is waited
 * out: every site of a network shares the network's hourly allowance of joins.
 */
class Network {

	/**
	 * The network's key, sealed, in the network's options.
	 */
	const KEY_OPTION = 'iminify_network_key';

	/**
	 * A site's state toward the network: whether its key came from the
	 * network (`joined`), the network key it is held under (`hold`), when it may
	 * ask again (`retry_at`), and the last refusal (`code`, `message`).
	 */
	const JOIN_OPTION = 'iminify_network_join';

	const LOCK_OPTION = 'iminify_join_lock';

	/**
	 * How long a join holds the lock at most: above the client's timeout.
	 */
	const LOCK_SECONDS = 30;

	/**
	 * How long a site waits before asking again after Iminify couldn't be reached.
	 */
	const RETRY_SECONDS = 300;

	/**
	 * Whether the plugin is active for the whole network, which makes the main
	 * site's connection the network's. Read from the network's own list, since
	 * `is_plugin_active_for_network()` isn't loaded in every request.
	 */
	public static function active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$plugins = get_site_option( 'active_sitewide_plugins' );

		return is_array( $plugins ) && isset( $plugins[ plugin_basename( IMINIFY_FILE ) ] );
	}

	/**
	 * Whether this is the main site of a network the plugin is active for.
	 */
	public static function is_main(): bool {
		return self::active() && is_main_site();
	}

	/**
	 * Whether this is another site of a network the plugin is active for.
	 */
	public static function is_member(): bool {
		return self::active() && ! is_main_site();
	}

	/**
	 * The network's key, or null while the network isn't connected.
	 *
	 * @param bool $fresh Whether to read the stored key from the database, past every cache.
	 */
	public static function key( bool $fresh = false ): ?string {
		if ( ! self::active() ) {
			return null;
		}

		if ( Key_Store::constant_set() ) {
			return Key_Store::constant();
		}

		return Sealer::open( $fresh ? self::read_stored() : get_site_option( self::KEY_OPTION ) );
	}

	/**
	 * The network's address, as its pairing sent it: the main site's home address.
	 */
	public static function address(): string {
		return get_home_url( get_main_site_id() );
	}

	/**
	 * The network's name, as Iminify should show it.
	 */
	public static function name(): string {
		$name = (string) get_site_option( 'site_name' );

		return trim( html_entity_decode( '' !== $name ? $name : (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A key's fingerprint, kept in place of the key where only its identity
	 * matters; the empty string for no key.
	 *
	 * @param string|null $key The key.
	 */
	public static function fingerprint( ?string $key ): string {
		return null === $key || '' === $key ? '' : hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * Whether this site will join the network when it next needs Iminify: a
	 * site of a connected network, without a key, not held. A site waiting out a
	 * refusal's wait still will, so its uploads queue and go on with the join.
	 */
	public static function joinable(): bool {
		if ( ! self::is_member() || null !== Plugin::keys()->get() ) {
			return false;
		}

		$network = self::key();

		return null !== $network && ! self::held( $network );
	}

	/**
	 * When this site may ask the network for a key again, while it waits out a
	 * refused join, or null when it isn't waiting.
	 */
	public static function waits_until(): ?int {
		$state = self::state();

		if ( ! self::joinable() || ! isset( $state['retry_at'] ) || (int) $state['retry_at'] <= time() ) {
			return null;
		}

		return (int) $state['retry_at'];
	}

	/**
	 * Whether this site's key came from the network, so the network's own
	 * disconnect stops it too.
	 */
	public static function joined(): bool {
		return ! empty( self::state()['joined'] );
	}

	/**
	 * The last refusal of a join, as the site's page shows it, or null.
	 *
	 * @return array{code: string, message: string}|null
	 */
	public static function refusal(): ?array {
		$state = self::state();

		if ( ! isset( $state['code'] ) || ! is_string( $state['code'] ) || '' === $state['code'] ) {
			return null;
		}

		$wait = isset( $state['retry_at'] ) ? max( 0, (int) $state['retry_at'] - time() ) : 0;

		return array(
			'code'    => $state['code'],
			'message' => Messages::for_error(
				new WP_Error(
					$state['code'],
					isset( $state['message'] ) && is_string( $state['message'] ) ? $state['message'] : '',
					array( 'retry_after' => $wait )
				)
			),
		);
	}

	/**
	 * Asks the network's key for a key of this site's own, and keeps it.
	 *
	 * Without `$asked` it asks only when the site may (`joinable()`, its wait
	 * over); a person pressing "Join the network" always asks. One join at a
	 * time: a site that already has a key once the lock is taken answers it,
	 * since Iminify stops a site's earlier key when it gives a new one.
	 *
	 * @param bool $asked Whether a person asked for it.
	 * @return string|WP_Error|null The site's key; the refusal; or null when it can't ask now.
	 */
	public static function join( bool $asked = false ) {
		if ( ! self::is_member() ) {
			return null;
		}

		$network = self::key();

		if ( null === $network ) {
			return new WP_Error( 'network_not_connected', __( 'The network isn\'t connected to Iminify. A super admin connects it from the network admin.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$state = self::state();

		if ( ! $asked && ( self::held( $network ) || ( isset( $state['retry_at'] ) && (int) $state['retry_at'] > time() ) ) ) {
			return null;
		}

		if ( ! Option_Lock::take( self::LOCK_OPTION, self::LOCK_SECONDS ) ) {
			return $asked ? new WP_Error( 'join_pending', __( 'This site is joining the network already. Wait a moment and look again.', 'iminify-image-optimizer' ), array( 'status' => 409 ) ) : null;
		}

		try {
			$key = Plugin::keys()->get( true );

			if ( null !== $key ) {
				return $key;
			}

			$answer = ( new Client( $network ) )->register_site( self::address(), home_url(), Connection::site_name() );

			if ( is_wp_error( $answer ) ) {
				self::refused( $answer, $network );

				return $answer;
			}

			$key = isset( $answer['key'] ) && is_string( $answer['key'] ) ? $answer['key'] : '';

			if ( '' === $key || ! Plugin::keys()->save( $key ) ) {
				$error = new WP_Error( 'key_not_saved', __( 'WordPress couldn\'t store the site key. Press Join the network to try again.', 'iminify-image-optimizer' ), array( 'status' => 500 ) );
				self::refused( $error, $network );

				return $error;
			}

			update_option(
				self::JOIN_OPTION,
				array(
					'joined' => true,
					'under'  => self::fingerprint( $network ),
				),
				false
			);
			delete_option( Connection::REVOKED_OPTION );
			Site_Record::forget();
			Queue::connection_back();

			return $key;
		} finally {
			Option_Lock::release( self::LOCK_OPTION );
		}
	}

	/**
	 * Keeps this site from joining by itself while the network's key is the one
	 * given: by default the one it has now, as when someone disconnected the site.
	 *
	 * @param string|null $under The fingerprint of the network's key to hold it under.
	 */
	public static function hold( ?string $under = null ): void {
		update_option( self::JOIN_OPTION, array( 'hold' => $under ?? self::fingerprint( self::key() ) ), false );
	}

	/**
	 * This site's own key stopped working: it is held under the network's key it
	 * joined with, so a network connected again since (whose disconnect stopped
	 * this key) lets it join again, while a site disconnected alone, under the
	 * same network key, stays out. A key it got on its own is held under the
	 * network's key of now.
	 */
	public static function stopped(): void {
		$state = self::state();

		self::hold( ! empty( $state['joined'] ) && isset( $state['under'] ) && is_string( $state['under'] ) ? $state['under'] : null );
	}

	/**
	 * Forgets this site's state toward the network: it connected on its own.
	 */
	public static function forget(): void {
		delete_option( self::JOIN_OPTION );
	}

	/**
	 * What a refused join leaves: a wait to wait out, or a hold until someone
	 * asks or the network's key changes. A network key Iminify no longer knows
	 * disconnects the network.
	 *
	 * @param WP_Error $error   The refusal.
	 * @param string   $network The network's key the join used.
	 */
	protected static function refused( WP_Error $error, string $network ): void {
		$code   = (string) $error->get_error_code();
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		$state  = array(
			'code'    => $code,
			'message' => $error->get_error_message(),
		);

		if ( 'unauthenticated' === $code && ! Key_Store::constant_set() ) {
			self::network_revoked( $network );
			delete_option( self::JOIN_OPTION );

			return;
		}

		if ( 'rate_limited' === $code || 'unreachable' === $code || 'key_not_saved' === $code || $status >= 500 ) {
			$wait              = 'rate_limited' === $code && isset( $data['retry_after'] ) ? (int) $data['retry_after'] : self::RETRY_SECONDS;
			$state['retry_at'] = time() + max( MINUTE_IN_SECONDS, $wait );

			/* The images that parked waiting for the join go on once the wait is over. */
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $state['retry_at'], Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP );
			}
		} else {
			$state['hold'] = self::fingerprint( $network );
		}

		update_option( self::JOIN_OPTION, $state, false );
	}

	/**
	 * Iminify no longer knows the network's key: forgets it, unless a newer one
	 * replaced it, and tells the network's page why.
	 *
	 * @param string $network The network's key that was refused.
	 */
	protected static function network_revoked( string $network ): void {
		if ( ! hash_equals( self::fingerprint( self::key( true ) ), self::fingerprint( $network ) ) ) {
			return;
		}

		delete_site_option( self::KEY_OPTION );
		update_blog_option( get_main_site_id(), Connection::REVOKED_OPTION, time() );
	}

	/**
	 * Whether this site is held under the network's key it has now.
	 *
	 * @param string $network The network's key.
	 */
	protected static function held( string $network ): bool {
		$state = self::state();

		return isset( $state['hold'] ) && is_string( $state['hold'] ) && hash_equals( $state['hold'], self::fingerprint( $network ) );
	}

	/**
	 * This site's state toward the network.
	 *
	 * @return array<string, mixed>
	 */
	protected static function state(): array {
		$state = get_option( self::JOIN_OPTION );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * The sealed network key as the database holds it now.
	 *
	 * @return string|null
	 */
	protected static function read_stored(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past every cache, since another request may have just replaced it.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", self::KEY_OPTION, get_current_network_id() ) );

		return is_string( $value ) ? $value : null;
	}
}
