<?php
/**
 * The bulk run: the library's images optimized or restored in the background, or while its page is open.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use ActionScheduler;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Optimizer as Folder_Optimizer;
use Iminify\WordPress\Folders\Restorer as Folder_Restorer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One run at a time, kept in an option: what it does (optimize the images not
 * optimized yet, send the failed ones again, restore every optimized one, or make
 * the formats or sizes the optimized ones lack), how it runs, and how far it got,
 * by attachment id, so it ends however each image fares. A run started from the
 * shell can name its images (`Cli`).
 *
 * Optimizing never queues the whole library at once. A feeder hands a window of
 * images to `Optimizer` at a time, each as the BULK trigger, which takes the slower
 * lane, and queues more as they leave the queue. The window is counted in the files
 * the service makes (each size in each format), since that is what fills its queue
 * for the account: it grows while groups are taken, and halves with a hold on the
 * feeder whenever the service turns a bulk group away for the work already waiting
 * there (`rate_limited`), so new uploads always find room. The service's own number
 * is never written here. While the day is spent (Pause), the site is disconnected
 * or the disk is low, the feeder sends nothing.
 *
 * A run takes either the media library or the files in the folders someone
 * chose (`Folders`), never both: its `scope`. The files go one group each through
 * their own pipeline (`Folders\Optimizer`), with the same window, hold and lock,
 * and the window counts the service's work for both, since they share its queue.
 *
 * In the background the feeder is an Action Scheduler action of its own. A host
 * whose cron or loopback requests don't run gets the other mode: the bulk page
 * calls `step()` while it is open, which feeds and then runs the plugin's due
 * actions itself, claimed from Action Scheduler so a run never goes twice.
 */
class Bulk {

	const OPTION = 'iminify_bulk';

	const FEED_HOOK = 'iminify_bulk_feed';

	/**
	 * What a run does: optimize the images never optimized, send the failed ones again, or restore the optimized ones.
	 */
	const OPTIMIZE = 'optimize';

	const RETRY = 'retry';

	const RESTORE = 'restore';

	/**
	 * What the optimized images lack: an image goes for it from its original, and keeps what it has (Missing).
	 */
	const FORMATS = Missing::FORMATS;

	const SIZES = Missing::SIZES;

	/**
	 * Every action a run can have.
	 */
	const ACTIONS = array( self::OPTIMIZE, self::RETRY, self::RESTORE, self::FORMATS, self::SIZES );

	/**
	 * What a run takes: the media library's images, or the files in the chosen folders.
	 */
	const LIBRARY = 'library';

	const FOLDERS = 'folders';

	/**
	 * The actions a run over the chosen folders can have: their files have no sizes, and get every format they lack by being optimized again.
	 */
	const FOLDER_ACTIONS = array( self::OPTIMIZE, self::RETRY, self::RESTORE );

	/**
	 * How it runs: on Action Scheduler, or driven by the bulk page while it is open.
	 */
	const BACKGROUND = 'background';

	const PAGE = 'page';

	const RUNNING = 'running';

	const PAUSED = 'paused';

	const STOPPED = 'stopped';

	const DONE = 'done';

	/**
	 * Seconds between the feeder's looks while it runs in the background.
	 */
	const TICK = 15;

	/**
	 * The window a run starts with, and the most it grows to, in files the service makes.
	 */
	const START_FILES = 60;

	const MAX_FILES = 150;

	/**
	 * Images one look restores: WordPress makes every size again for each, which is heavy.
	 */
	const RESTORE_BATCH = 5;

	/**
	 * Seconds of work one request from the bulk page does.
	 */
	const PAGE_BUDGET = 15;

	/**
	 * How late the plugin's due actions may run before a background run reads as stalled.
	 */
	const STALL_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Images looked at per query for the next candidates.
	 */
	const PAGE_SIZE = 50;

	/**
	 * Images whose originals the disk estimate measures.
	 */
	const SAMPLE = 200;

	/**
	 * Held while a look runs, so two requests (two open tabs, the page and the
	 * background feeder) never send or restore the same images at once.
	 */
	const LOCK_OPTION = 'iminify_bulk_lock';

	const LOCK_SECONDS = 60;

	/**
	 * The actions the bulk page runs itself, claimed by hook: an image's steps and
	 * the queue's own, never another plugin's.
	 */
	const PAGE_HOOKS = array( Optimizer::OPTIMIZE_HOOK, Optimizer::CHECK_HOOK, Optimizer::DELETE_GROUP_HOOK, Queue::OPENS_HOOK, Queue::RESUME_HOOK, Folder_Optimizer::OPTIMIZE_HOOK, Folder_Optimizer::CHECK_HOOK, Folder_Optimizer::RESUME_HOOK );

	/**
	 * The run, or null when there has been none.
	 *
	 * @return array{action: string, scope: string, mode: string, status: string, reason: string|null, rewind: bool, only: list<int>|null, cursor: int, total: int, sent: int, failed: int, passed: int, window: int, hold: int, grown: int, started: int, updated: int}|null
	 */
	public static function run(): ?array {
		$run = get_option( self::OPTION );

		if ( ! is_array( $run ) || ! isset( $run['action'] ) ) {
			return null;
		}

		return array(
			'action'  => in_array( $run['action'], self::ACTIONS, true ) ? (string) $run['action'] : self::OPTIMIZE,
			'scope'   => self::FOLDERS === ( $run['scope'] ?? null ) ? self::FOLDERS : self::LIBRARY,
			'mode'    => self::PAGE === ( $run['mode'] ?? '' ) ? self::PAGE : self::BACKGROUND,
			'status'  => in_array( $run['status'] ?? '', array( self::RUNNING, self::PAUSED, self::STOPPED, self::DONE ), true ) ? (string) $run['status'] : self::STOPPED,
			'reason'  => isset( $run['reason'] ) && is_string( $run['reason'] ) ? $run['reason'] : null,
			'rewind'  => ! empty( $run['rewind'] ),
			'only'    => isset( $run['only'] ) && is_array( $run['only'] ) ? array_values( array_map( 'intval', $run['only'] ) ) : null,
			'cursor'  => (int) ( $run['cursor'] ?? 0 ),
			'total'   => (int) ( $run['total'] ?? 0 ),
			'sent'    => (int) ( $run['sent'] ?? 0 ),
			'failed'  => (int) ( $run['failed'] ?? 0 ),
			'passed'  => (int) ( $run['passed'] ?? 0 ),
			'window'  => max( 1, (int) ( $run['window'] ?? self::START_FILES ) ),
			'hold'    => (int) ( $run['hold'] ?? 0 ),
			'grown'   => (int) ( $run['grown'] ?? 0 ),
			'started' => (int) ( $run['started'] ?? 0 ),
			'updated' => (int) ( $run['updated'] ?? 0 ),
		);
	}

	/**
	 * Starts a run, with a first look right away.
	 *
	 * @param string         $action One of ACTIONS.
	 * @param string         $mode   BACKGROUND or PAGE.
	 * @param list<int>|null $only   The images to take, or null for the whole library.
	 * @param string         $scope  LIBRARY or FOLDERS.
	 * @return array<string, mixed>|WP_Error The run.
	 */
	public static function start( string $action, string $mode, ?array $only = null, string $scope = self::LIBRARY ) {
		$current = self::run();
		$scope   = self::FOLDERS === $scope ? self::FOLDERS : self::LIBRARY;

		if ( null !== $current && in_array( $current['status'], array( self::RUNNING, self::PAUSED ), true ) ) {
			return new WP_Error( 'run_active', __( 'A bulk run is already going. Stop it first.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( self::FOLDERS === $scope ) {
			if ( ! in_array( $action, self::FOLDER_ACTIONS, true ) || null !== $only ) {
				return new WP_Error( 'invalid_action', __( 'The files in other folders are optimized, sent again or restored, all of them at once.', 'iminify-image-optimizer' ), array( 'status' => 422 ) );
			}

			if ( Scanner::running() ) {
				return new WP_Error( 'scan_running', __( 'Iminify is still looking through your folders. Start again once it has finished.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
			}
		}

		if ( self::RESTORE !== $action ) {
			$acting = Others::acting();

			if ( null !== $acting ) {
				return new WP_Error( Others::ACTING, Others::acting_message( $acting ), array( 'status' => 409 ) );
			}

			if ( ! Plugin::connected() ) {
				return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
			}

			if ( ! Disk::has_room_for( 0 ) ) {
				return new WP_Error( 'disk_full', __( 'The server is low on disk space. Free some up, then start again.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
			}
		}

		/* What is missing depends on what the site asks for now. */
		if ( self::LIBRARY === $scope && self::is_fill( $action ) ) {
			$site = Site_Record::fetch();

			if ( is_wp_error( $site ) ) {
				return $site;
			}
		}

		$only = null === $only ? null : array_values( array_unique( array_map( 'intval', $only ) ) );

		if ( null !== $only ) {
			sort( $only );
		}

		/* An image waiting for what it lacks is still optimized, so restoring takes it too. */
		if ( self::LIBRARY === $scope && self::RESTORE === $action ) {
			self::take_back_fills( $only );
		}

		$total = self::count_candidates( $action, $only, $scope );

		if ( 0 === $total ) {
			return new WP_Error( 'nothing_to_do', self::nothing_to_do( $action, $scope ), array( 'status' => 409 ) );
		}

		as_unschedule_all_actions( self::FEED_HOOK, array(), Optimizer::GROUP );

		update_option(
			self::OPTION,
			array(
				'action'  => $action,
				'scope'   => $scope,
				'mode'    => self::PAGE === $mode ? self::PAGE : self::BACKGROUND,
				'status'  => self::RUNNING,
				'reason'  => null,
				'only'    => $only,
				'cursor'  => 0,
				'total'   => $total,
				'sent'    => 0,
				'failed'  => 0,
				'passed'  => 0,
				'window'  => self::START_FILES,
				'hold'    => 0,
				'grown'   => 0,
				'started' => time(),
				'updated' => time(),
			),
			false
		);

		self::kick();

		return (array) self::run();
	}

	/**
	 * Holds the run: nothing more is sent, and what is on its way finishes.
	 *
	 * @param string|null $reason Why, when it isn't the person's press: `disk_full` or `deactivated`.
	 */
	public static function pause( ?string $reason = null ): void {
		$run = self::run();

		if ( null === $run || self::RUNNING !== $run['status'] ) {
			return;
		}

		self::change(
			array(
				'status' => self::PAUSED,
				'reason' => $reason,
			)
		);
		as_unschedule_all_actions( self::FEED_HOOK, array(), Optimizer::GROUP );

		/**
		 * A bulk run was paused: nothing more is sent until it goes on, and what is on its way finishes.
		 *
		 * @param array<string, mixed> $run The run, as `iminify_bulk_finished` gives it.
		 */
		do_action( 'iminify_bulk_paused', (array) self::run() );
	}

	/**
	 * Goes on with a held run.
	 */
	public static function resume(): void {
		$run = self::run();

		if ( null === $run || self::PAUSED !== $run['status'] ) {
			return;
		}

		self::change(
			array(
				'status' => self::RUNNING,
				'reason' => null,
			)
		);
		self::kick();
	}

	/**
	 * Ends the run. The images it queued that have no group open yet go back to
	 * "not optimized" with nothing counted; a group already open goes on, since
	 * its image is counted.
	 */
	public static function stop(): void {
		$run = self::run();

		if ( null === $run || ! in_array( $run['status'], array( self::RUNNING, self::PAUSED ), true ) ) {
			return;
		}

		self::change(
			array(
				'status' => self::STOPPED,
				'reason' => null,
			)
		);
		as_unschedule_all_actions( self::FEED_HOOK, array(), Optimizer::GROUP );

		/* A look under way finishes queueing first, so the sweep takes back what it sent too. */
		$tries = 0;

		while ( ! self::lock() && $tries < 8 ) {
			usleep( 250000 );
			++$tries;
		}

		if ( self::FOLDERS === $run['scope'] ) {
			self::take_back_files();
		} else {
			self::take_back_images();
		}

		self::unlock();

		/**
		 * A bulk run was stopped before it went through every image: what it did stays done.
		 *
		 * @param array<string, mixed> $run The run, as `iminify_bulk_finished` gives it.
		 */
		do_action( 'iminify_bulk_stopped', (array) self::run() );
	}

	/**
	 * Takes back the images a stopped run queued that have no group open yet:
	 * each reads not optimized again, with nothing counted, and one sent for what
	 * it lacks goes back to what it was.
	 */
	protected static function take_back_images(): void {
		$after = 0;

		do {
			$ids = Records::with_status( array( Records::QUEUED, Records::PAUSED, Records::PARKED ), 200, $after );

			foreach ( $ids as $id ) {
				$after  = $id;
				$record = Records::get( $id );

				if ( Optimizer::BULK !== $record['trigger'] || null !== $record['group'] ) {
					continue;
				}

				as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ), Optimizer::GROUP );

				/* An image sent for what it lacks goes back to what it was: nothing happened to it. */
				if ( null !== $record['fill'] ) {
					Records::end_fill( $id );
					continue;
				}

				Records::fail( $id, Records::NOT_OPTIMIZED, 'stopped', __( 'Not optimized: the bulk run was stopped.', 'iminify-image-optimizer' ) );
			}
		} while ( array() !== $ids );
	}

	/**
	 * Takes back the files a stopped run queued that have no group open yet: each
	 * reads not optimized again, with nothing counted.
	 */
	protected static function take_back_files(): void {
		$after = 0;

		do {
			$ids = Table::anywhere( array( Records::QUEUED, Records::PAUSED, Records::PARKED ), 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;
				$row   = Table::get( $id );

				if ( null === $row || Optimizer::BULK !== $row['trigger'] || null !== $row['group'] ) {
					continue;
				}

				as_unschedule_all_actions( Folder_Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ), Optimizer::GROUP );
				Folder_Optimizer::stop( $id, Records::NOT_OPTIMIZED, 'stopped', __( 'Not optimized: the bulk run was stopped.', 'iminify-image-optimizer' ) );
			}
		} while ( array() !== $ids );
	}

	/**
	 * Changes how a run goes on: in the background, or while the bulk page is open.
	 *
	 * @param string $mode BACKGROUND or PAGE.
	 */
	public static function switch_mode( string $mode ): void {
		$run = self::run();

		if ( null === $run ) {
			return;
		}

		self::change( array( 'mode' => self::PAGE === $mode ? self::PAGE : self::BACKGROUND ) );

		if ( self::PAGE === $mode ) {
			as_unschedule_all_actions( self::FEED_HOOK, array(), Optimizer::GROUP );

			return;
		}

		self::kick();
	}

	/**
	 * Gets a run going after a press: a first look at the images to send right
	 * away, so the page shows them on their way, while a restore, which makes every
	 * size again, runs in the background or from the page's next request.
	 */
	protected static function kick(): void {
		$run = self::run();

		if ( null === $run || self::RUNNING !== $run['status'] ) {
			return;
		}

		if ( self::RESTORE !== $run['action'] ) {
			self::feed();

			return;
		}

		if ( self::BACKGROUND === $run['mode'] && ! Optimizer::pending( self::FEED_HOOK, array() ) ) {
			as_schedule_single_action( time(), self::FEED_HOOK, array(), Optimizer::GROUP );
		}
	}

	/**
	 * One look of the run: sends or restores the next images, and in the
	 * background schedules the next look. Action Scheduler's run for FEED_HOOK.
	 */
	public static function feed(): void {
		$run = self::run();

		if ( null === $run || self::RUNNING !== $run['status'] ) {
			return;
		}

		$next = self::TICK;

		if ( self::lock() ) {
			try {
				$next = self::RESTORE === $run['action'] ? self::restore_some( microtime( true ) + self::PAGE_BUDGET ) : self::send_some();
			} finally {
				self::unlock();
			}
		}

		$run = self::run();

		if ( null === $next || null === $run || self::RUNNING !== $run['status'] || self::BACKGROUND !== $run['mode'] ) {
			return;
		}

		if ( ! Optimizer::pending( self::FEED_HOOK, array() ) ) {
			as_schedule_single_action( time() + $next, self::FEED_HOOK, array(), Optimizer::GROUP );
		}
	}

	/**
	 * A request from the bulk page for a run in its mode: a look while the run goes
	 * on, then the plugin's due actions run here for as long as the request's budget
	 * lasts. A run paused, stopped or done still has its images on their way
	 * finished here, since nothing else runs them on a host whose cron doesn't.
	 *
	 * @return int The seconds until a request has something to do: the next look, or the next action due.
	 */
	public static function step(): int {
		$run = self::run();

		if ( null === $run || self::PAGE !== $run['mode'] ) {
			return 60;
		}

		$deadline = microtime( true ) + self::PAGE_BUDGET;
		$next     = null;

		if ( self::RUNNING === $run['status'] && self::lock() ) {
			try {
				$next = self::RESTORE === $run['action'] ? self::restore_some( $deadline ) : self::send_some();
			} finally {
				self::unlock();
			}
		}

		if ( self::RESTORE !== $run['action'] ) {
			self::run_due( $deadline );
		}

		return self::until_due( $next );
	}

	/**
	 * The service turned a bulk group away for the work already waiting there: the
	 * feeder holds for the wait it asked, with its window halved. Refusals of one
	 * burst halve it once.
	 *
	 * @param int $seconds The wait the service asked for.
	 */
	public static function throttled( int $seconds ): void {
		wp_cache_delete( self::OPTION, 'options' );

		$run = self::run();

		if ( null === $run || self::RUNNING !== $run['status'] || $run['hold'] > time() ) {
			return;
		}

		self::change(
			array(
				'hold'   => time() + $seconds,
				'window' => max( self::files_per_image( $run['scope'] ), intdiv( $run['window'], 2 ) ),
			)
		);
	}

	/**
	 * An image the run sent came back unsent, as when another optimizer started
	 * acting: it isn't counted as sent, since the run sends it again.
	 */
	public static function returned(): void {
		wp_cache_delete( self::OPTION, 'options' );

		$run = self::run();

		if ( null !== $run && $run['sent'] > 0 ) {
			self::change( array( 'sent' => $run['sent'] - 1 ) );
		}
	}

	/**
	 * Whether a background run's actions have been due for long with nothing
	 * running them: the host's cron or its loopback requests don't work.
	 */
	public static function stalled(): bool {
		$run = self::run();

		if ( null === $run || self::RUNNING !== $run['status'] || self::BACKGROUND !== $run['mode'] ) {
			return false;
		}

		$late = as_get_scheduled_actions(
			array(
				'group'        => Optimizer::GROUP,
				'status'       => 'pending',
				'date'         => time() - self::STALL_SECONDS,
				'date_compare' => '<=',
				'per_page'     => 1,
			),
			'ids'
		);

		return array() !== $late;
	}

	/**
	 * The images the bulk page counts.
	 *
	 * @return array{images: int, optimized: int, not_optimized: int, waiting: int, failed: int, skipped: int, restored: int, other: int, missing_formats: int, missing_sizes: int}
	 */
	public static function counts(): array {
		return array(
			'images'          => self::count_images( false ),
			'optimized'       => Records::count_with_status( array( Records::OPTIMIZED ) ),
			'not_optimized'   => self::count_candidates( self::OPTIMIZE ),
			'waiting'         => Records::count_with_status( array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ) ),
			'failed'          => Records::count_with_status( array( Records::ERROR ) ),
			'skipped'         => Records::count_with_status( array( Records::SKIPPED ) ),
			'restored'        => Records::count_with_status( array( Records::RESTORED ) ),
			'other'           => Records::count_with_status( array( Records::OTHER ) ),
			'missing_formats' => Missing::count( Missing::FORMATS ),
			'missing_sizes'   => Missing::count( Missing::SIZES ),
		);
	}

	/**
	 * How many images a run of this kind would look at.
	 *
	 * @param string         $action One of ACTIONS.
	 * @param list<int>|null $only   The images to take, or null for the whole library.
	 * @param string         $scope  LIBRARY or FOLDERS.
	 */
	public static function count_candidates( string $action, ?array $only = null, string $scope = self::LIBRARY ): int {
		if ( self::FOLDERS === $scope ) {
			return Table::count_with_status( self::file_statuses( $action ) );
		}

		if ( null !== $only ) {
			return count(
				array_filter(
					$only,
					static function ( int $id ) use ( $action ): bool {
						return self::takes( $action, $id );
					}
				)
			);
		}

		switch ( $action ) {
			case self::RETRY:
				return Records::count_with_status( array( Records::ERROR ) );
			case self::RESTORE:
				return Records::count_with_status( array( Records::OPTIMIZED ) );
			case self::FORMATS:
			case self::SIZES:
				return Missing::count( $action );
			default:
				return self::count_images( true );
		}
	}

	/**
	 * The next images a run of this kind would send or restore, in the order of
	 * their ids. An image the service can't take is passed over, and the cursor
	 * still moves past it, so a run never looks at it twice.
	 *
	 * The images to optimize are the ones with no record, or "not optimized" for any
	 * reason but a restore someone chose (W161), never one optimized, failed,
	 * skipped or on its way. An image someone names (`takes()`) goes whatever it reads
	 * but optimized or on its way.
	 *
	 * A run over the chosen folders takes their files with the status its action
	 * reads, by row id, and passes over one the service can't take.
	 *
	 * @param string         $action One of ACTIONS.
	 * @param int            $limit  How many at most.
	 * @param int            $after  The id to start after.
	 * @param list<int>|null $only   The images to take, or null for the whole library.
	 * @param string         $scope  LIBRARY or FOLDERS.
	 * @return array{ids: list<int>, passed: list<int>, cursor: int, exhausted: bool} The images to send, and those passed over as ones the service can't take, or with nothing to make.
	 */
	public static function candidates( string $action, int $limit, int $after, ?array $only = null, string $scope = self::LIBRARY ): array {
		$found  = array();
		$passed = array();
		$cursor = $after;
		$files  = self::FOLDERS === $scope;

		do {
			$ids  = $files ? Table::with_status( self::file_statuses( $action ), self::PAGE_SIZE, $cursor ) : self::next_page( $action, $cursor, $only );
			$full = self::PAGE_SIZE === count( $ids );

			if ( $files ) {
				Others::file_owners( Table::paths( $ids ) );
			} else {
				Shared::prime( $ids );
				Others::prime( $ids );
			}

			foreach ( $ids as $id ) {
				$cursor = $id;

				if ( ! $files && null !== $only && ! self::takes( $action, $id ) ) {
					continue;
				}

				if ( self::RESTORE === $action || ( $files ? null === Folder_Optimizer::why_not( $id ) : self::can_send( $action, $id ) ) ) {
					$found[] = $id;
				} else {
					$passed[] = $id;
				}

				if ( count( $found ) >= $limit ) {
					return array(
						'ids'       => $found,
						'passed'    => $passed,
						'cursor'    => $cursor,
						'exhausted' => false,
					);
				}
			}
		} while ( $full );

		return array(
			'ids'       => $found,
			'passed'    => $passed,
			'cursor'    => $cursor,
			'exhausted' => true,
		);
	}

	/**
	 * The files the service makes for one image on the site's settings: the main
	 * file and each size it sends, in the image's format and each copy's. A file in
	 * another folder has no sizes.
	 *
	 * @param string $scope LIBRARY or FOLDERS.
	 */
	public static function files_per_image( string $scope = self::LIBRARY ): int {
		$site       = Site_Record::cached();
		$settings   = is_array( $site ) && isset( $site['settings'] ) && is_array( $site['settings'] ) ? $site['settings'] : array( 'webp' => true );
		$registered = array_keys( wp_get_registered_image_subsizes() );
		$sizes      = isset( $settings['sizes'] ) && is_array( $settings['sizes'] ) ? count( array_intersect( $registered, $settings['sizes'] ) ) : count( $registered );
		$formats    = 1 + ( empty( $settings['webp'] ) ? 0 : 1 ) + ( empty( $settings['avif'] ) ? 0 : 1 );

		return max( 1, ( 1 + ( self::FOLDERS === $scope ? 0 : $sizes ) ) * $formats );
	}

	/**
	 * The bytes the backups of the images not optimized yet would take: each
	 * original the plugin would keep (WordPress's unscaled original where it kept
	 * one, else the attached file), measured on up to SAMPLE images and scaled to
	 * the rest. One already backed up costs nothing.
	 */
	public static function backup_bytes(): int {
		$count = self::count_images( true );
		$ids   = 0 === $count ? array() : self::not_optimized( self::SAMPLE, 0 );

		if ( array() === $ids ) {
			return 0;
		}

		Shared::prime( $ids );
		$bytes = 0;

		foreach ( $ids as $id ) {
			$metadata = wp_get_attachment_metadata( $id, true );
			$source   = Originals::find( $id, is_array( $metadata ) ? $metadata : array(), null );

			if ( null !== $source && Originals::BACKUP !== $source['kind'] ) {
				$bytes += (int) Offload_Media::bytes( $id, $source['path'] );
			}
		}

		return (int) round( $bytes / count( $ids ) * $count );
	}

	/**
	 * Sends the next images within the window. Answers the seconds until the
	 * next look, or null when the run ended or waits for the person.
	 */
	protected static function send_some(): ?int {
		$run = self::run();

		if ( null === $run ) {
			return null;
		}

		/* While another optimizer acts on uploads nothing is sent (W134); the run holds, saying why, and goes on once it is switched off. */
		if ( null !== Others::acting() ) {
			if ( Others::ACTING !== $run['reason'] || ! $run['rewind'] ) {
				self::change(
					array(
						'reason' => Others::ACTING,
						'rewind' => true,
					)
				);
			}

			return MINUTE_IN_SECONDS;
		}

		/* The images it had sent that met the other optimizer read not optimized, so the run looks again from the start, passing over what is done. A pause or a resume since keeps this owed. */
		if ( $run['rewind'] ) {
			self::change(
				array(
					'reason' => Others::ACTING === $run['reason'] ? null : $run['reason'],
					'rewind' => false,
					'cursor' => 0,
				)
			);
			$run = self::run() ?? $run;
		}

		/* The images it sent wait for the connection on their own, and go on with it. */
		if ( ! Plugin::connected() ) {
			return 5 * MINUTE_IN_SECONDS;
		}

		$until = Pause::until();

		if ( null !== $until ) {
			return max( self::TICK, $until - time() + 5 );
		}

		if ( ! Disk::has_room_for( 0 ) ) {
			self::pause( 'disk_full' );

			return null;
		}

		if ( $run['hold'] > time() ) {
			return $run['hold'] - time() + 1;
		}

		/* An image waiting for the connection means the service refuses this key (a copy at another address, a key it stopped): one window waits, never the library. */
		if ( Records::count_with_status( array( Records::PARKED ) ) + Table::count_anywhere( array( Records::PARKED ) ) > 0 ) {
			return 5 * MINUTE_IN_SECONDS;
		}

		$files   = self::FOLDERS === $run['scope'];
		$per     = self::files_per_image( $run['scope'] );
		$working = Records::count_with_status( array( Records::QUEUED, Records::WAITING ) ) + Table::count_anywhere( array( Records::QUEUED, Records::WAITING ) );
		$room    = max( 1, intdiv( $run['window'], $per ) ) - $working;

		/* The window holds the run back and the service took all of it, so it can hold one image more, once a look. */
		if ( $room <= 0 ) {
			if ( 0 === Records::count_with_status( array( Records::QUEUED ) ) + Table::count_anywhere( array( Records::QUEUED ) ) && time() - $run['grown'] >= self::TICK ) {
				self::change(
					array(
						'window' => min( max( self::MAX_FILES, $per ), $run['window'] + $per ),
						'grown'  => time(),
					)
				);
			}

			return self::TICK;
		}

		$next  = self::candidates( $run['action'], $room, $run['cursor'], $run['only'], $run['scope'] );
		$total = $run['total'];

		foreach ( $files ? $next['passed'] : array() as $id ) {
			/* A file the service can't take, or one no longer in a chosen folder, is set aside like an image. */
			$why = Folder_Optimizer::why_not( $id ) ?? array(
				'code'    => 'unsupported_format',
				'message' => __( 'Kept as it is: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ),
			);

			Folder_Optimizer::set_aside( $id, $why );
		}

		foreach ( $files ? array() : $next['passed'] as $id ) {
			/* An image the service can't take (a format it doesn't read, a file gone from the disk) is set aside, so the counts and the estimate leave it out. */
			$why = Optimizer::why_not( $id );

			if ( ! self::is_fill( $run['action'] ) ) {
				$why = $why ?? array(
					'code'    => 'unsupported_format',
					'message' => __( 'Kept as uploaded: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ),
				);

				Optimizer::skip( $id, $why );
				continue;
			}

			--$total;

			/* An image with nothing to make was only looked at, once. One with no original to make it from stays as it is, and the page says so; one this site leaves out stays out. */
			if ( array() === Missing::of( $id, $run['action'] ) ) {
				Missing::settle( $id );
			} elseif ( null === $why ) {
				++$run['passed'];
			}
		}

		foreach ( $next['ids'] as $id ) {
			if ( $files ) {
				/* A failed file sent again starts with its tries counted from nothing. */
				if ( self::RETRY === $run['action'] ) {
					Table::update(
						$id,
						array(
							'attempts' => 0,
							'reopens'  => 0,
						)
					);
				}

				Folder_Optimizer::queue( $id, Optimizer::BULK, 0 );
				continue;
			}

			if ( self::is_fill( $run['action'] ) ) {
				Optimizer::send_for_missing( $id, $run['action'], Optimizer::BULK );
				continue;
			}

			/* A failed image sent again starts with its tries counted from nothing. */
			if ( self::RETRY === $run['action'] ) {
				Records::update(
					$id,
					array(
						'attempts' => 0,
						'reopens'  => 0,
					)
				);
			}

			Optimizer::queue( $id, Optimizer::BULK, 0 );
		}

		$changes = array(
			'cursor' => $next['cursor'],
			'sent'   => $run['sent'] + count( $next['ids'] ),
			'total'  => max( 0, $total ),
			'passed' => $run['passed'],
		);

		/* Done once nothing is left to send and nothing it sent is still on its way, a wait for the connection included. */
		if ( $next['exhausted'] && array() === $next['ids'] ) {
			$on_its_way = $files ? Table::count_anywhere( Table::ON_ITS_WAY ) : Records::count_with_status( array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ) );

			if ( 0 === $on_its_way ) {
				$changes['status'] = self::DONE;
			} else {
				self::heal( $files );
			}
		}

		self::change( $changes );

		if ( self::DONE === ( $changes['status'] ?? null ) ) {
			self::finished();

			return null;
		}

		return self::TICK;
	}

	/**
	 * The statuses of the files a run of this kind takes.
	 *
	 * @param string $action The run's action.
	 * @return list<string>
	 */
	protected static function file_statuses( string $action ): array {
		switch ( $action ) {
			case self::RETRY:
				return array( Records::ERROR );
			case self::RESTORE:
				return array( Records::OPTIMIZED );
			default:
				return array( Records::NOT_OPTIMIZED );
		}
	}

	/**
	 * Tells whoever listens that a run came to its end.
	 */
	protected static function finished(): void {
		/**
		 * A bulk run went through every image it had to, and nothing it sent is still on its way.
		 *
		 * @param array<string, mixed> $run The run: `action` (`optimize`, `retry`, `restore`, `formats` or `sizes`), `scope` (`library`, or `folders` for the files in other folders), `total`, `sent`, `failed` and `passed`.
		 */
		do_action( 'iminify_bulk_finished', (array) self::run() );
	}

	/**
	 * Whether a run sends optimized images for what they lack.
	 *
	 * @param string $action The run's action.
	 */
	protected static function is_fill( string $action ): bool {
		return in_array( $action, array( self::FORMATS, self::SIZES ), true );
	}

	/**
	 * What a run of this kind says when it has nothing to do.
	 *
	 * @param string $action The run's action.
	 * @param string $scope  LIBRARY or FOLDERS.
	 */
	protected static function nothing_to_do( string $action, string $scope ): string {
		if ( self::FOLDERS === $scope ) {
			if ( array() === Folders::listed() ) {
				return __( 'Choose a folder first.', 'iminify-image-optimizer' );
			}

			switch ( $action ) {
				case self::RESTORE:
					return __( 'No optimized file is left to restore in your folders.', 'iminify-image-optimizer' );
				case self::RETRY:
					return __( 'No file in your folders failed.', 'iminify-image-optimizer' );
				default:
					return __( 'Every file in your folders is already optimized or on its way.', 'iminify-image-optimizer' );
			}
		}

		if ( self::RESTORE === $action ) {
			return __( 'No optimized image is left to restore.', 'iminify-image-optimizer' );
		}

		return self::is_fill( $action ) ? __( 'No optimized image lacks anything your site asks for now.', 'iminify-image-optimizer' ) : __( 'Every image is already optimized or on its way.', 'iminify-image-optimizer' );
	}

	/**
	 * The next page of a run's images.
	 *
	 * @param string         $action The run's action.
	 * @param int            $after  The id to start after.
	 * @param list<int>|null $only   The images to take, or null for the whole library.
	 * @return list<int>
	 */
	protected static function next_page( string $action, int $after, ?array $only ): array {
		if ( null !== $only ) {
			$later = array_filter(
				$only,
				static function ( int $id ) use ( $after ): bool {
					return $id > $after;
				}
			);

			return array_slice( array_values( $later ), 0, self::PAGE_SIZE );
		}

		switch ( $action ) {
			case self::RETRY:
				return Records::with_status( array( Records::ERROR ), self::PAGE_SIZE, $after );
			case self::RESTORE:
				return Records::with_status( array( Records::OPTIMIZED ), self::PAGE_SIZE, $after );
			case self::FORMATS:
			case self::SIZES:
				return Missing::images( $action, self::PAGE_SIZE, $after );
			default:
				return self::not_optimized( self::PAGE_SIZE, $after );
		}
	}

	/**
	 * Whether a run takes an image someone named. Optimizing leaves out only what is
	 * optimized or on its way, so a repeated command spends nothing on an image twice.
	 *
	 * @param string $action The run's action.
	 * @param int    $id     The attachment's id.
	 */
	protected static function takes( string $action, int $id ): bool {
		$status = Records::get( $id )['status'];

		switch ( $action ) {
			case self::RETRY:
				return Records::ERROR === $status;
			case self::RESTORE:
				/* An image another plugin optimized is restored only when someone names it (W251). */
				return in_array( $status, array( Records::OPTIMIZED, Records::OTHER ), true );
			case self::FORMATS:
			case self::SIZES:
				return Records::OPTIMIZED === $status;
			default:
				return ! in_array( $status, array( Records::OPTIMIZED, Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ), true );
		}
	}

	/**
	 * Whether a run can send this image.
	 *
	 * @param string $action The run's action.
	 * @param int    $id     The attachment's id.
	 */
	protected static function can_send( string $action, int $id ): bool {
		if ( ! self::is_fill( $action ) ) {
			return Optimizer::is_optimizable( $id );
		}

		return Missing::can_fill( $id ) && array() !== Missing::of( $id, $action );
	}

	/**
	 * Takes back the images waiting for what they lack, whatever asked for them: each
	 * is as it was, and a restore has to find it among the optimized ones.
	 *
	 * @param list<int>|null $only The images about to be restored, or null for the whole library.
	 */
	protected static function take_back_fills( ?array $only ): void {
		$after = 0;

		do {
			$ids = Records::with_status( array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ), 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;

				if ( null !== $only && ! in_array( $id, $only, true ) ) {
					continue;
				}

				if ( null !== Records::get( $id )['fill'] ) {
					Optimizer::cancel( $id );
					Records::end_fill( $id );
				}
			}
		} while ( array() !== $ids );
	}

	/**
	 * Queues again an image left queued or waiting with no run for a long while:
	 * its run died with its request (a fatal error, a time limit), and the run
	 * would wait for it forever. One whose record moved lately may have a run
	 * under way, which isn't pending, so it is left.
	 *
	 * @param bool $files Whether the run takes the chosen folders' files.
	 */
	protected static function heal( bool $files ): void {
		if ( $files ) {
			foreach ( Table::anywhere( array( Records::QUEUED, Records::WAITING ), self::PAGE_SIZE ) as $id ) {
				$row = Table::get( $id );

				if ( null === $row || $row['updated'] > time() - self::STALL_SECONDS || Folder_Optimizer::has_run( $id ) ) {
					continue;
				}

				Folder_Optimizer::queue( $id, (string) $row['trigger'], 0 );
			}

			return;
		}

		foreach ( Records::with_status( array( Records::QUEUED, Records::WAITING ), self::PAGE_SIZE ) as $id ) {
			$record = Records::get( $id );

			if ( $record['updated'] > time() - self::STALL_SECONDS || Optimizer::has_run( $id ) ) {
				continue;
			}

			Optimizer::queue( $id, Optimizer::trigger( $record['trigger'] ), 0 );
		}
	}

	/**
	 * Restores the next images, until the deadline. Answers 0 while more are left,
	 * or null once the run is done. It needs no connection and no day left.
	 *
	 * @param float $deadline When to stop, as microtime.
	 */
	protected static function restore_some( float $deadline ): ?int {
		do {
			$run = self::run();

			if ( null === $run || self::RUNNING !== $run['status'] ) {
				return null;
			}

			$next = self::candidates( self::RESTORE, self::RESTORE_BATCH, $run['cursor'], $run['only'], $run['scope'] );

			if ( array() === $next['ids'] ) {
				self::change(
					array(
						'status' => self::DONE,
						'cursor' => $next['cursor'],
					)
				);
				self::finished();

				return null;
			}

			$sent   = $run['sent'];
			$failed = $run['failed'];

			foreach ( $next['ids'] as $id ) {
				if ( self::FOLDERS === $run['scope'] ? self::restore_file( $id ) : self::restore_one( $id ) ) {
					++$sent;
				} else {
					++$failed;
				}

				/* Each image moves the cursor itself, so a request cut short goes on from the next. */
				self::change(
					array(
						'cursor' => $id,
						'sent'   => $sent,
						'failed' => $failed,
					)
				);

				if ( microtime( true ) >= $deadline ) {
					return 0;
				}
			}
		} while ( microtime( true ) < $deadline );

		return 0;
	}

	/**
	 * Restores one image. One the service made nothing smaller for (every file it
	 * kept is the original, and no copy was written) is already as it was uploaded,
	 * so it only reads as restored.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function restore_one( int $attachment ): bool {
		$availability = Restorer::availability( $attachment );

		if ( Restorer::AVAILABLE === $availability ) {
			return true === Restorer::restore( $attachment );
		}

		if ( Restorer::NOTHING !== $availability ) {
			return false;
		}

		Records::update(
			$attachment,
			array(
				'status' => Records::NOT_OPTIMIZED,
				'result' => null,
				'error'  => array(
					'code'    => Records::RESTORED,
					'message' => __( 'Restored: the original is back.', 'iminify-image-optimizer' ),
				),
			)
		);

		return true;
	}

	/**
	 * Restores one file in a chosen folder. One the service made nothing smaller for is only marked restored.
	 *
	 * @param int $id The file's row id.
	 */
	protected static function restore_file( int $id ): bool {
		$row          = Table::get( $id );
		$availability = null === $row ? Folder_Restorer::GONE : Folder_Restorer::availability( $row );

		if ( Folder_Restorer::AVAILABLE === $availability ) {
			return true === Folder_Restorer::restore( $id );
		}

		if ( Folder_Restorer::NOTHING !== $availability ) {
			$why = Folder_Restorer::refusal( $availability );

			Table::update(
				$id,
				array(
					'error' => array(
						'code'    => (string) $why->get_error_code(),
						'message' => $why->get_error_message(),
					),
				)
			);

			return false;
		}

		Table::update(
			$id,
			array(
				'status' => Records::RESTORED,
				'result' => null,
				'error'  => null,
			)
		);

		return true;
	}

	/**
	 * The seconds until the bulk page's next request has something to do: the next
	 * look, or the plugin's next action due, between one second and a minute.
	 *
	 * @param int|null $next The seconds until the next look, or null for none.
	 */
	protected static function until_due( ?int $next ): int {
		global $wpdb;

		$waits = null === $next ? array() : array( $next );
		$in    = implode( ', ', array_fill( 0, count( self::PAGE_HOOKS ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Action Scheduler's own table, for when the plugin's next action is due; one placeholder per hook, built above.
		$due = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(scheduled_date_gmt) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = %s AND hook IN ({$in})", array_merge( array( 'pending' ), self::PAGE_HOOKS ) ) );
		$at  = is_string( $due ) ? strtotime( $due . ' UTC' ) : false;

		if ( false !== $at ) {
			$waits[] = $at - time();
		}

		return array() === $waits ? 60 : max( 1, min( 60, min( $waits ) ) );
	}

	/**
	 * Runs the plugin's own due actions here, until the deadline, each claimed
	 * from Action Scheduler first, so one its own runner holds, or already ran,
	 * never runs twice. They are claimed by their hooks rather than their group:
	 * Action Scheduler refuses a claim by a group whose id it hasn't cached.
	 *
	 * @param float $deadline When to stop, as microtime.
	 * @return int How many ran.
	 */
	protected static function run_due( float $deadline ): int {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}

		$store  = ActionScheduler::store();
		$runner = ActionScheduler::runner();
		$ran    = 0;

		try {
			while ( microtime( true ) < $deadline ) {
				$claim   = $store->stake_claim( 5, null, self::PAGE_HOOKS );
				$actions = $claim->get_actions();

				if ( array() === $actions ) {
					$store->release_claim( $claim );
					break;
				}

				foreach ( $actions as $action ) {
					if ( (int) $claim->get_id() !== (int) $store->get_claim_id( $action ) ) {
						break;
					}

					$runner->process_action( $action, 'Iminify bulk page' );
					++$ran;

					if ( microtime( true ) >= $deadline ) {
						break;
					}
				}

				$store->release_claim( $claim );
			}
		} finally {
			/*
			 * The hooks stay the store's claim filter for the rest of the request, where
			 * its own runner would read them. A store still moving older actions over
			 * (the hybrid one) keeps the filter out of reach; no runner of its own runs
			 * in the page's request, so it is harmless there.
			 */
			$reset = array( $store, 'set_claim_filter' );

			if ( is_callable( $reset ) ) {
				call_user_func( $reset, 'hooks', '' );
			}
		}

		return $ran;
	}

	/**
	 * The image attachments, or only those no run has optimized yet (`candidates()`'s meaning).
	 *
	 * @param bool $not_optimized Whether to count only those.
	 */
	protected static function count_images( bool $not_optimized ): int {
		global $wpdb;

		$types = Declaration::MIME_TYPES;
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		if ( ! $not_optimized ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own page; one placeholder per type, built above.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type IN ({$in})", $types ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own page; one placeholder per type, built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ({$in}) AND ( m.meta_id IS NULL OR m.meta_value = %s )", array_merge( array( Records::STATUS_META ), $types, array( Records::NOT_OPTIMIZED ) ) ) );
	}

	/**
	 * A page of the image attachments with no record or "not optimized", in the order of their ids.
	 *
	 * @param int $limit How many at most.
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	protected static function not_optimized( int $limit, int $after ): array {
		global $wpdb;

		$types = Declaration::MIME_TYPES;
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a background read by the primary key; one placeholder per type, built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ({$in}) AND p.ID > %d AND ( m.meta_id IS NULL OR m.meta_value = %s ) ORDER BY p.ID ASC LIMIT %d", array_merge( array( Records::STATUS_META ), $types, array( $after, Records::NOT_OPTIMIZED, $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Takes the lock a look holds: added only when no other request holds it, and
	 * taken over once it is older than LOCK_SECONDS, from a request that died.
	 */
	protected static function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time() + self::LOCK_SECONDS, '', false ) ) {
			return true;
		}

		if ( (int) get_option( self::LOCK_OPTION ) >= time() ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, time() + self::LOCK_SECONDS, '', false );
	}

	/**
	 * Lets the next look run.
	 */
	protected static function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Changes some of the run, read fresh from the database, since an image's own run
	 * can change the window while a look is under way, and another request can stop it.
	 *
	 * @param array<string, mixed> $changes The keys to change.
	 */
	protected static function change( array $changes ): void {
		/* Another request (a Stop from the page, a pause) may have written it since this one read it. */
		wp_cache_delete( self::OPTION, 'options' );

		$run = get_option( self::OPTION );

		if ( ! is_array( $run ) ) {
			return;
		}

		update_option( self::OPTION, array_merge( $run, $changes, array( 'updated' => time() ) ), false );
	}
}
