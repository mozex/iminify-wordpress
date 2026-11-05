<?php
/**
 * Plugin Name: Iminify end-to-end stub
 * Description: Answers the plugin's calls to Iminify from the test fixtures, for the browser tests. Loaded only by .wp-env.e2e.json.
 *
 * @package Iminify\WordPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Nothing runs Action Scheduler's queue here, neither its own requests nor
 * WP-Cron (which "Start now" nudges), so a test reads each image's state as a
 * button left it.
 */
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_get_ready_cron_jobs', '__return_empty_array' );

add_filter(
	'pre_http_request',
	/**
	 * Answers a call to the API from the fixtures and the state the tests set.
	 *
	 * @param mixed  $preempt Whatever answered before.
	 * @param array  $args    The request's arguments.
	 * @param string $url     The address called.
	 * @return mixed
	 */
	static function ( $preempt, $args, $url ) {
		$base = 'https://www.iminify.com/api/v1/';

		if ( 0 !== strpos( (string) $url, $base ) ) {
			return $preempt;
		}

		$path   = (string) strtok( substr( (string) $url, strlen( $base ) ), '?' );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$state  = (array) get_option( 'iminify_e2e_state', array() );
		$calls  = (array) get_option( 'iminify_e2e_calls', array() );

		$calls[] = array(
			'method' => $method,
			'path'   => $path,
			'body'   => isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);
		update_option( 'iminify_e2e_calls', $calls, false );

		$fixture = static function ( string $name ): array {
			return (array) json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/iminify-image-optimizer/tests/fixtures/' . $name . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a test fixture.
		};

		$answer = static function ( int $status, $body ): array {
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => null === $body ? '' : (string) wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => get_status_header_desc( $status ),
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$site = static function () use ( $fixture, $state ): array {
			$site                     = $fixture( 'site' );
			$site['data']['url']      = home_url();
			$site['data']['settings'] = array_merge( $site['data']['settings'], (array) ( $state['settings'] ?? array() ) );

			if ( ! empty( $state['spent'] ) ) {
				$site['data']['usage']['images']['used']      = 40;
				$site['data']['usage']['images']['remaining'] = 0;
				$site['data']['next_batch_at']                = gmdate( 'c', time() + 2 * HOUR_IN_SECONDS );
				$site['data']['message']                      = array(
					'code' => 'day_spent',
					'text' => 'Your account\'s images for the day are used up.',
				);
			}

			return $site;
		};

		if ( 'GET' === $method && 'site' === $path ) {
			return $answer( 200, $site() );
		}

		if ( 'PATCH' === $method && 'site' === $path ) {
			$state['settings'] = array_merge( (array) ( $state['settings'] ?? array() ), (array) json_decode( (string) $args['body'], true ) );
			update_option( 'iminify_e2e_state', $state, false );

			return $answer( 200, $site() );
		}

		if ( 'DELETE' === $method && 'site' === $path ) {
			return $answer( 204, null );
		}

		if ( 'POST' === $method && 'connect/start' === $path ) {
			$start                       = $fixture( 'connect-start' );
			$start['data']['expires_at'] = gmdate( 'c', time() + HOUR_IN_SECONDS );

			return $answer( 201, $start );
		}

		if ( 'POST' === $method && 'connect/claim' === $path ) {
			if ( empty( $state['approved'] ) ) {
				return $answer(
					409,
					array(
						'message' => 'Nothing to collect yet.',
						'code'    => 'pairing_pending',
					)
				);
			}

			return $answer( 200, $fixture( 'connect-claim' ) );
		}

		return $answer(
			404,
			array(
				'message' => 'The end-to-end stub has no answer for ' . $method . ' ' . $path . '.',
				'code'    => 'not_found',
			)
		);
	},
	10,
	3
);
