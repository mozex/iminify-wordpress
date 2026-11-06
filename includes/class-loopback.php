<?php
/**
 * Lets the background queue reach a site that asks for a password.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The background queue runs on requests a site makes to itself: WP-Cron's spawn
 * and Action Scheduler's async runner. On a site behind HTTP basic auth (a staging
 * copy, as a rule) both are refused with a 401 unless they carry the password, so
 * nothing would ever be optimized in the background. They carry the credentials
 * set in wp-config.php (`IMINIFY_AUTH_USER` and `IMINIFY_AUTH_PASSWORD`), or else
 * the ones the request making them came with, as Site Health's own loopback check
 * does. Nothing is stored, and only requests to the site itself carry them; calls
 * to Iminify never do.
 */
class Loopback {

	/**
	 * Hooks the credentials into the site's requests to itself.
	 */
	public static function register(): void {
		add_filter( 'cron_request', array( self::class, 'cron' ) );
		add_filter( 'as_async_request_queue_runner_post_args', array( self::class, 'async' ) );
	}

	/**
	 * The `Authorization` header a request to the site itself carries, or null when
	 * there is nothing to send.
	 */
	public static function authorization(): ?string {
		if ( defined( 'IMINIFY_AUTH_USER' ) && defined( 'IMINIFY_AUTH_PASSWORD' ) ) {
			return 'Basic ' . base64_encode( (string) constant( 'IMINIFY_AUTH_USER' ) . ':' . (string) constant( 'IMINIFY_AUTH_PASSWORD' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth is base64 by definition.
		}

		/* An API request's credentials are an application password, which nothing the queue loads would read. */
		if ( wp_is_serving_rest_request() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the request's own credentials, passed on unchanged as Site Health's loopback check does.
		return 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
	}

	/**
	 * The `cron_request` filter: WP-Cron's spawn of itself.
	 *
	 * @param mixed $request The spawn's `url`, `key` and `args`.
	 * @return mixed
	 */
	public static function cron( $request ) {
		if ( ! is_array( $request ) || ! isset( $request['url'] ) || ! self::is_own( (string) $request['url'] ) ) {
			return $request;
		}

		$request['args'] = self::with_authorization( isset( $request['args'] ) && is_array( $request['args'] ) ? $request['args'] : array() );

		return $request;
	}

	/**
	 * Action Scheduler's async runner, which posts to the site's own admin-ajax.php.
	 *
	 * @param mixed $args The request's arguments.
	 * @return mixed
	 */
	public static function async( $args ) {
		return is_array( $args ) ? self::with_authorization( $args ) : $args;
	}

	/**
	 * A request's arguments with the credentials, unless they carry their own.
	 *
	 * @param array<string, mixed> $args The request's arguments.
	 * @return array<string, mixed>
	 */
	protected static function with_authorization( array $args ): array {
		$authorization = self::authorization();
		$headers       = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		if ( null === $authorization || isset( $headers['Authorization'] ) ) {
			return $args;
		}

		$headers['Authorization'] = $authorization;
		$args['headers']          = $headers;

		return $args;
	}

	/**
	 * Whether an address is the site's own, by its host.
	 *
	 * @param string $url The address.
	 */
	public static function is_own( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
	}
}
