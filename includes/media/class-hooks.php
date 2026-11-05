<?php
/**
 * Catches uploads, edits, regenerated sizes and deletions.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Plugin;

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
 */
class Hooks {

	/**
	 * Hooks the pipeline into WordPress.
	 */
	public static function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'generated' ), 50, 2 );
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'updated' ), 50, 2 );
		add_action( 'delete_attachment', array( self::class, 'deleted' ) );
		add_action( Optimizer::OPTIMIZE_HOOK, array( Optimizer::class, 'optimize' ), 10, 2 );
		add_action( Optimizer::CHECK_HOOK, array( Optimizer::class, 'check' ) );
		add_action( Optimizer::DELETE_GROUP_HOOK, array( Optimizer::class, 'delete_group' ), 10, 2 );
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
	 * background.
	 *
	 * @param mixed $attachment The attachment's id.
	 */
	public static function deleted( $attachment ): void {
		$attachment = (int) $attachment;
		$record     = Records::get( $attachment );

		foreach ( $record['copies'] as $copy => $about ) {
			if ( is_array( $about ) && Records::ORIGIN === ( $about['origin'] ?? null ) ) {
				wp_delete_file( Paths::absolute( (string) $copy ) );
			}
		}

		Backups::forget( $record['backups'] );

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $attachment, Optimizer::AUTOMATIC ), Optimizer::GROUP );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $attachment, Optimizer::MANUAL ), Optimizer::GROUP );
		as_unschedule_all_actions( Optimizer::CHECK_HOOK, array( $attachment ), Optimizer::GROUP );

		if ( null !== $record['group'] && '' !== (string) ( $record['group']['id'] ?? '' ) ) {
			as_schedule_single_action( time(), Optimizer::DELETE_GROUP_HOOK, array( (string) $record['group']['id'], 0 ), Optimizer::GROUP );
		}
	}

	/**
	 * An attachment's files changed: a group already open for the old ones
	 * writes nothing, and the attachment is queued while the site is connected,
	 * or marked not optimized for the bulk page to pick up later.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function changed( int $attachment ): void {
		if ( ! Optimizer::is_optimizable( $attachment ) ) {
			return;
		}

		Records::bump( $attachment );

		if ( null === Plugin::keys()->get() ) {
			Records::fail( $attachment, Records::NOT_OPTIMIZED, 'not_connected', __( 'Not optimized: the site isn\'t connected to Iminify.', 'iminify-image-optimizer' ) );

			return;
		}

		Optimizer::queue( $attachment );
	}
}
