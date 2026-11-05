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
	 * Seconds for a call that carries an image either way: a source up to the plan's file size
	 * going up, or a group's zip coming down.
	 */
	const TRANSFER_TIMEOUT = 120;

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
	 * @param int $timeout How long to wait for the answer, in seconds.
	 * @return true|WP_Error
	 */
	public function disconnect( int $timeout = self::TIMEOUT ) {
		$response = $this->request( 'DELETE', 'site', array( 'timeout' => $timeout ) );

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
	 * Opens an upload group: one image and the outputs to make from it. A site's group takes
	 * the site's settings on Iminify, whatever the declaration says beside its outputs and a
	 * level it names.
	 *
	 * @param array<string, mixed> $declaration The source and its outputs, as `POST /groups` takes them.
	 * @return array<string, mixed>|WP_Error
	 */
	public function open_group( array $declaration ) {
		return $this->data( $this->request( 'POST', 'groups', array( 'json' => $declaration ) ) );
	}

	/**
	 * Sends a group's source, the file it was opened for.
	 *
	 * @param string $group The group's id.
	 * @param string $path  The file on this server.
	 * @param string $type  Its MIME type.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_group_source( string $group, string $path, string $type ) {
		return $this->data(
			$this->request(
				'POST',
				'groups/' . rawurlencode( $group ) . '/source',
				array(
					'file'    => array(
						'path' => $path,
						'type' => $type,
					),
					'timeout' => self::TRANSFER_TIMEOUT,
				)
			)
		);
	}

	/**
	 * A group as it stands: its status and each output's verdict.
	 *
	 * @param string $group The group's id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function group( string $group ) {
		return $this->data( $this->request( 'GET', 'groups/' . rawurlencode( $group ) ) );
	}

	/**
	 * Downloads a finished group's zip into a file on this server.
	 *
	 * @param string $group       The group's id.
	 * @param string $destination Where to write the zip.
	 * @return true|WP_Error
	 */
	public function download_group( string $group, string $destination ) {
		$response = $this->request(
			'GET',
			'groups/' . rawurlencode( $group ) . '/download',
			array(
				'save_to' => $destination,
				'timeout' => self::TRANSFER_TIMEOUT,
			)
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Deletes a group from Iminify, once its files are written.
	 *
	 * @param string $group The group's id.
	 * @return true|WP_Error
	 */
	public function delete_group( string $group ) {
		$response = $this->request( 'DELETE', 'groups/' . rawurlencode( $group ) );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Sends one call and reads its answer.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The path under the API's address.
	 * @param array{query?: array<string, scalar>, json?: array<string, mixed>, file?: array{path: string, type: string}, save_to?: string, auth?: bool, timeout?: int} $options How to send it: a JSON body, or one file as a multipart body; `save_to` writes a 2xx answer's body to a file.
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

		if ( isset( $options['file'] ) ) {
			$body = self::multipart( $options['file']['path'], $options['file']['type'] );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $body['boundary'];
			$args['body']                    = $body['body'];
		}

		if ( isset( $options['save_to'] ) ) {
			$args['stream']   = true;
			$args['filename'] = $options['save_to'];
		}

		$response = wp_remote_request( $url, $args );

		if ( isset( $options['save_to'] ) ) {
			return $this->read_saved( $response, $options['save_to'] );
		}

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
	 * A multipart body carrying one file as the `file` field. WordPress's HTTP functions send
	 * a string body, so the file is read into it; a source is at most the plan's file size.
	 *
	 * @param string $path The file.
	 * @param string $type Its MIME type.
	 * @return array{boundary: string, body: string}|WP_Error
	 */
	protected static function multipart( string $path, string $type ) {
		$bytes = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local image into the request body.

		if ( false === $bytes ) {
			return new WP_Error( 'source_unreadable', __( 'The image couldn\'t be read from the uploads folder.', 'iminify-image-optimizer' ), array( 'status' => 0 ) );
		}

		$boundary  = 'iminify-' . wp_generate_password( 24, false );
		$extension = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) );

		return array(
			'boundary' => $boundary,
			'body'     => '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="file"; filename="source.' . ( '' === $extension ? 'bin' : $extension ) . "\"\r\n"
				. 'Content-Type: ' . preg_replace( '#[^a-z0-9.+/-]#i', '', $type ) . "\r\n\r\n"
				. $bytes . "\r\n"
				. '--' . $boundary . "--\r\n",
		);
	}

	/**
	 * Reads an answer whose body went to a file: kept on 2xx, otherwise read as the API's
	 * error and removed.
	 *
	 * @param array<string, mixed>|WP_Error $response A response from wp_remote_request().
	 * @param string                        $file     Where its body was written.
	 * @return array{status: int, body: array<string, mixed>}|WP_Error
	 */
	protected function read_saved( $response, string $file ) {
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );

			return new WP_Error(
				'unreachable',
				/* translators: %s: the reason the connection failed. */
				sprintf( __( 'Iminify couldn\'t be reached: %s', 'iminify-image-optimizer' ), $response->get_error_message() ),
				array( 'status' => 0 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'status' => $status,
				'body'   => array(),
			);
		}

		$body = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 65536 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the error body the stream wrote to a local file.
		wp_delete_file( $file );

		$response['body'] = $body;

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
