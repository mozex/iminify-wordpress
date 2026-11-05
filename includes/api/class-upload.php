<?php
/**
 * Sends an image to Iminify without holding it in memory.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress's HTTP functions send a body only as a string, so a source read into
 * one costs its size twice over in PHP's memory: a 90 MB original on a 128 MB
 * limit would end the request. Where WordPress sends over cURL, as nearly every
 * host does, the request goes out with no body and `attach()` hands cURL the
 * file instead (`http_api_curl`), which cURL reads from the disk as it sends.
 * Elsewhere the body is built in memory, and `max_bytes()` says how large a file
 * that leaves room for, so a larger original gives way to WordPress's `-scaled`
 * copy rather than ending the request.
 */
class Upload {

	/**
	 * The request argument that carries the file to stream, read by `attach()`.
	 */
	const ARGUMENT = 'iminify_file';

	/**
	 * The memory kept free beside a source built into a body, in bytes.
	 */
	const MEMORY_RESERVE = 33554432;

	/**
	 * Hooks the stream into WordPress's cURL requests.
	 */
	public static function register(): void {
		add_action( 'http_api_curl', array( self::class, 'attach' ), 10, 2 );
	}

	/**
	 * Whether this site sends a file by streaming it: WordPress's HTTP functions go
	 * through cURL here, which `iminify_stream_source` can turn off.
	 *
	 * @param string $path The file about to be sent.
	 */
	public static function streams( string $path ): bool {
		/* Requests tries cURL first and takes it whenever it passes this test. */
		$streams = class_exists( '\WpOrg\Requests\Transport\Curl' ) && \WpOrg\Requests\Transport\Curl::test( array( \WpOrg\Requests\Capability::SSL => true ) );

		/**
		 * Whether to stream an image to Iminify over cURL, rather than read it into
		 * the request's body. Turn it off for a host whose proxy or firewall mangles a
		 * streamed upload; the plugin then sends WordPress's `-scaled` copy of an
		 * original too large to hold in memory.
		 *
		 * @param bool   $streams Whether it streams.
		 * @param string $path    The file about to be sent.
		 */
		return (bool) apply_filters( 'iminify_stream_source', $streams, $path );
	}

	/**
	 * The largest file this site can send now, or null for any size: a streamed file
	 * takes no memory, and a file built into a body takes about twice its size.
	 *
	 * @param string $path The file about to be sent, or '' to ask in general.
	 */
	public static function max_bytes( string $path = '' ): ?int {
		if ( self::streams( $path ) ) {
			return null;
		}

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return null;
		}

		return max( 0, (int) floor( ( $limit - memory_get_usage() - self::MEMORY_RESERVE ) / 2 ) );
	}

	/**
	 * The request's arguments for one file sent as the multipart field `file`: no body
	 * and the file for `attach()` where it streams, or the body built in memory.
	 *
	 * @param string $path The file.
	 * @param string $type Its MIME type.
	 * @return array<string, mixed>|WP_Error The arguments to merge into the request's.
	 */
	public static function arguments( string $path, string $type ) {
		if ( ! is_readable( $path ) ) {
			return self::unreadable();
		}

		$name = 'source.' . self::extension( $path );
		$type = (string) preg_replace( '#[^a-z0-9.+/-]#i', '', $type );

		if ( self::streams( $path ) ) {
			return array(
				'body'         => '',
				self::ARGUMENT => array(
					'path' => $path,
					'type' => $type,
					'name' => $name,
				),
			);
		}

		$max = self::max_bytes( $path );

		if ( null !== $max && (int) filesize( $path ) > $max ) {
			return new WP_Error( 'source_too_large', __( 'This server doesn\'t have the memory to send an image this large.', 'iminify-image-optimizer' ), array( 'status' => 0 ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local image into the request body where it can't be streamed.

		if ( false === $bytes ) {
			return self::unreadable();
		}

		$boundary = 'iminify-' . wp_generate_password( 24, false );

		return array(
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="file"; filename="' . $name . "\"\r\n"
				. 'Content-Type: ' . $type . "\r\n\r\n"
				. $bytes . "\r\n"
				. '--' . $boundary . "--\r\n",
		);
	}

	/**
	 * The `http_api_curl` action: hands cURL the file of a request that carries one,
	 * which it sends as the multipart field `file`, reading it from the disk as it goes.
	 * Every other request is left alone.
	 *
	 * @param mixed $handle The cURL handle (a resource on PHP 7, a CurlHandle on PHP 8).
	 * @param mixed $args   The request's arguments.
	 */
	public static function attach( $handle, $args ): void {
		$fields = self::fields( $args );

		if ( null !== $fields ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- streams the file through WordPress's own cURL request, whose body can only be a string.
			curl_setopt( $handle, CURLOPT_POSTFIELDS, $fields );
		}
	}

	/**
	 * The form cURL sends for a request that carries a file, or null for any other.
	 *
	 * @param mixed $args The request's arguments.
	 * @return array{file: \CURLFile}|null
	 */
	public static function fields( $args ): ?array {
		if ( ! is_array( $args ) || ! isset( $args[ self::ARGUMENT ]['path'] ) || ! class_exists( 'CURLFile' ) ) {
			return null;
		}

		$file = $args[ self::ARGUMENT ];

		return array( 'file' => new \CURLFile( (string) $file['path'], (string) ( $file['type'] ?? '' ), (string) ( $file['name'] ?? '' ) ) );
	}

	/**
	 * A file's extension as the service reads a name: lower case letters and digits.
	 *
	 * @param string $path The file.
	 */
	protected static function extension( string $path ): string {
		$extension = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) );

		return '' === $extension ? 'bin' : $extension;
	}

	/**
	 * The error for a source that can't be read.
	 */
	protected static function unreadable(): WP_Error {
		return new WP_Error( 'source_unreadable', __( 'The image couldn\'t be read from the uploads folder.', 'iminify-image-optimizer' ), array( 'status' => 0 ) );
	}
}
