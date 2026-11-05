<?php
/**
 * The site-wide stop while the account's day is spent.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Until a time: no new group opens, and no call goes out to find out whether
 * one could. Groups already open go on, since their image is already counted.
 *
 * `GET /site` is the record of a day it can see (`next_batch_at`), and such a
 * pause ends as soon as it says otherwise, as it does after an upgrade. A
 * refused group can also mean what `/site` can't show: a group with more files
 * than are left today, or a plan's fair-use line. That pause holds until its
 * `Retry-After` or until the account's plan changes.
 */
class Pause {

	const OPTION = 'iminify_pause';

	const FROM_SITE = 'site';

	const FROM_REFUSAL = 'refusal';

	/**
	 * How far a new time may be from the kept one and still be the same batch: every read works it out again.
	 */
	const SAME_BATCH_SECONDS = 60;

	/**
	 * When the pause ends, or null when there is none.
	 */
	public static function until(): ?int {
		$pause = self::current();

		return null === $pause ? null : $pause['until'];
	}

	/**
	 * The pause in force, or null.
	 *
	 * @return array{until: int, from: string, plan: string}|null
	 */
	public static function current(): ?array {
		$pause = get_option( self::OPTION );

		if ( ! is_array( $pause ) || ! isset( $pause['until'] ) || (int) $pause['until'] <= time() ) {
			return null;
		}

		return array(
			'until' => (int) $pause['until'],
			'from'  => isset( $pause['from'] ) ? (string) $pause['from'] : self::FROM_REFUSAL,
			'plan'  => isset( $pause['plan'] ) ? (string) $pause['plan'] : '',
		);
	}

	/**
	 * Starts the pause, with the timer that ends it.
	 *
	 * @param int    $until When the next batch opens.
	 * @param string $from  What said so: FROM_SITE or FROM_REFUSAL.
	 * @param string $plan  The account's plan when it did.
	 */
	public static function begin( int $until, string $from, string $plan ): void {
		$current = self::current();

		update_option(
			self::OPTION,
			array(
				'until' => $until,
				'from'  => $from,
				'plan'  => $plan,
			),
			false
		);

		if ( null === $current || abs( $current['until'] - $until ) > self::SAME_BATCH_SECONDS ) {
			as_unschedule_all_actions( Queue::OPENS_HOOK );
			as_schedule_single_action( $until + 1, Queue::OPENS_HOOK, array(), Optimizer::GROUP );
		}
	}

	/**
	 * A group was refused for the day: `/site`'s time stands if it has one, else the refusal's wait.
	 *
	 * @param int    $seconds How long the refusal asks to wait.
	 * @param string $plan    The account's plan.
	 */
	public static function refused( int $seconds, string $plan ): void {
		$current = self::current();

		if ( null === $current || self::FROM_SITE !== $current['from'] ) {
			self::begin( time() + $seconds, self::FROM_REFUSAL, $plan );
		}
	}

	/**
	 * Ends the pause.
	 */
	public static function lift(): void {
		delete_option( self::OPTION );
		as_unschedule_all_actions( Queue::OPENS_HOOK );
	}

	/**
	 * Reads a site record Iminify just answered. A spent day starts the pause; one
	 * that is no longer spent ends a pause that came from `/site`, and a changed plan
	 * ends any pause, and the images waiting go on.
	 *
	 * @param array<string, mixed> $site The record.
	 */
	public static function observe( array $site ): void {
		$next = isset( $site['next_batch_at'] ) && is_string( $site['next_batch_at'] ) ? strtotime( $site['next_batch_at'] ) : false;
		$plan = isset( $site['plan'] ) && is_string( $site['plan'] ) ? $site['plan'] : '';

		if ( false !== $next && $next > time() ) {
			self::begin( $next, self::FROM_SITE, $plan );

			return;
		}

		$current = self::current();

		if ( null !== $current && ( self::FROM_SITE === $current['from'] || $plan !== $current['plan'] ) ) {
			self::lift();
			Queue::resume_soon();
		}
	}
}
