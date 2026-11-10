<?php
/**
 * Catches uploads, edits, regenerated sizes and deletions.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Reasons;
use Iminify\WordPress\Switching\Others;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each hook only queues the attachment, by its id, and returns: no call to
 * Iminify ever runs inside an upload, which is already busy making sizes.
 *
 * - `wp_generate_attachment_metadata` fires for an upload ('create'), for
 *   regenerated sizes, and, since WordPress 7.1, once more ('update') when the
 *   browser has made a client-side upload's sizes. A REST edit makes a new
 *   attachment, which fires it too.
 * - `wp_update_attachment_metadata` catches the classic image editor, which
 *   saves an edited image (or restores the original) under another file name
 *   without generating metadata.
 * - `delete_attachment` removes the copies and backups the plugin made.
 *
 * Attachments that share their files (a translation WPML or Polylang made) send
 * them through one of them, `Shared::holder()`.
 */
class Hooks {

	/**
	 * Hooks the pipeline into WordPress.
	 */
	public static function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'generated' ), 50, 2 );
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'updated' ), 50, 2 );
		add_action( 'delete_attachment', array( self::class, 'deleted' ) );
		Shared::register();
		add_action( Optimizer::OPTIMIZE_HOOK, array( Optimizer::class, 'optimize' ), 10, 2 );
		add_action( Optimizer::CHECK_HOOK, array( Optimizer::class, 'check' ) );
		add_action( Optimizer::DELETE_GROUP_HOOK, array( Optimizer::class, 'delete_group' ), 10, 2 );
		add_action( Queue::OPENS_HOOK, array( Queue::class, 'batch_opens' ) );
		add_action( Queue::RESUME_HOOK, array( Queue::class, 'resume' ) );
		add_action( Bulk::FEED_HOOK, array( Bulk::class, 'feed' ) );
		Alt_Text::register();
	}

	/**
	 * Queues an attachment whose sizes WordPress just made, and returns its metadata unchanged.
	 *
	 * @param mixed $metadata   The attachment's metadata.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function generated( $metadata, $attachment ) {
		/* Another plugin answering the plugin's own save by generating the metadata again would queue the attachment forever. */
		if ( Writer::$writing !== (int) $attachment ) {
			self::changed( (int) $attachment );
		}

		return $metadata;
	}

	/**
	 * Queues an attachment whose main file changed (an edit under a new name,
	 * one written over the file in place, or restoring the original in the
	 * image editor), and returns its metadata unchanged. Saves while an upload
	 * makes its sizes leave the main file as it was, and the plugin's own save
	 * is left alone.
	 *
	 * @param mixed $metadata   The metadata about to be saved.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public static function updated( $metadata, $attachment ) {
		$attachment = (int) $attachment;

		if ( Writer::$writing === $attachment || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$saved = get_post_meta( $attachment, '_wp_attachment_metadata', true );

		if ( ! is_array( $saved ) || empty( $saved['file'] ) ) {
			return $metadata;
		}

		/* A new name, or the same file written over in place (the image editor with IMAGE_EDIT_OVERWRITE). */
		foreach ( array( 'file', 'filesize', 'width', 'height' ) as $key ) {
			if ( isset( $saved[ $key ], $metadata[ $key ] ) && (string) $saved[ $key ] !== (string) $metadata[ $key ] ) {
				self::changed( $attachment );

				break;
			}
		}

		return $metadata;
	}

	/**
	 * Deletes what the plugin made for an attachment that is being deleted: the
	 * copies its record names as its own and its backups. Its waiting runs are
	 * cancelled, and a group still open is deleted from Iminify in the
	 * background. Files another attachment still names (a translation's) keep
	 * everything the plugin made of them, which the next holder's record takes
	 * over (`Shared::leaves_files()`).
	 *
	 * @param mixed $attachment The attachment's id.
	 */
	public static function deleted( $attachment ): void {
		$attachment = (int) $attachment;
		$shared     = Shared::leaves_files( $attachment );

		Optimizer::cancel( $attachment );

		if ( $shared ) {
			return;
		}

		Copies::delete_own( $attachment );
		Backups::forget( Records::get( $attachment )['backups'] );
	}

	/**
	 * An attachment's files changed: a group already open for the old ones
	 * writes nothing, and the attachment is queued while the site is connected,
	 * or marked not optimized for the bulk page to pick up later.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function changed( int $attachment ): void {
		$given = $attachment;

		/* A translation's files are the holder's to send; a translation plugin copying the holder's own save onto it changed nothing. */
		$attachment = Shared::holder( $attachment, true );

		if ( $attachment !== $given ) {
			Shared::point( $attachment, array( $given ) );
		}

		if ( Writer::$writing === $attachment ) {
			return;
		}

		/* LiteSpeed Cache, active, deletes the WebP and AVIF copies beside an image's files when its metadata is made again, the plugin's own among them, and may put its own old files back over the plugin's: a copy gone is forgotten, and a file put back reads as changed below. */
		Copies::forget_gone( $attachment );

		/* A regeneration that skipped every existing size (Regenerate Thumbnails' default) left the files as they were. */
		if ( Records::unchanged( $attachment ) ) {
			return;
		}

		/* The server's rules serve any copy beside a file, so one that no longer stands for its file goes now, whatever happens to the image next. */
		if ( Rules::active() ) {
			Copies::delete_stale( $attachment );
		}

		$why = Optimizer::why_not( $attachment );

		if ( null !== $why ) {
			/* A regeneration of an image another plugin optimized leaves it that plugin's, and the screens say so. One waiting to be put back from that plugin's original stays on its way: its run puts it back as asked, and a stop still finds it among the images on their way. */
			if ( isset( $why['other'] ) && ! Records::get( $attachment )['redo'] ) {
				Others::mark( $attachment, $why['other'] );
			}

			return;
		}

		Records::bump( $attachment );

		if ( ! Plugin::connected() ) {
			Records::fail( $attachment, Records::NOT_OPTIMIZED, Reasons::of( 'not_connected' ) );

			return;
		}

		Optimizer::queue( $attachment );
	}
}
