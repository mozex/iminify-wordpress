<?php
/**
 * Looks through the chosen folders for new and changed images.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Optimizer as Media_Optimizer;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Switching\Others;
use WP_Upgrader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A walk of the chosen folders that stops at a time budget and goes on where it
 * stopped, since a folder can hold more files than one request can look at: the
 * folders still to read are kept in an option, a breadth-first queue. It follows
 * no link and enters no folder `Folders` leaves out.
 *
 * Each file it finds is marked with the scan's own number. A new file is added
 * as not optimized; a file whose bytes are no longer what the plugin wrote, or
 * what it had when it was looked at last, has changed (a theme update, an edit):
 * it reads not optimized again, and no longer restores, since its backup is the
 * old picture's. Once the walk ends, the rows of these folders it didn't find
 * read `missing`, keeping their records.
 *
 * A scan runs when a folder is added, when someone asks, and after a theme or
 * plugin is updated, in the background then; a run takes what the last one found.
 */
class Scanner {

	const OPTION = 'iminify_folders_scan';

	const LOCK_OPTION = 'iminify_folders_scan_lock';

	const HOOK = 'iminify_scan_folders';

	/**
	 * Seconds one request walks.
	 */
	const BUDGET = 10;

	/**
	 * Folders read between saves of where the walk stands, so a request cut short loses little.
	 */
	const SAVE_EVERY = 25;

	/**
	 * The scan, or null when there has been none.
	 *
	 * @return array{mark: int, folders: list<string>, pending: list<string>, found: int, added: int, changed: int, started: int, finished: int|null}|null
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION );

		if ( ! is_array( $state ) || ! isset( $state['mark'] ) ) {
			return null;
		}

		return array(
			'mark'     => (int) $state['mark'],
			'folders'  => isset( $state['folders'] ) && is_array( $state['folders'] ) ? array_values( array_map( 'strval', $state['folders'] ) ) : array(),
			'pending'  => isset( $state['pending'] ) && is_array( $state['pending'] ) ? array_values( array_map( 'strval', $state['pending'] ) ) : array(),
			'found'    => (int) ( $state['found'] ?? 0 ),
			'added'    => (int) ( $state['added'] ?? 0 ),
			'changed'  => (int) ( $state['changed'] ?? 0 ),
			'started'  => (int) ( $state['started'] ?? 0 ),
			'finished' => isset( $state['finished'] ) ? (int) $state['finished'] : null,
		);
	}

	/**
	 * Whether a scan is under way.
	 */
	public static function running(): bool {
		$state = self::state();

		return null !== $state && null === $state['finished'];
	}

	/**
	 * Starts a scan of every chosen folder, or adds folders to the one under way.
	 *
	 * @param list<string>|null $folders The folders to add to a scan under way, or null for a scan of them all.
	 */
	public static function start( ?array $folders = null ): void {
		$state = self::state();

		if ( null !== $folders && null !== $state && null === $state['finished'] ) {
			$new = array_values( array_diff( $folders, $state['folders'] ) );

			$state['folders'] = array_merge( $state['folders'], $new );
			$state['pending'] = array_merge( $state['pending'], $new );
			update_option( self::OPTION, $state, false );

			return;
		}

		$listed = Folders::listed();

		update_option(
			self::OPTION,
			array(
				/* Every scan's mark is higher than the last, so a file the last one marked can't pass for found by this one. */
				'mark'     => max( time(), null === $state ? 0 : $state['mark'] + 1 ),
				'folders'  => $listed,
				'pending'  => $listed,
				'found'    => 0,
				'added'    => 0,
				'changed'  => 0,
				'started'  => time(),
				'finished' => array() === $listed ? time() : null,
			),
			false
		);
	}

	/**
	 * Walks until the deadline. Answers the scan as it stands.
	 *
	 * @param float $deadline When to stop, as microtime.
	 * @return array<string, mixed>|null
	 */
	public static function step( float $deadline ): ?array {
		/* A long process (WP-CLI) keeps an option it read; another request may have moved the scan on since. */
		wp_cache_delete( self::OPTION, 'options' );

		$state = self::state();

		if ( null === $state || null !== $state['finished'] || ! self::lock() ) {
			return $state;
		}

		try {
			$read = 0;

			/* At least one folder a step, so a request that came in late still moves the walk on. */
			while ( array() !== $state['pending'] && ( 0 === $read || microtime( true ) < $deadline ) ) {
				$folder = (string) array_shift( $state['pending'] );
				$found  = self::read( $folder );

				$state['pending'] = array_merge( $state['pending'], $found['folders'] );
				$state['found']  += count( $found['files'] );

				$counted           = self::record( $found['files'], $state['mark'] );
				$state['added']   += $counted['added'];
				$state['changed'] += $counted['changed'];

				++$read;

				if ( 0 === $read % self::SAVE_EVERY ) {
					self::save( $state );
				}
			}

			if ( array() === $state['pending'] ) {
				Table::mark_missing( array_values( array_intersect( $state['folders'], Folders::listed() ) ), $state['mark'] );
				$state['finished'] = time();
			}

			self::save( $state );
		} finally {
			self::unlock();
		}

		return self::state();
	}

	/**
	 * Walks the whole scan in this request, as WP-CLI does.
	 */
	public static function finish(): void {
		while ( self::running() ) {
			$before = self::state();
			$after  = self::step( microtime( true ) + self::BUDGET );

			/* Another request holds the walk: wait for it rather than ask again at once. */
			if ( $before === $after ) {
				usleep( 500000 );
			}
		}
	}

	/**
	 * Action Scheduler's run for HOOK: a step, and another until the scan is done.
	 */
	public static function background(): void {
		self::step( microtime( true ) + self::BUDGET );

		if ( self::running() && ! Media_Optimizer::pending( self::HOOK, array() ) ) {
			as_schedule_single_action( time() + 5, self::HOOK, array(), Media_Optimizer::GROUP );
		}
	}

	/**
	 * After WordPress updated a theme or a plugin: its files may have changed, so the chosen folders are looked at again in the background.
	 *
	 * @param mixed $upgrader The upgrader.
	 * @param mixed $options  What it did: another plugin can call the action with anything.
	 */
	public static function after_update( $upgrader, $options ): void {
		if ( ! is_array( $options ) || ! in_array( $options['type'] ?? '', array( 'theme', 'plugin' ), true ) || array() === Folders::listed() || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		/* An update replaces the theme's or the plugin's folder whole, the copies beside its images with it, which a cached page of the whole-page mode names. */
		if ( self::replaced_listed( $upgrader, $options ) ) {
			Caches::copies_gone();
		}

		self::start();

		if ( ! Media_Optimizer::pending( self::HOOK, array() ) ) {
			as_schedule_single_action( time() + 30, self::HOOK, array(), Media_Optimizer::GROUP );
		}
	}

	/**
	 * Whether an update replaced a chosen folder, or the folder a chosen one lies
	 * in: the updated themes by their slugs, the plugins by their main files, and
	 * an upload that replaced one by the folder it went to.
	 *
	 * @param mixed                $upgrader The upgrader.
	 * @param array<string, mixed> $options  What it did.
	 */
	protected static function replaced_listed( $upgrader, array $options ): bool {
		$paths = array();

		foreach ( array_merge( (array) ( $options['themes'] ?? array() ), (array) ( $options['theme'] ?? array() ) ) as $theme ) {
			if ( is_string( $theme ) && '' !== $theme ) {
				$paths[] = get_theme_root( $theme ) . '/' . $theme;
			}
		}

		foreach ( array_merge( (array) ( $options['plugins'] ?? array() ), (array) ( $options['plugin'] ?? array() ) ) as $plugin ) {
			if ( is_string( $plugin ) && '' !== $plugin && '.' !== dirname( $plugin ) ) {
				$paths[] = WP_PLUGIN_DIR . '/' . dirname( $plugin );
			}
		}

		if ( $upgrader instanceof WP_Upgrader && is_array( $upgrader->result ) ) {
			$paths[] = $upgrader->result['destination'];
		}

		foreach ( $paths as $path ) {
			$replaced = Folders::relative( $path );

			foreach ( null === $replaced ? array() : Folders::listed() as $folder ) {
				if ( 0 === strpos( $folder . '/', $replaced . '/' ) || 0 === strpos( $replaced . '/', $folder . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reads one folder: its images and the folders below it to read next.
	 *
	 * @param string $folder The folder, relative to `wp-content`.
	 * @return array{files: array<string, int>, folders: list<string>}
	 */
	protected static function read( string $folder ): array {
		$found    = array(
			'files'   => array(),
			'folders' => array(),
		);
		$absolute = Folders::absolute( $folder );

		/* A folder taken off the list while the walk was under way is no longer read. */
		if ( ! in_array( $folder, Folders::listed(), true ) && ! Folders::contains( $folder . '/' ) ) {
			return $found;
		}

		if ( ! is_dir( $absolute ) || ! Folders::enters( $absolute ) ) {
			return $found;
		}

		$entries = scandir( $absolute );

		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $absolute . '/' . $entry;

			if ( is_link( $path ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				if ( Folders::enters( $path ) ) {
					$found['folders'][] = $folder . '/' . $entry;
				}

				continue;
			}

			if ( is_file( $path ) && Folders::takes( $entry ) ) {
				$found['files'][ $folder . '/' . $entry ] = (int) filesize( $path );
			}
		}

		return $found;
	}

	/**
	 * Records the files of one folder: the new ones added, the changed ones marked, every one stamped as found.
	 *
	 * @param array<string, int> $files Each file's bytes, by its path relative to `wp-content`.
	 * @param int                $mark  The scan's mark.
	 * @return array{added: int, changed: int}
	 */
	protected static function record( array $files, int $mark ): array {
		$counted = array(
			'added'   => 0,
			'changed' => 0,
		);

		if ( array() === $files ) {
			return $counted;
		}

		$rows  = Table::by_paths( array_map( 'strval', array_keys( $files ) ) );
		$new   = array();
		$found = array();

		foreach ( $files as $path => $bytes ) {
			$path = (string) $path;
			$row  = $rows[ $path ] ?? null;

			if ( null === $row ) {
				$new[ $path ] = $bytes;
				++$counted['added'];
				continue;
			}

			/* A run in another request may have written the file since the folder was read: it is measured again now its row is in hand. */
			$absolute = Folders::absolute( $path );
			clearstatcache( true, $absolute );
			$changes = self::changes( $row, is_file( $absolute ) ? (int) filesize( $absolute ) : $bytes );

			if ( array() === $changes ) {
				$found[] = (int) $row['id'];
				continue;
			}

			if ( isset( $changes['generation'] ) ) {
				++$counted['changed'];

				/* The server's rules serve any copy beside a file, so one made for its old bytes goes now. */
				if ( Rules::active() && array() !== Copies::own( $row['copies'] ) ) {
					Restorer::delete_copies( $row );
					$changes['copies'] = array_diff_key( $row['copies'], Copies::own( $row['copies'] ) );
				}
			}

			Table::update( (int) $row['id'], array_merge( $changes, array( 'seen' => $mark ) ) );
		}

		Table::stamp( $found, $mark );
		Table::insert( $new, $mark );
		self::mark_others( array_map( 'strval', array_keys( $new ) ) );

		return $counted;
	}

	/**
	 * Records the new files another plugin optimized through a folder of its own
	 * as that plugin's, so the counts show them and no run sends them.
	 *
	 * @param list<string> $paths The new files, relative to `wp-content`.
	 */
	protected static function mark_others( array $paths ): void {
		if ( array() === $paths ) {
			return;
		}

		$absolute = array();

		foreach ( $paths as $path ) {
			$absolute[ Folders::absolute( $path ) ] = $path;
		}

		$owners = Others::file_owners( array_keys( $absolute ) );

		if ( array() === $owners ) {
			return;
		}

		$rows = Table::by_paths( array_values( array_intersect_key( $absolute, $owners ) ) );

		foreach ( $owners as $file => $owner ) {
			$row = $rows[ $absolute[ $file ] ] ?? null;

			if ( null !== $row ) {
				Optimizer::set_aside(
					(int) $row['id'],
					array(
						'code'    => Others::CODE,
						'message' => Others::label( $owner ),
						'other'   => $owner,
					)
				);
			}
		}
	}

	/**
	 * What a scan changes on a file it found: its bytes, and where it stands when
	 * they are no longer what the plugin last wrote or saw; nothing for a file as it
	 * was. A file on its way is left to its run, which checks the file before it
	 * writes and records its bytes, so its row is only stamped as found.
	 *
	 * @param array<string, mixed> $row   The file's row.
	 * @param int                  $bytes The file's bytes now.
	 * @return array<string, mixed>
	 */
	protected static function changes( array $row, int $bytes ): array {
		if ( in_array( $row['status'], Table::ON_ITS_WAY, true ) ) {
			return array();
		}

		$written  = $row['written'];
		$expected = $written[ $row['path'] ] ?? (int) $row['bytes'];

		if ( $bytes === $expected ) {
			/* A file that came back as it was is where it stood. */
			if ( Table::MISSING === $row['status'] ) {
				$was = in_array( $row['was'], array( '', Table::MISSING ), true ) || in_array( $row['was'], Table::ON_ITS_WAY, true ) ? null : $row['was'];

				return array( 'status' => $was ?? ( null !== $row['result'] ? Records::OPTIMIZED : Records::NOT_OPTIMIZED ) );
			}

			return array();
		}

		$changes = array(
			'status'     => Records::NOT_OPTIMIZED,
			'bytes'      => $bytes,
			'generation' => $row['generation'] + 1,
			'pending'    => null,
			'attempts'   => 0,
			'reopens'    => 0,
			'error'      => null,
		);

		if ( isset( $written[ $row['path'] ] ) ) {
			unset( $written[ $row['path'] ] );

			$changes['written'] = $written;
			$changes['result']  = null;
			$changes['error']   = array(
				'code'    => 'changed',
				'message' => __( 'Changed since Iminify optimized it, so it can be optimized again.', 'iminify-image-optimizer' ),
			);
		}

		return $changes;
	}

	/**
	 * Saves where the walk stands.
	 *
	 * @param array<string, mixed> $state The scan.
	 */
	protected static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Takes the lock a step holds, so two requests never walk at once; one older than a minute was left by a request that died.
	 */
	protected static function lock(): bool {
		/* After this process's own unlock, WordPress remembers the option as missing and would write over another request's lock without reading it. */
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( add_option( self::LOCK_OPTION, time() + MINUTE_IN_SECONDS, '', false ) ) {
			return true;
		}

		if ( (int) get_option( self::LOCK_OPTION ) >= time() ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, time() + MINUTE_IN_SECONDS, '', false );
	}

	/**
	 * Lets the next step walk.
	 */
	protected static function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
