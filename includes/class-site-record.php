<?php
/**
 * The site's record on Iminify, read and kept for a few minutes.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /api/v1/site` answers the site's settings, the plan, the day's counts
 * and the service's message. The plugin's own pages read it fresh and keep the
 * record a few minutes; the message is kept until a later answer replaces it,
 * since the notice on other screens reads only what is kept here, so no admin
 * screen outside the plugin's calls Iminify.
 */
class Site_Record {

	const TRANSIENT = 'iminify_site';

	const MESSAGE_OPTION = 'iminify_site_message';

	const TTL = 300;

	/**
	 * Reads the record from Iminify and keeps it. A key Iminify no longer knows
	 * disconnects the site here too.
	 *
	 * @param array{attachments: int, files: int}|null $waiting What the site has waiting, for the bulk estimate.
	 * @return array<string, mixed>|WP_Error|null Null while the site isn't connected.
	 */
	public static function fetch( ?array $waiting = null ) {
		$client = Plugin::client();

		if ( null === $client ) {
			return null;
		}

		$site = $client->site( $waiting );

		if ( is_wp_error( $site ) ) {
			if ( 'unauthenticated' === $site->get_error_code() ) {
				( new Connection() )->revoked_by_service();
			}

			return $site;
		}

		self::remember( $site );

		return $site;
	}

	/**
	 * The record last read, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cached(): ?array {
		$site = get_transient( self::TRANSIENT );

		return is_array( $site ) ? $site : null;
	}

	/**
	 * Keeps a record Iminify just answered.
	 *
	 * @param array<string, mixed> $site The record.
	 */
	public static function remember( array $site ): void {
		set_transient( self::TRANSIENT, $site, self::TTL );
		delete_option( Connection::REVOKED_OPTION );

		$message = $site['message'] ?? null;

		if ( ! is_array( $message ) || ! isset( $message['code'] ) || ! is_string( $message['code'] ) ) {
			delete_option( self::MESSAGE_OPTION );

			return;
		}

		update_option(
			self::MESSAGE_OPTION,
			array(
				'message'         => $message,
				'next_batch_at'   => isset( $site['next_batch_at'] ) && is_string( $site['next_batch_at'] ) ? $site['next_batch_at'] : null,
				'minimum_version' => isset( $site['minimum_version'] ) && is_string( $site['minimum_version'] ) ? $site['minimum_version'] : null,
			),
			false
		);
	}

	/**
	 * The service's message from the last record read, with when the next batch
	 * opens, or null.
	 *
	 * @return array{message: array<string, mixed>, next_batch_at: string|null, minimum_version: string|null}|null
	 */
	public static function message(): ?array {
		$kept = get_option( self::MESSAGE_OPTION );

		if ( ! is_array( $kept ) || ! isset( $kept['message'] ) || ! is_array( $kept['message'] ) ) {
			return null;
		}

		return array(
			'message'         => $kept['message'],
			'next_batch_at'   => isset( $kept['next_batch_at'] ) && is_string( $kept['next_batch_at'] ) ? $kept['next_batch_at'] : null,
			'minimum_version' => isset( $kept['minimum_version'] ) && is_string( $kept['minimum_version'] ) ? $kept['minimum_version'] : null,
		);
	}

	/**
	 * Drops the kept record.
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
		delete_option( self::MESSAGE_OPTION );
	}
}
