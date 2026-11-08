<?php
/**
 * The other image optimizers a site had before, and the one it may still run.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Declaration;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks each reader (`Reader`) what its plugin left on the site.
 *
 * An attachment whose attached file still holds another plugin's output reads as
 * optimized by that plugin: it costs nothing, is never sent again
 * unless someone asks, and its pages and counts say whose it is. That is checked
 * afresh wherever an image could be sent (`Optimizer::why_not()`), which is what
 * keeps another plugin's output from a second lossy pass; the plugin's own record
 * of it (status `other`, `Records::OTHER_META`) is only for the screens and the
 * counts, which have to find such images by a query. A look over the library
 * (`look()`, then `sweep()` in the background) writes that record when another
 * plugin's records change, and takes it off an image no reader claims any more.
 *
 * While another optimizer can act on uploads (`acting()`) the plugin
 * optimizes nothing, on upload or in bulk; restoring and serving copies go on.
 *
 * @phpstan-import-type Found from Reader
 * @phpstan-type Trace array{plugin: string, files: array<string, array{size: string, before: int, after: int}>, outputs: list<string>, backups: array<string, string>, copies: array<string, string>, outside: array<string, list<string>>, elsewhere: string}
 */
class Others {

	/**
	 * The background look over the library, a page at a time.
	 */
	const SWEEP_HOOK = 'iminify_look_at_others';

	/**
	 * What the last look found, so the next one is taken only when another plugin's records changed.
	 */
	const STAMP_OPTION = 'iminify_others_stamp';

	/**
	 * Why an image isn't sent: another plugin optimized it.
	 */
	const CODE = 'other_plugin';

	/**
	 * Why nothing is sent: another optimizer acts on uploads.
	 */
	const ACTING = 'other_optimizer';

	const PAGE_SIZE = 100;

	/**
	 * How long one look over the library may run in a request, in seconds.
	 */
	const BUDGET = 10;

	/**
	 * How long the look a page asks for may hold that page, in seconds.
	 */
	const PAGE_BUDGET = 2;

	/**
	 * The readers, in the order they are asked.
	 *
	 * @var list<Reader>|null
	 */
	protected static $readers = null;

	/**
	 * Which plugin optimized each file in another folder, by path, as asked in this
	 * request (empty for none), so a run's page asks once.
	 *
	 * @var array<string, string>
	 */
	protected static $owners = array();

	/**
	 * What each attachment's readers answered in this request, by site and id.
	 *
	 * @var array<string, Trace|false>
	 */
	protected static $traces = array();

	/**
	 * Hooks the look into WordPress.
	 */
	public static function register(): void {
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep' ) );
		add_action( 'deactivated_plugin', array( self::class, 'deactivated' ) );
		add_action( 'deleted_plugin', array( self::class, 'deleted' ) );
		add_filter( 'the_posts', array( self::class, 'prime_posts' ) );
	}

	/**
	 * Every reader, those of the plugins whose records it reads first.
	 *
	 * @return list<Reader>
	 */
	public static function readers(): array {
		if ( null === self::$readers ) {
			self::$readers = array(
				new Imagify(),
				new Ewww(),
				new Shortpixel(),
				new Smush(),
				new Tinypng(),
				new Elementor(),
				new Converter(),
				new Optimole(),
				new Modern_Formats(),
				new Litespeed(),
			);
		}

		return self::$readers;
	}

	/**
	 * A reader by its plugin's short name, or null.
	 *
	 * @param string $slug The short name.
	 */
	public static function reader( string $slug ): ?Reader {
		foreach ( self::readers() as $reader ) {
			if ( $reader->slug() === $slug ) {
				return $reader;
			}
		}

		return null;
	}

	/**
	 * The other optimizer that can act on the library now, or null.
	 */
	public static function acting(): ?Reader {
		foreach ( self::readers() as $reader ) {
			if ( $reader->acts() ) {
				return $reader;
			}
		}

		return null;
	}

	/**
	 * The reason a record gives for its image, as it reads now: a hold for another
	 * optimizer (ACTING) names whichever acts at this moment, and gives none once
	 * nothing does, since the image is no longer held back; any other reads as
	 * recorded.
	 *
	 * @param mixed $error The record's `error`.
	 */
	public static function reason( $error ): ?string {
		if ( ! is_array( $error ) || ! isset( $error['message'] ) || ! is_string( $error['message'] ) ) {
			return null;
		}

		if ( self::ACTING !== ( $error['code'] ?? null ) ) {
			return $error['message'];
		}

		$acting = self::acting();

		return null === $acting ? null : self::acting_message( $acting );
	}

	/**
	 * Why the plugin holds back while another optimizer can act, in words: who, and what to switch off.
	 *
	 * @param Reader $reader The optimizer that acts.
	 */
	public static function acting_message( Reader $reader ): string {
		return sprintf(
			/* translators: 1: another image optimizer's name, 2: what to switch off in it, a sentence. */
			__( '%1$s is active and optimizes your uploads itself, so Iminify optimizes nothing while it does, to keep any image from being compressed twice. %2$s', 'iminify-image-optimizer' ),
			$reader->name(),
			$reader->switch_off()
		);
	}

	/**
	 * Reads the records of a page of attachments in one go.
	 *
	 * @param list<int> $attachments The attachments' ids.
	 */
	public static function prime( array $attachments ): void {
		if ( array() === $attachments ) {
			return;
		}

		foreach ( self::readers() as $reader ) {
			if ( $reader->present() ) {
				$reader->prime( $attachments );
			}
		}
	}

	/**
	 * Primes the media screens' attachments, as `Shared::prime_posts()` does.
	 *
	 * @param mixed $posts The posts a query found.
	 * @return mixed
	 */
	public static function prime_posts( $posts ) {
		if ( is_array( $posts ) && ( is_admin() || wp_doing_ajax() ) ) {
			$ids = array();

			foreach ( $posts as $post ) {
				if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
					$ids[] = (int) $post->ID;
				}
			}

			self::prime( $ids );
		}

		return $posts;
	}

	/**
	 * What another plugin left for an attachment: the first reader that finds a
	 * file still holding its plugin's output, or null.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to read again rather than take this request's answer.
	 * @return Trace|null
	 */
	public static function trace( int $attachment, bool $fresh = false ): ?array {
		$key = get_current_blog_id() . ':' . $attachment;

		if ( $fresh || ! isset( self::$traces[ $key ] ) ) {
			if ( $fresh ) {
				self::drop( $attachment );
			}

			self::$traces[ $key ] = self::read( $attachment ) ?? false;
		}

		$trace = self::$traces[ $key ];

		return false === $trace ? null : $trace;
	}

	/**
	 * Whether an attachment reads as optimized by another plugin, and what it
	 * saved: its attached file still holds that plugin's output.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to read again.
	 * @return array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}|null
	 */
	public static function verdict( int $attachment, bool $fresh = false ): ?array {
		$trace = self::trace( $attachment, $fresh );
		$main  = Paths::relative( (string) get_attached_file( $attachment, true ) );

		if ( null === $main ) {
			return null;
		}

		if ( null === $trace ) {
			$kept = self::remembered( $attachment );

			return null !== $kept && isset( $kept['outputs'][ $main ] ) ? $kept : null;
		}

		if ( ! in_array( $main, $trace['outputs'], true ) ) {
			return null;
		}

		$before  = 0;
		$after   = 0;
		$outputs = array();

		foreach ( $trace['outputs'] as $path ) {
			if ( isset( $trace['files'][ $path ] ) ) {
				$before          += $trace['files'][ $path ]['before'];
				$after           += $trace['files'][ $path ]['after'];
				$outputs[ $path ] = $trace['files'][ $path ]['after'];
			}
		}

		return array(
			'plugin'   => $trace['plugin'],
			'before'   => $before,
			'after'    => $after,
			'files'    => count( $trace['outputs'] ),
			'original' => self::original_kept( $attachment, $trace ),
			'outputs'  => $outputs,
		);
	}

	/**
	 * What the plugin's own record remembers of another plugin's optimization
	 * once that plugin's records are gone (Smush and ShortPixel delete theirs on
	 * some uninstalls, while the files stay as they compressed them): its files
	 * that still have the bytes recorded, or null when none has.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}|null
	 */
	protected static function remembered( int $attachment ): ?array {
		$record = Records::get( $attachment );
		$other  = Records::OTHER === $record['status'] || $record['redo'] ? $record['other'] : null;

		if ( null === $other ) {
			return null;
		}

		$still = array();

		foreach ( $other['outputs'] as $path => $bytes ) {
			$file = Places::upload( $path );

			if ( null !== $file ) {
				clearstatcache( true, $file );

				if ( (int) filesize( $file ) === $bytes ) {
					$still[ $path ] = $bytes;
				}
			}
		}

		if ( array() === $still ) {
			return null;
		}

		/* Its backups were checked against records that are gone, so none is trusted now. */
		$other['outputs']  = $still;
		$other['original'] = false;

		return $other;
	}

	/**
	 * Why restoring an attachment another plugin optimized is that plugin's own to
	 * do rather than Iminify's, in a sentence, or empty. An image that
	 * plugin converted, which the plugin optimized again from the original it kept,
	 * is no longer claimed by its reader, so its sentence comes from the record
	 * (`Originals::converted()`).
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function elsewhere( int $attachment ): string {
		$trace = self::trace( $attachment );

		if ( null !== $trace && '' !== $trace['elsewhere'] ) {
			return $trace['elsewhere'];
		}

		$converted = Originals::converted( $attachment );
		$reader    = null === $converted ? null : self::reader( $converted['plugin'] );

		return null === $reader ? '' : $reader->restore_elsewhere();
	}

	/**
	 * The plugin that took an attachment away from the site (Optimole's offload),
	 * whose files only it brings back, or null.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function away( int $attachment ): ?Reader {
		foreach ( self::readers() as $reader ) {
			if ( $reader->present() && $reader->away( $attachment ) ) {
				return $reader;
			}
		}

		return null;
	}

	/**
	 * Forgets what this request read about one attachment, after its files changed.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function drop( int $attachment ): void {
		unset( self::$traces[ get_current_blog_id() . ':' . $attachment ] );

		foreach ( self::readers() as $reader ) {
			$reader->drop( $attachment );
		}
	}

	/**
	 * Whether a file of an attachment holds another plugin's output, and the
	 * backup that plugin kept of it, when that backup passed its size check.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $relative   The file, relative to the uploads folder.
	 * @return array{plugin: string, backup: string|null, before: int}|null The plugin, its backup, and the bytes it recorded the file had before it (0 when unknown).
	 */
	public static function output( int $attachment, string $relative ): ?array {
		$trace = self::trace( $attachment );

		if ( null === $trace ) {
			$kept = self::remembered( $attachment );

			return null !== $kept && isset( $kept['outputs'][ $relative ] ) ? array(
				'plugin' => $kept['plugin'],
				'backup' => null,
				'before' => 0,
			) : null;
		}

		if ( ! in_array( $relative, $trace['outputs'], true ) ) {
			return null;
		}

		return array(
			'plugin' => $trace['plugin'],
			'backup' => $trace['backups'][ $relative ] ?? null,
			'before' => $trace['files'][ $relative ]['before'] ?? 0,
		);
	}

	/**
	 * The plugin that optimized a file in another folder through a folder of its
	 * own, or null.
	 *
	 * @param string $path The file's absolute path.
	 */
	public static function file_owner( string $path ): ?string {
		return self::file_owners( array( $path ) )[ $path ] ?? null;
	}

	/**
	 * The plugins that optimized these files through folders of their own, by path,
	 * one query a plugin.
	 *
	 * @param list<string> $paths Absolute paths.
	 * @return array<string, string>
	 */
	public static function file_owners( array $paths ): array {
		$owners = array();
		$ask    = array();

		foreach ( $paths as $path ) {
			if ( ! isset( self::$owners[ $path ] ) ) {
				$ask[] = $path;
			} elseif ( '' !== self::$owners[ $path ] ) {
				$owners[ $path ] = self::$owners[ $path ];
			}
		}

		foreach ( self::readers() as $reader ) {
			$left = array_values( array_diff( $ask, array_keys( $owners ) ) );

			if ( array() === $left ) {
				break;
			}

			foreach ( $reader->optimized_files( $left ) as $path ) {
				$owners[ $path ] = $reader->slug();
			}
		}

		foreach ( $ask as $path ) {
			self::$owners[ $path ] = $owners[ $path ] ?? '';
		}

		return $owners;
	}

	/**
	 * Why an image isn't sent because another plugin optimized it, as
	 * `Optimizer::why_not()` answers, with what to record; or null.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to read again.
	 * @return array{code: string, message: string, other: array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}}|null
	 */
	public static function why( int $attachment, bool $fresh = false ): ?array {
		$verdict = self::verdict( $attachment, $fresh );

		if ( null === $verdict ) {
			return null;
		}

		return array(
			'code'    => self::CODE,
			'message' => self::label( $verdict['plugin'] ),
			'other'   => $verdict,
		);
	}

	/**
	 * "Optimized by <plugin>", in words.
	 *
	 * @param string $slug The plugin's short name.
	 */
	public static function label( string $slug ): string {
		return sprintf(
			/* translators: %s: another image optimizer's name, such as Imagify. */
			__( 'Optimized by %s', 'iminify-image-optimizer' ),
			self::name( $slug )
		);
	}

	/**
	 * A plugin's name by its short name.
	 *
	 * @param string $slug The short name.
	 */
	public static function name( string $slug ): string {
		$reader = self::reader( $slug );

		return null === $reader ? $slug : $reader->name();
	}

	/**
	 * Records that another plugin optimized an attachment, for the screens and the counts.
	 *
	 * @param int                                                                                                $attachment The attachment's id.
	 * @param array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>} $verdict    What `verdict()` answered.
	 */
	public static function mark( int $attachment, array $verdict ): void {
		$record = Records::get( $attachment );

		if ( Records::OTHER === $record['status'] && $record['other'] === $verdict ) {
			return;
		}

		Records::update(
			$attachment,
			array(
				'status' => Records::OTHER,
				'other'  => $verdict,
				'error'  => null,
			)
		);
	}

	/**
	 * Takes the record off an image no reader claims any more: it reads not optimized.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function unmark( int $attachment ): void {
		Records::update(
			$attachment,
			array(
				'status' => Records::NOT_OPTIMIZED,
				'other'  => null,
				'error'  => null,
			)
		);
	}

	/**
	 * Looks over the library when another plugin's records changed since the last
	 * look, or the plugin keeps records of images another plugin optimized and no
	 * look has been taken since: a first page now, the rest in the background.
	 * The bulk page and the media library ask for it as they open.
	 *
	 * @param bool $force Whether to look whatever changed.
	 */
	public static function look( bool $force = false ): void {
		$stamp = self::stamp();

		if ( '' === $stamp || ( ! $force && get_option( self::STAMP_OPTION ) === $stamp ) ) {
			return;
		}

		/* A look already under way goes on, and stamps what it found once it reaches the end. */
		if ( ! $force && as_has_scheduled_action( self::SWEEP_HOOK, null, Optimizer::GROUP ) ) {
			return;
		}

		as_unschedule_all_actions( self::SWEEP_HOOK );

		self::go_on( self::walk( 0, microtime( true ) + self::PAGE_BUDGET ) );
	}

	/**
	 * Action Scheduler's run for SWEEP_HOOK: goes on looking where the last run stopped.
	 *
	 * @param mixed $after The id to start after.
	 */
	public static function sweep( $after = 0 ): void {
		self::go_on( self::walk( (int) $after, microtime( true ) + self::BUDGET ) );
	}

	/**
	 * Another optimizer was turned off: what it did while it was on is now final, so the library is looked at again.
	 *
	 * @param mixed $plugin The plugin's main file.
	 */
	public static function deactivated( $plugin ): void {
		foreach ( self::readers() as $reader ) {
			if ( $reader->owns( (string) $plugin ) ) {
				delete_option( self::STAMP_OPTION );
				$reader->deactivated();
				Reader::forget();

				if ( ! as_has_scheduled_action( self::SWEEP_HOOK, null, Optimizer::GROUP ) ) {
					as_schedule_single_action( time() + 5, self::SWEEP_HOOK, array( 0 ), Optimizer::GROUP );
				}

				return;
			}
		}
	}

	/**
	 * Another optimizer was deleted, which may have deleted its copies with it
	 * (Converter for Media's uninstall deletes its folder): the library is looked
	 * at again, so the copies taken over from it that are gone are forgotten, and
	 * the page caches are emptied, since a page they kept may name those copies.
	 *
	 * @param mixed $plugin The plugin's main file.
	 */
	public static function deleted( $plugin ): void {
		foreach ( self::readers() as $reader ) {
			if ( $reader->owns( (string) $plugin ) ) {
				self::deactivated( $plugin );
				Caches::later( 'others' );

				return;
			}
		}
	}

	/**
	 * Schedules the look's next page, or, at the end, stamps what it found, read
	 * then, so a change made while it walked is looked at again.
	 *
	 * @param int|null $next The id to go on after, or null at the end.
	 */
	protected static function go_on( ?int $next ): void {
		if ( null !== $next ) {
			as_schedule_single_action( time(), self::SWEEP_HOOK, array( $next ), Optimizer::GROUP );

			return;
		}

		update_option( self::STAMP_OPTION, self::stamp(), false );
	}

	/**
	 * Forgets what this request learned, for a long process (WP-CLI) or after a change.
	 */
	public static function forget(): void {
		self::$traces = array();
		self::$owners = array();
		Reader::forget();

		foreach ( self::readers() as $reader ) {
			$reader->reset();
		}
	}

	/**
	 * What another plugin's records look like now, with the plugin's own count of
	 * the images it records as theirs; empty when there is nothing to look at.
	 */
	public static function stamp(): string {
		$parts = array();

		foreach ( self::readers() as $reader ) {
			if ( $reader->present() ) {
				$parts[] = $reader->slug() . '=' . $reader->stamp();
			}
		}

		$marked = Records::count_with_status( array( Records::OTHER ) );

		if ( array() === $parts && 0 === $marked ) {
			return '';
		}

		$parts[] = 'marked=' . $marked;

		return implode( ';', $parts );
	}

	/**
	 * The images the plugin records as another plugin's, by plugin and by whether an original was kept.
	 *
	 * @return array<string, array{with: int, without: int}>
	 */
	public static function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count by the meta_key index, on the plugin's own page.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS other, COUNT(*) AS total FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash' WHERE m.meta_key = %s GROUP BY m.meta_value", Records::OTHER_META ), ARRAY_A );
		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$parts = explode( ':', (string) $row['other'] );
			$slug  = $parts[0];

			if ( ! isset( $counts[ $slug ] ) ) {
				$counts[ $slug ] = array(
					'with'    => 0,
					'without' => 0,
				);
			}

			$kept = isset( $parts[1] ) && '1' === $parts[1];

			$counts[ $slug ][ $kept ? 'with' : 'without' ] += (int) $row['total'];
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * A page of the images the plugin records as another plugin's with an original
	 * kept, in the order of their ids: what a run optimizing them again from those
	 * originals looks at (`Bulk::REDO`), each asked again before it is sent.
	 *
	 * @param int $limit How many at most.
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	public static function with_original( int $limit, int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a background read by the meta_key index.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash' WHERE m.meta_key = %s AND m.meta_value LIKE %s AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d", Records::OTHER_META, '%:1', $after, $limit ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many images `with_original()` finds.
	 */
	public static function count_with_original(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count by the meta_key index, on the plugin's own page.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash' WHERE m.meta_key = %s AND m.meta_value LIKE %s", Records::OTHER_META, '%:1' ) );
	}

	/**
	 * Looks at the images from one id on until the time is spent: each another
	 * plugin optimized is recorded so, one recorded so that no reader claims any
	 * more reads not optimized, and the copies other plugins made of each are
	 * taken over (`Takeover`). Answers where to go on, or null at the end.
	 *
	 * @param int   $after    The id to start after.
	 * @param float $deadline When to stop, as `microtime( true )`.
	 */
	protected static function walk( int $after, float $deadline ): ?int {
		do {
			$ids = self::page( $after );

			Shared::prime( $ids );

			/* The page's records are read again once, in one query a plugin, rather than one an image. */
			foreach ( $ids as $id ) {
				self::drop( $id );
			}

			self::prime( $ids );

			foreach ( $ids as $id ) {
				$after = $id;

				if ( Shared::holder( $id ) !== $id ) {
					continue;
				}

				$verdict = self::verdict( $id );

				if ( null !== $verdict ) {
					self::mark( $id, $verdict );
				} elseif ( Records::OTHER === Records::get( $id )['status'] ) {
					self::unmark( $id );
				}

				Takeover::take( $id );

				/* A translation shares its holder's files, so it serves the copies taken over for them through the holder (`Picture::source()`). */
				if ( array() !== Copies::taken( Records::get( $id )['copies'] ) && array() !== Shared::others( $id ) ) {
					Shared::point( $id, Shared::others( $id ) );
				}
			}

			if ( self::PAGE_SIZE > count( $ids ) ) {
				return null;
			}
		} while ( microtime( true ) < $deadline );

		return $after;
	}

	/**
	 * A page of the images a look takes: those with no record, "not optimized" or
	 * recorded as another plugin's, in the order of their ids. A restored image is
	 * as the person chose, and one the plugin optimized or has on its way is its own.
	 *
	 * @param int $after The id to start after.
	 * @return list<int>
	 */
	protected static function page( int $after ): array {
		global $wpdb;

		$types = Declaration::MIME_TYPES;
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a background read by the primary key; one placeholder per type, built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ({$in}) AND p.ID > %d AND ( m.meta_id IS NULL OR m.meta_value IN (%s, %s) ) ORDER BY p.ID ASC LIMIT %d", array_merge( array( Records::STATUS_META ), $types, array( $after, Records::NOT_OPTIMIZED, Records::OTHER, self::PAGE_SIZE ) ) ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Whether an original of the attachment is kept that passes its plugin's size
	 * check, in the order the original lookup takes them: WordPress's own original
	 * beside a `-scaled` or `-rotated` copy, then the attached file, each either
	 * untouched or with a verified backup.
	 *
	 * @param int                                                                       $attachment The attachment's id.
	 * @param array{outputs: list<string>, backups: array<string, string>} $trace      What `trace()` answered.
	 */
	protected static function original_kept( int $attachment, array $trace ): bool {
		$metadata   = wp_get_attachment_metadata( $attachment, true );
		$metadata   = is_array( $metadata ) ? $metadata : array();
		$attached   = Paths::relative( (string) get_attached_file( $attachment, true ) );
		$candidates = array();
		$original   = isset( $metadata['original_image'] ) ? wp_basename( (string) $metadata['original_image'] ) : '';

		if ( null === $attached ) {
			return false;
		}

		if ( '' !== $original && in_array( pathinfo( $attached, PATHINFO_FILENAME ), array( pathinfo( $original, PATHINFO_FILENAME ) . '-scaled', pathinfo( $original, PATHINFO_FILENAME ) . '-rotated' ), true ) ) {
			$folder       = dirname( $attached );
			$candidates[] = ( '.' === $folder ? '' : $folder . '/' ) . $original;
		}

		$candidates[] = $attached;

		foreach ( $candidates as $path ) {
			if ( in_array( $path, $trace['outputs'], true ) ) {
				if ( isset( $trace['backups'][ $path ] ) ) {
					return true;
				}

				continue;
			}

			if ( null !== Places::upload( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A reader's answer without the backups that hold the plugin's own output: where
	 * another plugin compressed a file the plugin had written (with its backups off),
	 * that plugin's backup is of the plugin's output, which passes that plugin's own
	 * size check and is never an original.
	 *
	 * @param int   $attachment The attachment's id.
	 * @param Found $found      A reader's answer.
	 * @return Found
	 */
	protected static function without_own_output( int $attachment, array $found ): array {
		$written = Records::get( $attachment )['written'];

		foreach ( $found['backups'] as $path => $backup ) {
			clearstatcache( true, $backup );

			if ( isset( $written[ $path ] ) && (int) filesize( $backup ) === $written[ $path ] ) {
				unset( $found['backups'][ $path ] );
			}
		}

		return $found;
	}

	/**
	 * Asks the readers, in order, for the first that finds its plugin's output.
	 *
	 * @param int $attachment The attachment's id.
	 * @return Trace|null
	 */
	protected static function read( int $attachment ): ?array {
		$readers = array_filter(
			self::readers(),
			static function ( Reader $reader ): bool {
				return $reader->compresses() && ( $reader->in_metadata() || $reader->present() );
			}
		);

		if ( array() === $readers ) {
			return null;
		}

		/* A translation plugin's attachments share their files, and another optimizer may have recorded its work on any of them. */
		foreach ( array_merge( array( $attachment ), Shared::others( $attachment ) ) as $id ) {
			$metadata = wp_get_attachment_metadata( $id, true );
			$metadata = is_array( $metadata ) ? $metadata : array();

			foreach ( $readers as $reader ) {
				$found = $reader->read( $id, $metadata );

				if ( null !== $found && array() !== $found['outputs'] ) {
					return array_merge( array( 'plugin' => $reader->slug() ), self::without_own_output( $attachment, $found ) );
				}
			}
		}

		return null;
	}
}
