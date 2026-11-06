<?php
/**
 * Sends the images that wait when the reason they wait is over.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Folders\Optimizer as Folder_Optimizer;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An attachment waiting on the next batch reads `paused` and one waiting on the
 * connection reads `parked`, each with no run scheduled, however many there are.
 * One routine queues them again, whether the batch opened, someone pressed
 * "Start now" or the site connected again, and Action Scheduler's priorities
 * send new uploads ahead of older work.
 */
class Queue {

	/**
	 * The timer that ends a pause.
	 */
	const OPENS_HOOK = 'iminify_batch_opens';

	/**
	 * A run that queues one page of the images waiting, and schedules the next.
	 */
	const RESUME_HOOK = 'iminify_resume_queue';

	const PAGE = 50;

	/**
	 * Action Scheduler's run for OPENS_HOOK. A pause that moved has its own timer.
	 */
	public static function batch_opens(): void {
		$until = Pause::until();

		if ( null !== $until ) {
			/* A pause moved by less than a timer's worth kept the old timer, which is this run. */
			if ( ! Optimizer::pending( self::OPENS_HOOK, array() ) ) {
				as_schedule_single_action( $until + 1, self::OPENS_HOOK, array(), Optimizer::GROUP );
			}

			return;
		}

		Pause::lift();
		self::resume();
	}

	/**
	 * Queues a page of the images waiting, in the order of their ids so a run ends
	 * however they fare. Those waiting on the next batch stay while it is shut. The
	 * first page also sends on the files in other folders, which page themselves
	 * (`Folders\Optimizer::resume()`).
	 *
	 * @param mixed $after The last id a run before this one queued.
	 */
	public static function resume( $after = 0 ): void {
		if ( null === Plugin::client() ) {
			return;
		}

		$statuses = null === Pause::until() ? array( Records::PARKED, Records::PAUSED ) : array( Records::PARKED );
		$ids      = Records::with_status( $statuses, self::PAGE, (int) $after );

		foreach ( $ids as $id ) {
			Optimizer::queue( $id, Optimizer::trigger( Records::get( $id )['trigger'] ), 0 );
		}

		if ( self::PAGE === count( $ids ) ) {
			as_schedule_single_action( time(), self::RESUME_HOOK, array( (int) end( $ids ) ), Optimizer::GROUP );
		}

		if ( 0 === (int) $after ) {
			Folder_Optimizer::resume( 0 );
		}
	}

	/**
	 * Schedules a run that queues the images waiting.
	 */
	public static function resume_soon(): void {
		$next = as_next_scheduled_action( self::RESUME_HOOK, array( 0 ), Optimizer::GROUP );

		/* A later run (the end of a network join's wait, `Network`) would hold back what can go now. */
		if ( is_int( $next ) && $next > time() ) {
			as_unschedule_action( self::RESUME_HOOK, array( 0 ), Optimizer::GROUP );
			$next = false;
		}

		if ( false === $next ) {
			as_schedule_single_action( time(), self::RESUME_HOOK, array( 0 ), Optimizer::GROUP );
		}
	}

	/**
	 * The site's calls to Iminify work, so anything parked goes on, and so does
	 * anything paused with no pause left: its batch may have opened while the site
	 * was disconnected, with no key to send it with.
	 */
	public static function connection_back(): void {
		$statuses = null === Pause::until() ? array( Records::PARKED, Records::PAUSED ) : array( Records::PARKED );

		if ( array() !== Records::with_status( $statuses, 1 ) || array() !== Table::anywhere( $statuses, 1 ) ) {
			self::resume_soon();
		}
	}

	/**
	 * "Start now": asks Iminify whether a batch can start, and sends what waits.
	 *
	 * @return bool|WP_Error Whether it started: false while the day is still spent.
	 */
	public static function start_now() {
		$site = Site_Record::fetch();

		if ( null === $site ) {
			return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( $site instanceof WP_Error ) {
			return $site;
		}

		$pause = Pause::current();

		if ( null !== $pause && Pause::FROM_SITE === $pause['from'] ) {
			return false;
		}

		Pause::lift();
		self::resume();

		/* A quiet site's next visitor may be hours away, so its cron gets a nudge. */
		spawn_cron();

		return true;
	}

	/**
	 * What waits, for the settings page, the files in other folders counted with the images.
	 *
	 * @return array{working: int, paused: int, parked: int, next_batch: string|null, can_start: bool}
	 */
	public static function summary(): array {
		$pause  = Pause::current();
		$paused = Records::count_with_status( array( Records::PAUSED ) ) + Table::count_anywhere( array( Records::PAUSED ) );

		return array(
			'working'    => Records::count_with_status( array( Records::QUEUED, Records::WAITING ) ) + Table::count_anywhere( array( Records::QUEUED, Records::WAITING ) ),
			'paused'     => $paused,
			'parked'     => Records::count_with_status( array( Records::PARKED ) ) + Table::count_anywhere( array( Records::PARKED ) ),
			'next_batch' => null === $pause ? null : State::date( gmdate( 'c', $pause['until'] ) ),
			'can_start'  => $paused > 0 && Plugin::connected() && ( null === $pause || Pause::FROM_SITE !== $pause['from'] ),
		);
	}
}
