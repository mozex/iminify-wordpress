<?php
/**
 * What EWWW Image Optimizer left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EWWW Image Optimizer (read from 8.9.1) keeps no post meta: each file it
 * handled is a row of `{prefix}ewwwio_images` by its path, written from
 * `ABSPATH` or `WP_CONTENT_DIR` (`ABSPATHwp-content/uploads/...`) or whole. A row
 * with `image_size` above 0 and nothing `pending` was compressed in place from
 * `orig_size`, the bytes the file had when EWWW first met it and never written
 * again, to `image_size`, the bytes it wrote. In local mode it backs up every
 * file it compresses under `wp-content/ewww/image-backup/`, at the file's path
 * from the uploads folder under `uploads/` (from `wp-content` or the site's
 * folder for others), never written over, so a backup passes when it has
 * `orig_size`; in cloud mode the originals are on its servers (W141). Its WebP
 * copies are `photo.jpg.webp`, or `photo.webp` in its replace mode. Files outside
 * the library are rows with no attachment, and so are the library's own files a
 * folder scan compressed ("Include Media Folders", or a folder under the uploads
 * folder), which are found by their paths. A site can have it write paths from a
 * folder of its own (`EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER`).
 */
class Ewww extends Reader {

	const TABLE = 'ewwwio_images';

	/**
	 * The rows read for a page, by site and attachment.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	protected $rows = array();

	public function slug(): string {
		return 'ewww';
	}

	public function name(): string {
		return 'EWWW Image Optimizer';
	}

	public function switch_off(): string {
		return __( 'Deactivate EWWW Image Optimizer.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'ewww-image-optimizer/ewww-image-optimizer.php', 'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' );
	}

	protected function loaded(): bool {
		return defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' );
	}

	/**
	 * Optimizes uploads unless its "no auto" option, or the constant of that name, says not to.
	 */
	protected function optimizes_uploads(): bool {
		if ( defined( 'EWWW_IMAGE_OPTIMIZER_NOAUTO' ) ) {
			return ! constant( 'EWWW_IMAGE_OPTIMIZER_NOAUTO' );
		}

		return ! get_option( 'ewww_image_optimizer_noauto' ) && ! ( is_multisite() && get_site_option( 'ewww_image_optimizer_noauto' ) );
	}

	protected function holds_records(): bool {
		global $wpdb;

		if ( ! self::table_exists( $wpdb->prefix . self::TABLE ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request at another plugin's table.
		return null !== $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}ewwwio_images WHERE image_size > 0 LIMIT 1" );
	}

	public function stamp(): string {
		global $wpdb;

		/* By its primary key alone: its sizes have no index, and the media library asks on every load. */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count on the plugin's own pages.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, MAX(id) AS newest FROM {$wpdb->prefix}ewwwio_images", ARRAY_A );

		return is_array( $row ) ? $row['total'] . '.' . $row['newest'] : '';
	}

	public function reset(): void {
		$this->rows = array();
	}

	public function drop( int $attachment ): void {
		unset( $this->rows[ get_current_blog_id() . ':' . $attachment ] );
	}

	public function prime( array $attachments ): void {
		global $wpdb;

		$blog = get_current_blog_id();
		$ask  = array();

		foreach ( $attachments as $attachment ) {
			if ( ! isset( $this->rows[ $blog . ':' . $attachment ] ) ) {
				$ask[]                                   = (int) $attachment;
				$this->rows[ $blog . ':' . $attachment ] = array();
			}
		}

		if ( array() === $ask ) {
			return;
		}

		$in = implode( ', ', array_fill( 0, count( $ask ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a page's rows of another plugin's table in one query; one placeholder per id, built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, path, image_size, orig_size, webp_size, backup FROM {$wpdb->prefix}ewwwio_images WHERE attachment_id IN ({$in}) AND gallery = %s AND image_size > 0 AND pending = 0", array_merge( $ask, array( 'media' ) ) ), ARRAY_A );
		$seen = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->rows[ $blog . ':' . (int) $row['attachment_id'] ][] = $row;
			$seen[ self::absolute( (string) $row['path'] ) ]           = true;
		}

		/* A folder scan ("Include Media Folders", or a folder under the uploads folder) compresses a library's files through rows with no attachment, found by their paths. */
		$forms = array();

		foreach ( $ask as $attachment ) {
			$metadata = wp_get_attachment_metadata( $attachment, true );

			foreach ( self::files( $attachment, is_array( $metadata ) ? $metadata : array() ) as $relative ) {
				$path = Paths::absolute( $relative );

				if ( ! isset( $seen[ $path ] ) ) {
					foreach ( self::forms( $path ) as $form ) {
						$forms[ $form ] = $attachment;
					}
				}
			}
		}

		if ( array() === $forms ) {
			return;
		}

		$in = implode( ', ', array_fill( 0, count( $forms ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the page's files by EWWW's indexed path in one query; one placeholder per form, built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path, image_size, orig_size, webp_size, backup FROM {$wpdb->prefix}ewwwio_images WHERE path IN ({$in}) AND image_size > 0 AND pending = 0", array_keys( $forms ) ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $forms[ (string) $row['path'] ] ) ) {
				$this->rows[ $blog . ':' . $forms[ (string) $row['path'] ] ][] = $row;
			}
		}
	}

	public function read( int $attachment, array $metadata ): ?array {
		$this->prime( array( $attachment ) );

		$rows          = $this->rows[ get_current_blog_id() . ':' . $attachment ] ?? array();
		$names_by_size = self::files( $attachment, $metadata );
		$names         = array_flip( $names_by_size );

		if ( array() === $rows || array() === $names ) {
			return null;
		}

		$replace = 'replace' === get_option( 'ewww_image_optimizer_webp_naming_mode' );
		$files   = array();
		$backups = array();
		$copies  = array();
		$cloud   = false;

		foreach ( $rows as $row ) {
			$relative = Paths::relative( self::absolute( (string) $row['path'] ) );

			/* A row is matched to the attachment's own files by its path, so a path that leads anywhere else names nothing here. */
			if ( null === $relative || ! isset( $names[ $relative ] ) ) {
				continue;
			}

			if ( null !== $row['webp_size'] && (int) $row['webp_size'] > 0 ) {
				$copies[ $replace ? (string) preg_replace( '/\.[^.\/]+$/', '', $relative ) . '.webp' : $relative . '.webp' ] = $relative;
			}

			$before = (int) $row['orig_size'];
			$after  = (int) $row['image_size'];

			if ( $after <= 0 || $after >= $before ) {
				continue;
			}

			/* A path the attachment's own row already gave is read from that row. */
			if ( isset( $files[ $relative ] ) ) {
				continue;
			}

			$files[ $relative ] = array(
				'size'   => (string) $names[ $relative ],
				'before' => $before,
				'after'  => $after,
			);

			$backup = $this->backup( $relative, $before );

			if ( null !== $backup ) {
				$backups[ $relative ] = $backup;
			} elseif ( 'full' === $names[ $relative ] && ! empty( $row['backup'] ) ) {
				$cloud = true;
			}
		}

		/* Its cloud mode keeps the original on EWWW's servers for 30 days, fetched only with its key (W141). */
		$elsewhere = $cloud && ! isset( $names_by_size['original'] ) ? __( 'EWWW Image Optimizer kept this image\'s original on its own servers, for 30 days. Restore it in EWWW Image Optimizer while its key and those days last; Iminify can\'t fetch it.', 'iminify-image-optimizer' ) : '';

		return self::answer( $attachment, $files, $backups, $copies, $elsewhere );
	}

	public function optimized_files( array $paths ): array {
		global $wpdb;

		if ( array() === $paths || ! self::table_exists( $wpdb->prefix . self::TABLE ) ) {
			return array();
		}

		$forms = array();

		foreach ( $paths as $path ) {
			foreach ( self::forms( $path ) as $form ) {
				$forms[ $form ] = $path;
			}
		}

		$in = implode( ', ', array_fill( 0, count( $forms ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the files of a folder's page in one query; one placeholder per form, built above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT path, image_size FROM {$wpdb->prefix}ewwwio_images WHERE image_size > 0 AND pending = 0 AND path IN ({$in})", array_keys( $forms ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$path = $forms[ (string) $row['path'] ] ?? null;

			if ( null === $path ) {
				continue;
			}

			clearstatcache( true, $path );

			if ( is_file( $path ) && (int) filesize( $path ) === (int) $row['image_size'] ) {
				$found[] = $path;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * A row's path made whole: EWWW writes it from `ABSPATH` or `WP_CONTENT_DIR`.
	 *
	 * @param string $path The row's path.
	 */
	protected static function absolute( string $path ): string {
		$folder = self::relative_folder();

		if ( '' !== $folder && 0 === strpos( $path, 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) ) {
			return wp_normalize_path( $folder . substr( $path, strlen( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) ) );
		}

		if ( 0 === strpos( $path, 'ABSPATH' ) ) {
			return wp_normalize_path( trailingslashit( ABSPATH ) . substr( $path, strlen( 'ABSPATH' ) ) );
		}

		if ( 0 === strpos( $path, 'WP_CONTENT_DIR' ) ) {
			return wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) . substr( $path, strlen( 'WP_CONTENT_DIR' ) ) );
		}

		return wp_normalize_path( $path );
	}

	/**
	 * The folder a site names in `EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER`, which EWWW
	 * writes as that constant's name at the start of a path, or empty.
	 */
	protected static function relative_folder(): string {
		return defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) ? (string) constant( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) : '';
	}

	/**
	 * The ways EWWW may have written a file's path in its table.
	 *
	 * @param string $path An absolute path.
	 * @return list<string>
	 */
	protected static function forms( string $path ): array {
		$path    = wp_normalize_path( $path );
		$abs     = trailingslashit( wp_normalize_path( ABSPATH ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$forms   = array( $path );
		$folder  = wp_normalize_path( self::relative_folder() );

		if ( '' !== $folder && 0 === strpos( $path, $folder ) ) {
			$forms[] = 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' . substr( $path, strlen( $folder ) );
		}

		if ( 0 === strpos( $path, $abs ) ) {
			$forms[] = 'ABSPATH' . substr( $path, strlen( $abs ) );
		}

		if ( 0 === strpos( $path, $content . '/' ) ) {
			$forms[] = 'WP_CONTENT_DIR' . substr( $path, strlen( $content ) );
		}

		return $forms;
	}

	/**
	 * The backup EWWW kept of a file in its local mode, when it passes the size
	 * check. On a network every site's uploads share one backup folder, so a
	 * site's own path is tried as well.
	 *
	 * @param string $relative The file, relative to the uploads folder.
	 * @param int    $before   The bytes EWWW recorded when it first met the file.
	 */
	protected function backup( string $relative, int $before ): ?string {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$tails   = array( $relative );
		$network = Places::network_uploads();

		if ( null !== $network ) {
			$tails[] = substr( Paths::absolute( $relative ), strlen( $network ) + 1 );
		}

		foreach ( array( $content . '/ewww/image-backup/uploads/', Paths::uploads() . '/ewww/image-backup/uploads/' ) as $root ) {
			foreach ( array_unique( $tails ) as $tail ) {
				$found = self::verified( $root . $tail, $before );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
