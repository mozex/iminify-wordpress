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
use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Optimizer as Folder_Optimizer;
use Iminify\WordPress\Folders\Restorer as Folder_Restorer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Switching\Modern_Formats;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Settings_Copy;

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
 * second lossy pass, and take it for the original. The files in other folders
 * follow the same rule: their table (`Folders\Table`) goes only with their copies
 * and backups.
 *
 * On a network the plugin is active for, turning it off and deleting it does
 * the same on every site it ran on (`each_site()`), each by its own settings;
 * the network's key is stopped once, which stops every key its sites joined
 * with, and the files in other folders, which only the main site lists, are
 * dealt with once, from it.
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
		Site_Record::SIZES_OPTION,
		Local_Settings::OPTION,
		Pause::OPTION,
		Bulk::OPTION,
		Bulk::LOCK_OPTION,
		Folders::OPTION,
		Scanner::OPTION,
		Scanner::LOCK_OPTION,
		Network::JOIN_OPTION,
		Network::LOCK_OPTION,
		Caches::OWED,
		Rules::CHECK_OPTION,
		Others::STAMP_OPTION,
		Settings_Copy::OPTION,
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
		Folder_Optimizer::OPTIMIZE_HOOK,
		Folder_Optimizer::CHECK_HOOK,
		Folder_Optimizer::RESUME_HOOK,
		Scanner::HOOK,
		Rules::SWEEP_HOOK,
		Others::SWEEP_HOOK,
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
	 * How many sites of a network a page of `each_site()` reads.
	 */
	const SITES_PAGE = 100;

	/**
	 * The activation hook. Action Scheduler may not be ready yet in this request,
	 * so the queue is looked at once it is (`resume()`). Both options are kept
	 * autoloaded, since every request reads them: a missing option costs a query
	 * on each request that asks for it. Turned on for a whole network, this runs
	 * on the main site alone, and each other site sets itself up on its first
	 * request (`resume()`), so a network of thousands of sites is never walked here.
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
	 *
	 * A site with no such option at all is a site of a network the plugin was
	 * turned on for since this site last ran it (turning it off for the network
	 * removes the option, `deactivate()`): it runs this once too, and keeps both
	 * options from then on, which is also how `each_site()` knows the plugin ran here.
	 */
	public static function resume(): void {
		$activated = get_option( self::ACTIVATED_OPTION, null );

		if ( null !== $activated && ! $activated ) {
			return;
		}

		update_option( self::ACTIVATED_OPTION, 0, true );
		add_option( Local_Settings::OPTION, Local_Settings::defaults(), '', true );

		/* Another optimizer may have worked while the plugin was off, and deactivation dropped any look under way. */
		delete_option( Others::STAMP_OPTION );

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

		$after = 0;

		/* The files in other folders are the main site's alone on a network, and their runs are in its queue. */
		do {
			$ids = Folders::available() ? Table::anywhere( array( Records::QUEUED, Records::WAITING ), 200, $after ) : array();

			foreach ( $ids as $id ) {
				$after = $id;

				if ( ! Folder_Optimizer::has_run( $id ) ) {
					Folder_Optimizer::park( $id, 'deactivated' );
				}
			}
		} while ( array() !== $ids );

		$until = Pause::until();

		if ( null !== $until && ! Optimizer::pending( Queue::OPENS_HOOK, array() ) ) {
			as_schedule_single_action( $until + 1, Queue::OPENS_HOOK, array(), Optimizer::GROUP );
		}

		Queue::connection_back();

		/* Turning the plugin off unscheduled a scan's next step, and a folders run can't start while one is under way. */
		if ( Scanner::running() && ! Optimizer::pending( Scanner::HOOK, array() ) ) {
			as_schedule_single_action( time(), Scanner::HOOK, array(), Optimizer::GROUP );
		}

		$run = Bulk::run();

		if ( null !== $run && Bulk::PAUSED === $run['status'] && 'deactivated' === $run['reason'] ) {
			Bulk::resume();
		}

		/* Turning the plugin off took the server's rules out; a site serving through them gets them back, and its images changed meanwhile lose their copies. */
		Rules::sync();

		if ( Rules::active() ) {
			Rules::start_sweep();
		}
	}

	/**
	 * The deactivation hook: a waiting pairing is dropped, a bulk run is held,
	 * every image on its way is parked, and nothing of the plugin's stays
	 * scheduled; turned off for a whole network, on every site it ran on, each of
	 * which picks up again on its first request once it is on again (`resume()`).
	 *
	 * @param bool $network_wide Whether it was turned off for the whole network.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( true === $network_wide && is_multisite() ) {
			self::each_site(
				static function (): void {
					self::deactivate_site();
					delete_option( self::ACTIVATED_OPTION );
				}
			);

			return;
		}

		self::deactivate_site();
	}

	/**
	 * Turns the plugin off on the site it runs on.
	 */
	protected static function deactivate_site(): void {
		( new Connection( Plugin::keys() ) )->cancel();

		/* A copy the rules would serve while the plugin is off is no longer taken away when its file changes. */
		Rules::remove();

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

		$after = 0;

		do {
			$ids = Folders::available() ? Table::anywhere( array( Records::QUEUED, Records::WAITING ), 200, $after ) : array();

			foreach ( $ids as $id ) {
				$after = $id;
				Folder_Optimizer::park( $id, 'deactivated' );
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
	 * Run by `uninstall.php`, with the plugin's main file not loaded. On a
	 * network, from the main site: the network's key is stopped first, which
	 * stops every key its sites joined with, then every site the plugin ran on
	 * is cleaned by its own settings, and the network's key goes last.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			self::revoke_network();
			self::each_site( array( self::class, 'uninstall_site' ) );
			delete_site_option( Network::KEY_OPTION );
		} else {
			self::uninstall_site();
		}

		delete_metadata( 'user', 0, Notice::META, '', true );
	}

	/**
	 * Cleans the site the deletion is on: its key, files, records, options and
	 * runs, and the files in other folders on the site that lists them.
	 */
	protected static function uninstall_site(): void {
		$settings = Local_Settings::get();

		self::revoke();
		Rules::remove();

		if ( Folders::available() ) {
			self::delete_folder_files( $settings['uninstall_copies'], $settings['uninstall_backups'] );
		}

		self::delete_files( $settings['uninstall_copies'], $settings['uninstall_backups'] );
		self::forget_others();

		if ( $settings['uninstall_copies'] && $settings['uninstall_backups'] ) {
			foreach ( self::RECORD_META as $key ) {
				delete_post_meta_by_key( $key );
			}

			if ( Folders::available() ) {
				Table::drop();
			}
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		delete_transient( Site_Record::TRANSIENT );
		delete_transient( Bulk_State::LOOPBACK_TRANSIENT );
		delete_transient( Modern_Formats::FOUND );

		self::delete_runs();
	}

	/**
	 * Stops the site's key on Iminify, best-effort and briefly, so a deletion
	 * never hangs on an outage. A key set in wp-config.php is left alone, as
	 * Disconnect leaves it, and Iminify refuses the call from a copy of the site
	 * at another address, so a staging copy never cuts off the real site. A key a
	 * site joined its network with went with the network's (`revoke_network()`).
	 */
	protected static function revoke(): void {
		$keys = Plugin::keys();
		$key  = $keys->get();

		if ( null === $key || $keys->from_constant() || Network::joined() ) {
			return;
		}

		( new Client( $key ) )->disconnect( self::REVOKE_TIMEOUT );
	}

	/**
	 * Stops the network's key, which stops every key its sites joined with, in
	 * one call from the network's address. The plugin is off for the network by
	 * now, so the key is read from where the network keeps it.
	 */
	protected static function revoke_network(): void {
		$key = Sealer::open( get_site_option( Network::KEY_OPTION ) );

		if ( null === $key || Key_Store::constant_set() ) {
			return;
		}

		( new Client( $key ) )->disconnect( self::REVOKE_TIMEOUT, Network::address() );
	}

	/**
	 * Runs a step on every site of the network the plugin ran on: those keeping
	 * its settings or its activation mark, which its first run on a site adds
	 * (`resume()`). Both are autoloaded, so each site costs the one read of its
	 * autoloaded options, and they are looked up there rather than through
	 * `get_option()`, which answers the settings' registered default for a site
	 * that has none. A page of sites at a time, each switched to, so a step reads
	 * and writes that site's options, records and queue.
	 *
	 * @param callable $step The step.
	 */
	protected static function each_site( callable $step ): void {
		$offset = 0;

		do {
			$sites = get_sites(
				array(
					'fields'     => 'ids',
					'network_id' => get_current_network_id(),
					'number'     => self::SITES_PAGE,
					'offset'     => $offset,
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			);

			$count = count( $sites );

			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );

				try {
					$options = wp_load_alloptions();

					if ( isset( $options[ Local_Settings::OPTION ] ) || isset( $options[ self::ACTIVATED_OPTION ] ) ) {
						$step();
					}
				} finally {
					restore_current_blog();
				}
			}

			$offset += self::SITES_PAGE;
		} while ( self::SITES_PAGE === $count );
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
			$ids = Records::recorded( $after, 200 );

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

		/* A page a cache kept still names the copies just deleted, and would show broken images. */
		if ( $copies ) {
			Caches::purge( 'uninstall' );
		}
	}

	/**
	 * Deletes the records of images another plugin optimized that hold nothing of
	 * the plugin's, so none outlives it and a later install looks again. One that
	 * still names the plugin's backups or copies (an image another plugin
	 * compressed after Iminify did) is kept, as every record holding work is.
	 */
	protected static function forget_others(): void {
		$after = 0;

		do {
			$ids = Records::with_status( array( Records::OTHER ), 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;

				if ( Shared::holds_work( $id ) ) {
					continue;
				}

				foreach ( self::RECORD_META as $key ) {
					delete_post_meta( $id, $key );
				}
			}
		} while ( array() !== $ids );
	}

	/**
	 * Deletes the copies and the backups of the files in other folders, as the
	 * person asked; with both, the table goes afterwards (`uninstall()`).
	 *
	 * @param bool $copies  Whether to delete the WebP and AVIF copies.
	 * @param bool $backups Whether to delete the backups.
	 */
	protected static function delete_folder_files( bool $copies, bool $backups ): void {
		if ( ! $copies && ! $backups ) {
			return;
		}

		$after = 0;

		do {
			$ids = Table::ids( $after, 200 );

			foreach ( $ids as $id ) {
				$after = $id;
				$row   = Table::get( $id );

				if ( null === $row ) {
					continue;
				}

				if ( $copies ) {
					Folder_Restorer::delete_copies( $row );
				}

				if ( $backups ) {
					Backups::forget( $row['backups'] );
				}
			}
		} while ( array() !== $ids );
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
