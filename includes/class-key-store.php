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
 */
class Key_Store {

	const OPTION = 'iminify_site_key';

	const CONSTANT = 'IMINIFY_API_KEY';

	const FORMAT = Sealer::FORMAT;

	const HIDE_CONSTANT = 'IMINIFY_HIDE_ACCOUNT';

	/**
	 * The site key: the constant's when wp-config.php defines one, otherwise the
	 * stored one, or null when there is none or it can no longer be read.
	 */
	public function get(): ?string {
		if ( $this->from_constant() ) {
			return trim( (string) constant( self::CONSTANT ) );
		}

		return Sealer::open( get_option( self::OPTION ) );
	}

	/**
	 * Whether wp-config.php sets the key, which then wins over a stored one.
	 */
	public function from_constant(): bool {
		return defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) && '' !== trim( (string) constant( self::CONSTANT ) );
	}

	/**
	 * Whether wp-config.php both sets the key and asks to keep the account out of
	 * sight: an agency connecting a client's site with its own account keeps the
	 * account's plan and counts from the site's administrators, and the
	 * connection out of their reach. It takes effect only with the key's constant,
	 * since otherwise nobody could connect the site at all.
	 */
	public function hides_account(): bool {
		return $this->from_constant() && defined( self::HIDE_CONSTANT ) && true === constant( self::HIDE_CONSTANT );
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

		return update_option( self::OPTION, $sealed, false );
	}

	/**
	 * Forgets the stored key. A key set in wp-config.php stays until it is removed there.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}
}
