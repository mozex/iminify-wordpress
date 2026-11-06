<?php
/**
 * What an image does when the service refuses it or can't be reached.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Connection;
use Iminify\WordPress\Site_Record;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One reading of a refusal for every pipeline that sends images (an
 * attachment's, `Optimizer`, and a file's in another folder, `Folders\Optimizer`),
 * so a spent day, a full queue and a stopped key mean the same everywhere. It
 * does what concerns the whole site itself (a key the service stopped
 * disconnects it; a spent day starts the pause) and answers what the image
 * does next.
 */
class Refusal {

	/**
	 * Wait for the site to be connected again, keeping any open group.
	 */
	const PARK = 'park';

	/**
	 * Wait for the account's next batch.
	 */
	const PAUSE = 'pause';

	/**
	 * Run the same step again after the wait the service asked for, which never counts as a failed try.
	 */
	const WAIT = 'wait';

	/**
	 * Run the same step again after a backoff, counted as a failed try.
	 */
	const RETRY = 'retry';

	/**
	 * Show the error.
	 */
	const FAIL = 'fail';

	/**
	 * Reads a refusal.
	 *
	 * @param WP_Error|null $error    What went wrong, or null for no answer at all.
	 * @param bool          $opening  Whether the refused call opened a group, the only call a spent day refuses.
	 * @param int           $attempts The image's failed tries so far.
	 * @return array{then: string, code: string, message: string, seconds: int, throttled: bool} What the image does, why, how long it waits, and whether a bulk group was turned away for the work already waiting on the service.
	 */
	public static function decide( ?WP_Error $error, bool $opening, int $attempts ): array {
		$code    = null === $error ? 'unexpected_response' : (string) $error->get_error_code();
		$message = null === $error ? __( 'Iminify sent an answer this version of the plugin can\'t read.', 'iminify-image-optimizer' ) : $error->get_error_message();
		$data    = null === $error ? array() : $error->get_error_data();
		$data    = is_array( $data ) ? $data : array();
		$status  = (int) ( $data['status'] ?? 0 );
		$then    = array(
			'then'      => self::FAIL,
			'code'      => $code,
			'message'   => $message,
			'seconds'   => 0,
			'throttled' => false,
		);

		if ( 'unauthenticated' === $code ) {
			( new Connection() )->revoked_by_service();

			return array_merge( $then, array( 'then' => self::PARK ) );
		}

		if ( in_array( $code, array( 'not_connected', 'site_moved' ), true ) ) {
			return array_merge( $then, array( 'then' => self::PARK ) );
		}

		if ( Optimizer::DAILY_LIMIT === $code && $opening ) {
			$site = Site_Record::fetch();

			Pause::refused( isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : HOUR_IN_SECONDS, is_array( $site ) && isset( $site['plan'] ) ? (string) $site['plan'] : '' );

			return array_merge( $then, array( 'then' => self::PAUSE ) );
		}

		if ( 429 === $status ) {
			return array_merge(
				$then,
				array(
					'then'      => self::WAIT,
					'seconds'   => isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : MINUTE_IN_SECONDS,
					'throttled' => 'rate_limited' === $code && $opening,
				)
			);
		}

		if ( 'unreachable' === $code || $status >= 500 ) {
			return array_merge(
				$then,
				array(
					'then'    => self::RETRY,
					'seconds' => (int) min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, $attempts ) ),
				)
			);
		}

		return $then;
	}
}
