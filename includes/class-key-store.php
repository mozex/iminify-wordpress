<?php
/**
 * Keeps the site key, encrypted, where only the server reads it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site key opens this site's calls on Iminify, so it is never shown, never
 * sent to the browser and never autoloaded. It is stored sealed (Sealer), so a
 * site whose salts changed reads as disconnected, which a new Connect mends.
 *
 * On a network the plugin is active for, the main site's key is the network's
 * (`Network`), kept in the network's options so every site can join with it, and
 * `IMINIFY_API_KEY` is the network's key too: every other site keeps only a key
 * of its own, never the constant, which it would send from its own address.
 */
class Key_Store {

	const OPTION = 'iminify_site_key';

	const CONSTANT = 'IMINIFY_API_KEY';

	const FORMAT = Sealer::FORMAT;

	const HIDE_CONSTANT = 'IMINIFY_HIDE_ACCOUNT';

	/**
	 * The site key: the constant's when wp-config.php defines one, otherwise the
	 * stored one, or null when there is none or it can no longer be read.
	 *
	 * @param bool $fresh Whether to read the stored key from the database, past every cache.
	 */
	public function get( bool $fresh = false ): ?string {
		if ( Network::is_main() ) {
			return Network::key( $fresh );
		}

		if ( $this->from_constant() ) {
			return self::constant();
		}

		return Sealer::open( $fresh ? self::read_stored() : get_option( self::OPTION ) );
	}

	/**
	 * Whether this site's key is the one wp-config.php sets, which then wins over a
	 * stored one: on a network, only the main site's.
	 */
	public function from_constant(): bool {
		return self::constant_set() && ( ! is_multisite() || is_main_site() );
	}

	/**
	 * Whether wp-config.php sets a key.
	 */
	public static function constant_set(): bool {
		return defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) && '' !== trim( (string) constant( self::CONSTANT ) );
	}

	/**
	 * The key wp-config.php sets, trimmed.
	 */
	public static function constant(): string {
		return trim( (string) constant( self::CONSTANT ) );
	}

	/**
	 * Whether wp-config.php both sets the key and asks to keep the account out of
	 * sight: an agency connecting a client's site with its own account keeps the
	 * account's plan and counts from the site's administrators, and the
	 * connection out of their reach. It takes effect only with the key's constant,
	 * since otherwise nobody could connect the site at all: on the site that uses
	 * it, and on a network also on every site that joins with it, never on a site
	 * that connected an account of its own.
	 */
	public function hides_account(): bool {
		if ( ! self::constant_set() || ! defined( self::HIDE_CONSTANT ) || true !== constant( self::HIDE_CONSTANT ) ) {
			return false;
		}

		return $this->from_constant() || ( Network::is_member() && ( Network::joined() || Network::joinable() ) );
	}

	/**
	 * Stores a key, sealed, replacing any stored before.
	 *
	 * @param string $key The site key Iminify issued.
	 */
	public function save( string $key ): bool {
		$sealed = Sealer::seal( $key );

		if ( null === $sealed ) {
			return false;
		}

		if ( Network::is_main() ) {
			return update_site_option( Network::KEY_OPTION, $sealed );
		}

		return update_option( self::OPTION, $sealed, false );
	}

	/**
	 * Forgets the stored key. A key set in wp-config.php stays until it is removed there.
	 */
	public function forget(): void {
		if ( Network::is_main() ) {
			delete_site_option( Network::KEY_OPTION );

			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * The sealed key as the database holds it now, past every cache: another
	 * request may have just replaced it.
	 */
	protected static function read_stored(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past every cache, since another request may have just replaced it.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		return is_string( $value ) ? $value : null;
	}
}
