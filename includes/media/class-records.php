<?php
/**
 * What the plugin keeps about each attachment it handles.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Compat\Offload_Media;

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
 * An image another optimizer compressed and someone asked to optimize again from
 * the original that plugin kept reads `redo` until its files are written: it is
 * put back from that original first, and keeps a backup of it whatever the
 * setting says.
 *
 * An image another optimizer converted into WebP or AVIF and the plugin
 * optimized again from the JPEG or PNG that plugin kept keeps `converted`: the
 * kept file's path (whose backup the plugin holds under its own name), the
 * plugin's short name and the format the attachment's files are in. Its backup is
 * in another format than its files, so it is never put over them, and every later
 * send makes the files in their own format from it (`Originals::converted()`).
 *
 * Right before a group's files are renamed into place, `pending` names them with
 * the bytes they are about to have (`Writer::put()`), and the write's own save
 * empties it. A request that dies between the two leaves it, and `get()` takes
 * each file that has those bytes as the plugin's own (`Writer::settle()`).
 *
 * An attachment that shares its files with another (a translation's, `Shared`)
 * keeps only `holder`, the id of the one whose record holds the work on them,
 * so `<picture>` finds that one's copies with no lookup by file name.
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
	 * The other plugin that optimized an image, and whether its original is kept
	 * (`imagify:1`), while the record reads OTHER: what the bulk page counts.
	 */
	const OTHER_META = '_iminify_other';

	/**
	 * Where an AI alt text stands (`Alt_Text`), which a query has to find: the ones
	 * still waiting, once the plugin comes back on.
	 */
	const ALT_META = '_iminify_alt';

	/**
	 * Every post meta key of a record.
	 */
	const ALL_META = array( self::META, self::STATUS_META, self::FORMATS_META, self::SIZES_META, self::BEFORE_META, self::AFTER_META, self::OTHER_META, self::ALT_META );

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
	 * Another plugin optimized the image (`Switching\Others`), which the record
	 * keeps in `other`: it costs nothing and is never sent again unless someone asks.
	 */
	const OTHER = 'other';

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
	 * @return array{status: string|null, trigger: string|null, level: string|null, fill: string|null, made: list<string>, priority: int|null, generation: int, group: array<string, mixed>|null, written: array<string, int>, copies: array<string, mixed>, pending: array{written: array<string, int>, copies: array<string, array<string, mixed>>}|null, backups: list<string>, fetched: array<string, array<string, mixed>>, result: array<string, mixed>|null, error: array<string, mixed>|null, attempts: int, reopens: int, holder: int|null, other: array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}|null, redo: bool, converted: array{file: string, plugin: string, type: string}|null, alt: array{status: string, group: string|null, request: string|null, since: int, language: string|null, tries: int}|null, updated: int}
	 */
	public static function get( int $attachment ): array {
		$record = get_post_meta( $attachment, self::META, true );
		$record = is_array( $record ) ? $record : array();

		/* What a write that died before its save of the record left in place (`Writer::put()`). */
		$settled = Writer::settle(
			isset( $record['written'] ) && is_array( $record['written'] ) ? array_map( 'intval', $record['written'] ) : array(),
			isset( $record['copies'] ) && is_array( $record['copies'] ) ? $record['copies'] : array(),
			$record['pending'] ?? null,
			static function ( string $file ) use ( $attachment ): ?int {
				return Offload_Media::bytes( $attachment, Paths::absolute( $file ) );
			}
		);

		return array(
			'status'     => isset( $record['status'] ) && is_string( $record['status'] ) ? $record['status'] : null,
			'trigger'    => isset( $record['trigger'] ) && is_string( $record['trigger'] ) ? $record['trigger'] : null,
			'level'      => isset( $record['level'] ) && is_string( $record['level'] ) ? $record['level'] : null,
			'fill'       => isset( $record['fill'] ) && is_string( $record['fill'] ) ? $record['fill'] : null,
			'made'       => isset( $record['made'] ) && is_array( $record['made'] ) ? array_values( array_map( 'strval', $record['made'] ) ) : array(),
			'priority'   => isset( $record['priority'] ) ? (int) $record['priority'] : null,
			'generation' => isset( $record['generation'] ) ? (int) $record['generation'] : 0,
			'group'      => isset( $record['group'] ) && is_array( $record['group'] ) ? $record['group'] : null,
			'written'    => $settled['written'],
			'copies'     => $settled['copies'],
			'pending'    => $settled['pending'],
			'backups'    => isset( $record['backups'] ) && is_array( $record['backups'] ) ? array_values( array_map( 'strval', $record['backups'] ) ) : array(),
			'fetched'    => isset( $record['fetched'] ) && is_array( $record['fetched'] ) ? array_filter( $record['fetched'], 'is_array' ) : array(),
			'result'     => isset( $record['result'] ) && is_array( $record['result'] ) ? $record['result'] : null,
			'error'      => isset( $record['error'] ) && is_array( $record['error'] ) ? $record['error'] : null,
			'attempts'   => isset( $record['attempts'] ) ? (int) $record['attempts'] : 0,
			'reopens'    => isset( $record['reopens'] ) ? (int) $record['reopens'] : 0,
			'holder'     => isset( $record['holder'] ) && (int) $record['holder'] > 0 ? (int) $record['holder'] : null,
			'other'      => self::other( $record['other'] ?? null ),
			'redo'       => ! empty( $record['redo'] ),
			'converted'  => self::converted( $record['converted'] ?? null ),
			'alt'        => self::alt( $record['alt'] ?? null ),
			'updated'    => isset( $record['updated'] ) ? (int) $record['updated'] : 0,
		);
	}

	/**
	 * The mark of an image optimized again from another optimizer's original in
	 * another format than its files, as a record keeps it, or null.
	 *
	 * @param mixed $converted The record's `converted`.
	 * @return array{file: string, plugin: string, type: string}|null
	 */
	protected static function converted( $converted ): ?array {
		if ( ! is_array( $converted ) || empty( $converted['file'] ) || empty( $converted['plugin'] ) || empty( $converted['type'] ) || ! is_string( $converted['file'] ) || ! is_string( $converted['plugin'] ) || ! is_string( $converted['type'] ) ) {
			return null;
		}

		return array(
			'file'   => $converted['file'],
			'plugin' => sanitize_key( $converted['plugin'] ),
			'type'   => $converted['type'],
		);
	}

	/**
	 * Where an AI alt text stands, as a record keeps it, or null when none was asked for (`Alt_Text`).
	 *
	 * @param mixed $alt The record's `alt`.
	 * @return array{status: string, group: string|null, request: string|null, since: int, language: string|null, tries: int}|null
	 */
	protected static function alt( $alt ): ?array {
		if ( ! is_array( $alt ) || empty( $alt['status'] ) || ! is_string( $alt['status'] ) ) {
			return null;
		}

		return array(
			'status'   => $alt['status'],
			'group'    => isset( $alt['group'] ) && is_string( $alt['group'] ) ? $alt['group'] : null,
			'request'  => isset( $alt['request'] ) && is_string( $alt['request'] ) ? $alt['request'] : null,
			'since'    => (int) ( $alt['since'] ?? 0 ),
			'language' => isset( $alt['language'] ) && is_string( $alt['language'] ) ? $alt['language'] : null,
			'tries'    => (int) ( $alt['tries'] ?? 0 ),
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

		/* Whose an image is holds while it reads as another plugin's, and while it waits to be put back from that plugin's original (`redo`), so the plugin's memory of that plugin's output still guards it if that plugin deletes its records meanwhile. */
		if ( self::OTHER !== $record['status'] && empty( $record['redo'] ) ) {
			$record['other'] = null;
		}

		update_post_meta( $attachment, self::META, $record );

		$other = self::OTHER === $record['status'] ? self::other( $record['other'] ) : null;

		if ( null !== $other ) {
			update_post_meta( $attachment, self::OTHER_META, $other['plugin'] . ':' . ( $other['original'] ? '1' : '0' ) );
		} else {
			delete_post_meta( $attachment, self::OTHER_META );
		}

		$alt = self::alt( $record['alt'] ?? null );

		if ( null !== $alt ) {
			update_post_meta( $attachment, self::ALT_META, $alt['status'] );
		} else {
			delete_post_meta( $attachment, self::ALT_META );
		}

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
	 * Another plugin's optimization as a record keeps it, or null.
	 *
	 * @param mixed $other The record's `other`.
	 * @return array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}|null
	 */
	protected static function other( $other ): ?array {
		if ( ! is_array( $other ) || ! isset( $other['plugin'] ) || ! is_string( $other['plugin'] ) || '' === $other['plugin'] ) {
			return null;
		}

		$outputs = array();

		foreach ( isset( $other['outputs'] ) && is_array( $other['outputs'] ) ? $other['outputs'] : array() as $path => $bytes ) {
			$outputs[ (string) $path ] = (int) $bytes;
		}

		return array(
			'plugin'   => sanitize_key( $other['plugin'] ),
			'before'   => (int) ( $other['before'] ?? 0 ),
			'after'    => (int) ( $other['after'] ?? 0 ),
			'files'    => (int) ( $other['files'] ?? 0 ),
			'original' => ! empty( $other['original'] ),
			'outputs'  => $outputs,
		);
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
				'redo'       => false,
				'pending'    => null,
			)
		);

		return $generation;
	}

	/**
	 * Whether the file at a path is one the plugin wrote: its record names the
	 * path, and the file still has the bytes the plugin wrote there (for a file
	 * only in WP Offload Media's bucket, the bytes the metadata gives it).
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

		return Offload_Media::bytes( $attachment, $path ) === $written[ $relative ];
	}

	/**
	 * Whether an optimized attachment's files are all as its result left them: the
	 * main file and every size the result accounts for has the bytes it had after
	 * (read from the metadata for a file only in WP Offload Media's bucket), so an
	 * event that changed none of them (a regeneration that skipped every size
	 * it found) has nothing to send. A size the result doesn't name was never sent,
	 * and what it lacks is `Missing`'s to find.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function unchanged( int $attachment ): bool {
		$record   = self::get( $attachment );
		$outputs  = isset( $record['result']['outputs'] ) && is_array( $record['result']['outputs'] ) ? $record['result']['outputs'] : array();
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$main     = (string) get_attached_file( $attachment, true );

		if ( self::OPTIMIZED !== $record['status'] || ! isset( $outputs[ Declaration::MAIN ] ) || ! is_array( $metadata ) || '' === $main ) {
			return false;
		}

		$files = array( Declaration::MAIN => $main );

		foreach ( $metadata['sizes'] ?? array() as $name => $size ) {
			if ( isset( $outputs[ $name ] ) ) {
				$files[ $name ] = dirname( $main ) . '/' . wp_basename( $size['file'] );
			}
		}

		foreach ( $files as $name => $path ) {
			if ( Offload_Media::bytes( $attachment, $path ) !== (int) ( $outputs[ $name ]['after'] ?? -1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Records why an attachment waits, keeping an open group: its image is already
	 * counted, so it is finished rather than opened again once the wait is over.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param string               $status     What it waits for: PAUSED or PARKED.
	 * @param array<string, mixed> $reason     Why, as `Reasons` makes it.
	 */
	public static function wait( int $attachment, string $status, array $reason ): void {
		self::update(
			$attachment,
			array(
				'status' => $status,
				'error'  => $reason,
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
	 * The attachments whose AI alt text is on its way, waiting to go out or to come back, in the order of their ids.
	 *
	 * @param int $limit How many at most.
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	public static function waiting_for_alt_text( int $limit, int $after = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a background read by the meta_key index.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (%s, %s) AND post_id > %d ORDER BY post_id ASC LIMIT %d", self::ALT_META, Alt_Text::QUEUED, Alt_Text::WAITING, $after, $limit ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many attachments' AI alt text is on its way, for a page's poll.
	 */
	public static function count_waiting_for_alt_text(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count by the meta_key index.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (%s, %s)", self::ALT_META, Alt_Text::QUEUED, Alt_Text::WAITING ) );
	}

	/**
	 * A page of the images a bulk run could ask an alt text for, in the order of their
	 * ids: those with an empty alt, leaving out what is on its way already (a text being
	 * written, a translation set aside, or the image's own optimization, whose group
	 * will ask for one by itself). A translation still reads here until a run has
	 * looked at it, since only that finds out which attachment holds the files.
	 *
	 * @param int $limit How many at most.
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	public static function lacking_alt( int $limit, int $after = 0 ): array {
		global $wpdb;

		$types = Declaration::MIME_TYPES;
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a background read by the primary key; one placeholder per type, built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ({$in}) AND ( a.meta_id IS NULL OR TRIM(a.meta_value) = '' ) AND ( s.meta_id IS NULL OR s.meta_value NOT IN (%s, %s, %s) ) AND ( r.meta_id IS NULL OR r.meta_value NOT IN (%s, %s, %s, %s) ) AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", array_merge( array( Alt_Text::META, self::ALT_META, self::STATUS_META ), $types, array( Alt_Text::QUEUED, Alt_Text::WAITING, Alt_Text::SHARED ), array( self::QUEUED, self::WAITING, self::PAUSED, self::PARKED ), array( $after, $limit ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many images `lacking_alt()` finds.
	 */
	public static function count_lacking_alt(): int {
		global $wpdb;

		$types = Declaration::MIME_TYPES;
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count on the plugin's own page; one placeholder per type, built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ({$in}) AND ( a.meta_id IS NULL OR TRIM(a.meta_value) = '' ) AND ( s.meta_id IS NULL OR s.meta_value NOT IN (%s, %s, %s) ) AND ( r.meta_id IS NULL OR r.meta_value NOT IN (%s, %s, %s, %s) )", array_merge( array( Alt_Text::META, self::ALT_META, self::STATUS_META ), $types, array( Alt_Text::QUEUED, Alt_Text::WAITING, Alt_Text::SHARED ), array( self::QUEUED, self::WAITING, self::PAUSED, self::PARKED ) ) ) );
	}

	/**
	 * A page of the attachments the plugin keeps a record of, in the order of their ids.
	 *
	 * @param int $after The id to start after.
	 * @param int $limit How many.
	 * @return list<int>
	 */
	public static function recorded( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a background read by the meta_key index.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d", self::META, $after, $limit ) );

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
	 * @param int                  $attachment The attachment's id.
	 * @param string               $status     Where it stands now.
	 * @param array<string, mixed> $reason     Why, as `Reasons` makes it.
	 */
	public static function fail( int $attachment, string $status, array $reason ): void {
		$filling = null !== self::get( $attachment )['fill'];

		self::update(
			$attachment,
			array_merge(
				array(
					'status' => $filling ? self::OPTIMIZED : $status,
					'group'  => null,
					'error'  => $reason,
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
