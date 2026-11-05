<?php
/**
 * Connects the site to an Iminify account, and lets it go.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Media\Queue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connect opens a pairing from the site's server, the person approves it on
 * www.iminify.com in a new tab, and the server collects the site key with the
 * pairing's secret, which never leaves it: every few seconds while the
 * settings page is open, and in the background through Action Scheduler until
 * the key comes, the pairing closes, or it expires. A key pasted on the page,
 * or set in wp-config.php, connects the site too. Disconnect stops the key on
 * Iminify, which refuses it from a copy of the site at another address, and
 * forgets it here.
 */
class Connection {

	const PAIRING_OPTION = 'iminify_pairing';

	const REVOKED_OPTION = 'iminify_revoked';

	const LOCK_OPTION = 'iminify_claim_lock';

	const CLAIM_HOOK = 'iminify_claim_pairing';

	const GROUP = 'iminify';

	/**
	 * How long the background waits between claims. Action Scheduler runs at most
	 * once a minute without a visitor or the open page, so this only sets its pace.
	 */
	const BACKGROUND_SECONDS = 30;

	/**
	 * How long a claim holds the lock at most: above the client's timeout, so a
	 * request that died mid-claim frees it.
	 */
	const LOCK_SECONDS = 30;

	/**
	 * Where the site key is kept.
	 *
	 * @var Key_Store
	 */
	protected $keys;

	/**
	 * Builds the connection over the site's key store.
	 *
	 * @param Key_Store|null $keys Where the key is kept.
	 */
	public function __construct( ?Key_Store $keys = null ) {
		$this->keys = $keys ?? new Key_Store();
	}

	/**
	 * Opens a pairing for this site and schedules the background claim.
	 *
	 * @return array{connect_url: string, expires_at: int}|WP_Error
	 */
	public function start() {
		if ( $this->keys->from_constant() ) {
			return self::key_in_config();
		}

		$answer = ( new Client() )->start_pairing( home_url(), self::site_name() );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$id      = isset( $answer['pairing'] ) && is_string( $answer['pairing'] ) ? $answer['pairing'] : '';
		$secret  = isset( $answer['secret'] ) && is_string( $answer['secret'] ) ? Sealer::seal( $answer['secret'] ) : null;
		$url     = isset( $answer['connect_url'] ) && is_string( $answer['connect_url'] ) ? $answer['connect_url'] : '';
		$expires = isset( $answer['expires_at'] ) && is_string( $answer['expires_at'] ) ? strtotime( $answer['expires_at'] ) : false;

		if ( '' === $id || null === $secret || ! self::is_service_page( $url ) || false === $expires ) {
			return new WP_Error( 'unexpected_response', __( 'Iminify sent an answer this version of the plugin can\'t read.', 'iminify-image-optimizer' ), array( 'status' => 502 ) );
		}

		$this->cancel();

		update_option(
			self::PAIRING_OPTION,
			array(
				'id'          => $id,
				'secret'      => $secret,
				'connect_url' => $url,
				'expires_at'  => $expires,
			),
			false
		);

		$this->schedule( $id, self::BACKGROUND_SECONDS );

		return array(
			'connect_url' => $url,
			'expires_at'  => $expires,
		);
	}

	/**
	 * The pairing waiting for its approval, or null when there is none or it expired.
	 *
	 * @return array{id: string, connect_url: string, expires_at: int}|null
	 */
	public function pairing(): ?array {
		$pairing = $this->stored_pairing();

		if ( null === $pairing || $pairing['expires_at'] <= time() ) {
			return null;
		}

		return array(
			'id'          => $pairing['id'],
			'connect_url' => $pairing['connect_url'],
			'expires_at'  => $pairing['expires_at'],
		);
	}

	/**
	 * Asks Iminify once for the site key the pairing gives once approved.
	 *
	 * `connected` once the key is kept (by this claim or another), `pending`
	 * while it waits for the approval or can't be asked yet, `closed` when the
	 * pairing can no longer give a key (turned down, or its key collected by a
	 * claim that couldn't keep it), `expired` past its last moment, `none` with
	 * no pairing, and `failed` when the service refuses the account itself.
	 *
	 * @return array{status: string, retry_after?: int|null, error?: WP_Error}
	 */
	public function claim(): array {
		if ( null !== $this->keys->get() && null === $this->stored_pairing() ) {
			return array( 'status' => 'connected' );
		}

		$pairing = $this->stored_pairing();

		if ( null === $pairing ) {
			return array( 'status' => 'none' );
		}

		if ( $pairing['expires_at'] <= time() ) {
			$this->cancel( $pairing['id'] );

			return array( 'status' => 'expired' );
		}

		$secret = Sealer::open( $pairing['secret'] );

		if ( null === $secret ) {
			$this->cancel( $pairing['id'] );

			return array( 'status' => 'closed' );
		}

		if ( ! $this->lock() ) {
			return array( 'status' => 'pending' );
		}

		try {
			return $this->collect( $pairing['id'], $secret );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * The background claim, run by Action Scheduler: claims once for the pairing it
	 * was scheduled for, and schedules the next while the pairing waits.
	 *
	 * @param string $pairing_id The pairing it was scheduled for.
	 */
	public function run_background_claim( string $pairing_id ): void {
		$pairing = $this->stored_pairing();

		if ( null === $pairing || $pairing['id'] !== $pairing_id ) {
			return;
		}

		$result = $this->claim();

		if ( 'pending' === $result['status'] ) {
			$this->schedule( $pairing_id, max( self::BACKGROUND_SECONDS, (int) ( $result['retry_after'] ?? 0 ) ) );
		}
	}

	/**
	 * Drops the pairing waiting for approval, and its background claim. Given an
	 * id, only while that pairing is still the stored one: a claim that ends after
	 * Connect was pressed again leaves the newer pairing alone.
	 *
	 * @param string|null $id The pairing a claim ended, or null for whichever is stored.
	 */
	public function cancel( ?string $id = null ): void {
		$stored = $this->stored_pairing();

		if ( null !== $id && ( null === $stored || $stored['id'] !== $id ) ) {
			return;
		}

		delete_option( self::PAIRING_OPTION );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CLAIM_HOOK );
		}
	}

	/**
	 * Connects the site with a key someone pasted, once Iminify confirms it is a
	 * site key. A key made for another address (`site_moved`) is kept too, so the
	 * page offers to move that site's connection here or to connect this site on
	 * its own, and the refusal still comes back.
	 *
	 * @param string $key The pasted key.
	 * @return array<string, mixed>|WP_Error The site's record.
	 */
	public function connect_with_key( string $key ) {
		if ( $this->keys->from_constant() ) {
			return self::key_in_config();
		}

		$key = trim( $key );

		if ( '' === $key ) {
			return new WP_Error( 'key_missing', __( 'Paste the site key first.', 'iminify-image-optimizer' ), array( 'status' => 422 ) );
		}

		$site = ( new Client( $key ) )->site();

		if ( is_wp_error( $site ) && 'site_moved' !== $site->get_error_code() ) {
			return $site;
		}

		if ( ! $this->keys->save( $key ) ) {
			return self::not_saved();
		}

		$this->connected( is_wp_error( $site ) ? null : $site );

		return $site;
	}

	/**
	 * Disconnects the site: stops its key on Iminify where it can, and always
	 * forgets it here. Iminify refuses to stop it from a copy of the site at
	 * another address, so a staging copy never cuts off the real site.
	 *
	 * @return array{revoked: bool}|WP_Error Whether the key was stopped on Iminify.
	 */
	public function disconnect() {
		if ( $this->keys->from_constant() ) {
			return self::key_in_config();
		}

		$client  = Plugin::client();
		$revoked = false;

		if ( null !== $client ) {
			$answer  = $client->disconnect();
			$revoked = true === $answer || 'unauthenticated' === $answer->get_error_code();
		}

		$this->keys->forget();
		$this->cancel();
		Site_Record::forget();
		delete_option( self::REVOKED_OPTION );

		return array( 'revoked' => $revoked );
	}

	/**
	 * Moves the site's connection to the address this copy runs at, after a `site_moved`.
	 *
	 * @return array<string, mixed>|WP_Error The site's record.
	 */
	public function move() {
		$client = Plugin::client();

		if ( null === $client ) {
			return self::not_connected();
		}

		$site = $client->move_site();

		if ( ! is_wp_error( $site ) ) {
			Site_Record::remember( $site );
		}

		return $site;
	}

	/**
	 * Lets this copy of the site go its own way after a `site_moved`: forgets the key
	 * it carried from the other site, never stopping it there, and opens a pairing
	 * for this address.
	 *
	 * @return array{connect_url: string, expires_at: int}|WP_Error
	 */
	public function connect_copy() {
		if ( $this->keys->from_constant() ) {
			return self::key_in_config();
		}

		$this->keys->forget();
		Site_Record::forget();

		return $this->start();
	}

	/**
	 * Iminify no longer knows the key (it was disconnected on www.iminify.com, or
	 * the account went): forgets a stored key and remembers why, for the page. A
	 * key set in wp-config.php stays, and the page says it no longer works.
	 */
	public function revoked_by_service(): void {
		Site_Record::forget();

		if ( $this->keys->from_constant() ) {
			return;
		}

		$this->keys->forget();
		update_option( self::REVOKED_OPTION, time(), false );
	}

	/**
	 * Whether the site lost its connection because Iminify stopped its key.
	 */
	public static function was_revoked(): bool {
		return false !== get_option( self::REVOKED_OPTION );
	}

	/**
	 * The site's name as Iminify should show it: WordPress keeps it with HTML entities.
	 */
	public static function site_name(): string {
		return trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Claims with the secret, keeping the key it gives.
	 *
	 * @param string $id     The pairing's id.
	 * @param string $secret The pairing's secret.
	 * @return array{status: string, retry_after?: int|null, error?: WP_Error}
	 */
	protected function collect( string $id, string $secret ): array {
		$answer = ( new Client() )->claim_pairing( $id, $secret );

		if ( ! is_wp_error( $answer ) ) {
			$key = isset( $answer['key'] ) && is_string( $answer['key'] ) ? $answer['key'] : '';

			if ( '' === $key || ! $this->keys->save( $key ) ) {
				$this->cancel( $id );

				return array(
					'status' => 'failed',
					'error'  => self::not_saved(),
				);
			}

			$this->connected();

			return array( 'status' => 'connected' );
		}

		$code = $answer->get_error_code();
		$data = (array) $answer->get_error_data();

		if ( 'pairing_closed' === $code ) {
			$this->cancel( $id );

			return array( 'status' => null !== $this->keys->get() ? 'connected' : 'closed' );
		}

		$status = (int) ( $data['status'] ?? 0 );

		if ( 'pairing_pending' === $code || 'rate_limited' === $code || 'unreachable' === $code || $status >= 500 ) {
			return array(
				'status'      => 'pending',
				'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : null,
			);
		}

		$this->cancel( $id );

		return array(
			'status' => 'failed',
			'error'  => $answer,
		);
	}

	/**
	 * After a key was kept: the pairing and the reason for an earlier disconnect go,
	 * and the site's record is the one just read, if any.
	 *
	 * @param array<string, mixed>|null $site The site's record, when one was read.
	 */
	protected function connected( ?array $site = null ): void {
		$this->cancel();
		delete_option( self::REVOKED_OPTION );

		if ( null === $site ) {
			Site_Record::forget();
			Queue::connection_back();
		} else {
			Site_Record::remember( $site );
		}
	}

	/**
	 * The stored pairing, read whole, or null.
	 *
	 * @return array{id: string, secret: string, connect_url: string, expires_at: int}|null
	 */
	protected function stored_pairing(): ?array {
		$pairing = get_option( self::PAIRING_OPTION );

		if ( ! is_array( $pairing ) || ! isset( $pairing['id'], $pairing['secret'], $pairing['connect_url'], $pairing['expires_at'] ) ) {
			return null;
		}

		return array(
			'id'          => (string) $pairing['id'],
			'secret'      => (string) $pairing['secret'],
			'connect_url' => (string) $pairing['connect_url'],
			'expires_at'  => (int) $pairing['expires_at'],
		);
	}

	/**
	 * Schedules the background claim for a pairing. Never as a unique action: the
	 * claim schedules its own next run while Action Scheduler still counts it as
	 * running, and start() cancels any earlier chain first.
	 *
	 * @param string $id      The pairing's id.
	 * @param int    $seconds How long from now.
	 */
	protected function schedule( string $id, int $seconds ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $seconds, self::CLAIM_HOOK, array( $id ), self::GROUP );
		}
	}

	/**
	 * Takes the claim lock, so the open page and the background never claim at once
	 * and the one that loses the race never reports a pairing the other just used
	 * as closed. A lock older than LOCK_SECONDS is taken over.
	 */
	protected function lock(): bool {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic insert is the lock: add_option() overwrites an existing row.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, (string) ( $now + self::LOCK_SECONDS ) ) );

		if ( 1 === $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- takes over a lock past its expiry in one conditional update.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) ( $now + self::LOCK_SECONDS ), self::LOCK_OPTION, $now ) );

		return 1 === $taken;
	}

	/**
	 * Frees the claim lock.
	 */
	protected function unlock(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock row is never read through the options API.
	}

	/**
	 * Whether an address is a page on the service the plugin talks to, over https.
	 *
	 * @param string $url The address the service sent.
	 */
	protected static function is_service_page( string $url ): bool {
		$host = wp_parse_url( Client::base(), PHP_URL_HOST );

		return 0 === stripos( $url, 'https://' ) && is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * The refusal for a change wp-config.php's key rules out.
	 */
	protected static function key_in_config(): WP_Error {
		return new WP_Error( 'key_in_config', __( 'This site\'s key is set in wp-config.php. Change or remove it there.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
	}

	/**
	 * The refusal for a call that needs a key the site doesn't have.
	 */
	protected static function not_connected(): WP_Error {
		return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
	}

	/**
	 * The error for a key WordPress couldn't store.
	 */
	protected static function not_saved(): WP_Error {
		return new WP_Error( 'key_not_saved', __( 'WordPress couldn\'t store the site key. Press Connect to try again.', 'iminify-image-optimizer' ), array( 'status' => 500 ) );
	}
}
