<?php
/**
 * What the plugin keeps about each attachment it handles.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post meta row per attachment: where its optimization stands, the group
 * that is making it, every file the plugin wrote over with the bytes it wrote,
 * the WebP and AVIF copies it made beside them, and the backups it keeps. The
 * written bytes are how the plugin tells its own output from a file someone or
 * something else put there since, so it never sends its own output back for a
 * second lossy pass. Every copy carries its origin, so restoring, deleting the
 * copies or uninstalling only ever deletes the plugin's own.
 *
 * The record is one serialized value, which no query can read, so its status
 * and the formats its group was asked for also go in post meta of their own, for
 * the media library's filters, the queue and the bulk run, as do the bytes its
 * files had before and after, for the bulk page's savings.
 */
class Records {

	const META = '_iminify';

	const STATUS_META = '_iminify_status';

	const FORMATS_META = '_iminify_formats';

	/**
	 * The sizes a result accounts for (Missing), which only a query can find an image lacking.
	 */
	const SIZES_META = '_iminify_sizes';

	/**
	 * The bytes of the files the plugin optimized, before and after, while the record holds a result.
	 */
	const BEFORE_META = '_iminify_before';

	const AFTER_META = '_iminify_after';

	/**
	 * Every post meta key of a record.
	 */
	const ALL_META = array( self::META, self::STATUS_META, self::FORMATS_META, self::SIZES_META, self::BEFORE_META, self::AFTER_META );

	const ORIGIN = 'iminify';

	const QUEUED = 'queued';

	const WAITING = 'waiting';

	const OPTIMIZED = 'optimized';

	const NOT_OPTIMIZED = 'not_optimized';

	/**
	 * Waiting for the account's next batch, with no run scheduled (Queue).
	 */
	const PAUSED = 'paused';

	/**
	 * Waiting for the site to be connected again, with no run scheduled and any open group kept (Queue).
	 */
	const PARKED = 'parked';

	const SKIPPED = 'skipped';

	const ERROR = 'error';

	/**
	 * The status meta of an image someone restored: its record reads NOT_OPTIMIZED
	 * with the code `restored`, which the bulk run leaves out, so the image stays as
	 * the person chose (the media library's "Not optimized" filter still lists it).
	 */
	const RESTORED = 'restored';

	/**
	 * An attachment's record, every key present.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{status: string|null, trigger: string|null, level: string|null, fill: string|null, made: list<string>, priority: int|null, generation: int, group: array<string, mixed>|null, written: array<string, int>, copies: array<string, mixed>, backups: list<string>, result: array<string, mixed>|null, error: array<string, mixed>|null, attempts: int, reopens: int, updated: int}
	 */
	public static function get( int $attachment ): array {
		$record = get_post_meta( $attachment, self::META, true );
		$record = is_array( $record ) ? $record : array();

		return array(
			'status'     => isset( $record['status'] ) && is_string( $record['status'] ) ? $record['status'] : null,
			'trigger'    => isset( $record['trigger'] ) && is_string( $record['trigger'] ) ? $record['trigger'] : null,
			'level'      => isset( $record['level'] ) && is_string( $record['level'] ) ? $record['level'] : null,
			'fill'       => isset( $record['fill'] ) && is_string( $record['fill'] ) ? $record['fill'] : null,
			'made'       => isset( $record['made'] ) && is_array( $record['made'] ) ? array_values( array_map( 'strval', $record['made'] ) ) : array(),
			'priority'   => isset( $record['priority'] ) ? (int) $record['priority'] : null,
			'generation' => isset( $record['generation'] ) ? (int) $record['generation'] : 0,
			'group'      => isset( $record['group'] ) && is_array( $record['group'] ) ? $record['group'] : null,
			'written'    => isset( $record['written'] ) && is_array( $record['written'] ) ? array_map( 'intval', $record['written'] ) : array(),
			'copies'     => isset( $record['copies'] ) && is_array( $record['copies'] ) ? $record['copies'] : array(),
			'backups'    => isset( $record['backups'] ) && is_array( $record['backups'] ) ? array_values( array_map( 'strval', $record['backups'] ) ) : array(),
			'result'     => isset( $record['result'] ) && is_array( $record['result'] ) ? $record['result'] : null,
			'error'      => isset( $record['error'] ) && is_array( $record['error'] ) ? $record['error'] : null,
			'attempts'   => isset( $record['attempts'] ) ? (int) $record['attempts'] : 0,
			'reopens'    => isset( $record['reopens'] ) ? (int) $record['reopens'] : 0,
			'updated'    => isset( $record['updated'] ) ? (int) $record['updated'] : 0,
		);
	}

	/**
	 * Changes some of an attachment's record.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $changes    The keys to change.
	 * @return array<string, mixed> The record as stored.
	 */
	public static function update( int $attachment, array $changes ): array {
		$record            = array_merge( self::get( $attachment ), $changes );
		$record['updated'] = time();

		update_post_meta( $attachment, self::META, $record );

		if ( is_string( $record['status'] ) ) {
			$restored = self::NOT_OPTIMIZED === $record['status'] && is_array( $record['error'] ) && self::RESTORED === ( $record['error']['code'] ?? null );

			update_post_meta( $attachment, self::STATUS_META, $restored ? self::RESTORED : $record['status'] );
		}

		$outputs = isset( $record['result']['outputs'] ) && is_array( $record['result']['outputs'] ) ? $record['result']['outputs'] : array();

		if ( array() !== $outputs ) {
			update_post_meta( $attachment, self::BEFORE_META, (int) array_sum( array_map( 'intval', array_column( $outputs, 'before' ) ) ) );
			update_post_meta( $attachment, self::AFTER_META, (int) array_sum( array_map( 'intval', array_column( $outputs, 'after' ) ) ) );
		} else {
			delete_post_meta( $attachment, self::BEFORE_META );
			delete_post_meta( $attachment, self::AFTER_META );
		}

		if ( isset( $record['result']['formats'] ) && is_array( $record['result']['formats'] ) ) {
			update_post_meta( $attachment, self::FORMATS_META, '|' . implode( '|', $record['result']['formats'] ) . '|' );
		} else {
			delete_post_meta( $attachment, self::FORMATS_META );
		}

		if ( isset( $record['result']['sizes'] ) && is_array( $record['result']['sizes'] ) ) {
			update_post_meta( $attachment, self::SIZES_META, '|' . implode( '|', $record['result']['sizes'] ) . '|' );
		} else {
			delete_post_meta( $attachment, self::SIZES_META );
		}

		return $record;
	}

	/**
	 * Marks the attachment's files as changed, so a group already open for the
	 * files it had writes nothing over the new ones, and the new files get
	 * their own reopens, their own place in the queue and the site's own level.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function bump( int $attachment ): int {
		$generation = self::get( $attachment )['generation'] + 1;

		self::update(
			$attachment,
			array(
				'generation' => $generation,
				'reopens'    => 0,
				'level'      => null,
				'fill'       => null,
				'made'       => array(),
				'priority'   => null,
			)
		);

		return $generation;
	}

	/**
	 * Whether the file at a path is one the plugin wrote: its record names the
	 * path, and the file still has the bytes the plugin wrote there.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function wrote( int $attachment, string $path ): bool {
		$relative = Paths::relative( $path );
		$written  = self::get( $attachment )['written'];

		if ( null === $relative || ! isset( $written[ $relative ] ) ) {
			return false;
		}

		/* PHP 7.4 keeps a file's size from before a write in the same run. */
		clearstatcache( true, $path );

		if ( ! is_file( $path ) ) {
			return false;
		}

		return (int) filesize( $path ) === $written[ $relative ];
	}

	/**
	 * Records why an attachment waits, keeping an open group: its image is already
	 * counted, so it is finished rather than opened again once the wait is over.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $status     What it waits for: PAUSED or PARKED.
	 * @param string $code       The reason's code.
	 * @param string $message    The reason, in words.
	 */
	public static function wait( int $attachment, string $status, string $code, string $message ): void {
		self::update(
			$attachment,
			array(
				'status' => $status,
				'error'  => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * The attachments with one of these statuses, in the order of their ids.
	 *
	 * @param list<string> $statuses The statuses.
	 * @param int          $limit    How many at most.
	 * @param int          $after    The id to start after.
	 * @return list<int>
	 */
	public static function with_status( array $statuses, int $limit, int $after = 0 ): array {
		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a background read by the meta_key index; the placeholders are built above, one per status.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$in}) AND post_id > %d ORDER BY post_id ASC LIMIT %d", array_merge( array( self::STATUS_META ), $statuses, array( $after, $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many attachments have one of these statuses.
	 *
	 * @param list<string> $statuses The statuses.
	 */
	public static function count_with_status( array $statuses ): int {
		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count by the meta_key index, on the plugin's own page; the placeholders are built above, one per status.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$in})", array_merge( array( self::STATUS_META ), $statuses ) ) );
	}

	/**
	 * The optimized images whose meta doesn't name all of these values, with no meta
	 * at all among them, in the order of their ids.
	 *
	 * @param string       $meta  FORMATS_META or SIZES_META.
	 * @param list<string> $names The values.
	 * @param int          $limit How many at most.
	 * @param int          $after The id to start after.
	 * @return list<int>
	 */
	public static function lacking( string $meta, array $names, int $limit, int $after = 0 ): array {
		global $wpdb;

		if ( array() === $names ) {
			return array();
		}

		$lacks = implode( ' OR ', array_fill( 0, count( $names ), 'z.meta_value NOT LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.LikeWithoutWildcards -- a background read by the meta_key index; one placeholder per value, built above, each with its wildcards.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT s.post_id FROM {$wpdb->postmeta} s INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_status <> 'trash' LEFT JOIN {$wpdb->postmeta} z ON z.post_id = s.post_id AND z.meta_key = %s WHERE s.meta_key = %s AND s.meta_value = %s AND s.post_id > %d AND ( z.meta_id IS NULL OR {$lacks} ) ORDER BY s.post_id ASC LIMIT %d", array_merge( array( $meta, self::STATUS_META, self::OPTIMIZED, $after ), self::likes( $names ), array( $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many images `lacking()` finds.
	 *
	 * @param string       $meta  FORMATS_META or SIZES_META.
	 * @param list<string> $names The values.
	 */
	public static function count_lacking( string $meta, array $names ): int {
		global $wpdb;

		if ( array() === $names ) {
			return 0;
		}

		$lacks = implode( ' OR ', array_fill( 0, count( $names ), 'z.meta_value NOT LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.LikeWithoutWildcards -- a count by the meta_key index, on the plugin's own page; one placeholder per value, built above, each with its wildcards.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} s INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_status <> 'trash' LEFT JOIN {$wpdb->postmeta} z ON z.post_id = s.post_id AND z.meta_key = %s WHERE s.meta_key = %s AND s.meta_value = %s AND ( z.meta_id IS NULL OR {$lacks} )", array_merge( array( $meta, self::STATUS_META, self::OPTIMIZED ), self::likes( $names ) ) ) );
	}

	/**
	 * The patterns that find a value in a meta written as `|a|b|`, whose underscores would otherwise match any letter.
	 *
	 * @param list<string> $names The values.
	 * @return list<string>
	 */
	protected static function likes( array $names ): array {
		global $wpdb;

		return array_map(
			static function ( string $name ) use ( $wpdb ): string {
				return '%' . $wpdb->esc_like( '|' . $name . '|' ) . '%';
			},
			$names
		);
	}

	/**
	 * The bytes before and after across every image the plugin optimized.
	 *
	 * @return array{before: int, after: int}
	 */
	public static function saved(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a sum by the meta_key index, on the plugin's own page.
		$sums = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) ELSE 0 END) AS before_bytes, SUM(CASE WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) ELSE 0 END) AS after_bytes FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", self::BEFORE_META, self::AFTER_META, self::BEFORE_META, self::AFTER_META ), ARRAY_A );

		return array(
			'before' => is_array( $sums ) ? (int) $sums['before_bytes'] : 0,
			'after'  => is_array( $sums ) ? (int) $sums['after_bytes'] : 0,
		);
	}

	/**
	 * Records a failure to show for the attachment. An image that was only sent for
	 * what it lacked (Missing) is still optimized, whatever the failure: its files,
	 * its copies and its savings are all as they were, so it reads optimized, and
	 * Restore all still takes it, with the reason beside it.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $status     Where it stands now.
	 * @param string $code       The reason's code.
	 * @param string $message    The reason, in words.
	 */
	public static function fail( int $attachment, string $status, string $code, string $message ): void {
		$filling = null !== self::get( $attachment )['fill'];

		self::update(
			$attachment,
			array_merge(
				array(
					'status' => $filling ? self::OPTIMIZED : $status,
					'group'  => null,
					'error'  => array(
						'code'    => $code,
						'message' => $message,
					),
				),
				$filling ? array(
					'fill'  => null,
					'made'  => array(),
					'level' => null,
				) : array()
			)
		);
	}

	/**
	 * Puts an image that was sent for what it lacked back as it was, with no reason to show: a stop, or nothing left to make.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function end_fill( int $attachment ): void {
		self::update(
			$attachment,
			array(
				'status' => self::OPTIMIZED,
				'fill'   => null,
				'made'   => array(),
				'level'  => null,
				'group'  => null,
				'error'  => null,
			)
		);
	}
}
