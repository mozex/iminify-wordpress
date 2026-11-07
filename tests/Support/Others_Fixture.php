<?php
/**
 * Images another optimizer optimized, left as that plugin leaves them.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Switching\Others;

/**
 * For the tests of the readers (`ReadersTest`, `SwitchingTest`). Each plugin's
 * records and files are written as its code writes them (the shapes in each
 * reader's docblock, EWWW's rows captured from it running in local mode), over a
 * real upload whose files are compressed for real, so every byte count the
 * records name is the file's. A test using it calls `forget_others()` in its
 * tear-down.
 */
trait Others_Fixture {

	/**
	 * Files and folders the fixture made outside an attachment's own, removed afterwards.
	 *
	 * @var list<string>
	 */
	protected $others_made = array();

	/**
	 * A JPEG upload WordPress scaled: `canola-scaled.jpg` attached, `canola.jpg` its original.
	 */
	protected function scaled_upload(): int {
		$scale = static function (): int {
			return 500;
		};

		add_filter( 'big_image_size_threshold', $scale );
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		remove_filter( 'big_image_size_threshold', $scale );

		return $id;
	}

	/**
	 * A JPEG upload WordPress didn't scale.
	 */
	protected function plain_upload(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * The attachment's files by WordPress's names, absolute: `full`, `original` beside a `-scaled` copy, and each size.
	 *
	 * @param int $id The attachment's id.
	 * @return array<string, string>
	 */
	protected function named( int $id ): array {
		$attached = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$folder   = dirname( $attached );
		$files    = array( 'full' => $attached );

		if ( ! empty( $metadata['original_image'] ) ) {
			$files['original'] = $folder . '/' . $metadata['original_image'];
		}

		foreach ( $metadata['sizes'] as $size => $about ) {
			$files[ $size ] = $folder . '/' . $about['file'];
		}

		return $files;
	}

	/**
	 * Compresses a file in place, as another optimizer does, keeping a copy of it
	 * first where a backup goes when one is asked for.
	 *
	 * @param string      $path   The file.
	 * @param string|null $backup Where to keep the untouched file, or null for none.
	 * @return array{before: int, after: int}
	 */
	protected function squeeze( string $path, ?string $backup = null ): array {
		clearstatcache( true, $path );
		$before = (int) filesize( $path );

		if ( null !== $backup ) {
			$this->keep_copy( $path, $backup );
		}

		$editor = wp_get_image_editor( $path );
		$editor->set_quality( 20 );
		$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );
		copy( $saved['path'], $path );
		wp_delete_file( $saved['path'] );
		clearstatcache( true, $path );

		return array(
			'before' => $before,
			'after'  => (int) filesize( $path ),
		);
	}

	/**
	 * Copies a file to a place, making its folders, and remembers the place.
	 *
	 * @param string $from The file.
	 * @param string $to   Where to copy it.
	 */
	protected function keep_copy( string $from, string $to ): void {
		wp_mkdir_p( dirname( $to ) );
		copy( $from, $to );
		$this->others_made[] = $to;
	}

	/**
	 * A file's path from WordPress's own folder, as ShortPixel and EWWW write it.
	 *
	 * @param string $path An absolute path.
	 */
	protected function from_abspath( string $path ): string {
		return substr( wp_normalize_path( $path ), strlen( trailingslashit( wp_normalize_path( ABSPATH ) ) ) );
	}

	/**
	 * Imagify's records: the attached file and the sizes compressed with its
	 * service's numbers, its backup of the attached file (and of WordPress's
	 * original, which it never changes), and a WebP copy of the medium size.
	 *
	 * @param int  $id     The attachment's id.
	 * @param bool $backup Whether its backups are on.
	 * @return array<string, array{before: int, after: int}> The numbers, by size.
	 */
	protected function as_imagify( int $id, bool $backup = true ): array {
		$files   = $this->named( $id );
		$backups = Paths::uploads() . '/backup/';
		$sizes   = array();
		$numbers = array();

		foreach ( array( 'full', 'thumbnail', 'medium' ) as $size ) {
			$numbers[ $size ] = $this->squeeze( $files[ $size ], $backup && 'full' === $size ? $backups . Paths::relative( $files['full'] ) : null );
			$sizes[ $size ]   = array(
				'success'        => true,
				'original_size'  => $numbers[ $size ]['before'],
				'optimized_size' => $numbers[ $size ]['after'],
				'percent'        => 10.5,
			);
		}

		if ( $backup && isset( $files['original'] ) ) {
			$this->keep_copy( $files['original'], $backups . Paths::relative( $files['original'] ) );
		}

		file_put_contents( $files['medium'] . '.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another plugin's copy.
		$this->others_made[]          = $files['medium'] . '.webp';
		$sizes['medium@imagify-webp'] = array(
			'success'        => true,
			'original_size'  => 9000,
			'optimized_size' => 4,
			'percent'        => 99.9,
		);

		update_post_meta( $id, '_imagify_status', 'success' );
		update_post_meta( $id, '_imagify_optimization_level', '2' );
		update_post_meta(
			$id,
			'_imagify_data',
			array(
				'sizes' => $sizes,
				'stats' => array(
					'original_size'  => 1,
					'optimized_size' => 1,
					'percent'        => 1,
					'message'        => '',
				),
			)
		);
		Others::forget();

		return $numbers;
	}

	/**
	 * ShortPixel's rows, with every file compressed in place, WordPress's original
	 * included, each backed up under its folder from WordPress's own.
	 *
	 * @param int  $id      The attachment's id.
	 * @param bool $backups Whether its backups are on.
	 * @return array<string, array{before: int, after: int}> The numbers, by size.
	 */
	protected function as_shortpixel( int $id, bool $backups = true ): array {
		global $wpdb;

		$this->shortpixel_table();

		$files   = $this->named( $id );
		$numbers = array();

		foreach ( array( 'full', 'original', 'thumbnail', 'medium' ) as $size ) {
			if ( ! isset( $files[ $size ] ) ) {
				continue;
			}

			$numbers[ $size ] = $this->squeeze( $files[ $size ], $backups ? Paths::uploads() . '/ShortpixelBackups/' . $this->from_abspath( $files[ $size ] ) : null );
			$type             = 'full' === $size ? 0 : ( 'original' === $size ? 2 : 1 );

			if ( 'medium' === $size ) {
				file_put_contents( dirname( $files['medium'] ) . '/' . wp_basename( $files['medium'], '.jpg' ) . '.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another plugin's copy.
				$this->others_made[] = dirname( $files['medium'] ) . '/' . wp_basename( $files['medium'], '.jpg' ) . '.webp';
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, as it writes it.
				$wpdb->prefix . 'shortpixel_postmeta',
				array(
					'attach_id'        => $id,
					'parent'           => 0 === $type ? 0 : $id,
					'image_type'       => $type,
					'size'             => 1 === $type ? $size : null,
					'status'           => 2,
					'compression_type' => 1,
					'compressed_size'  => $numbers[ $size ]['after'],
					'original_size'    => $numbers[ $size ]['before'],
					'extra_info'       => wp_json_encode( 'medium' === $size ? array( 'webp' => wp_basename( $files['medium'], '.jpg' ) . '.webp' ) : array( 'did_keepExif' => 0 ) ),
				)
			);
		}

		Others::forget();

		return $numbers;
	}

	/**
	 * Makes ShortPixel's table for the attachments, as its installer writes it.
	 */
	protected function shortpixel_table(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- another plugin's table, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shortpixel_postmeta ( id bigint unsigned NOT NULL AUTO_INCREMENT, attach_id bigint unsigned NOT NULL, parent bigint unsigned NOT NULL, image_type tinyint default 0, size varchar(200), status tinyint default 0, compression_type tinyint, compressed_size int, original_size int, tsAdded timestamp NULL, tsOptimized timestamp NULL, extra_info LONGTEXT, PRIMARY KEY id (id) )" );
	}

	/**
	 * EWWW's rows for an upload it didn't scale, from the rows captured from it
	 * running in local mode (`tests/fixtures/others/ewww-rows.json`), each file
	 * compressed and backed up under `wp-content/ewww/image-backup/uploads/`.
	 *
	 * @param int $id The attachment's id.
	 * @return array<string, array{before: int, after: int}> The numbers, by size.
	 */
	protected function as_ewww( int $id ): array {
		global $wpdb;

		$this->ewww_table();

		$captured = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/others/ewww-rows.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a fixture.
		$files    = $this->named( $id );
		$numbers  = array();

		foreach ( $captured as $row ) {
			$size = 'full' === $row['resize'] ? 'full' : $row['resize'];

			if ( ! isset( $files[ $size ] ) ) {
				continue;
			}

			$numbers[ $size ] = $this->squeeze( $files[ $size ], WP_CONTENT_DIR . '/ewww/image-backup/uploads/' . Paths::relative( $files[ $size ] ) );

			file_put_contents( $files[ $size ] . '.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another plugin's copy.
			$this->others_made[] = $files[ $size ] . '.webp';

			$row['attachment_id'] = $id;
			$row['path']          = 'ABSPATH' . $this->from_abspath( $files[ $size ] );
			$row['image_size']    = $numbers[ $size ]['after'];
			$row['orig_size']     = $numbers[ $size ]['before'];

			$wpdb->insert( $wpdb->prefix . 'ewwwio_images', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, as it writes it.
		}

		$this->others_made[] = WP_CONTENT_DIR . '/ewww';
		Others::forget();

		return $numbers;
	}

	/**
	 * Makes EWWW's table, with the columns its rows carry.
	 */
	protected function ewww_table(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- another plugin's table, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ewwwio_images ( id int unsigned NOT NULL AUTO_INCREMENT, attachment_id bigint unsigned, gallery varchar(10), resize varchar(75), path text NOT NULL, converted text NOT NULL, image_size int unsigned, orig_size int unsigned, backup varchar(100), retrieve varchar(100), level int unsigned, resized_width smallint unsigned, resized_height smallint unsigned, resize_error tinyint unsigned, webp_size int unsigned, webp_error tinyint unsigned, pending tinyint NOT NULL DEFAULT 0, updates int unsigned, updated timestamp NULL, PRIMARY KEY (id) )" );
	}

	/**
	 * Smush's records: WordPress's original (`full` to Smush), the `-scaled` copy
	 * (`wp_scaled`) and the sizes compressed, its original backed up beside it as
	 * `canola.bak.jpg`, named in core's backup sizes.
	 *
	 * @param int  $id     The attachment's id.
	 * @param bool $backup Whether its backup is on.
	 * @return array<string, array{before: int, after: int}> The numbers, by Smush's size names.
	 */
	protected function as_smush( int $id, bool $backup = true ): array {
		$files   = $this->named( $id );
		$numbers = array();
		$sizes   = array();
		$map     = isset( $files['original'] ) ? array(
			'full'      => $files['original'],
			'wp_scaled' => $files['full'],
		) : array( 'full' => $files['full'] );

		$map['thumbnail'] = $files['thumbnail'];

		foreach ( $map as $size => $path ) {
			$bak              = 'full' === $size && $backup ? dirname( $path ) . '/' . wp_basename( $path, '.jpg' ) . '.bak.jpg' : null;
			$numbers[ $size ] = $this->squeeze( $path, $bak );
			$sizes[ $size ]   = (object) array(
				'time'        => 0.2,
				'bytes'       => $numbers[ $size ]['before'] - $numbers[ $size ]['after'],
				'percent'     => 20.1,
				'size_before' => $numbers[ $size ]['before'],
				'size_after'  => $numbers[ $size ]['after'],
			);
		}

		if ( $backup ) {
			update_post_meta(
				$id,
				'_wp_attachment_backup_sizes',
				array(
					'smush-full' => array(
						'file'   => wp_basename( $map['full'], '.jpg' ) . '.bak.jpg',
						'width'  => 640,
						'height' => 480,
					),
				)
			);
		}

		update_post_meta(
			$id,
			'wp-smpro-smush-data',
			array(
				'stats' => array(
					'size_before' => 1,
					'size_after'  => 1,
					'lossy'       => 0,
				),
				'sizes' => $sizes,
			)
		);
		Others::forget();

		return $numbers;
	}

	/**
	 * TinyPNG's records: the attached file (key `0`), WordPress's original and a
	 * size compressed, its one backup of the original under `tinify_backup/`.
	 *
	 * @param int    $id     The attachment's id.
	 * @param bool   $backup Whether its backup is on.
	 * @param string $key    The meta key: the current one, or the one before 3.7.0.
	 * @return array<string, array{before: int, after: int}> The numbers, by its size keys.
	 */
	protected function as_tinypng( int $id, bool $backup = true, string $key = '_tiny_compress_images' ): array {
		$files   = $this->named( $id );
		$numbers = array();
		$entries = array();
		$map     = array( 0 => $files['full'] );

		if ( isset( $files['original'] ) ) {
			$map['original_unscaled'] = $files['original'];
		}

		$map['thumbnail'] = $files['thumbnail'];
		$original         = $map['original_unscaled'] ?? $map[0];

		foreach ( $map as $size => $path ) {
			$keep             = $backup && $path === $original ? Paths::uploads() . '/tinify_backup/' . Paths::relative( $path ) : null;
			$numbers[ $size ] = $this->squeeze( $path, $keep );
			$entries[ $size ] = array(
				'input'  => array(
					'size' => $numbers[ $size ]['before'],
					'type' => 'image/jpeg',
				),
				'output' => array(
					'size'   => $numbers[ $size ]['after'],
					'type'   => 'image/jpeg',
					'width'  => 100,
					'height' => 100,
					'ratio'  => 0.5,
				),
				'end'    => time(),
			);
		}

		$entries['medium'] = array();

		update_post_meta( $id, $key, $entries );
		Others::forget();

		return $numbers;
	}

	/**
	 * Removes what the fixture made and the other plugins' tables, and forgets what the readers read.
	 */
	protected function forget_others(): void {
		global $wpdb;

		foreach ( array_reverse( $this->others_made ) as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test file.
			} elseif ( is_dir( $path ) ) {
				$this->rmdir( $path );
			}
		}

		foreach ( array( 'backup', 'ShortpixelBackups', 'tinify_backup' ) as $folder ) {
			if ( is_dir( Paths::uploads() . '/' . $folder ) ) {
				$this->rmdir( Paths::uploads() . '/' . $folder );
			}
		}

		foreach ( array( 'shortpixel_postmeta', 'shortpixel_meta', 'ewwwio_images' ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- another plugin's table, made for a test.
		}

		$this->others_made = array();
		Others::forget();
	}
}
