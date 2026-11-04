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
 * sent to the browser and never autoloaded. It is stored sealed with a key
 * derived from the site's salts: a database copied without wp-config.php can't
 * read it, and a site whose salts changed reads as disconnected, which a new
 * Connect mends.
 */
class Key_Store {

	const OPTION = 'iminify_site_key';

	const CONSTANT = 'IMINIFY_API_KEY';

	const FORMAT = 'v1:';

	/**
	 * The site key: the constant's when wp-config.php defines one, otherwise the
	 * stored one, or null when there is none or it can no longer be read.
	 */
	public function get(): ?string {
		if ( $this->from_constant() ) {
			return (string) constant( self::CONSTANT );
		}

		$sealed = get_option( self::OPTION );

		return is_string( $sealed ) ? $this->open( $sealed ) : null;
	}

	/**
	 * Whether wp-config.php sets the key, which then wins over a stored one.
	 */
	public function from_constant(): bool {
		return defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) && '' !== trim( (string) constant( self::CONSTANT ) );
	}

	/**
	 * Stores a key, sealed, replacing any stored before.
	 *
	 * @param string $key The site key Iminify issued.
	 */
	public function save( string $key ): bool {
		$sealed = $this->seal( $key );

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

	/**
	 * The key sealed as `v1:` and the base64 of its nonce and ciphertext.
	 *
	 * @param string $key The site key.
	 */
	protected function seal( string $key ): ?string {
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::FORMAT . base64_encode( $nonce . sodium_crypto_secretbox( $key, $nonce, $this->secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The key a sealed value holds, or null when it was sealed under other salts or altered.
	 *
	 * @param string $sealed The stored value.
	 */
	protected function open( string $sealed ): ?string {
		if ( 0 !== strpos( $sealed, self::FORMAT ) ) {
			return null;
		}

		$bytes = base64_decode( substr( $sealed, strlen( self::FORMAT ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext stored as text.

		if ( false === $bytes || strlen( $bytes ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		try {
			$key = sodium_crypto_secretbox_open(
				substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$this->secret()
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * The sealing key, derived from the site's auth salt so it never sits in the database beside the value.
	 */
	protected function secret(): string {
		return sodium_crypto_generichash( 'iminify-site-key|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
