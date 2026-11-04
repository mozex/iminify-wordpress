<?php
/**
 * Seals the secrets the plugin keeps in the database.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seals a secret with a key derived from the site's auth salt: where
 * wp-config.php holds the salts, as WordPress's installer writes them, a copy
 * of the database alone can't read it. A value sealed under other salts, or
 * altered, opens as null.
 */
class Sealer {

	const FORMAT = 'v1:';

	/**
	 * The secret sealed as `v1:` and the base64 of its nonce and ciphertext, or null when sodium fails.
	 *
	 * @param string $secret The secret.
	 */
	public static function seal( string $secret ): ?string {
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::FORMAT . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The secret a sealed value holds, or null when it was sealed under other salts, altered, or isn't sealed at all.
	 *
	 * @param mixed $sealed The stored value.
	 */
	public static function open( $sealed ): ?string {
		if ( ! is_string( $sealed ) || 0 !== strpos( $sealed, self::FORMAT ) ) {
			return null;
		}

		$bytes = base64_decode( substr( $sealed, strlen( self::FORMAT ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext stored as text.

		if ( false === $bytes || strlen( $bytes ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		try {
			$secret = sodium_crypto_secretbox_open(
				substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $secret ) && '' !== $secret ? $secret : null;
	}

	/**
	 * The sealing key, derived from the site's auth salt so it never sits in the database beside the value.
	 */
	protected static function key(): string {
		return sodium_crypto_generichash( 'iminify-site-key|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
