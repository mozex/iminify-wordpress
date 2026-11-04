<?php
/**
 * A stand-in for Iminify's API in the integration tests.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

use Iminify\WordPress\Api\Client;
use WP_Error;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Answers every call to the API's address through `pre_http_request`, so the
 * plugin's real HTTP code runs end to end without a network or a key, and
 * records each call. A call nothing was set up for answers 404 `not_found`,
 * so a test never passes on an answer it didn't expect.
 */
class Api_Stub {

	/**
	 * The calls the plugin made, in order.
	 *
	 * @var list<array{method: string, path: string, url: string, args: array<string, mixed>}>
	 */
	public $requests = array();

	/**
	 * The answers by method and path.
	 *
	 * @var array<string, array{status: int, body: string, headers: array<string, string>}|WP_Error>
	 */
	protected $answers = array();

	/**
	 * Starts answering calls to the API.
	 */
	public function start(): self {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );

		return $this;
	}

	/**
	 * Stops answering.
	 */
	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
	}

	/**
	 * Sets the answer to a call.
	 *
	 * @param string                    $method  The HTTP method.
	 * @param string                    $path    The path under the API's address, without its query.
	 * @param int                       $status  The HTTP status.
	 * @param array<mixed>|string       $body    The body, encoded as JSON when an array.
	 * @param array<string, string>     $headers The response headers.
	 */
	public function on( string $method, string $path, int $status, $body, array $headers = array() ): self {
		$this->answers[ strtoupper( $method ) . ' ' . $path ] = array(
			'status'  => $status,
			'body'    => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'headers' => $headers,
		);

		return $this;
	}

	/**
	 * Makes a call fail before it reaches the API, as a lost connection does.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The path under the API's address.
	 */
	public function fail( string $method, string $path ): self {
		$this->answers[ strtoupper( $method ) . ' ' . $path ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		return $this;
	}

	/**
	 * A fixture's decoded JSON from tests/fixtures.
	 *
	 * @param string $name The fixture's name, without `.json`.
	 * @return array<string, mixed>
	 */
	public static function fixture( string $name ): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.

		return (array) json_decode( (string) $json, true );
	}

	/**
	 * The `pre_http_request` filter: answers a call to the API, and lets any other through.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt What an earlier filter answered.
	 * @param array<string, mixed>                $args    The request's arguments.
	 * @param string                              $url     The request's address.
	 * @return false|array<string, mixed>|WP_Error
	 */
	public function answer( $preempt, array $args, string $url ) {
		$base = Client::base() . '/';

		if ( 0 !== strpos( $url, $base ) ) {
			return $preempt;
		}

		$path   = (string) strtok( substr( $url, strlen( $base ) ), '?' );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

		$this->requests[] = array(
			'method' => $method,
			'path'   => $path,
			'url'    => $url,
			'args'   => $args,
		);

		$answer = $this->answers[ $method . ' ' . $path ] ?? array(
			'status'  => 404,
			'body'    => (string) wp_json_encode(
				array(
					'message' => 'Nothing was set up to answer ' . $method . ' ' . $path . '.',
					'code'    => 'not_found',
				)
			),
			'headers' => array(),
		);

		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		return array(
			'headers'  => new CaseInsensitiveDictionary( array_merge( array( 'content-type' => 'application/json' ), $answer['headers'] ) ),
			'body'     => $answer['body'],
			'response' => array(
				'code'    => $answer['status'],
				'message' => get_status_header_desc( $answer['status'] ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The last call the plugin made.
	 *
	 * @return array{method: string, path: string, url: string, args: array<string, mixed>}
	 */
	public function last(): array {
		$last = end( $this->requests );

		if ( false === $last ) {
			throw new \RuntimeException( 'No call reached the API.' );
		}

		return $last;
	}
}
