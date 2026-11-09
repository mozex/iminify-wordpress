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

/*
 * With the state's `rtl`, the admin reads right to left as a right-to-left
 * language does, without a language pack to install.
 */
add_action(
	'after_setup_theme',
	static function () {
		$state = (array) get_option( 'iminify_e2e_state', array() );

		if ( ! empty( $state['rtl'] ) && isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale']->text_direction = 'rtl'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test's right-to-left admin.
		}
	}
);

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

		$answer = static function ( int $status, $body, array $headers = array() ): array {
			return array(
				'headers'  => array_merge( array( 'content-type' => 'application/json' ), $headers ),
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

			$site['data']['usage']['scans'] = array(
				'used'      => 1,
				'remaining' => 2,
				'resets_at' => gmdate( 'c', time() + 5 * HOUR_IN_SECONDS ),
			);

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

		/*
		 * The site's audits by id: the fixture's, of the page the test started it
		 * on, whose images' addresses are the ones `audit-images` seeded, which runs
		 * while the state's `audit_status` says so and whose site turns the scanner
		 * away with `audit_blocked`; and with `older`, one finished an hour before,
		 * of another page. Any other id is unknown.
		 */
		$older_id = '9c2b3c4d-1111-4a2b-9c3d-000000000002';
		$audits   = static function ( array $state ) use ( $fixture, $older_id ): array {
			$audit  = $fixture( 'site-audit' )['data'];
			$status = (string) ( $state['audit_status'] ?? 'completed' );

			$audit['url']    = (string) ( $state['audit_url'] ?? home_url( '/' ) );
			$audit['status'] = $status;
			$audit['done']   = ! in_array( $status, array( 'queued', 'scanning' ), true );

			foreach ( (array) ( $state['audit_images'] ?? array() ) as $index => $url ) {
				$audit['images'][ $index ]['url'] = (string) $url;
			}

			$older               = $audit;
			$older['id']         = $older_id;
			$older['url']        = home_url( '/sample-page/' );
			$older['status']     = 'completed';
			$older['done']       = true;
			$older['created_at'] = gmdate( 'c', strtotime( (string) $audit['created_at'] ) - HOUR_IN_SECONDS );
			$older['images']     = array( $audit['images'][0] );

			if ( ! empty( $state['audit_blocked'] ) ) {
				$audit['status']  = 'failed';
				$audit['done']    = true;
				$audit['problem'] = array(
					'code'    => 'blocked',
					'status'  => 403,
					'message' => 'Your site turned the scanner away.',
				);
			}

			if ( ! $audit['done'] || null !== $audit['problem'] ) {
				$audit['images']       = array();
				$audit['images_found'] = null;
			}

			$found = empty( $state['audited'] ) ? array() : array( $audit['id'] => $audit );

			if ( ! empty( $state['older'] ) ) {
				$found[ $older_id ] = $older;
			}

			return $found;
		};

		if ( ! empty( $state['audit_revoked'] ) && 0 === strpos( $path, 'site/audits' ) ) {
			return $answer(
				401,
				array(
					'message' => 'Unauthenticated.',
					'code'    => 'unauthenticated',
				)
			);
		}

		/* `audit_down` names an audit's id, or `list` for the list of them, that Iminify fails to answer. */
		if ( 'GET' === $method && ! empty( $state['audit_down'] ) && ( 'list' === $state['audit_down'] ? 'site/audits' : 'site/audits/' . $state['audit_down'] ) === $path ) {
			return $answer(
				500,
				array(
					'message' => 'Server Error',
					'code'    => 'server_error',
				)
			);
		}

		if ( 'GET' === $method && 'site/audits' === $path ) {
			$listed = array();

			foreach ( $audits( $state ) as $one ) {
				$listed[] = array_intersect_key( $one, array_flip( array( 'id', 'url', 'status', 'done', 'problem', 'images_found', 'created_at' ) ) );
			}

			return $answer( 200, array( 'data' => $listed ) );
		}

		if ( 'GET' === $method && 0 === strpos( $path, 'site/audits/' ) ) {
			$found = $audits( $state )[ substr( $path, strlen( 'site/audits/' ) ) ] ?? null;

			return null === $found ? $answer( 404, array( 'message' => 'Not found.' ) ) : $answer( 200, array( 'data' => $found ) );
		}

		if ( 'POST' === $method && 'site/audits' === $path ) {
			$url = (string) ( json_decode( (string) $args['body'], true )['url'] ?? '' );

			if ( ! empty( $state['audit_spent'] ) ) {
				return $answer(
					429,
					array(
						'message' => 'You have used your page scans for today. Come back in about 14 hours.',
						'code'    => 'daily_limit_reached',
					),
					array( 'retry-after' => '50400' )
				);
			}

			if ( ! empty( $state['audit_wait'] ) ) {
				return $answer(
					429,
					array(
						'message' => 'Too many page scans in a minute.',
						'code'    => 'rate_limited',
					),
					array( 'retry-after' => (string) $state['audit_wait'] )
				);
			}

			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return $answer(
					422,
					array(
						'message' => 'An audit scans only the site\'s own pages.',
						'code'    => 'audit_off_site',
					)
				);
			}

			$state = array_merge(
				$state,
				array(
					'audited'      => true,
					'audit_url'    => $url,
					'audit_status' => 'queued',
				)
			);
			update_option( 'iminify_e2e_state', $state, false );

			return $answer( 202, array( 'data' => $audits( $state )['9c2b3c4d-1111-4a2b-9c3d-000000000001'] ) );
		}

		if ( 'GET' === $method && 'site' === $path ) {
			return $answer( 200, $site() );
		}

		if ( 'PATCH' === $method && 'site' === $path ) {
			$state['settings'] = array_merge( (array) ( $state['settings'] ?? array() ), (array) json_decode( (string) $args['body'], true ) );
			update_option( 'iminify_e2e_state', $state, false );

			return $answer( 200, $site() );
		}

		if ( 'POST' === $method && 'site/import' === $path ) {
			return $answer( 200, $fixture( 'site-import' ) );
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
