<?php
/**
 * The plugin's WP-CLI commands.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Switching\Others;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes and restores the media library from the shell, for large libraries and hosts whose scheduled tasks don't run.
 *
 * Every command goes through the same bulk run as Media, Bulk Optimization, so a
 * run started here shows on that page, and one started there can be carried on here.
 *
 * ## EXAMPLES
 *
 *     # Optimize every image not optimized yet, watching it from here.
 *     $ wp iminify optimize --all
 *
 *     # Make the AVIF copies of a library optimized before the site asked for them.
 *     $ wp iminify missing formats
 *
 *     # Optimize again, from the originals it kept, the images another optimizer compressed.
 *     $ wp iminify optimize --others
 *
 *     # Optimize the images in the active theme's folder.
 *     $ wp iminify folders add themes/twentytwentyfive
 *     $ wp iminify optimize --folders
 *
 *     # See where the library and the run stand.
 *     $ wp iminify status
 */
class Cli {

	/**
	 * Hooks the commands into WP-CLI, where it runs.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'iminify', self::class );
		}
	}

	/**
	 * Optimizes images that aren't optimized yet.
	 *
	 * An image already optimized or on its way is left out, even when named, so
	 * running a command twice spends nothing on an image twice. Each image counts as
	 * one of the account's daily images. Once they are used up the run goes on by
	 * itself in the background when the next batch opens.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : The attachments to optimize.
	 *
	 * [--all]
	 * : Optimize every image not optimized yet.
	 *
	 * [--failed]
	 * : Send the images that failed again.
	 *
	 * [--folders]
	 * : Optimize the files in the folders chosen with `wp iminify folders` instead of the media library, every one not optimized yet, or with --failed the ones that failed.
	 *
	 * [--others]
	 * : Optimize again the images another image optimizer compressed, from the originals it kept: every one, or the attachments named. Each original is put back first, and each image counts as one of the account's daily images.
	 *
	 * [--background]
	 * : Start the run and return, leaving it to the site's scheduled tasks.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify optimize --all
	 *     $ wp iminify optimize 12 34
	 *     $ wp iminify optimize --failed
	 *     $ wp iminify optimize --folders
	 *     $ wp iminify optimize --others
	 *
	 * @param list<string>         $args  The attachments' ids.
	 * @param array<string, mixed> $assoc The flags.
	 */
	public function optimize( array $args, array $assoc ): void {
		$all    = ! empty( $assoc['all'] );
		$failed = ! empty( $assoc['failed'] );

		if ( ! empty( $assoc['others'] ) ) {
			if ( $all || $failed || ! empty( $assoc['folders'] ) ) {
				WP_CLI::error( __( 'Pass --others alone, or with the attachments to optimize again.', 'iminify-image-optimizer' ) );
			}

			$this->begin( Bulk::REDO, array() === $args ? null : $this->ids( $args ), $assoc );

			return;
		}

		if ( ! empty( $assoc['folders'] ) ) {
			if ( $all || array() !== $args ) {
				WP_CLI::error( __( 'The folders\' files go all at once: pass --folders alone, or with --failed.', 'iminify-image-optimizer' ) );
			}

			$this->begin( $failed ? Bulk::RETRY : Bulk::OPTIMIZE, null, $assoc, Bulk::FOLDERS );

			return;
		}

		if ( (int) $all + (int) $failed + (int) ( array() !== $args ) !== 1 ) {
			WP_CLI::error( __( 'Name the attachments, or pass --all or --failed.', 'iminify-image-optimizer' ) );
		}

		$this->begin( $failed ? Bulk::RETRY : Bulk::OPTIMIZE, $all || $failed ? null : $this->ids( $args ), $assoc );
	}

	/**
	 * Puts optimized images back as they were uploaded, from their backups.
	 *
	 * Their sizes are made again by WordPress and their WebP and AVIF copies deleted;
	 * the backups stay. This works on any plan and with the site disconnected.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : The attachments to restore.
	 *
	 * [--all]
	 * : Restore every optimized image.
	 *
	 * [--background]
	 * : Start the run and return, leaving it to the site's scheduled tasks.
	 *
	 * [--folders]
	 * : Restore every optimized file in the folders chosen with `wp iminify folders` instead of the media library.
	 *
	 * [--yes]
	 * : Don't ask before restoring every image.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify restore --all --yes
	 *     $ wp iminify restore 12 34
	 *     $ wp iminify restore --folders --yes
	 *
	 * @param list<string>         $args  The attachments' ids.
	 * @param array<string, mixed> $assoc The flags.
	 */
	public function restore( array $args, array $assoc ): void {
		$all = ! empty( $assoc['all'] );

		if ( ! empty( $assoc['folders'] ) ) {
			if ( $all || array() !== $args ) {
				WP_CLI::error( __( 'The folders\' files go all at once: pass --folders alone.', 'iminify-image-optimizer' ) );
			}

			WP_CLI::confirm( __( 'Put every optimized file in your folders back as it was?', 'iminify-image-optimizer' ), $assoc );
			$this->begin( Bulk::RESTORE, null, $assoc, Bulk::FOLDERS );

			return;
		}

		$named = array() !== $args;

		if ( $all === $named ) {
			WP_CLI::error( __( 'Name the attachments, or pass --all.', 'iminify-image-optimizer' ) );
		}

		if ( $all ) {
			WP_CLI::confirm( __( 'Put every optimized image back as it was uploaded?', 'iminify-image-optimizer' ), $assoc );
		}

		$this->begin( Bulk::RESTORE, $all ? null : $this->ids( $args ), $assoc );
	}

	/**
	 * Makes the formats or sizes optimized images lack.
	 *
	 * An image lacks a format the site asks for now that it wasn't sent for, as when
	 * AVIF was turned on later, and a size the site sends now that it wasn't made
	 * with, as when a theme registered a new one. Each image is sent from its original
	 * and counts as one of the account's daily images, and it keeps the files the
	 * service doesn't better.
	 *
	 * ## OPTIONS
	 *
	 * <what>
	 * : What to make.
	 * ---
	 * options:
	 *   - formats
	 *   - sizes
	 * ---
	 *
	 * [<id>...]
	 * : The attachments to make it for. Leave them out for every image that lacks it.
	 *
	 * [--background]
	 * : Start the run and return, leaving it to the site's scheduled tasks.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify missing formats
	 *     $ wp iminify missing sizes 12 34
	 *
	 * @param list<string>         $args  What to make, then the attachments' ids.
	 * @param array<string, mixed> $assoc The flags.
	 */
	public function missing( array $args, array $assoc ): void {
		$what = (string) array_shift( $args );

		if ( ! in_array( $what, array( Missing::FORMATS, Missing::SIZES ), true ) ) {
			WP_CLI::error( __( 'Say what to make: formats or sizes.', 'iminify-image-optimizer' ) );
		}

		$this->begin( $what, array() === $args ? null : $this->ids( $args ), $assoc );
	}

	/**
	 * Chooses the folders outside the media library whose images Iminify optimizes, and looks through them.
	 *
	 * The active theme, its parent, or any folder inside wp-content, named by its path
	 * there. The media library's folder, the plugin's own, folders other tools fill with
	 * copies, caches or backups, and links to other places are refused. A folder is
	 * looked through when it is added; `scan` looks again for new and changed files,
	 * as after editing a theme's images by hand.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : What to do.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - add
	 *   - remove
	 *   - scan
	 * ---
	 *
	 * [<folder>]
	 * : The folder to add or remove, relative to wp-content.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify folders add themes/twentytwentyfive
	 *     $ wp iminify folders scan
	 *     $ wp iminify folders remove themes/twentytwentyfive
	 *
	 * @param list<string>         $args  The action, then the folder.
	 * @param array<string, mixed> $assoc Unused.
	 */
	public function folders( array $args, array $assoc ): void {
		unset( $assoc );

		$this->check_user( Capabilities::FOLDERS );

		if ( ! Folders::available() ) {
			WP_CLI::error( __( 'On a network, other folders are chosen on the main site: add --url with its address.', 'iminify-image-optimizer' ) );
		}

		$action = (string) ( $args[0] ?? 'list' );
		$folder = (string) ( $args[1] ?? '' );

		if ( in_array( $action, array( 'add', 'remove' ), true ) && '' === $folder ) {
			WP_CLI::error( __( 'Name the folder, relative to wp-content.', 'iminify-image-optimizer' ) );
		}

		if ( 'add' === $action ) {
			$added = Folders::add( $folder );

			if ( is_wp_error( $added ) ) {
				WP_CLI::error( Messages::for_error( $added ) );
			}

			Scanner::start( array( $added ) );
		}

		if ( 'remove' === $action && ! Folders::remove( trim( wp_normalize_path( $folder ), '/' ) ) ) {
			WP_CLI::error( __( 'That folder isn\'t in the list.', 'iminify-image-optimizer' ) );
		}

		if ( 'scan' === $action && ! Scanner::running() ) {
			Scanner::start();
		}

		if ( in_array( $action, array( 'add', 'scan' ), true ) ) {
			Scanner::finish();

			$scan = Scanner::state() ?? array(
				'found'   => 0,
				'added'   => 0,
				'changed' => 0,
			);

			WP_CLI::success(
				sprintf(
					/* translators: 1: how many images were found, 2: how many are new, 3: how many changed. */
					__( 'Found %1$s images: %2$s new, %3$s changed.', 'iminify-image-optimizer' ),
					number_format_i18n( (int) $scan['found'] ),
					number_format_i18n( (int) $scan['added'] ),
					number_format_i18n( (int) $scan['changed'] )
				)
			);
		}

		$items = array();

		foreach ( Folders::listed() as $listed ) {
			$items[] = array( 'folder' => $listed );
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'folder' ) );
	}

	/**
	 * Carries on the bulk run that is going from here, whatever started it.
	 *
	 * For a run whose scheduled tasks don't run, or one left behind when a command was
	 * cut short. A paused run goes on too.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify run
	 *
	 * @param list<string>         $args  Unused.
	 * @param array<string, mixed> $assoc Unused.
	 */
	public function run( array $args, array $assoc ): void {
		unset( $args, $assoc );

		$this->check_user();

		$run = Bulk::run();

		if ( null === $run || ! in_array( $run['status'], array( Bulk::RUNNING, Bulk::PAUSED ), true ) ) {
			WP_CLI::error( __( 'No bulk run is going.', 'iminify-image-optimizer' ) );
		}

		Bulk::switch_mode( Bulk::PAGE );
		Bulk::resume();
		$this->drive();
	}

	/**
	 * Ends the bulk run that is going.
	 *
	 * Images it sent that have no group open yet go back as they were; images already
	 * with Iminify finish.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify stop
	 *
	 * @param list<string>         $args  Unused.
	 * @param array<string, mixed> $assoc Unused.
	 */
	public function stop( array $args, array $assoc ): void {
		unset( $args, $assoc );

		$this->check_user();

		$run = Bulk::run();

		if ( null === $run || ! in_array( $run['status'], array( Bulk::RUNNING, Bulk::PAUSED ), true ) ) {
			WP_CLI::error( __( 'No bulk run is going.', 'iminify-image-optimizer' ) );
		}

		Bulk::stop();
		WP_CLI::success( __( 'Stopped.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Shows where the library and the bulk run stand.
	 *
	 * ## OPTIONS
	 *
	 * [--estimate]
	 * : Ask Iminify how long the images left take on the account's plan.
	 *
	 * [--format=<format>]
	 * : How to print it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp iminify status
	 *     $ wp iminify status --estimate --format=json
	 *
	 * @param list<string>         $args  Unused.
	 * @param array<string, mixed> $assoc The flags.
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args );

		$this->check_user();

		$state = Bulk_State::build( ! empty( $assoc['estimate'] ) );
		$rows  = $this->rows( $state );

		WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'item', 'value' ) );
	}

	/**
	 * Starts a run, and watches it unless asked not to.
	 *
	 * @param string               $action The run's action.
	 * @param list<int>|null       $only   The images to take, or null for the whole library.
	 * @param array<string, mixed> $assoc  The flags.
	 * @param string               $scope  Bulk::LIBRARY or Bulk::FOLDERS.
	 */
	protected function begin( string $action, ?array $only, array $assoc, string $scope = Bulk::LIBRARY ): void {
		$this->check_user( Bulk::FOLDERS === $scope ? Capabilities::FOLDERS : Capabilities::BULK );

		/* A run over the folders waits for a look through them that is under way, which the shell can finish here. */
		if ( Bulk::FOLDERS === $scope && Scanner::running() ) {
			Scanner::finish();
		}

		$background = ! empty( $assoc['background'] );
		$run        = Bulk::start( $action, $background ? Bulk::BACKGROUND : Bulk::PAGE, $only, $scope );

		if ( is_wp_error( $run ) ) {
			WP_CLI::error( Messages::for_error( $run ) );
		}

		if ( $background ) {
			WP_CLI::success( __( 'The run goes on in the background. See how far it got with: wp iminify status', 'iminify-image-optimizer' ) );

			return;
		}

		$this->drive();
	}

	/**
	 * Runs the bulk run from here until nothing is left for this process to do, with
	 * a progress bar. A wait that lasts (a spent day, a connection that's gone) hands
	 * the run to the site's scheduled tasks, since a shell can't wait for it.
	 */
	protected function drive(): void {
		$start = Bulk::run();

		if ( null === $start ) {
			return;
		}

		$shown = $start['sent'] + $start['failed'];
		$bar   = WP_CLI\Utils\make_progress_bar( __( 'Bulk run', 'iminify-image-optimizer' ), $start['total'] );

		$bar->tick( $shown );

		while ( true ) {
			$this->forget();

			$wait = $this->look();

			$this->forget();

			$run = Bulk::run();

			if ( null === $run ) {
				break;
			}

			$now = $run['sent'] + $run['failed'];

			if ( $now > $shown ) {
				$bar->tick( $now - $shown );
				$shown = $now;
			}

			$working = Records::count_with_status( array( Records::QUEUED, Records::WAITING ) ) + Table::count_anywhere( array( Records::QUEUED, Records::WAITING ) );

			$restore = Bulk::RESTORE === $run['action'];

			/* A restore needs neither the connection nor the day, and leaves an image's own run to it. */
			if ( ! $restore && 0 === $working && Bulk::RUNNING === $run['status'] && $this->waits_long() ) {
				$bar->finish();
				$this->hand_over();

				return;
			}

			if ( Bulk::RUNNING !== $run['status'] && ( 0 === $working || $restore ) ) {
				break;
			}

			$this->pause( $wait );
		}

		$bar->finish();
		$this->report();
	}

	/**
	 * One look of the run.
	 *
	 * @return int The seconds until the next has something to do.
	 */
	protected function look(): int {
		return Bulk::step();
	}

	/**
	 * Waits between two looks.
	 *
	 * @param int $seconds How long.
	 */
	protected function pause( int $seconds ): void {
		sleep( $seconds );
	}

	/**
	 * Whether the run waits on something no shell should sit through.
	 */
	protected function waits_long(): bool {
		return null !== Pause::until() || ! Plugin::connected() || null !== Others::acting() || Records::count_with_status( array( Records::PARKED ) ) + Table::count_anywhere( array( Records::PARKED ) ) > 0;
	}

	/**
	 * Moves the run to the background, and says why.
	 */
	protected function hand_over(): void {
		Bulk::switch_mode( Bulk::BACKGROUND );

		$acting = Others::acting();

		if ( null !== $acting ) {
			WP_CLI::warning( Others::acting_message( $acting ) . ' ' . __( 'The run goes on in the background once it stops.', 'iminify-image-optimizer' ) );

			return;
		}

		$until = Pause::until();

		if ( null !== $until ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: the date and time the next batch opens. */
					__( 'The account\'s images for the day are used up. The run goes on in the background when the next batch opens on %s.', 'iminify-image-optimizer' ),
					(string) Admin\State::date( gmdate( 'c', $until ) )
				)
			);

			return;
		}

		WP_CLI::warning( __( 'The run waits for this site\'s connection to Iminify, and goes on in the background once it works.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Says how the run ended.
	 */
	protected function report(): void {
		$run = Bulk::run();

		if ( null === $run ) {
			return;
		}

		if ( Bulk::STOPPED === $run['status'] ) {
			WP_CLI::warning( __( 'The run was stopped.', 'iminify-image-optimizer' ) );

			return;
		}

		if ( Bulk::PAUSED === $run['status'] ) {
			if ( 'disk_full' === $run['reason'] ) {
				WP_CLI::error( __( 'The run paused: the server is low on disk space. Free some up, then run: wp iminify run', 'iminify-image-optimizer' ) );
			}

			WP_CLI::warning( __( 'The run was paused. Carry on with: wp iminify run', 'iminify-image-optimizer' ) );

			return;
		}

		if ( $run['failed'] > 0 ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: how many images. */
					_n( '%s image couldn\'t be restored. Its panel in the media library says why.', '%s images couldn\'t be restored. Their panels in the media library say why.', $run['failed'], 'iminify-image-optimizer' ),
					number_format_i18n( $run['failed'] )
				)
			);
		}

		if ( $run['passed'] > 0 && Bulk::REDO === $run['action'] ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: how many images. */
					_n( '%s image was left as the other plugin made it: its original is gone, too large to send, or only that plugin can put it back. Its panel in the media library says which.', '%s images were left as the other plugins made them: their originals are gone, too large to send, or only those plugins can put them back. Their panels in the media library say which.', $run['passed'], 'iminify-image-optimizer' ),
					number_format_i18n( $run['passed'] )
				)
			);
		} elseif ( $run['passed'] > 0 ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: how many images. */
					_n( '%s image has no original to go from, or changed since it was optimized, so nothing was made for it.', '%s images have no original to go from, or changed since they were optimized, so nothing was made for them.', $run['passed'], 'iminify-image-optimizer' ),
					number_format_i18n( $run['passed'] )
				)
			);
		}

		if ( Bulk::FOLDERS === $run['scope'] ) {
			$files = Table::counts();
			$done  = Bulk::RESTORE === $run['action']
				/* translators: %s: how many files. */
				? _n( '%s file restored.', '%s files restored.', $run['sent'], 'iminify-image-optimizer' )
				/* translators: %s: how many files. */
				: _n( '%s file sent to Iminify.', '%s files sent to Iminify.', $run['sent'], 'iminify-image-optimizer' );

			WP_CLI::success(
				sprintf( $done, number_format_i18n( $run['sent'] ) ) . ' ' . sprintf(
					/* translators: 1: how many files are optimized, 2: how many the folders have, 3: how many failed. */
					__( 'Your folders have %1$s of %2$s files optimized, %3$s failed.', 'iminify-image-optimizer' ),
					number_format_i18n( $files['optimized'] ),
					number_format_i18n( $files['files'] ),
					number_format_i18n( $files['failed'] )
				)
			);

			return;
		}

		$counts = Bulk::counts();
		$done   = Bulk::RESTORE === $run['action']
			/* translators: %s: how many images. */
			? _n( '%s image restored.', '%s images restored.', $run['sent'], 'iminify-image-optimizer' )
			/* translators: %s: how many images. */
			: _n( '%s image sent to Iminify.', '%s images sent to Iminify.', $run['sent'], 'iminify-image-optimizer' );

		WP_CLI::success(
			sprintf( $done, number_format_i18n( $run['sent'] ) ) . ' ' . sprintf(
				/* translators: 1: how many images are optimized, 2: how many the library has, 3: how many failed. */
				__( 'The library has %1$s of %2$s images optimized, %3$s failed.', 'iminify-image-optimizer' ),
				number_format_i18n( $counts['optimized'] ),
				number_format_i18n( $counts['images'] ),
				number_format_i18n( $counts['failed'] )
			)
		);
	}

	/**
	 * A long process keeps every option and record it read, and another request
	 * (the bulk page's Stop, an image's own run) changes them: a stale run written
	 * back would undo the Stop. So each look reads them afresh, and the process
	 * doesn't grow with the library.
	 */
	protected function forget(): void {
		global $wpdb;

		$wpdb->queries = array();
		Shared::forget();
		Others::forget();

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();

			return;
		}

		wp_cache_delete( Bulk::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The ids a command names, each an attachment that exists.
	 *
	 * @param list<string> $args What the command got.
	 * @return list<int>
	 */
	protected function ids( array $args ): array {
		$ids = array();

		foreach ( $args as $arg ) {
			$id = (int) $arg;

			if ( (string) $id !== (string) $arg || 'attachment' !== get_post_type( $id ) ) {
				WP_CLI::error(
					sprintf(
						/* translators: %s: what was given as an attachment's id. */
						__( '%s isn\'t an attachment.', 'iminify-image-optimizer' ),
						$arg
					)
				);
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * The shell is its own gate, as for `wp action-scheduler run`: whoever has it can
	 * reach the database. Only an account someone named with `--user` is asked for
	 * the capability the bulk page asks for, or the folders' for a run over them.
	 *
	 * @param string $context Capabilities::BULK or Capabilities::FOLDERS.
	 */
	protected function check_user( string $context = Capabilities::BULK ): void {
		if ( get_current_user_id() > 0 && ! Capabilities::can( $context ) ) {
			WP_CLI::error( __( 'This account can\'t run bulk commands.', 'iminify-image-optimizer' ) );
		}
	}

	/**
	 * The page's state, as rows.
	 *
	 * @param array<string, mixed> $state What Bulk_State answered.
	 * @return list<array{item: string, value: string}>
	 */
	protected function rows( array $state ): array {
		$saved = Records::saved();
		$rows  = array(
			'connected' => $state['connected'] ? 'yes' : 'no',
		);

		foreach ( (array) $state['counts'] as $name => $count ) {
			$rows[ (string) $name ] = (string) $count;
		}

		$rows['bytes_before'] = (string) $saved['before'];
		$rows['bytes_after']  = (string) $saved['after'];

		/* The page's state leaves the folders out for a person who can't choose them; the shell is its own gate. */
		if ( array() !== Folders::listed() ) {
			foreach ( Table::counts() as $name => $count ) {
				$rows[ 'folders_' . $name ] = (string) $count;
			}
		}

		if ( is_array( $state['run'] ) ) {
			foreach ( array( 'action', 'scope', 'mode', 'status', 'reason', 'sent', 'total', 'failed', 'passed' ) as $key ) {
				$rows[ 'run_' . $key ] = (string) ( $state['run'][ $key ] ?? '' );
			}

			$rows['run_stalled'] = $state['run']['stalled'] ? 'yes' : 'no';
		}

		$rows['next_batch'] = (string) ( $state['queue']['next_batch'] ?? '' );

		if ( is_array( $state['estimate'] ) ) {
			$rows['estimate_days'] = (string) $state['estimate']['days'];
		}

		$items = array();

		foreach ( $rows as $item => $value ) {
			$items[] = array(
				'item'  => (string) $item,
				'value' => (string) $value,
			);
		}

		return $items;
	}
}
