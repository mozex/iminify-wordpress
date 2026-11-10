<?php
/**
 * The reasons the plugin keeps beside an image, worded when someone reads them.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use Iminify\WordPress\Switching\Others;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why an image failed, waits or was set aside is kept on its record, its folder
 * row or a bulk run's state long after the request that wrote it, which ran in
 * the language of whoever or whatever made it: a background run writes in the
 * site's language, a press in that person's own. So a reason is kept as a code,
 * which the logic reads, and a key with what fills its words (a plugin's short
 * name, an attachment's id, a status), and is worded again in the language of
 * whoever reads it. Its words as they were written are kept beside them, read
 * only for a key this version doesn't know and for a record kept before keys
 * were. The service's own text has no key: it is worded by `Messages`, which
 * gives the plugin's words for a code it knows and the service's English text
 * for any other.
 */
class Reasons {

	/**
	 * The key of a reason whose words are the service's.
	 */
	const SERVICE = 'service';

	/**
	 * A reason the plugin words itself, as a record keeps it.
	 *
	 * @param string            $code The code the logic reads.
	 * @param string            $say  The key of its words; the code when empty.
	 * @param list<int|string> $args What fills them.
	 * @return array{code: string, message: string, say: string, args: list<int|string>}
	 */
	public static function of( string $code, string $say = '', array $args = array() ): array {
		$say = '' === $say ? $code : $say;

		return array(
			'code'    => $code,
			'message' => (string) self::words( $say, $args ),
			'say'     => $say,
			'args'    => $args,
		);
	}

	/**
	 * A refusal the plugin words itself, which a run can keep as its reason (`from()`).
	 *
	 * @param string               $code The code.
	 * @param string               $say  The key of its words; the code when empty.
	 * @param list<int|string>     $args What fills them.
	 * @param array<string, mixed> $data The error's data, such as its HTTP status.
	 */
	public static function error( string $code, string $say = '', array $args = array(), array $data = array() ): WP_Error {
		$reason = self::of( $code, $say, $args );

		return new WP_Error(
			$code,
			$reason['message'],
			array_merge(
				$data,
				array(
					'say'  => $reason['say'],
					'args' => $args,
				)
			)
		);
	}

	/**
	 * What a record keeps of a refusal: the plugin's key when it worded it, else the service's text.
	 *
	 * @param WP_Error|null $error The refusal, or null for an answer the plugin couldn't read.
	 * @return array{code: string, message: string, say: string, args: list<int|string>}
	 */
	public static function from( ?WP_Error $error ): array {
		if ( null === $error ) {
			return self::of( 'unexpected_response' );
		}

		$code = (string) $error->get_error_code();
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['say'] ) && is_string( $data['say'] ) ) {
			return self::of( $code, $data['say'], isset( $data['args'] ) && is_array( $data['args'] ) ? array_values( $data['args'] ) : array() );
		}

		return array(
			'code'    => $code,
			'message' => $error->get_error_message(),
			'say'     => self::SERVICE,
			'args'    => array(),
		);
	}

	/**
	 * A kept reason in the reader's language, or null when it has none. A hold
	 * for another optimizer names whichever acts now, and gives none once nothing
	 * does, since the image is no longer held back.
	 *
	 * @param mixed $kept The record's `error`.
	 * @param int   $wait Seconds left of a wait the service asked for, if the keeper knows them.
	 */
	public static function text( $kept, int $wait = 0 ): ?string {
		if ( ! is_array( $kept ) || ! isset( $kept['code'] ) || ! is_string( $kept['code'] ) ) {
			return null;
		}

		if ( Others::ACTING === $kept['code'] ) {
			$acting = Others::acting();

			return null === $acting ? null : Others::acting_message( $acting );
		}

		$message = isset( $kept['message'] ) && is_string( $kept['message'] ) ? $kept['message'] : '';
		$words   = null;

		if ( isset( $kept['say'] ) && is_string( $kept['say'] ) ) {
			$args  = isset( $kept['args'] ) && is_array( $kept['args'] ) ? array_values( $kept['args'] ) : array();
			$words = self::SERVICE === $kept['say'] ? Messages::for_code( $kept['code'], $message, $wait ) : self::words( $kept['say'], $args );
		}

		$words = null === $words || '' === $words ? $message : $words;

		return '' === $words ? null : $words;
	}

	/**
	 * A key's words in the language of the request, or null for a key this version doesn't know.
	 *
	 * @param string           $say  The key.
	 * @param list<int|string> $args What fills the words.
	 */
	protected static function words( string $say, array $args ): ?string {
		$first = isset( $args[0] ) ? (string) $args[0] : '';

		switch ( $say ) {
			case 'deactivated':
				return __( 'Waiting to go on: the plugin was turned off while this image was on its way.', 'iminify-image-optimizer' );
			case 'day_spent':
				return __( 'Waiting for the next batch.', 'iminify-image-optimizer' );
			case 'parked':
				return __( 'Waiting for the site to be connected to Iminify.', 'iminify-image-optimizer' );
			case 'stopped':
				return __( 'Not optimized: the bulk run was stopped.', 'iminify-image-optimizer' );
			case 'not_connected':
				return __( 'Not optimized: the site isn\'t connected to Iminify.', 'iminify-image-optimizer' );
			case 'update_required':
				return __( 'Not optimized: update the Iminify plugin first.', 'iminify-image-optimizer' );
			case 'off':
				return __( 'Not optimized: the site doesn\'t optimize uploads.', 'iminify-image-optimizer' );
			case 'disk_full':
				return __( 'Not optimized: the server is low on disk space. Free some up, then optimize this image.', 'iminify-image-optimizer' );
			case 'zip_unavailable':
				return __( 'Not sent: this server\'s PHP can\'t open zip files. Ask your host to turn on PHP\'s zip extension.', 'iminify-image-optimizer' );
			case 'zip_extension':
				return __( 'This server\'s PHP can\'t open zip files: ask your host to turn on PHP\'s zip extension.', 'iminify-image-optimizer' );
			case 'zip_unreadable':
				return __( 'Iminify\'s download couldn\'t be opened.', 'iminify-image-optimizer' );
			case 'file_too_large':
				return __( 'Not sent: this image\'s original is too large to send, past your plan\'s file size or more than this server\'s memory can hold.', 'iminify-image-optimizer' );
			case 'file_too_large_file':
				return __( 'Not sent: this image is past your plan\'s file size, or more than this server\'s memory can hold.', 'iminify-image-optimizer' );
			case 'no_original_left':
				return __( 'Not sent: no original of this image is left to make the optimized files from.', 'iminify-image-optimizer' );
			case 'no_original_left_file':
				return __( 'Not sent: no original of this image is left to make the optimized file from.', 'iminify-image-optimizer' );
			case 'sizes_failed':
				return __( 'WordPress couldn\'t make the missing sizes of this image.', 'iminify-image-optimizer' );
			case 'unsupported_upload':
				return __( 'Kept as uploaded: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' );
			case 'unsupported_file':
				return __( 'Kept as it is: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' );
			case 'left_out_upload':
				return __( 'Kept as uploaded: this site leaves this image out.', 'iminify-image-optimizer' );
			case 'left_out_file':
				return __( 'Kept as it is: this site leaves this image out.', 'iminify-image-optimizer' );
			case 'unreadable_image':
				return __( 'The image couldn\'t be read.', 'iminify-image-optimizer' );
			case 'outside_uploads':
				return __( 'The image isn\'t in the uploads folder.', 'iminify-image-optimizer' );
			case 'file_missing_upload':
				return __( 'Not sent: the image\'s file isn\'t in the uploads folder.', 'iminify-image-optimizer' );
			case 'file_missing_folder':
				return __( 'Not sent: the image\'s file isn\'t there, or is a link to another place.', 'iminify-image-optimizer' );
			case 'file_gone':
				return __( 'The image\'s file or its backup went missing while it was on its way.', 'iminify-image-optimizer' );
			case 'outside_folders':
				return __( 'Not sent: the image is no longer in a folder Iminify optimizes.', 'iminify-image-optimizer' );
			case 'outside_folders_written':
				return __( 'Not written: the image is no longer in a folder Iminify optimizes.', 'iminify-image-optimizer' );
			case 'changed_since':
				return __( 'Changed since Iminify optimized it, so it can be optimized again.', 'iminify-image-optimizer' );
			case 'changed':
				return __( 'The image changed while Iminify was optimizing it, so it\'s sent again.', 'iminify-image-optimizer' );
			case 'shared':
				return sprintf(
					/* translators: %d: the id of the attachment whose files this one shares. */
					__( 'Its files are attachment #%d\'s, as a translation\'s are, so Iminify optimizes them there.', 'iminify-image-optimizer' ),
					(int) $first
				);
			case 'other_plugin':
				return Others::label( $first );
			case 'other_optimizer':
				$acting = Others::reader( $first );

				return null === $acting ? null : Others::acting_message( $acting );
			case 'away':
				$reader = Others::reader( $first );
				$away   = null === $reader ? '' : $reader->away_message();

				return '' === $away ? null : $away;
			case 'backup_failed':
				return __( 'The backup of this image couldn\'t be saved, so it wasn\'t optimized. Check that WordPress can write to the uploads folder, or turn backups off in the Iminify settings.', 'iminify-image-optimizer' );
			case 'backup_stale':
				return __( 'The image changed since its backup was made, and the old backup couldn\'t be moved aside, so it wasn\'t optimized.', 'iminify-image-optimizer' );
			case 'bucket_unreachable':
				return __( 'The image couldn\'t be copied back from WP Offload Media\'s bucket to send it.', 'iminify-image-optimizer' );
			case 'reopened':
				return __( 'Iminify couldn\'t finish this image after several tries.', 'iminify-image-optimizer' );
			case 'restored':
				return __( 'Restored: the original is back.', 'iminify-image-optimizer' );
			case 'restore_failed':
				return __( 'The image couldn\'t be restored.', 'iminify-image-optimizer' );
			case 'restore_bucket':
				return __( 'The original couldn\'t be copied back from WP Offload Media\'s bucket, so the image stays as it was. Try again.', 'iminify-image-optimizer' );
			case 'restore_put_back':
				return __( 'The original couldn\'t be put back, so the image stays as it was.', 'iminify-image-optimizer' );
			case 'restore_unreadable':
				return __( 'WordPress couldn\'t read the original, so the image\'s sizes stay as they were. Try again.', 'iminify-image-optimizer' );
			case 'restore_elsewhere':
				$elsewhere = Others::elsewhere( (int) $first );

				return '' === $elsewhere ? null : $elsewhere;
			case 'nothing_to_restore':
				return __( 'Iminify hasn\'t changed this image, so there\'s nothing to restore.', 'iminify-image-optimizer' );
			case 'nothing_to_restore_file':
				return __( 'Iminify changed nothing in this image, so it already is as it was.', 'iminify-image-optimizer' );
			case 'changed_restore':
				return __( 'This image has changed since Iminify optimized it, so restoring it would overwrite the new one.', 'iminify-image-optimizer' );
			case 'changed_restore_file':
				return __( 'This image has changed since Iminify optimized it, so its backup is no longer its original.', 'iminify-image-optimizer' );
			case 'gone_restore_file':
				return __( 'The image\'s file isn\'t there any more, or isn\'t in a folder Iminify optimizes.', 'iminify-image-optimizer' );
			case 'no_original_restore':
				return __( 'No original of this image was kept, so it can\'t be restored. Backups were off when it was optimized, or the backup was deleted.', 'iminify-image-optimizer' );
			case 'no_original_restore_file':
				return __( 'No original of this image was kept, so it can\'t be restored.', 'iminify-image-optimizer' );
			case 'no_original_theirs':
				return sprintf(
					/* translators: %s: another image optimizer's name, such as Imagify. */
					__( '%s kept no original of this image that Iminify can check, so it can\'t be restored here.', 'iminify-image-optimizer' ),
					Others::name( $first )
				);
			case 'unreachable':
				return sprintf(
					/* translators: %s: the reason the connection failed. */
					__( 'Iminify couldn\'t be reached: %s', 'iminify-image-optimizer' ),
					$first
				);
			case 'http_error':
				return sprintf(
					/* translators: %d: the HTTP status Iminify answered. */
					__( 'Iminify answered with an error (HTTP %d).', 'iminify-image-optimizer' ),
					(int) $first
				);
			case 'unexpected_response':
				return __( 'Iminify sent an answer this version of the plugin can\'t read.', 'iminify-image-optimizer' );
			case 'source_too_large':
				return __( 'This server doesn\'t have the memory to send an image this large.', 'iminify-image-optimizer' );
			case 'source_unreadable':
				return __( 'The image couldn\'t be read from the uploads folder.', 'iminify-image-optimizer' );
			case 'join_key_not_saved':
				return __( 'WordPress couldn\'t store the site key. Press Join the network to try again.', 'iminify-image-optimizer' );
			default:
				return null;
		}
	}
}
