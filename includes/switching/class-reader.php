<?php
/**
 * What one other image optimizer left behind on the site.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A reader knows one other optimizer by what it leaves behind, since a person
 * switching usually turns the old plugin off first: its post meta, its tables,
 * its backups and its copies. It answers, for an attachment, which of its files
 * that plugin compressed and recorded, which of them still hold the bytes it
 * wrote (so they are its output and never an original), the backup it kept of
 * each where that backup passes its own size check, and the WebP and AVIF
 * copies its records name. A shape it doesn't know reads as nothing, and the
 * attachment is left as it is.
 *
 * It also says whether that plugin, active now, would act on uploads by itself
 * (W134), in which case the plugin optimizes nothing until it is switched off.
 *
 * Readers only read. Every path a record gives goes through `Places`.
 */
abstract class Reader {

	/**
	 * Whether the site holds any of the plugin's records, asked once a request.
	 *
	 * @var array<string, bool>
	 */
	protected static $present = array();

	/**
	 * The plugin's short name, kept in the plugin's own records.
	 */
	abstract public function slug(): string;

	/**
	 * The plugin's name as people know it.
	 */
	abstract public function name(): string;

	/**
	 * What to switch off so the plugin stops acting on uploads, in a sentence.
	 */
	abstract public function switch_off(): string;

	/**
	 * The plugin's main files, as WordPress lists active plugins.
	 *
	 * @return list<string>
	 */
	abstract protected function basenames(): array;

	/**
	 * Whether the plugin's code is loaded in this request: a constant or a class only it defines.
	 */
	abstract protected function loaded(): bool;

	/**
	 * Whether, while active, the plugin optimizes uploads by itself, read from its settings.
	 */
	abstract protected function optimizes_uploads(): bool;

	/**
	 * Whether the plugin is active on this site, loaded or listed.
	 */
	public function active(): bool {
		if ( $this->loaded() ) {
			return true;
		}

		$active = (array) get_option( 'active_plugins', array() );
		$wide   = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		foreach ( $this->basenames() as $basename ) {
			if ( in_array( $basename, $active, true ) || isset( $wide[ $basename ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a plugin's main file is this plugin's.
	 *
	 * @param string $basename The main file, as WordPress names an active plugin.
	 */
	public function owns( string $basename ): bool {
		return in_array( $basename, $this->basenames(), true );
	}

	/**
	 * Forgets the rows this reader read for a page, for a long process.
	 */
	public function reset(): void {
	}

	/**
	 * Forgets the rows this reader read for one attachment, so they are read again.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public function drop( int $attachment ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- a reader that keeps no rows has nothing to forget.
	}

	/**
	 * Whether the plugin can act on the library now (W134): active, with its
	 * optimization of uploads on.
	 */
	public function acts(): bool {
		return $this->active() && $this->optimizes_uploads();
	}

	/**
	 * Whether the site keeps any of the plugin's records about its media library,
	 * asked once a request, so a site that never had the plugin pays one query.
	 */
	public function present(): bool {
		$key = get_current_blog_id() . ':' . $this->slug();

		if ( ! isset( self::$present[ $key ] ) ) {
			self::$present[ $key ] = $this->holds_records();
		}

		return self::$present[ $key ];
	}

	/**
	 * Whether the site keeps any of the plugin's records, by one indexed query.
	 */
	protected function holds_records(): bool {
		return false;
	}

	/**
	 * A mark that changes when the plugin's records about the library change, so a
	 * new look over the library is taken only then.
	 */
	public function stamp(): string {
		return '';
	}

	/**
	 * Reads the records of a page of attachments in one go, for a plugin that keeps
	 * them in a table of its own.
	 *
	 * @param list<int> $attachments The attachments' ids.
	 */
	public function prime( array $attachments ): void {
	}

	/**
	 * What the plugin left for an attachment, or null when it left nothing this
	 * reader recognizes.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @return array{files: array<string, array{size: string, before: int, after: int}>, outputs: list<string>, backups: array<string, string>, copies: list<string>}|null
	 *         Paths relative to the uploads folder: the files it recorded compressing with the bytes it recorded, those
	 *         that still hold its output, a verified backup of each it kept (absolute), and the copies its records name.
	 */
	public function read( int $attachment, array $metadata ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- a reader that keeps no records answers nothing.
		return null;
	}

	/**
	 * Of these files outside the media library, those the plugin optimized through
	 * a folder of its own (W140) that still hold what it wrote.
	 *
	 * @param list<string> $paths Absolute paths.
	 * @return list<string>
	 */
	public function optimized_files( array $paths ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- a plugin with no folders of its own optimized none.
		return array();
	}

	/**
	 * Forgets what this request learned about the site, for a long process.
	 */
	public static function forget(): void {
		self::$present = array();
	}

	/**
	 * The files of an attachment by WordPress's names for them, relative to the
	 * uploads folder: `full` for the attached file, `original` for the original
	 * WordPress keeps beside a `-scaled` or `-rotated` copy, and each size.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @return array<string, string>
	 */
	protected static function files( int $attachment, array $metadata ): array {
		$attached = Paths::relative( (string) get_attached_file( $attachment, true ) );

		if ( null === $attached ) {
			return array();
		}

		$folder = dirname( $attached );
		$folder = '.' === $folder ? '' : $folder . '/';
		$files  = array( 'full' => $attached );

		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && '' !== $metadata['original_image'] ) {
			$files['original'] = $folder . wp_basename( $metadata['original_image'] );
		}

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $name => $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] ) {
				$files[ (string) $name ] = $folder . wp_basename( $size['file'] );
			}
		}

		return $files;
	}

	/**
	 * A file's bytes now, or -1 when it isn't a file in the uploads folder. A file
	 * only in WP Offload Media's bucket has the bytes its metadata gives it.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $relative   The file, relative to the uploads folder.
	 */
	protected static function bytes( int $attachment, string $relative ): int {
		$path = Places::upload( $relative );

		if ( null !== $path ) {
			clearstatcache( true, $path );

			return (int) filesize( $path );
		}

		$path = Paths::absolute( $relative );

		return null === Paths::relative( $path ) || ! Offload_Media::only_in_bucket( $attachment, $path ) ? -1 : (int) ( Offload_Media::bytes( $attachment, $path ) ?? -1 );
	}

	/**
	 * The answer for one attachment from what a reader found: each recorded file
	 * that still has the bytes the plugin recorded after is its output.
	 *
	 * @param int                                                         $attachment The attachment's id.
	 * @param array<string, array{size: string, before: int, after: int}> $files      The files it recorded, by path.
	 * @param array<string, string>                                       $backups    A verified backup for each file that has one.
	 * @param list<string>                                                $copies     The copies its records name.
	 * @return array{files: array<string, array{size: string, before: int, after: int}>, outputs: list<string>, backups: array<string, string>, copies: list<string>}|null
	 */
	protected static function answer( int $attachment, array $files, array $backups, array $copies ): ?array {
		$outputs = array();

		foreach ( $files as $path => $file ) {
			if ( $file['after'] > 0 && self::bytes( $attachment, $path ) === $file['after'] ) {
				$outputs[] = $path;
			}
		}

		if ( array() === $files && array() === $outputs ) {
			return null;
		}

		$existing = array();

		foreach ( $copies as $copy ) {
			if ( null !== Places::upload( $copy ) ) {
				$existing[] = $copy;
			}
		}

		return array(
			'files'   => $files,
			'outputs' => $outputs,
			'backups' => array_intersect_key( $backups, array_flip( $outputs ) ),
			'copies'  => array_values( array_unique( $existing ) ),
		);
	}

	/**
	 * A backup's absolute path when it passes the size check: a file inside the
	 * places, with exactly the bytes the plugin recorded before its first pass, so
	 * a backup taken of a file it had already compressed is refused.
	 *
	 * @param string $path   The backup's absolute path.
	 * @param int    $before The bytes the plugin recorded before it compressed the file.
	 */
	protected static function verified( string $path, int $before ): ?string {
		$file = Places::file( $path );

		if ( null === $file || $before <= 0 ) {
			return null;
		}

		clearstatcache( true, $file );

		return (int) filesize( $file ) === $before ? $file : null;
	}

	/**
	 * Whether a table exists, asked once a request.
	 *
	 * @param string $table The table's full name.
	 */
	protected static function table_exists( string $table ): bool {
		global $wpdb;

		$key = 'table:' . $table;

		if ( ! isset( self::$present[ $key ] ) ) {
			$quiet = $wpdb->suppress_errors( true );

			/* Read rather than listed, which also finds a temporary table, and costs nothing on a table that isn't there. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- asks once a request whether another plugin's table is there; the name is the site's prefix and a constant.
			$wpdb->query( 'SELECT 1 FROM `' . esc_sql( $table ) . '` LIMIT 0' );

			self::$present[ $key ] = '' === $wpdb->last_error;
			$wpdb->suppress_errors( $quiet );
		}

		return self::$present[ $key ];
	}

	/**
	 * Whether any post meta of this key exists, by the meta_key index.
	 *
	 * @param list<string> $keys The meta keys.
	 */
	protected static function meta_exists( array $keys ): bool {
		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one indexed look a request; one placeholder per key, built above.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) LIMIT 1", $keys ) );
	}

	/**
	 * How many rows of these meta keys exist and the newest one's id, as a stamp.
	 *
	 * @param list<string> $keys The meta keys.
	 */
	protected static function meta_stamp( array $keys ): string {
		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a count by the meta_key index, on the plugin's own pages; one placeholder per key, built above.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, MAX(meta_id) AS newest FROM {$wpdb->postmeta} WHERE meta_key IN ({$in})", $keys ), ARRAY_A );

		return is_array( $row ) ? $row['total'] . '.' . $row['newest'] : '';
	}

	/**
	 * A setting read from another plugin's option: the option's value under a key, or the default.
	 *
	 * @param string $option  The option.
	 * @param string $key     The key inside it.
	 * @param mixed  $fallback What a missing option or key reads as.
	 * @return mixed
	 */
	protected static function setting( string $option, string $key, $fallback ) {
		$value = get_option( $option );

		if ( is_array( $value ) ) {
			return array_key_exists( $key, $value ) ? $value[ $key ] : $fallback;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );

			return array_key_exists( $key, $value ) ? $value[ $key ] : $fallback;
		}

		return $fallback;
	}
}
