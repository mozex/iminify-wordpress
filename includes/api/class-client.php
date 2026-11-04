<?php
/**
 * The plugin's client for Iminify's API, on WordPress's HTTP functions.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every call to Iminify goes through here, from the site's server only: the
 * site key never reaches a browser. Each call names the site by its home
 * address, which Iminify checks against the address the key was issued for,
 * and the plugin's version, which Iminify reads to tell an old copy to update.
 * An answer outside 2xx comes back as a WP_Error whose code is the API's own
 * (`rate_limited`, `site_moved`, ...), with the HTTP status, any
 * Retry-After and a refused field's message in its data.
 */
class Client {

	const BASE = 'https://www.iminify.com/api/v1';

	const TIMEOUT = 15;

	/**
	 * The site key, or null for the calls that connect a site.
	 *
	 * @var string|null
	 */
	protected $key;

	/**
	 * Builds a client for the given site key.
	 *
	 * @param string|null $key The site key, or null before the site is connected.
	 */
	public function __construct( ?string $key = null ) {
		$this->key = $key;
	}

	/**
	 * The API's address: Iminify's own, unless wp-config.php points the plugin at another
	 * copy of the service over https. An address without https is ignored, since every call
	 * carries the site key.
	 */
	public static function base(): string {
		$base = defined( 'IMINIFY_API_BASE' ) ? constant( 'IMINIFY_API_BASE' ) : null;

		return untrailingslashit( is_string( $base ) && 0 === stripos( $base, 'https://' ) ? $base : self::BASE );
	}

	/**
	 * An address on the website the API belongs to, such as its pricing page, with
	 * the query the plugin's links carry so Iminify can tell where a visit came from.
	 *
	 * @param string                $path  The path on the website.
	 * @param array<string, string> $query Extra query parameters.
	 */
	public static function website( string $path, array $query = array() ): string {
		$site = (string) preg_replace( '#/api/v\d+$#', '', self::base() );

		return add_query_arg(
			array_merge(
				array(
					'utm_source' => 'wordpress',
					'utm_medium' => 'plugin',
				),
				$query
			),
			$site . '/' . ltrim( $path, '/' )
		);
	}

	/**
	 * The site's record on Iminify: its settings, the plan, the day's counts and the service's message.
	 *
	 * @param array{attachments: int, files: int}|null $waiting What the site has waiting, for the bulk estimate.
	 * @return array<string, mixed>|WP_Error
	 */
	public function site( ?array $waiting = null ) {
		$query = null === $waiting ? array() : array(
			'waiting'       => $waiting['attachments'],
			'waiting_files' => $waiting['files'],
		);

		return $this->data( $this->request( 'GET', 'site', array( 'query' => $query ) ) );
	}

	/**
	 * Changes the site's settings on Iminify, which checks every value.
	 *
	 * @param array<string, mixed> $settings The settings to change, flat.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_site( array $settings ) {
		return $this->data( $this->request( 'PATCH', 'site', array( 'json' => $settings ) ) );
	}

	/**
	 * Moves the site's connection to the address this copy runs at, after a `site_moved`.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function move_site() {
		return $this->data( $this->request( 'POST', 'site/move' ) );
	}

	/**
	 * Disconnects the site: its key stops working on Iminify.
	 *
	 * @return true|WP_Error
	 */
	public function disconnect() {
		$response = $this->request( 'DELETE', 'site' );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Opens a pairing for this site, to be approved on www.iminify.com.
	 *
	 * @param string $url  The site's home address.
	 * @param string $name The site's name.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start_pairing( string $url, string $name ) {
		return $this->data(
			$this->request(
				'POST',
				'connect/start',
				array(
					'json' => array(
						'url'  => $url,
						'name' => $name,
					),
					'auth' => false,
				)
			)
		);
	}

	/**
	 * Collects the site key once the pairing was approved; until then the API answers `pairing_pending`.
	 *
	 * @param string $pairing The pairing's id.
	 * @param string $secret  The pairing's secret, which never left this server.
	 * @return array<string, mixed>|WP_Error
	 */
	public function claim_pairing( string $pairing, string $secret ) {
		return $this->data(
			$this->request(
				'POST',
				'connect/claim',
				array(
					'json' => array(
						'pairing' => $pairing,
						'secret'  => $secret,
					),
					'auth' => false,
				)
			)
		);
	}

	/**
	 * Sends one call and reads its answer.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The path under the API's address.
	 * @param array{query?: array<string, scalar>, json?: array<string, mixed>, auth?: bool, timeout?: int} $options How to send it.
	 * @return array{status: int, body: array<string, mixed>}|WP_Error
	 */
	public function request( string $method, string $path, array $options = array() ) {
		$auth = $options['auth'] ?? true;

		if ( $auth && ( null === $this->key || '' === $this->key ) ) {
			return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 0 ) );
		}

		$url = self::base() . '/' . ltrim( $path, '/' );

		if ( ! empty( $options['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $options['query'] ) ), $url );
		}

		$headers = array(
			'Accept'         => 'application/json',
			'Iminify-Site'   => home_url(),
			'Iminify-Client' => 'wordpress/' . IMINIFY_VERSION,
		);

		if ( $auth ) {
			$headers['Authorization'] = 'Bearer ' . $this->key;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $options['timeout'] ?? self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
		);

		if ( isset( $options['json'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $options['json'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'unreachable',
				/* translators: %s: the reason the connection failed. */
				sprintf( __( 'Iminify couldn\'t be reached: %s', 'iminify-image-optimizer' ), $response->get_error_message() ),
				array( 'status' => 0 )
			);
		}

		return $this->read( $response );
	}

	/**
	 * Reads an answer: its JSON body on 2xx, otherwise the API's error.
	 *
	 * @param array<string, mixed> $response A response from wp_remote_request().
	 * @return array{status: int, body: array<string, mixed>}|WP_Error
	 */
	protected function read( array $response ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : array();

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'status' => $status,
				'body'   => $body,
			);
		}

		$code    = isset( $body['code'] ) && is_string( $body['code'] ) && '' !== $body['code'] ? $body['code'] : 'http_' . $status;
		$message = isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message']
			? $body['message']
			/* translators: %d: the HTTP status Iminify answered. */
			: sprintf( __( 'Iminify answered with an error (HTTP %d).', 'iminify-image-optimizer' ), $status );

		return new WP_Error(
			$code,
			$message,
			array(
				'status'      => $status,
				'retry_after' => self::retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) ),
				'errors'      => self::field_errors( $body['errors'] ?? null ),
			)
		);
	}

	/**
	 * The answer's `data`, or the error it carries.
	 *
	 * @param array{status: int, body: array<string, mixed>}|WP_Error $response A read answer.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['body']['data'] ?? null;

		return is_array( $data ) ? $data : new WP_Error(
			'unexpected_response',
			__( 'Iminify sent an answer this version of the plugin can\'t read.', 'iminify-image-optimizer' ),
			array( 'status' => $response['status'] )
		);
	}

	/**
	 * A `validation_failed` answer's messages by field, each field's first one: the service's
	 * own words for a value it refused, so the plugin never restates its bounds.
	 *
	 * @param mixed $errors The answer's `errors`.
	 * @return array<string, string>
	 */
	protected static function field_errors( $errors ): array {
		if ( ! is_array( $errors ) ) {
			return array();
		}

		$fields = array();

		foreach ( $errors as $field => $messages ) {
			$first = is_array( $messages ) ? reset( $messages ) : $messages;

			if ( is_string( $field ) && is_string( $first ) && '' !== $first ) {
				$fields[ $field ] = $first;
			}
		}

		return $fields;
	}

	/**
	 * The seconds a Retry-After header asks to wait, as seconds or an HTTP date, or null without one.
	 *
	 * @param string|string[] $header The header's value.
	 */
	public static function retry_after( $header ): ?int {
		$value = is_array( $header ) ? (string) reset( $header ) : (string) $header;
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$date = \DateTimeImmutable::createFromFormat( 'D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone( 'UTC' ) );

		return false === $date ? null : max( 0, $date->getTimestamp() - time() );
	}
}
