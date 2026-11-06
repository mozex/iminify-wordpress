<?php
/**
 * Attachments that share their files, as a translation plugin makes them.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPML and Polylang translate an image by making another attachment that names
 * the same file (`_wp_attached_file`), so two records would speak for one set of
 * files. The second would take the first's output for its original, compress it
 * again and keep it as its backup, moving the real backup aside into a record
 * that deleting it would empty. So one attachment holds the plugin's work on the
 * files: the one whose record has that work, or, while none has, the lowest id.
 * The others send nothing (`Optimizer::why_not()`), anything that changes their
 * files queues the holder (`Hooks`), and deleting one deletes nothing the files
 * still need: the holder's record goes to the next one instead.
 *
 * Post meta has no index on its value, so finding who names a file reads every
 * attachment's row, as core's `attachment_url_to_postid()` does. The paths that
 * write or delete files ask afresh every time (`$fresh`), so no cached answer can
 * send a translation's files; everything else (the media screens, the bulk page's
 * counts) reads an answer kept for the request, which an attachment's file
 * changing, a WP-CLI look and each Action Scheduler action drop, and whose worst
 * error is a button the action then refuses.
 *
 * Each of the others keeps the holder's id in its record (`holder`), so a page
 * showing a translation serves the holder's copies (`Picture`) without asking
 * who names the file. It is written wherever the holder is already known: when
 * the holder's work is written, when a run sets one aside as sharing its files,
 * when its files change, when the holder's record moves on, and when an
 * attachment comes to name a file the plugin has worked on. `Picture` takes it
 * only while both still name the same file, so one left behind serves nothing.
 */
class Shared {

	/**
	 * The attachments naming each file this request asked about, by blog and file.
	 *
	 * @var array<string, list<int>>
	 */
	protected static $named = array();

	/**
	 * Drops the kept answers whenever an attachment's file is named, renamed or
	 * unnamed, and before each Action Scheduler action, which may run long after
	 * the request that started the batch read them.
	 */
	public static function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'file_changed' ), 10, 4 );
		}

		add_action( 'action_scheduler_before_execute', array( self::class, 'forget' ) );
		add_filter( 'the_posts', array( self::class, 'prime_posts' ) );
	}

	/**
	 * The meta hooks: drops the kept answers when the meta is an attachment's
	 * file. An attachment coming to name a file the plugin has worked on (a
	 * translation WPML or Polylang just made of an optimized image) is pointed at
	 * the holder. Asking who names the file costs a query, so it is asked only
	 * when the file has a copy or a backup of the plugin's kind, which a new
	 * upload's unique name never has.
	 *
	 * @param mixed $meta_ids  The meta rows.
	 * @param mixed $object_id The post's id.
	 * @param mixed $meta_key  The meta key.
	 * @param mixed $value     The meta's value.
	 */
	public static function file_changed( $meta_ids, $object_id, $meta_key, $value = null ): void {
		if ( '_wp_attached_file' !== $meta_key ) {
			return;
		}

		self::forget();

		if ( ! is_string( $value ) || '' === $value || ! self::worked_on( $value ) ) {
			return;
		}

		$attachment = (int) $object_id;
		$sharing    = array_merge( array( $attachment ), self::others( $attachment, true ) );

		if ( count( $sharing ) > 1 ) {
			self::point( self::first_holder( $sharing ), $sharing );
		}
	}

	/**
	 * Writes the holder's id into the records of the others sharing its files,
	 * where it isn't already, and takes it out of the holder's own.
	 *
	 * @param int       $holder      The attachment holding the work.
	 * @param list<int> $attachments The attachments sharing its files, the holder among them or not.
	 */
	public static function point( int $holder, array $attachments ): void {
		foreach ( array_unique( array_merge( array( $holder ), $attachments ) ) as $attachment ) {
			$pointer = $attachment === $holder ? null : $holder;

			if ( Records::get( $attachment )['holder'] !== $pointer ) {
				Records::update( $attachment, array( 'holder' => $pointer ) );
			}
		}
	}

	/**
	 * Whether a file, relative to the uploads folder, has a WebP or AVIF copy beside
	 * it or a backup in the plugin's backup folder: the marks the plugin's work
	 * leaves on an image's main file.
	 *
	 * @param string $file The file, relative to the uploads folder.
	 */
	protected static function worked_on( string $file ): bool {
		if ( false !== strpos( '/' . $file . '/', '/../' ) ) {
			return false;
		}

		$path = Paths::absolute( $file );

		return is_file( $path . '.webp' ) || is_file( $path . '.avif' ) || is_file( Paths::backup( $file ) );
	}

	/**
	 * Drops the answers kept for this request.
	 */
	public static function forget(): void {
		self::$named = array();
	}

	/**
	 * The other attachments, by id in order, that name an attachment's file exactly.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to ask the database rather than this request's answer.
	 * @return list<int>
	 */
	public static function others( int $attachment, bool $fresh = false ): array {
		$file = get_post_meta( $attachment, '_wp_attached_file', true );

		if ( ! is_string( $file ) || '' === $file ) {
			return array();
		}

		return array_values( array_diff( self::naming( $file, $fresh ), array( $attachment ) ) );
	}

	/**
	 * The attachment that holds the plugin's work on an attachment's files: itself
	 * when no other shares them.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to ask the database rather than this request's answer.
	 */
	public static function holder( int $attachment, bool $fresh = false ): int {
		$others = self::others( $attachment, $fresh );

		return array() === $others ? $attachment : self::first_holder( array_merge( array( $attachment ), $others ) );
	}

	/**
	 * Whether an attachment's record holds work on its files: something the plugin
	 * wrote, copied or backed up, or a send on its way.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function holds_work( int $attachment ): bool {
		$record = Records::get( $attachment );

		return array() !== $record['written']
			|| array() !== Copies::own( $record['copies'] )
			|| array() !== $record['backups']
			|| null !== $record['group']
			|| in_array( $record['status'], array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ), true );
	}

	/**
	 * Before an attachment is deleted: answers whether others still share its
	 * files, in which case nothing of the plugin's may go with it. A holder gives
	 * its record to the next holder when it held the work, so the backups and copies
	 * stay proven as the plugin's, and the others set aside as sharing its files are
	 * looked at again, since the holder they named is gone.
	 *
	 * @param int $attachment The attachment being deleted.
	 */
	public static function leaves_files( int $attachment ): bool {
		$others = self::others( $attachment, true );

		if ( array() === $others ) {
			return false;
		}

		if ( self::first_holder( array_merge( array( $attachment ), $others ) ) !== $attachment ) {
			return true;
		}

		foreach ( $others as $other ) {
			$record = Records::get( $other );

			if ( Records::SKIPPED === $record['status'] && 'shared' === ( $record['error']['code'] ?? null ) ) {
				Records::update(
					$other,
					array(
						'status' => Records::NOT_OPTIMIZED,
						'error'  => null,
					)
				);
			}
		}

		$next = self::first_holder( $others );

		if ( self::holds_work( $attachment ) ) {
			$record = Records::get( $attachment );
			$send   = in_array( $record['status'], array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ), true );

			/* A send on its way belongs to the deleted one's runs, which are cancelled with it; the next holder sends again. */
			Records::update(
				$next,
				array_merge(
					$record,
					array(
						'group'  => null,
						'status' => $send ? Records::NOT_OPTIMIZED : $record['status'],
					)
				)
			);

			if ( $send ) {
				Optimizer::queue( $next, Optimizer::trigger( $record['trigger'] ) );
			}
		}

		self::point( $next, $others );

		return true;
	}

	/**
	 * Answers, for this request, who names each of these attachments' files, in one
	 * query rather than one per image: a media screen or a bulk page about to ask
	 * for every image it shows calls it first.
	 *
	 * @param list<int> $attachments The attachments' ids.
	 */
	public static function prime( array $attachments ): void {
		$files = array();

		foreach ( $attachments as $attachment ) {
			$file = get_post_meta( (int) $attachment, '_wp_attached_file', true );

			if ( is_string( $file ) && '' !== $file ) {
				$files[] = $file;
			}
		}

		self::named( $files );
	}

	/**
	 * The attachments, by id in order, that name each of these files exactly,
	 * asking the database in one query about those this request hasn't asked
	 * about yet: a page's whole-page buffer (`Delivery\Buffer`) finds every image
	 * it names this way.
	 *
	 * @param list<string> $files Files relative to the uploads folder.
	 * @return array<string, list<int>>
	 */
	public static function named( array $files ): array {
		$blog = get_current_blog_id();
		$ask  = array();

		foreach ( $files as $file ) {
			if ( '' !== $file && ! isset( self::$named[ $blog . ':' . $file ] ) ) {
				$ask[ $file ] = array();
			}
		}

		if ( array() !== $ask ) {
			global $wpdb;

			$placeholders = implode( ', ', array_fill( 0, count( $ask ), '%s' ) );
			$values       = array_merge( array( '_wp_attached_file', 'attachment' ), array_map( 'strval', array_keys( $ask ) ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one lookup for a page of images, kept for the request; one placeholder per file, built above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta.post_id, meta.meta_value FROM {$wpdb->postmeta} AS meta INNER JOIN {$wpdb->posts} AS posts ON posts.ID = meta.post_id WHERE meta.meta_key = %s AND posts.post_type = %s AND meta.meta_value IN ({$placeholders}) ORDER BY meta.post_id", $values ), ARRAY_A );

			/* The column ignores case and accents, so only the exact name counts. */
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( isset( $ask[ (string) $row['meta_value'] ] ) ) {
					$ask[ (string) $row['meta_value'] ][] = (int) $row['post_id'];
				}
			}

			foreach ( $ask as $file => $ids ) {
				self::$named[ $blog . ':' . $file ] = $ids;
			}
		}

		$named = array();

		foreach ( $files as $file ) {
			$named[ $file ] = self::$named[ $blog . ':' . $file ] ?? array();
		}

		return $named;
	}

	/**
	 * The `the_posts` filter on the media screens: answers who names the files of
	 * the attachments a query found, so drawing each one asks nothing more.
	 *
	 * @param mixed $posts The posts the query found.
	 * @return mixed
	 */
	public static function prime_posts( $posts ) {
		if ( is_array( $posts ) && ( is_admin() || wp_doing_ajax() ) ) {
			$ids = array();

			foreach ( $posts as $post ) {
				if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
					$ids[] = $post->ID;
				}
			}

			self::prime( $ids );
		}

		return $posts;
	}

	/**
	 * The attachments, by id in order, that name a file exactly. The column ignores
	 * case and accents, so `Logo.png` would match `logo.png`, which on most servers
	 * is another file: the match is checked again here, byte for byte.
	 *
	 * @param string $file  The file, relative to the uploads folder.
	 * @param bool   $fresh Whether to ask the database rather than this request's answer.
	 * @return list<int>
	 */
	protected static function naming( string $file, bool $fresh ): array {
		$key = get_current_blog_id() . ':' . $file;

		if ( ! $fresh && isset( self::$named[ $key ] ) ) {
			return self::$named[ $key ];
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the lookup core's attachment_url_to_postid() makes; the answer is kept for the request above.
			$wpdb->prepare(
				"SELECT meta.post_id, meta.meta_value FROM {$wpdb->postmeta} AS meta INNER JOIN {$wpdb->posts} AS posts ON posts.ID = meta.post_id WHERE meta.meta_key = %s AND meta.meta_value = %s AND posts.post_type = %s ORDER BY meta.post_id",
				'_wp_attached_file',
				$file,
				'attachment'
			),
			ARRAY_A
		);
		$ids  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $file === (string) $row['meta_value'] ) {
				$ids[] = (int) $row['post_id'];
			}
		}

		self::$named[ $key ] = $ids;

		return $ids;
	}

	/**
	 * The first of these attachments whose record holds work, or the lowest id.
	 *
	 * @param list<int> $attachments The attachments sharing the files.
	 */
	protected static function first_holder( array $attachments ): int {
		sort( $attachments );

		foreach ( $attachments as $attachment ) {
			if ( self::holds_work( $attachment ) ) {
				return $attachment;
			}
		}

		return $attachments[0];
	}
}
