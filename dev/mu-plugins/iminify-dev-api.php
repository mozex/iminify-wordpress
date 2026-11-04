<?php
/**
 * Plugin Name: Iminify development API
 * Description: Lets the development WordPress reach a development copy of the Iminify API. Mapped in only by a local .wp-env.override.json, and never shipped.
 *
 * Three constants, set in the override's "config":
 * - IMINIFY_API_BASE: the API's address, such as https://iminify.local/api/v1.
 * - IMINIFY_DEV_RESOLVE: the address the API's host name reaches from inside the
 *   container, such as host.docker.internal.
 * - IMINIFY_DEV_CA: a certificate authority file that signed the development
 *   site's certificate, trusted for that host alone.
 *
 * @package Iminify\WordPress
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'IMINIFY_API_BASE' ) ) {
	return;
}

/**
 * The development API's host name.
 */
function iminify_dev_api_host(): string {
	return (string) wp_parse_url( (string) constant( 'IMINIFY_API_BASE' ), PHP_URL_HOST );
}

add_filter(
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( defined( 'IMINIFY_DEV_CA' ) && iminify_dev_api_host() === wp_parse_url( $url, PHP_URL_HOST ) ) {
			$args['sslcertificates'] = (string) constant( 'IMINIFY_DEV_CA' );
		}

		return $args;
	},
	10,
	2
);

add_action(
	'http_api_curl',
	static function ( $handle, array $args, string $url ): void {
		if ( ! defined( 'IMINIFY_DEV_RESOLVE' ) || iminify_dev_api_host() !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return;
		}

		$port = wp_parse_url( $url, PHP_URL_PORT );
		$port = $port ? $port : ( 'http' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443 );

		curl_setopt( $handle, CURLOPT_RESOLVE, array( iminify_dev_api_host() . ':' . $port . ':' . gethostbyname( (string) constant( 'IMINIFY_DEV_RESOLVE' ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- only the HTTP API's own handle is adjusted.
	},
	10,
	3
);
