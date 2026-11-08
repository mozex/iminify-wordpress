<?php
/**
 * What Modern Image Formats left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern Image Formats (read from 2.7.1), the WordPress performance team's
 * plugin, has WordPress make every upload in AVIF (or WebP where the server can't
 * make AVIF) while it is active, with no setting that stops it. Its records are
 * `sources` in the attachment's metadata, at the top and in each size: for each
 * type, a file and its `filesize`.
 *
 * - Without its JPEG fallback (the usual case) the attachment's own files are
 *   the AVIFs (`photo.avif`, `photo-scaled.avif`, `photo-300x200.avif`), made from
 *   the upload, which WordPress keeps untouched as its `original_image`, while
 *   the attachment still says `image/jpeg`. Those files are its output, while they
 *   have the bytes the metadata gives them, and the upload is the original.
 *   Restoring the JPEG would give the attachment new addresses and break the posts
 *   linking to it, which Iminify never does.
 * - With its fallback the JPEGs stay as they were and each AVIF is a copy beside
 *   its file (`photo-jpg.avif`), which `sources` names.
 *
 * It keeps no backups, and its uninstall leaves its files and records.
 */
class Modern_Formats extends Reader {

	/**
	 * The formats it makes.
	 */
	const MODERN = array( 'image/avif', 'image/webp' );

	/**
	 * Whether an attachment it converted was found, kept for a day.
	 */
	const FOUND = 'iminify_modern_formats_found';

	public function slug(): string {
		return 'modern-formats';
	}

	public function name(): string {
		return 'Modern Image Formats';
	}

	public function switch_off(): string {
		return __( 'Deactivate Modern Image Formats: no setting of its own stops it.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'webp-uploads/load.php' );
	}

	protected function loaded(): bool {
		return defined( 'WEBP_UPLOADS_VERSION' );
	}

	/**
	 * Always, while it is active: no setting stops it.
	 */
	protected function optimizes_uploads(): bool {
		return true;
	}

	/**
	 * The format it makes, saved only once someone saves Settings, Media.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'perflab_modern_image_format' => null,
		);
	}

	/**
	 * The plugin itself, its fallback setting (which only its uninstall deletes), or
	 * an attachment it left: one that still says JPEG or PNG while its file is AVIF
	 * or WebP. Its format setting is stored only when someone saves its settings.
	 */
	protected function holds_records(): bool {
		if ( $this->active() ) {
			set_transient( self::FOUND, '1', DAY_IN_SECONDS );

			return true;
		}

		if ( false !== get_option( 'perflab_generate_webp_and_jpeg' ) ) {
			return true;
		}

		/* The look over every attachment is kept for a day, and forgotten when the plugin is turned off, so a site that never ran it pays it once a day; a send reads each image's metadata whatever it says (`in_metadata()`). */
		$found = get_transient( self::FOUND );

		if ( false === $found ) {
			$found = self::converted( true );
			set_transient( self::FOUND, $found, DAY_IN_SECONDS );
		}

		return '1' === $found;
	}

	public function deactivated(): void {
		delete_transient( self::FOUND );
	}

	public function in_metadata(): bool {
		return true;
	}

	/**
	 * How many attachments it converted, which changes as it converts more.
	 */
	public function stamp(): string {
		return self::converted( false );
	}

	/**
	 * How many attachments say JPEG or PNG while their file is AVIF or WebP, or
	 * whether there is one, by a scan of the attachments' attached-file rows.
	 *
	 * @param bool $any Whether one is enough.
	 */
	protected static function converted( bool $any ): string {
		global $wpdb;

		$avif = '%' . $wpdb->esc_like( '.avif' );
		$webp = '%' . $wpdb->esc_like( '.webp' );

		if ( $any ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request, on the type's index.
			return null === $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg', 'image/png') AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 1", '_wp_attached_file', $avif, $webp ) ) ? '0' : '1';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count on the plugin's own pages, on the type's index.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg', 'image/png') AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )", '_wp_attached_file', $avif, $webp ) );
	}

	public function read( int $attachment, array $metadata ): ?array {
		$type  = (string) get_post_mime_type( $attachment );
		$names = self::files( $attachment, $metadata );

		if ( ! isset( $names['full'] ) || ! self::has_sources( $metadata ) ) {
			return null;
		}

		$folder   = dirname( $names['full'] );
		$folder   = '.' === $folder ? '' : $folder . '/';
		$files    = array();
		$backups  = array();
		$copies   = array();
		$original = isset( $names['original'] ) ? Places::upload( $names['original'] ) : null;

		foreach ( $names as $size => $relative ) {
			if ( 'original' === $size ) {
				continue;
			}

			$entry   = 'full' === $size ? $metadata : ( $metadata['sizes'][ $size ] ?? array() );
			$entry   = is_array( $entry ) ? $entry : array();
			$sources = isset( $entry['sources'] ) && is_array( $entry['sources'] ) ? $entry['sources'] : array();
			$format  = (string) wp_check_filetype( $relative )['type'];

			foreach ( $sources as $mime => $source ) {
				if ( in_array( $mime, self::MODERN, true ) && is_array( $source ) && ! empty( $source['file'] ) && $folder . wp_basename( (string) $source['file'] ) !== $relative ) {
					$copies[ $folder . wp_basename( (string) $source['file'] ) ] = $relative;
				}
			}

			/* Its own files take the attachment's place only in a format the attachment doesn't say it has. */
			if ( ! in_array( $format, self::MODERN, true ) || $format === $type ) {
				continue;
			}

			$recorded = isset( $sources[ $format ]['filesize'] ) ? (int) $sources[ $format ]['filesize'] : (int) ( $entry['filesize'] ?? 0 );

			if ( $recorded <= 0 ) {
				continue;
			}

			$files[ $relative ] = array(
				'size'   => $size,
				'before' => 'full' === $size && null !== $original ? self::size_of( $original ) : $recorded,
				'after'  => $recorded,
			);

			if ( 'full' === $size && null !== $original ) {
				$backups[ $relative ] = $original;
			}
		}

		return self::answer( $attachment, $files, $backups, $copies, array() === $files ? '' : $this->restore_elsewhere() );
	}

	public function restore_elsewhere(): string {
		return __( 'Modern Image Formats replaced this image\'s files with modern-format ones made from its original, which WordPress keeps. Putting the original back would change the image\'s addresses and break the posts that link to them, so Iminify doesn\'t restore it.', 'iminify-image-optimizer' );
	}

	/**
	 * Whether the metadata carries `sources`, at the top or in a size.
	 *
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 */
	protected static function has_sources( array $metadata ): bool {
		if ( isset( $metadata['sources'] ) && is_array( $metadata['sources'] ) ) {
			return true;
		}

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['sources'] ) && is_array( $size['sources'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A file's bytes now.
	 *
	 * @param string $path An absolute path.
	 */
	protected static function size_of( string $path ): int {
		clearstatcache( true, $path );

		return (int) filesize( $path );
	}
}
