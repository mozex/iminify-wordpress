<?php
/**
 * What ShortPixel Image Optimizer left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShortPixel (read from 6.6.1) keeps one row per file in `{prefix}shortpixel_postmeta`:
 * `image_type` 0 is the attached file, 1 a size (by its name in `size`, or an
 * unlisted file named in `extra_info.file`), 2 the original WordPress keeps
 * beside a `-scaled` copy, 3 a retina copy and 4 a translation's pointer. A row
 * at `status` 2 was compressed, from `original_size` to `compressed_size`, the
 * bytes it wrote; one where the two are equal gained nothing and left the file
 * as it was. It compresses in place, the WordPress original included, and backs
 * each file up under `uploads/ShortpixelBackups/` at its path from the site's
 * home folder, a backup it writes again on a later pass, so only one with the
 * recorded `original_size` passes. It converts PNG, HEIC, TIFF and BMP to JPEG
 * under a new name, whose backup can't be checked, so such an image has none
 * here. Its WebP and AVIF copies are named in `extra_info` (`photo.webp`, or
 * `photo.jpg.webp` with a wp-config constant). Files outside the library are
 * rows of `{prefix}shortpixel_meta` by absolute path.
 *
 * @phpstan-import-type Found from Reader
 */
class Shortpixel extends Reader {

	const TABLE = 'shortpixel_postmeta';

	const FOLDERS_TABLE = 'shortpixel_meta';

	const BACKUP_FOLDER = 'ShortpixelBackups';

	/**
	 * The keys of `spio_settings` Iminify's settings import maps.
	 */
	const SETTING_KEYS = array( 'compressionType', 'resizeImages', 'resizeType', 'resizeWidth', 'exif', 'createWebp', 'createAvif', 'processThumbnails', 'excludeSizes', 'backupImages' );

	/**
	 * The rows read for a page, by site and attachment.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	protected $rows = array();

	public function slug(): string {
		return 'shortpixel';
	}

	public function name(): string {
		return 'ShortPixel';
	}

	public function switch_off(): string {
		return __( 'Deactivate ShortPixel, or turn off "Optimize media on upload" in its settings, under Processing.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'shortpixel-image-optimiser/wp-shortpixel.php' );
	}

	protected function loaded(): bool {
		return defined( 'SHORTPIXEL_PLUGIN_FILE' );
	}

	/**
	 * "Optimize media on upload" (on by default) with a key it verified, without
	 * which it sends nothing.
	 */
	protected function optimizes_uploads(): bool {
		$auto = self::setting( 'spio_settings', 'autoMediaLibrary', true );
		$key  = defined( 'SHORTPIXEL_API_KEY' ) || ! empty( self::setting( 'spio_key', 'verifiedKey', false ) ) || ! empty( get_option( 'wp-short-pixel-verifiedKey' ) );

		return ! empty( $auto ) && $key;
	}

	/**
	 * One array option; its API key lives in another.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'spio_settings' => self::SETTING_KEYS,
		);
	}

	/**
	 * The site's settings, or the network's while the network's override is on.
	 * ShortPixel then reads every setting from the network, a setting the
	 * network never saved as its own default, so the site's value is left out
	 * rather than sent for it; only the resize mode, whose default is none,
	 * still comes from the site.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$settings = parent::settings();
		$network  = is_multisite() ? get_site_option( 'spio_wpmu' ) : false;

		if ( ! is_array( $network ) || empty( $network['network_settings_override_enabled'] ) ) {
			return $settings;
		}

		$cut = self::only( $network, self::SETTING_KEYS );
		$set = is_array( $cut ) ? array_filter(
			$cut,
			static function ( $value ): bool {
				return null !== $value;
			}
		) : array();
		$own = $settings['spio_settings'] ?? null;

		if ( ! isset( $set['resizeType'] ) && is_array( $own ) && isset( $own['resizeType'] ) ) {
			$set['resizeType'] = $own['resizeType'];
		}

		unset( $settings['spio_settings'] );

		if ( array() !== $set ) {
			$settings['spio_settings'] = $set;
		}

		return $settings;
	}

	protected function holds_records(): bool {
		global $wpdb;

		/* Before 5.0 its records were in the attachments' metadata, under options of another name. */
		if ( false !== get_option( 'wp-short-pixel-apiKey' ) || false !== get_option( 'wp-short-pixel-verifiedKey' ) ) {
			return true;
		}

		if ( ! self::table_exists( $wpdb->prefix . self::TABLE ) ) {
			return false;
		}

		/* Any row: a conversion it made at upload is a row before its compression is. */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request at another plugin's table.
		return null !== $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}shortpixel_postmeta LIMIT 1" );
	}

	public function stamp(): string {
		global $wpdb;

		if ( ! self::table_exists( $wpdb->prefix . self::TABLE ) ) {
			return 'legacy';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count on the plugin's own pages.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM( status = %d ) AS done, MAX(id) AS newest FROM {$wpdb->prefix}shortpixel_postmeta", 2 ), ARRAY_A );

		return is_array( $row ) ? $row['total'] . '.' . $row['done'] . '.' . $row['newest'] : '';
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

		/* A site that ran it only before 5.0 has no table: its records are in the metadata. */
		if ( array() === $ask || ! self::table_exists( $wpdb->prefix . self::TABLE ) ) {
			return;
		}

		$in = implode( ', ', array_fill( 0, count( $ask ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a page's rows of another plugin's table in one query; one placeholder per id, built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attach_id, image_type, size, status, original_size, compressed_size, extra_info FROM {$wpdb->prefix}shortpixel_postmeta WHERE attach_id IN ({$in}) AND ( status = %d OR image_type = %d )", array_merge( $ask, array( 2, 0 ) ) ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->rows[ $blog . ':' . (int) $row['attach_id'] ][] = $row;
		}
	}

	public function read( int $attachment, array $metadata ): ?array {
		$this->prime( array( $attachment ) );

		$rows  = $this->rows[ get_current_blog_id() . ':' . $attachment ] ?? array();
		$names = self::files( $attachment, $metadata );

		if ( ! isset( $names['full'] ) ) {
			return null;
		}

		if ( array() === $rows ) {
			return $this->legacy( $attachment, $metadata, $names );
		}

		$folder    = dirname( $names['full'] );
		$folder    = '.' === $folder ? '' : $folder . '/';
		$files     = array();
		$backups   = array();
		$copies    = array();
		$convert   = false;
		$converted = false;

		foreach ( $rows as $row ) {
			$extra = json_decode( (string) $row['extra_info'], true );
			$extra = is_array( $extra ) ? $extra : array();
			$path  = $this->path( (int) $row['image_type'], (string) $row['size'], $extra, $names, $folder );
			$main  = 0 === (int) $row['image_type'];

			if ( null === $path ) {
				continue;
			}

			/* Its own format conversion wrote the attached file at its new name, in JPEG, at upload; a row migrated from before 5.0 took its `original_size` from the backup itself. Neither leaves a backup whose bytes it recorded. */
			if ( $main && $this->converted( $extra, $names['full'] ) ) {
				$convert   = true;
				$converted = true;
			} elseif ( $main && ! empty( $extra['wasConverted'] ) ) {
				$convert = true;
			}

			/* A main row it hasn't compressed yet is read only for whether it converted the file. */
			if ( 2 !== (int) $row['status'] ) {
				continue;
			}

			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( isset( $extra[ $format ] ) && is_string( $extra[ $format ] ) && '' !== $extra[ $format ] ) {
					$copies[ $folder . wp_basename( $extra[ $format ] ) ] = $path;
				}
			}

			$before = (int) $row['original_size'];
			$after  = (int) $row['compressed_size'];

			/* A file it gained nothing on is the file as uploaded, unless it is the JPEG its conversion wrote, whose bytes it recorded all the same. */
			if ( $after <= 0 || ( $after >= $before && ! ( $main && $converted ) ) ) {
				continue;
			}

			$files[ $path ] = array(
				'size'   => 0 === (int) $row['image_type'] ? 'full' : ( 2 === (int) $row['image_type'] ? 'original' : (string) $row['size'] ),
				'before' => $before,
				'after'  => $after,
			);

			$backup = $convert ? null : $this->backup( $path, $before );

			if ( null !== $backup ) {
				$backups[ $path ] = $backup;
			}
		}

		/* A conversion not compressed yet recorded no bytes: the JPEG it wrote is its output, with no original, while its record of the conversion is there. */
		if ( $converted && ! isset( $files[ $names['full'] ] ) ) {
			return self::answer( $attachment, $files + $this->held( $attachment, array( 'full' => $names['full'] ) ), array(), $copies );
		}

		/* A converted image's main row is read after its sizes only by chance, so its backups are dropped once it is known. */
		return self::answer( $attachment, $files, $convert ? array() : $backups, $copies );
	}

	/**
	 * Whether its format conversion (PNG, HEIC, TIFF or BMP to JPEG) made the
	 * attached file: its main row says so, from a format other than the file's.
	 *
	 * @param array<string, mixed> $extra    The main row's `extra_info`.
	 * @param string               $attached The attached file, relative to the uploads folder.
	 */
	protected function converted( array $extra, string $attached ): bool {
		$meta = isset( $extra['convertMeta'] ) && is_array( $extra['convertMeta'] ) ? $extra['convertMeta'] : array();
		$from = isset( $meta['fileFormat'] ) ? strtolower( (string) $meta['fileFormat'] ) : '';

		return ! empty( $meta['isConverted'] ) && '' !== $from && strtolower( pathinfo( $attached, PATHINFO_EXTENSION ) ) !== $from;
	}

	/**
	 * What ShortPixel before 5.0 left in the attachment's metadata, which it moves
	 * into its table only when it loads the image again: an improvement recorded
	 * means it compressed the attached file and WordPress's original in place.
	 * Those records keep no bytes, so the files are taken as its output while
	 * they are there, and no backup is trusted.
	 *
	 * @param int                   $attachment The attachment's id.
	 * @param array<string, mixed>  $metadata   Its metadata.
	 * @param array<string, string> $names      Its files by WordPress's names.
	 * @return Found|null
	 */
	protected function legacy( int $attachment, array $metadata, array $names ): ?array {
		$improvement = $metadata['ShortPixelImprovement'] ?? null;

		if ( ! isset( $metadata['ShortPixel'] ) || ! is_numeric( $improvement ) || (float) $improvement <= 0 ) {
			return null;
		}

		$outputs = array();

		foreach ( array( 'full', 'original' ) as $name ) {
			if ( isset( $names[ $name ] ) && self::bytes( $attachment, $names[ $name ] ) >= 0 ) {
				$outputs[] = $names[ $name ];
			}
		}

		return array() === $outputs ? null : array(
			'files'     => array(),
			'outputs'   => $outputs,
			'backups'   => array(),
			'copies'    => array(),
			'outside'   => array(),
			'elsewhere' => '',
		);
	}

	public function optimized_files( array $paths ): array {
		global $wpdb;

		if ( array() === $paths || ! self::table_exists( $wpdb->prefix . self::FOLDERS_TABLE ) ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $paths ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the files of a folder's page in one query; one placeholder per path, built above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT path, compressed_size FROM {$wpdb->prefix}shortpixel_meta WHERE status = %d AND path IN ({$in})", array_merge( array( 2 ), $paths ) ), ARRAY_A );
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$path = (string) $row['path'];

			clearstatcache( true, $path );

			if ( in_array( $path, $paths, true ) && is_file( $path ) && (int) $row['compressed_size'] > 0 && (int) filesize( $path ) === (int) $row['compressed_size'] ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * The file a row stands for, relative to the uploads folder, or null for a
	 * retina copy, a translation's pointer or a size the metadata doesn't name.
	 *
	 * @param int                   $type   The row's `image_type`.
	 * @param string                $size   The row's `size`.
	 * @param array<string, mixed>  $extra  The row's `extra_info`.
	 * @param array<string, string> $names  The attachment's files by WordPress's names.
	 * @param string                $folder The attachment's folder, with a trailing slash.
	 */
	protected function path( int $type, string $size, array $extra, array $names, string $folder ): ?string {
		switch ( $type ) {
			case 0:
				return $names['full'];
			case 2:
				return $names['original'] ?? null;
			case 1:
				if ( isset( $extra['file'] ) && is_string( $extra['file'] ) && '' !== $extra['file'] ) {
					return $folder . wp_basename( $extra['file'] );
				}

				return in_array( $size, array( 'full', 'original' ), true ) ? null : ( $names[ $size ] ?? null );
			default:
				return null;
		}
	}

	/**
	 * The backup ShortPixel kept of a file, when one passes the size check. Its
	 * path is the file's folder from the site's home folder, which ShortPixel reads
	 * from `get_home_path()`, a value that changes with the request, so each folder
	 * it can have used is tried.
	 *
	 * @param string $relative The file, relative to the uploads folder.
	 * @param int    $before   The bytes ShortPixel recorded before its pass.
	 */
	protected function backup( string $relative, int $before ): ?string {
		$file = wp_normalize_path( Paths::absolute( $relative ) );

		foreach ( self::backup_roots() as $root ) {
			foreach ( self::from_home( $file ) as $from ) {
				$found = self::verified( $root . '/' . $from, $before );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Where ShortPixel keeps its backups: under the uploads folder, the network's
	 * on a site of a network.
	 *
	 * @return list<string>
	 */
	protected static function backup_roots(): array {
		$roots = array( Paths::uploads() . '/' . self::BACKUP_FOLDER );
		$main  = Places::network_uploads();

		if ( null !== $main ) {
			$roots[] = $main . '/' . self::BACKUP_FOLDER;
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * The paths a file can have from the folders ShortPixel takes for the site's
	 * home: WordPress's own folder, the one above it, and from `wp-content` or the
	 * uploads folder on.
	 *
	 * @param string $file The file's absolute path.
	 * @return list<string>
	 */
	protected static function from_home( string $file ): array {
		$from = array();
		$abs  = untrailingslashit( wp_normalize_path( ABSPATH ) );

		foreach ( array( $abs, dirname( $abs ) ) as $home ) {
			if ( '' !== $home && 0 === strpos( $file, $home . '/' ) ) {
				$from[] = substr( $file, strlen( $home ) + 1 );
			}
		}

		$content = '/' . wp_basename( WP_CONTENT_DIR ) . '/';
		$at      = strpos( $file, $content );

		if ( false !== $at ) {
			$from[] = substr( $file, $at + 1 );
		}

		$at = strpos( $file, '/uploads/' );

		if ( false !== $at ) {
			$from[] = substr( $file, $at + 1 );
		}

		return array_values( array_unique( array_filter( $from ) ) );
	}
}
