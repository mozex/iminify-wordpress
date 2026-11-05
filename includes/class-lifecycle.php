<?php
/**
 * What happens when the plugin is turned off, on again, or deleted.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turning the plugin off leaves nothing scheduled: on a site where another
 * plugin also runs Action Scheduler, a run whose plugin is off fails at once, so
 * the images on their way are parked instead, each keeping a group it opened, and
 * go on by themselves once the plugin is on again.
 *
 * Deleting the plugin removes its settings, its key (stopped on Iminify first,
 * where it can be) and its scheduled runs, and keeps what the site's pages and
 * images rely on unless the person ticked otherwise: the WebP and AVIF copies
 * (a page cached with a `<picture>` that names one would show a broken image) and
 * the backups (the only originals of the images it optimized). Each attachment's
 * record stays while either is kept, since it is the only proof a file is the
 * plugin's output: without it a later install would send an optimized file for a
 * second lossy pass, and take it for the original.
 */
class Lifecycle {

	/**
	 * Set when the plugin is turned on, and read once Action Scheduler is ready.
	 */
	const ACTIVATED_OPTION = 'iminify_activated';

	/**
	 * Every option the plugin keeps.
	 */
	const OPTIONS = array(
		Key_Store::OPTION,
		Connection::PAIRING_OPTION,
		Connection::REVOKED_OPTION,
		Connection::LOCK_OPTION,
		Site_Record::MESSAGE_OPTION,
		Site_Record::FORMATS_OPTION,
		Local_Settings::OPTION,
		Pause::OPTION,
		Bulk::OPTION,
		Bulk::LOCK_OPTION,
		self::ACTIVATED_OPTION,
	);

	/**
	 * Every hook the plugin schedules in Action Scheduler.
	 */
	const HOOKS = array(
		Optimizer::OPTIMIZE_HOOK,
		Optimizer::CHECK_HOOK,
		Optimizer::DELETE_GROUP_HOOK,
		Queue::OPENS_HOOK,
		Queue::RESUME_HOOK,
		Bulk::FEED_HOOK,
		Connection::CLAIM_HOOK,
	);

	/**
	 * The post meta of an attachment's record.
	 */
	const RECORD_META = Records::ALL_META;

	/**
	 * How long the deletion waits for Iminify to stop the key.
	 */
	const REVOKE_TIMEOUT = 5;

	/**
	 * The activation hook. Action Scheduler may not be ready yet in this request,
	 * so the queue is looked at once it is (`resume()`). Both options are kept
	 * autoloaded, since every request reads them: a missing option costs a query
	 * on each request that asks for it.
	 */
	public static function activate(): void {
		update_option( self::ACTIVATED_OPTION, 1, true );
		add_option( Local_Settings::OPTION, Local_Settings::defaults(), '', true );
	}

	/**
	 * Once Action Scheduler is ready after the plugin was turned on: an image
	 * left queued or waiting with no run of its own (the plugin was switched off
	 * without its deactivation running, as renaming its folder does, or a run
	 * scheduled itself while it was) is parked, a pause that is still on gets its
	 * timer back, and whatever was parked goes on.
	 */
	public static function resume(): void {
		if ( ! get_option( self::ACTIVATED_OPTION ) ) {
			return;
		}

		update_option( self::ACTIVATED_OPTION, 0, true );

		$after = 0;

		do {
			$ids = Records::with_status( array( Records::QUEUED, Records::WAITING ), 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;

				if ( ! Optimizer::has_run( $id ) ) {
					self::park( $id );
				}
			}
		} while ( array() !== $ids );

		$until = Pause::until();

		if ( null !== $until && ! Optimizer::pending( Queue::OPENS_HOOK, array() ) ) {
			as_schedule_single_action( $until + 1, Queue::OPENS_HOOK, array(), Optimizer::GROUP );
		}

		Queue::connection_back();

		$run = Bulk::run();

		if ( null !== $run && Bulk::PAUSED === $run['status'] && 'deactivated' === $run['reason'] ) {
			Bulk::resume();
		}
	}

	/**
	 * The deactivation hook: a waiting pairing is dropped, a bulk run is held,
	 * every image on its way is parked, and nothing of the plugin's stays scheduled.
	 */
	public static function deactivate(): void {
		( new Connection( Plugin::keys() ) )->cancel();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			Bulk::pause( 'deactivated' );
		}

		$after = 0;

		do {
			$ids = Records::with_status( array( Records::QUEUED, Records::WAITING ), 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;
				self::park( $id );
			}
		} while ( array() !== $ids );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		}
	}

	/**
	 * Parks an image that was on its way, keeping any group it opened.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function park( int $attachment ): void {
		Records::wait( $attachment, Records::PARKED, 'deactivated', __( 'Waiting to go on: the plugin was turned off while this image was on its way.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Run by `uninstall.php`, with the plugin's main file not loaded.
	 */
	public static function uninstall(): void {
		$settings = Local_Settings::get();

		self::revoke();
		self::delete_files( $settings['uninstall_copies'], $settings['uninstall_backups'] );

		if ( $settings['uninstall_copies'] && $settings['uninstall_backups'] ) {
			foreach ( self::RECORD_META as $key ) {
				delete_post_meta_by_key( $key );
			}
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		delete_transient( Site_Record::TRANSIENT );
		delete_transient( Bulk_State::LOOPBACK_TRANSIENT );
		delete_metadata( 'user', 0, Notice::META, '', true );

		self::delete_runs();
	}

	/**
	 * Stops the site's key on Iminify, best-effort and briefly, so a deletion
	 * never hangs on an outage. A key set in wp-config.php is left alone, as
	 * Disconnect leaves it, and Iminify refuses the call from a copy of the site
	 * at another address, so a staging copy never cuts off the real site.
	 */
	protected static function revoke(): void {
		$keys = Plugin::keys();
		$key  = $keys->get();

		if ( null === $key || $keys->from_constant() ) {
			return;
		}

		( new Client( $key ) )->disconnect( self::REVOKE_TIMEOUT );
	}

	/**
	 * Deletes the copies and the backups the records name as the plugin's own,
	 * as the person asked. With both, each record goes with its files, so a
	 * deletion that runs out of time carries on where it stopped when retried.
	 *
	 * @param bool $copies  Whether to delete the WebP and AVIF copies.
	 * @param bool $backups Whether to delete the backups.
	 */
	protected static function delete_files( bool $copies, bool $backups ): void {
		if ( ! $copies && ! $backups ) {
			return;
		}

		$after = 0;

		do {
			$ids = self::recorded( $after );

			foreach ( $ids as $id ) {
				if ( $copies ) {
					Copies::delete_own( $id );
				}

				if ( $backups ) {
					Backups::forget( Records::get( $id )['backups'] );
				}

				if ( $copies && $backups ) {
					foreach ( self::RECORD_META as $key ) {
						delete_post_meta( $id, $key );
					}
				}

				$after = $id;
			}
		} while ( array() !== $ids );

		if ( $backups ) {
			Backups::remove_folder();
		}
	}

	/**
	 * A page of the attachments the plugin keeps a record of.
	 *
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	protected static function recorded( int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off read while the plugin is deleted, by the meta_key index.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT 200", Records::META, $after ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Deletes the plugin's runs and their logs from Action Scheduler's tables.
	 * Its functions run only once it is set up for the request, which a deletion
	 * may not have (the plugin that brought it is off), so the rows go directly.
	 */
	protected static function delete_runs(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- whether Action Scheduler's table exists, once, while the plugin is deleted.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$in = implode( ', ', array_fill( 0, count( self::HOOKS ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Action Scheduler's own tables, while the plugin is deleted; one placeholder per hook, built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ({$in})", self::HOOKS ) );

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$each = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per id, built above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$each})", $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per id, built above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$each})", $chunk ) );
		}
	}
}
