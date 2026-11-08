<?php
/**
 * What LiteSpeed Cache's image optimization left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeed Cache (read from 7.9.3) usually stays active for its cache. Its image
 * optimization acts when its "Auto Request Cron" sends the library to QUIC.cloud,
 * which needs the site's QUIC.cloud account, or while images it already sent are
 * on their way back (rows of `{prefix}litespeed_img_optming`): each that comes back
 * replaces its file. Its "Auto Rescale Original Images" moves each upload's
 * `-scaled` copy over its original on every request, with no backup, which would
 * leave the plugin's records naming files that moved.
 *
 * It records no bytes per file: `litespeed-optimize-size` holds the attachment's
 * totals once its results came back, and `litespeed-optimize-set` the site's
 * sizes once it queued the attachment. The files tell the rest. It writes over the
 * attached file and each size (never WordPress's original beside a `-scaled` copy,
 * and only a lowercase `jpg`, `jpeg`, `png` or `gif`), and never the metadata's
 * `filesize`, so a file whose bytes are no longer the `filesize` WordPress wrote is
 * its output. Before writing it copies the file once beside it as
 * `photo.bk.jpg`, never written again, which is the original only with the bytes
 * that `filesize` gives: a backup taken after its backups were off for a pass, or
 * after its "Remove Original Image Backups", is of a file it had compressed. With
 * its backups off, or removed, its output stands alone with no original, read as
 * its once results came back with an optimized original (`ori_total`, which a
 * WebP- or AVIF-only result leaves out). Its own "Restore original" moves the `.bk.` back
 * and parks its output as `photo.bk.optm.jpg`, so a file beside one is the original
 * again. Its WebP and AVIF copies are `photo.jpg.webp` and `photo.jpg.avif`, the
 * plugin's own names, and no record names them: those the plugin's record names as
 * its own are never listed as LiteSpeed's.
 */
class Litespeed extends Reader {

	/**
	 * Its post meta, whose presence says it took the attachment.
	 */
	const META = array( 'litespeed-optimize-size', 'litespeed-optimize-set' );

	/**
	 * The extensions it optimizes, as it compares them, case included.
	 */
	const TYPES = array( 'jpg', 'jpeg', 'png', 'gif' );

	public function slug(): string {
		return 'litespeed';
	}

	public function name(): string {
		return 'LiteSpeed Cache';
	}

	public function switch_off(): string {
		return __( 'Turn off "Auto Request Cron" under LiteSpeed Cache, Image Optimization, and "Auto Rescale Original Images" under Page Optimization, Media Settings, and let the images it already sent come back, or deactivate LiteSpeed Cache.', 'iminify-image-optimizer' );
	}

	protected function basenames(): array {
		return array( 'litespeed-cache/litespeed-cache.php' );
	}

	protected function loaded(): bool {
		return defined( 'LSCWP_V' );
	}

	/**
	 * Its "Auto Request Cron" on with a QUIC.cloud account to send with, images
	 * still on their way back, or its "Auto Rescale Original Images" on, read
	 * only while LiteSpeed is loaded, since its settings filter answers
	 * its own name otherwise. It keeps an array setting as JSON in its option.
	 */
	protected function optimizes_uploads(): bool {
		global $wpdb;

		if ( ! $this->loaded() ) {
			return false;
		}

		$rescale = apply_filters( 'litespeed_conf', 'media-auto_rescale_ori' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own documented filter, which answers its setting after every override.

		if ( 'media-auto_rescale_ori' !== $rescale && ! empty( $rescale ) ) {
			return true;
		}

		$auto    = apply_filters( 'litespeed_conf', 'img_optm-auto' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- as above.
		$summary = get_option( 'litespeed.cloud._summary' );
		$summary = is_string( $summary ) ? json_decode( $summary, true ) : $summary;
		$account = is_array( $summary ) && ! empty( $summary['sk_b64'] ) && ! empty( $summary['qc_activated'] );

		if ( 'img_optm-auto' !== $auto && ! empty( $auto ) && $account ) {
			return true;
		}

		if ( ! self::table_exists( $wpdb->prefix . 'litespeed_img_optming' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one look a request at another plugin's table.
		return null !== $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}litespeed_img_optming LIMIT 1" );
	}

	/**
	 * Only once it optimized images here, or optimizes uploads by itself: it
	 * writes every default on install, and most sites keep it for its cache.
	 */
	public function settings_chosen(): bool {
		if ( $this->present() ) {
			return true;
		}

		$auto = $this->stored( 'litespeed.conf.img_optm-auto' );

		return self::MISSING !== $auto && ! empty( $auto );
	}

	/**
	 * One option per setting, booleans as `'1'` or empty and lists as JSON, sent as stored.
	 *
	 * @return array<string, list<string>|null>
	 */
	protected function setting_options(): array {
		return array(
			'litespeed.conf.img_optm-ori'           => null,
			'litespeed.conf.img_optm-lossless'      => null,
			'litespeed.conf.img_optm-exif'          => null,
			'litespeed.conf.img_optm-webp'          => null,
			'litespeed.conf.img_optm-rm_bkup'       => null,
			'litespeed.conf.img_optm-sizes_skipped' => null,
		);
	}

	/**
	 * Its WebP setting is the network's while the site follows the network's
	 * configuration (`follows_network()`). Its other image settings are then the
	 * main site's on every other site while the network's "Use Primary Site
	 * Configuration" is on (`use_primary_settings`, which
	 * `Conf::_try_load_site_options()` lays over a site's own), and the site's
	 * own otherwise.
	 *
	 * @param string $option The option's name.
	 * @return mixed
	 */
	protected function stored( string $option ) {
		if ( 'litespeed.conf.img_optm-webp' === $option ) {
			return $this->follows_network() ? get_site_option( $option, self::MISSING ) : get_option( $option, self::MISSING );
		}

		$primary = $this->primary_site();

		if ( null === $primary ) {
			return get_option( $option, self::MISSING );
		}

		switch_to_blog( $primary );
		$value = get_option( $option, self::MISSING );
		restore_current_blog();

		return $value;
	}

	/**
	 * Whether this site runs, or last ran, on the network's configuration: while
	 * LiteSpeed is active for the network, and once it is turned off there, while
	 * it isn't active on this site and the network's settings are still kept
	 * (`litespeed.conf._version`, which only a network activation writes and its
	 * uninstall deletes). Each site keeps LiteSpeed's install defaults in its own
	 * options, which it never ran on, so they are no answer then.
	 */
	protected function follows_network(): bool {
		if ( $this->network_active() ) {
			return true;
		}

		return is_multisite() && ! $this->active() && false !== get_site_option( 'litespeed.conf._version', false );
	}

	/**
	 * The main site whose settings this site follows, or null: only while it
	 * follows the network's configuration with "Use Primary Site Configuration" on,
	 * and only on a site other than the main one, as LiteSpeed decides it (by
	 * `BLOG_ID_CURRENT_SITE`).
	 */
	protected function primary_site(): ?int {
		if ( ! $this->follows_network() || empty( get_site_option( 'litespeed.conf.use_primary_settings' ) ) ) {
			return null;
		}

		$primary = defined( 'BLOG_ID_CURRENT_SITE' ) ? (int) BLOG_ID_CURRENT_SITE : get_main_site_id();

		return get_current_blog_id() === $primary ? null : $primary;
	}

	protected function holds_records(): bool {
		return self::meta_exists( self::META );
	}

	public function stamp(): string {
		return self::meta_stamp( self::META );
	}

	public function read( int $attachment, array $metadata ): ?array {
		/* Its results came back with an optimized original only when its totals count one: WebP- or AVIF-only results write the totals too. */
		$totals = get_post_meta( $attachment, 'litespeed-optimize-size', true );
		$done   = is_array( $totals ) && ! empty( $totals['ori_total'] );

		if ( ! $done && ! metadata_exists( 'post', $attachment, 'litespeed-optimize-size' ) && ! metadata_exists( 'post', $attachment, 'litespeed-optimize-set' ) ) {
			return null;
		}

		$own     = Copies::own( Records::get( $attachment )['copies'] );
		$files   = array();
		$backups = array();
		$copies  = array();

		foreach ( self::files( $attachment, $metadata ) as $size => $relative ) {
			if ( 'original' === $size || ! in_array( pathinfo( $relative, PATHINFO_EXTENSION ), self::TYPES, true ) ) {
				continue;
			}

			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( ! isset( $own[ $relative . '.' . $format ] ) ) {
					$copies[ $relative . '.' . $format ] = $relative;
				}
			}

			$live = Places::upload( $relative );

			if ( null === $live ) {
				continue;
			}

			clearstatcache( true, $live );

			$bytes    = (int) filesize( $live );
			$recorded = self::recorded( $metadata, $size );
			$backup   = Places::upload( self::backup_of( $relative, 'bk.' ) );

			/* A file with the bytes WordPress wrote is no file it wrote; one beside its parked output is the original its own restore put back. */
			if ( $recorded === $bytes || ( null === $backup && ( ! $done || null !== Places::upload( self::backup_of( $relative, 'bk.optm.' ) ) ) ) ) {
				continue;
			}

			$kept = null === $backup ? 0 : self::size_of( $backup );

			$files[ $relative ] = array(
				'size'   => $size,
				'before' => $recorded > 0 ? $recorded : ( $kept > 0 ? $kept : $bytes ),
				'after'  => $bytes,
			);

			if ( null !== $backup && $recorded > 0 && $kept === $recorded ) {
				$backups[ $relative ] = $backup;
			}
		}

		return self::answer( $attachment, $files, $backups, $copies );
	}

	/**
	 * A file LiteSpeed keeps beside another: its backup (`photo.bk.jpg` beside
	 * `photo.jpg`) or, after its own restore, its parked output (`photo.bk.optm.jpg`).
	 *
	 * @param string $relative The file, relative to the uploads folder.
	 * @param string $infix    `bk.` or `bk.optm.`.
	 */
	protected static function backup_of( string $relative, string $infix ): string {
		$extension = pathinfo( $relative, PATHINFO_EXTENSION );

		return substr( $relative, 0, -strlen( $extension ) ) . $infix . $extension;
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

	/**
	 * The bytes WordPress recorded for a file when it made it, or 0 where the
	 * metadata gives none (an upload made before WordPress 6.0).
	 *
	 * @param array<string, mixed> $metadata The attachment's metadata.
	 * @param string               $size     The file's name: `full` or a size's.
	 */
	protected static function recorded( array $metadata, string $size ): int {
		if ( 'full' === $size ) {
			return isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : 0;
		}

		return isset( $metadata['sizes'][ $size ]['filesize'] ) ? (int) $metadata['sizes'][ $size ]['filesize'] : 0;
	}
}
