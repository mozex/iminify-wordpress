<?php
/**
 * The record of every file the plugin found in the folders someone chose.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per file, the media library's record for a file that has no
 * attachment: where its optimization stands, the group making it, the bytes the
 * plugin wrote over it, its WebP and AVIF copies and its backups, kept in the
 * serialized `record`, with the status, the bytes and the savings in columns of
 * their own for the queries. The written bytes are the only proof a file is the
 * plugin's output, so a row stays while its file is gone (`missing`) or its
 * folder leaves the list, and goes only with its files when the plugin is
 * deleted. A write's `pending` names the files it is about to rename into
 * place, so one that dies before saving the row still leaves that proof
 * (`Writer::settle()`, read through `Folders::guard()`).
 *
 * The table is made when the first folder is chosen, never on a site that
 * chooses none, and is the network's (`base_prefix`), since `wp-content` is.
 */
class Table {

	const NAME = 'iminify_files';

	/**
	 * The option that says the table exists, with its schema's version.
	 */
	const VERSION_OPTION = 'iminify_files_db';

	const VERSION = '2';

	/**
	 * A file a scan no longer finds: its row keeps the proof of what the plugin wrote, should it come back.
	 */
	const MISSING = 'missing';

	/**
	 * Statuses that mean the file is on its way to the service, or waiting to go on.
	 */
	const ON_ITS_WAY = array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED );

	/**
	 * The keys `record` holds.
	 */
	const RECORD_KEYS = array( 'trigger', 'generation', 'group', 'written', 'copies', 'pending', 'backups', 'result', 'error', 'attempts', 'reopens' );

	/**
	 * Whether the table has been made.
	 */
	public static function exists(): bool {
		return (bool) get_option( self::VERSION_OPTION );
	}

	/**
	 * Brings a table made by an earlier version to the current schema. It reads the
	 * autoloaded options alone, which every request holds already: a site that never
	 * chose a folder has no such option, and asking for one would cost it a query on
	 * every request.
	 */
	public static function upgrade(): void {
		$options = wp_load_alloptions();

		if ( isset( $options[ self::VERSION_OPTION ] ) && self::VERSION !== $options[ self::VERSION_OPTION ] ) {
			self::create();
		}
	}

	/**
	 * Makes the table, or brings it to the current schema.
	 */
	public static function create(): void {
		global $wpdb;

		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->base_prefix . self::NAME;
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(1024) NOT NULL,
			path_hash char(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'not_optimized',
			was varchar(20) NOT NULL DEFAULT '',
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			before_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			after_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			seen bigint(20) unsigned NOT NULL DEFAULT 0,
			record longtext NULL,
			updated bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY path (path(191)),
			KEY status (status)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Removes the table.
	 */
	public static function drop(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table, as the plugin is deleted.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}iminify_files" );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * A file's row, every record key present, or null for none.
	 *
	 * @param int $id The row's id.
	 * @return array{id: int, path: string, status: string, was: string, bytes: int, seen: int, updated: int, trigger: string|null, generation: int, group: array<string, mixed>|null, written: array<string, int>, copies: array<string, mixed>, pending: array{written: array<string, int>, copies: array<string, array<string, mixed>>}|null, backups: list<string>, result: array<string, mixed>|null, error: array<string, mixed>|null, attempts: int, reopens: int}|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( ! self::exists() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, by its primary key.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}iminify_files WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Changes some of a file's row: `status`, `bytes` and `seen` are columns, every other key is the record's.
	 *
	 * @param int                  $id      The row's id.
	 * @param array<string, mixed> $changes The keys to change.
	 */
	public static function update( int $id, array $changes ): void {
		global $wpdb;

		$row = self::get( $id );

		if ( null === $row ) {
			return;
		}

		$row     = array_merge( $row, $changes );
		$record  = array_intersect_key( $row, array_flip( self::RECORD_KEYS ) );
		$outputs = isset( $row['result']['outputs'] ) && is_array( $row['result']['outputs'] ) ? $row['result']['outputs'] : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, by its primary key.
		$wpdb->update(
			$wpdb->base_prefix . self::NAME,
			array(
				'status'       => (string) $row['status'],
				'bytes'        => max( 0, (int) $row['bytes'] ),
				'seen'         => max( 0, (int) $row['seen'] ),
				'before_bytes' => (int) array_sum( array_map( 'intval', array_column( $outputs, 'before' ) ) ),
				'after_bytes'  => (int) array_sum( array_map( 'intval', array_column( $outputs, 'after' ) ) ),
				'record'       => maybe_serialize( $record ),
				'updated'      => time(),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Adds files a scan found for the first time, as not optimized.
	 *
	 * @param array<string, int> $files Each file's bytes, by its path relative to `wp-content`.
	 * @param int                $seen  The scan's mark.
	 */
	public static function insert( array $files, int $seen ): void {
		global $wpdb;

		foreach ( array_chunk( $files, 100, true ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $path => $bytes ) {
				array_push( $values, (string) $path, sha1( (string) $path ), Records::NOT_OPTIMIZED, $bytes, $seen, time() );
			}

			$each = implode( ', ', array_fill( 0, count( $chunk ), '(%s, %s, %s, %d, %d, %d)' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; one group of placeholders per row, built above.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->base_prefix}iminify_files (path, path_hash, status, bytes, seen, updated) VALUES {$each}", $values ) );
		}
	}

	/**
	 * The rows of these files, by their paths.
	 *
	 * @param list<string> $paths Paths relative to `wp-content`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function by_paths( array $paths ): array {
		global $wpdb;

		if ( array() === $paths ) {
			return array();
		}

		$hashes = array_map( 'sha1', $paths );
		$in     = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table, by its unique index; one placeholder per path, built above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}iminify_files WHERE path_hash IN ({$in})", $hashes ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[ (string) $row['path'] ] = self::shape( $row );
		}

		return $found;
	}

	/**
	 * Marks the files a scan of these folders didn't find as missing, but those on their
	 * way, keeping where each stood (`was`), so one that comes back returns there.
	 *
	 * @param list<string> $folders The folders scanned, relative to `wp-content`.
	 * @param int          $seen    The scan's mark.
	 */
	public static function mark_missing( array $folders, int $seen ): void {
		global $wpdb;

		if ( array() === $folders || ! self::exists() ) {
			return;
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );
		$left  = array_merge( self::ON_ITS_WAY, array( self::MISSING ) );
		$skip  = implode( ', ', array_fill( 0, count( $left ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; the placeholders are built above, one per folder and status.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}iminify_files SET was = status, status = %s, updated = %d WHERE seen < %d AND status NOT IN ({$skip}) AND ({$likes})", array_merge( array( self::MISSING, time(), $seen ), $left, self::patterns( $folders ) ) ) );
	}

	/**
	 * Stamps the files a scan found as they were, with the scan's mark alone, never their records, which a run may be writing at the same moment.
	 *
	 * @param list<int> $ids  The rows.
	 * @param int       $seen The scan's mark.
	 */
	public static function stamp( array $ids, int $seen ): void {
		global $wpdb;

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$each = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table, by its primary key; one placeholder per id, built above.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}iminify_files SET seen = %d WHERE id IN ({$each})", array_merge( array( $seen ), $chunk ) ) );
		}
	}

	/**
	 * The files in the chosen folders with one of these statuses, in the order of their ids.
	 *
	 * @param list<string> $statuses The statuses.
	 * @param int          $limit    How many at most.
	 * @param int          $after    The id to start after.
	 * @return list<int>
	 */
	public static function with_status( array $statuses, int $limit, int $after = 0 ): array {
		global $wpdb;

		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() || array() === $statuses ) {
			return array();
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table, by its status index; the placeholders are built above, one per status and folder.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in}) AND id > %d AND ({$likes}) ORDER BY id ASC LIMIT %d", array_merge( $statuses, array( $after ), self::patterns( $folders ), array( $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many files in the chosen folders have one of these statuses.
	 *
	 * @param list<string> $statuses The statuses.
	 */
	public static function count_with_status( array $statuses ): int {
		global $wpdb;

		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() || array() === $statuses ) {
			return 0;
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own pages; the placeholders are built above, one per status and folder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in}) AND ({$likes})", array_merge( $statuses, self::patterns( $folders ) ) ) );
	}

	/**
	 * How many files of anywhere in the table have one of these statuses, chosen folder or not: the work on its way, which an unlisted folder's file still is.
	 *
	 * @param list<string> $statuses The statuses.
	 */
	public static function count_anywhere( array $statuses ): int {
		global $wpdb;

		if ( ! self::exists() || array() === $statuses ) {
			return 0;
		}

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count by the plugin's own status index; one placeholder per status, built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in})", $statuses ) );
	}

	/**
	 * The files of anywhere in the table with one of these statuses, in the order of their ids.
	 *
	 * @param list<string> $statuses The statuses.
	 * @param int          $limit    How many at most.
	 * @param int          $after    The id to start after.
	 * @return list<int>
	 */
	public static function anywhere( array $statuses, int $limit, int $after = 0 ): array {
		global $wpdb;

		if ( ! self::exists() || array() === $statuses ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table, by its status index; one placeholder per status, built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in}) AND id > %d ORDER BY id ASC LIMIT %d", array_merge( $statuses, array( $after, $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Every row's id, a page at a time, chosen folder or not.
	 *
	 * @param int $after The id to start after.
	 * @param int $limit How many at most.
	 * @return list<int>
	 */
	public static function ids( int $after, int $limit ): array {
		global $wpdb;

		if ( ! self::exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, by its primary key.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->base_prefix}iminify_files WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * The absolute paths of some files, by one query, for a run's page.
	 *
	 * @param list<int> $ids The files' row ids.
	 * @return list<string>
	 */
	public static function paths( array $ids ): array {
		global $wpdb;

		if ( array() === $ids || ! self::exists() ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a run's page by the primary key; one placeholder per id, built above.
		$paths = $wpdb->get_col( $wpdb->prepare( "SELECT path FROM {$wpdb->base_prefix}iminify_files WHERE id IN ({$in})", $ids ) );

		return array_values( array_map( array( Folders::class, 'absolute' ), array_map( 'strval', $paths ) ) );
	}

	/**
	 * The files in the chosen folders the screens count.
	 *
	 * @return array{files: int, optimized: int, not_optimized: int, waiting: int, failed: int, skipped: int, restored: int, other: int}
	 */
	public static function counts(): array {
		global $wpdb;

		$counts  = array(
			'files'         => 0,
			'optimized'     => 0,
			'not_optimized' => 0,
			'waiting'       => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'restored'      => 0,
			'other'         => 0,
		);
		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() ) {
			return $counts;
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own pages; one placeholder per folder, built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$wpdb->base_prefix}iminify_files WHERE status <> %s AND ({$likes}) GROUP BY status", array_merge( array( self::MISSING ), self::patterns( $folders ) ) ), ARRAY_A );
		$map  = array(
			Records::OPTIMIZED     => 'optimized',
			Records::NOT_OPTIMIZED => 'not_optimized',
			Records::QUEUED        => 'waiting',
			Records::WAITING       => 'waiting',
			Records::PAUSED        => 'waiting',
			Records::PARKED        => 'waiting',
			Records::ERROR         => 'failed',
			Records::SKIPPED       => 'skipped',
			Records::RESTORED      => 'restored',
			Records::OTHER         => 'other',
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$total            = (int) $row['total'];
			$counts['files'] += $total;

			if ( isset( $map[ $row['status'] ] ) ) {
				$counts[ $map[ $row['status'] ] ] += $total;
			}
		}

		return $counts;
	}

	/**
	 * The bytes before and after across every file in the chosen folders the plugin optimized.
	 *
	 * @return array{before: int, after: int}
	 */
	public static function saved(): array {
		global $wpdb;

		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() ) {
			return array(
				'before' => 0,
				'after'  => 0,
			);
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a sum on the plugin's own pages; one placeholder per folder, built above.
		$sums = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(before_bytes) AS before_bytes, SUM(after_bytes) AS after_bytes FROM {$wpdb->base_prefix}iminify_files WHERE status = %s AND ({$likes})", array_merge( array( Records::OPTIMIZED ), self::patterns( $folders ) ) ), ARRAY_A );

		return array(
			'before' => is_array( $sums ) ? (int) $sums['before_bytes'] : 0,
			'after'  => is_array( $sums ) ? (int) $sums['after_bytes'] : 0,
		);
	}

	/**
	 * The bytes of the files in the chosen folders not optimized yet, which their backups would take.
	 */
	public static function waiting_bytes(): int {
		global $wpdb;

		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() ) {
			return 0;
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a sum on the plugin's own pages; one placeholder per folder, built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(bytes) FROM {$wpdb->base_prefix}iminify_files WHERE status = %s AND ({$likes})", array_merge( array( Records::NOT_OPTIMIZED ), self::patterns( $folders ) ) ) );
	}

	/**
	 * A page of the files in the chosen folders, for the list screen.
	 *
	 * @param list<string>|null $statuses The statuses to show, or null for every file there is.
	 * @param int               $page     The page, from 1.
	 * @param int               $per_page How many a page.
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public static function page( ?array $statuses, int $page, int $per_page ): array {
		global $wpdb;

		$folders = Folders::listed();

		if ( array() === $folders || ! self::exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$likes = implode( ' OR ', array_fill( 0, count( $folders ), 'path LIKE %s' ) );

		$statuses = $statuses ?? array( Records::OPTIMIZED, Records::NOT_OPTIMIZED, Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED, Records::ERROR, Records::SKIPPED, Records::RESTORED, Records::OTHER );
		$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$values   = array_merge( $statuses, self::patterns( $folders ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own pages; the placeholders are built above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in}) AND ({$likes})", $values ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a page on the plugin's own screen; the placeholders are built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}iminify_files WHERE status IN ({$in}) AND ({$likes}) ORDER BY path ASC, id ASC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, max( 0, $page - 1 ) * $per_page ) ) ), ARRAY_A );

		return array(
			'rows'  => array_values( array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() ) ),
			'total' => $total,
		);
	}

	/**
	 * A row as `get()` answers it.
	 *
	 * @param array<string, mixed> $row The row as the database answers it.
	 * @return array{id: int, path: string, status: string, was: string, bytes: int, seen: int, updated: int, trigger: string|null, generation: int, group: array<string, mixed>|null, written: array<string, int>, copies: array<string, mixed>, pending: array{written: array<string, int>, copies: array<string, array<string, mixed>>}|null, backups: list<string>, result: array<string, mixed>|null, error: array<string, mixed>|null, attempts: int, reopens: int}
	 */
	protected static function shape( array $row ): array {
		$record  = maybe_unserialize( (string) ( $row['record'] ?? '' ) );
		$record  = is_array( $record ) ? $record : array();
		$settled = Writer::settle(
			isset( $record['written'] ) && is_array( $record['written'] ) ? array_map( 'intval', $record['written'] ) : array(),
			isset( $record['copies'] ) && is_array( $record['copies'] ) ? $record['copies'] : array(),
			$record['pending'] ?? null,
			static function ( string $file ): ?int {
				$path = Folders::guard( $file );

				return null === $path ? null : (int) filesize( $path );
			}
		);

		return array(
			'id'         => (int) $row['id'],
			'path'       => (string) $row['path'],
			'status'     => (string) $row['status'],
			'was'        => (string) ( $row['was'] ?? '' ),
			'bytes'      => (int) $row['bytes'],
			'seen'       => (int) $row['seen'],
			'updated'    => (int) $row['updated'],
			'trigger'    => isset( $record['trigger'] ) && is_string( $record['trigger'] ) ? $record['trigger'] : null,
			'generation' => isset( $record['generation'] ) ? (int) $record['generation'] : 0,
			'group'      => isset( $record['group'] ) && is_array( $record['group'] ) ? $record['group'] : null,
			'written'    => $settled['written'],
			'copies'     => $settled['copies'],
			'pending'    => $settled['pending'],
			'backups'    => isset( $record['backups'] ) && is_array( $record['backups'] ) ? array_values( array_map( 'strval', $record['backups'] ) ) : array(),
			'result'     => isset( $record['result'] ) && is_array( $record['result'] ) ? $record['result'] : null,
			'error'      => isset( $record['error'] ) && is_array( $record['error'] ) ? $record['error'] : null,
			'attempts'   => isset( $record['attempts'] ) ? (int) $record['attempts'] : 0,
			'reopens'    => isset( $record['reopens'] ) ? (int) $record['reopens'] : 0,
		);
	}

	/**
	 * The LIKE patterns that keep a query to these folders, one per folder, for the
	 * `path LIKE %s` each query lists inline (its placeholders are built where the
	 * query is, so Plugin Check can read them).
	 *
	 * @param list<string> $folders Folders relative to `wp-content`.
	 * @return list<string>
	 */
	protected static function patterns( array $folders ): array {
		global $wpdb;

		return array_map(
			static function ( string $folder ) use ( $wpdb ): string {
				return $wpdb->esc_like( $folder . '/' ) . '%';
			},
			$folders
		);
	}
}
