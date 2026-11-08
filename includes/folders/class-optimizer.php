<?php
/**
 * Optimizes one file in a chosen folder through an upload group, in the background.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Declaration;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Optimizer as Media_Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Refusal;
use Iminify\WordPress\Media\Writer;
use Iminify\WordPress\Media\Zip;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The media library's pipeline (`Media\Optimizer`) for a file that has no
 * attachment: its record is a row of `Table`, and its group declares the file
 * alone, no sizes, asking the service to keep its dimensions, since a theme's
 * layout relies on them (the site's maximum width and size limit are for its
 * uploads). The same two Action Scheduler steps, the same reading of a refusal
 * (`Refusal`), the same waits for a spent day and a lost connection (`Queue`
 * sends them on), and the same proof of what the plugin wrote: the source is the
 * file itself while it isn't the plugin's output, else its backup, so no image
 * is compressed twice. Every read and write goes through `Folders::guard()`.
 */
class Optimizer {

	const OPTIMIZE_HOOK = 'iminify_optimize_folder_file';

	const CHECK_HOOK = 'iminify_check_folder_file';

	const RESUME_HOOK = 'iminify_resume_folder_files';

	/**
	 * The sources a group is made from: the file, or its backup when the file is the plugin's output.
	 */
	const FILE = 'file';

	const BACKUP = 'backup';

	/**
	 * Files the queue sends on per run.
	 */
	const RESUME_PAGE = 50;

	/**
	 * Queues a file, once: a run already waiting for it covers this one.
	 *
	 * @param int    $id      The file's row id.
	 * @param string $trigger MANUAL or BULK.
	 * @param int    $delay   Seconds before it runs.
	 */
	public static function queue( int $id, string $trigger = Media_Optimizer::MANUAL, int $delay = 0 ): void {
		$trigger = Media_Optimizer::BULK === $trigger ? Media_Optimizer::BULK : Media_Optimizer::MANUAL;
		$args    = array( $id, $trigger );

		if ( ( Media_Optimizer::BULK === $trigger && self::has_run( $id ) ) || Media_Optimizer::pending( self::OPTIMIZE_HOOK, $args ) ) {
			return;
		}

		Table::update(
			$id,
			array(
				'status'  => Records::QUEUED,
				'trigger' => $trigger,
				'error'   => null,
			)
		);

		as_schedule_single_action( time() + $delay, self::OPTIMIZE_HOOK, $args, Media_Optimizer::GROUP, false, self::priority( $trigger ) );
	}

	/**
	 * Someone asked for a file to be optimized, here and now. One the plugin
	 * already wrote goes back to its original first, as an attachment does.
	 *
	 * @param int $id The file's row id.
	 * @return true|WP_Error
	 */
	public static function ask( int $id ) {
		$refusal = self::refusal( $id );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$row = Table::get( $id );

		if ( null !== $row && Restorer::AVAILABLE === Restorer::availability( $row ) ) {
			$restored = Restorer::restore( $id );

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
		}

		self::cancel( $id );
		self::queue( $id, Media_Optimizer::MANUAL, 0 );

		return true;
	}

	/**
	 * Why someone can't send a file right now, or null when they can.
	 *
	 * @param int $id The file's row id.
	 */
	protected static function refusal( int $id ): ?WP_Error {
		$why = self::why_not( $id );

		if ( null !== $why ) {
			return new WP_Error( 'not_optimizable', $why['message'], array( 'status' => 422 ) );
		}

		$acting = Others::acting();

		if ( null !== $acting ) {
			return new WP_Error( Others::ACTING, Others::acting_message( $acting ), array( 'status' => 409 ) );
		}

		$site = Site_Record::fetch();

		if ( null === $site ) {
			return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( $site instanceof WP_Error ) {
			return $site;
		}

		if ( 'update_required' === ( $site['message']['code'] ?? '' ) ) {
			return new WP_Error( 'update_required', __( 'Update the Iminify plugin first.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$until = Pause::until();

		if ( null !== $until ) {
			return new WP_Error(
				'day_spent',
				sprintf(
					/* translators: %s: the date and time the next batch opens. */
					__( 'Your account\'s images for the day are used up. The next batch opens on %s.', 'iminify-image-optimizer' ),
					(string) State::date( gmdate( 'c', $until ) )
				),
				array( 'status' => 409 )
			);
		}

		if ( ! Disk::has_room_for( 0 ) ) {
			return new WP_Error( 'disk_full', __( 'The server is low on disk space. Free some up, then optimize this image.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$row    = Table::get( $id );
		$source = null === $row ? null : self::source( $row );

		if ( null === $source ) {
			return new WP_Error( 'no_original', __( 'No original of this image was kept, so it can\'t be optimized again.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$max = Media_Optimizer::max_source( $site );

		if ( null !== $max && (int) filesize( $source['path'] ) > $max ) {
			return new WP_Error( 'file_too_large', __( 'This image is too large to send: past your plan\'s file size, or more than this server\'s memory can hold.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		return null;
	}

	/**
	 * Drops everything waiting for a file: its runs, and any group it has open, which is deleted from Iminify in the background.
	 *
	 * @param int $id The file's row id.
	 */
	public static function cancel( int $id ): void {
		foreach ( array( Media_Optimizer::MANUAL, Media_Optimizer::BULK ) as $trigger ) {
			as_unschedule_all_actions( self::OPTIMIZE_HOOK, array( $id, $trigger ), Media_Optimizer::GROUP );
		}

		as_unschedule_all_actions( self::CHECK_HOOK, array( $id ), Media_Optimizer::GROUP );

		$row = Table::get( $id );

		if ( null !== $row && null !== $row['group'] ) {
			self::drop_group( $id, (string) ( $row['group']['id'] ?? '' ) );
		}
	}

	/**
	 * Action Scheduler's run for OPTIMIZE_HOOK: opens the file's group and sends its source.
	 *
	 * @param mixed $id      The file's row id.
	 * @param mixed $trigger What asked for it.
	 */
	public static function optimize( $id, $trigger = Media_Optimizer::MANUAL ): void {
		$id      = (int) $id;
		$trigger = Media_Optimizer::BULK === $trigger ? Media_Optimizer::BULK : Media_Optimizer::MANUAL;
		$row     = Table::get( $id );

		if ( null === $row ) {
			return;
		}

		$why = self::why_not( $id );

		if ( null !== $why ) {
			self::set_aside( $id, $why );

			return;
		}

		$client = Plugin::client();

		Table::update( $id, array( 'trigger' => $trigger ) );

		if ( null === $client ) {
			self::park( $id, 'not_connected' );

			return;
		}

		if ( null !== $row['group'] ) {
			/* A group already open for the file as it is now is the one to finish. */
			if ( (int) ( $row['group']['generation'] ?? -1 ) === $row['generation'] ) {
				self::later( $id, self::CHECK_HOOK, array( $id ), 0, false );

				return;
			}

			self::drop_group( $id, (string) ( $row['group']['id'] ?? '' ) );
		}

		/* While another optimizer acts on uploads nothing opens (W134). */
		$acting = Others::acting();

		if ( null !== $acting ) {
			self::stop( $id, Records::NOT_OPTIMIZED, Others::ACTING, Others::acting_message( $acting ) );

			return;
		}

		if ( self::waits_for_batch( $id ) ) {
			return;
		}

		$site = Site_Record::cached() ?? Site_Record::fetch();

		if ( ! is_array( $site ) ) {
			self::refused( $id, $site instanceof WP_Error ? $site : null, self::OPTIMIZE_HOOK, array( $id, $trigger ) );

			return;
		}

		if ( 'update_required' === ( isset( $site['message']['code'] ) ? (string) $site['message']['code'] : '' ) ) {
			self::stop( $id, Records::NOT_OPTIMIZED, 'update_required', __( 'Not optimized: update the Iminify plugin first.', 'iminify-image-optimizer' ) );

			return;
		}

		/* The read above may have just found the day spent. */
		if ( self::waits_for_batch( $id ) ) {
			return;
		}

		if ( ! Zip::available() ) {
			self::stop( $id, Records::SKIPPED, 'zip_unavailable', __( 'Not sent: this server\'s PHP can\'t open zip files. Ask your host to turn on PHP\'s zip extension.', 'iminify-image-optimizer' ) );

			return;
		}

		if ( ! Disk::has_room_for( 0 ) ) {
			self::no_room( $id );

			return;
		}

		$row    = Table::get( $id ) ?? $row;
		$source = self::source( $row );

		if ( null === $source ) {
			self::stop( $id, Records::SKIPPED, 'no_original', __( 'Not sent: no original of this image is left to make the optimized file from.', 'iminify-image-optimizer' ) );

			return;
		}

		$max = Media_Optimizer::max_source( $site );

		if ( null !== $max && (int) filesize( $source['path'] ) > $max ) {
			self::stop( $id, Records::SKIPPED, 'file_too_large', __( 'Not sent: this image is past your plan\'s file size, or more than this server\'s memory can hold.', 'iminify-image-optimizer' ) );

			return;
		}

		$declaration = self::declaration( $row, $source, Media_Optimizer::BULK === $trigger );

		if ( is_wp_error( $declaration ) ) {
			self::stop( $id, Records::SKIPPED, (string) $declaration->get_error_code(), $declaration->get_error_message() );

			return;
		}

		$backups = $row['backups'];

		/* Before the group opens, so a backup that can't be trusted costs no image. */
		if ( self::FILE === $source['kind'] ) {
			$keep = Local_Settings::get()['backups'];

			if ( $keep && ! Disk::has_room_for( (int) filesize( $source['path'] ) ) ) {
				self::no_room( $id );

				return;
			}

			$backups = $keep
				? Backups::keep( $source['path'], $row['path'], $backups, Paths::FOLDERS_BACKUP_FOLDER )
				: Backups::retire( $source['path'], $row['path'], $backups, Paths::FOLDERS_BACKUP_FOLDER );

			if ( is_wp_error( $backups ) ) {
				self::stop( $id, Records::ERROR, (string) $backups->get_error_code(), $backups->get_error_message() );

				return;
			}

			Table::update( $id, array( 'backups' => $backups ) );
		}

		$group = $client->open_group( $declaration['body'] );

		if ( is_wp_error( $group ) ) {
			self::refused( $id, $group, self::OPTIMIZE_HOOK, array( $id, $trigger ) );

			return;
		}

		Table::update(
			$id,
			array(
				'status'   => Records::WAITING,
				'attempts' => 0,
				'error'    => null,
				'group'    => array(
					'id'         => (string) ( $group['id'] ?? '' ),
					'generation' => $row['generation'],
					'outputs'    => $declaration['outputs'],
					'source'     => $source['kind'],
					'type'       => $declaration['type'],
					'sent'       => false,
					'opened'     => time(),
				),
			)
		);

		self::send( $id, $client );
	}

	/**
	 * Action Scheduler's run for CHECK_HOOK: reads the group, sends its source if it is still waiting for it, and writes its file once it has finished.
	 *
	 * @param mixed $id The file's row id.
	 */
	public static function check( $id ): void {
		$id     = (int) $id;
		$row    = Table::get( $id );
		$group  = null === $row ? null : $row['group'];
		$client = Plugin::client();

		if ( null === $row || null === $group || '' === (string) ( $group['id'] ?? '' ) ) {
			return;
		}

		if ( null === $client ) {
			self::park( $id, 'not_connected' );

			return;
		}

		if ( (int) $group['generation'] !== $row['generation'] ) {
			self::drop_group( $id, (string) $group['id'] );
			self::requeue( $id, false );

			return;
		}

		$answer = $client->group( (string) $group['id'] );

		if ( is_wp_error( $answer ) ) {
			if ( in_array( $answer->get_error_code(), array( 'not_found', 'http_404' ), true ) ) {
				Table::update( $id, array( 'group' => null ) );
				self::requeue( $id, true );

				return;
			}

			self::refused( $id, $answer, self::CHECK_HOOK, array( $id ) );

			return;
		}

		$status = (string) ( $answer['status'] ?? '' );

		if ( 'expired' === $status ) {
			self::drop_group( $id, (string) $group['id'] );
			self::requeue( $id, true );

			return;
		}

		if ( 'awaiting_source' === $status ) {
			self::send( $id, $client );

			return;
		}

		if ( empty( $group['sent'] ) ) {
			Table::update( $id, array( 'group' => array_merge( $group, array( 'sent' => true ) ) ) );
		}

		if ( 'finished' !== $status ) {
			self::later( $id, self::CHECK_HOOK, array( $id ), (int) min( MINUTE_IN_SECONDS, 10 + ( time() - (int) ( $group['opened'] ?? time() ) ) / 6 ), false );

			return;
		}

		self::finish( $id, $client, $group, $answer );
	}

	/**
	 * Sends the open group's source, then checks back on it.
	 *
	 * @param int    $id     The file's row id.
	 * @param Client $client The client.
	 */
	protected static function send( int $id, Client $client ): void {
		$row   = Table::get( $id );
		$group = null === $row ? null : $row['group'];

		if ( null === $row || null === $group ) {
			return;
		}

		$source = self::source_path( $row, (string) $group['source'] );

		if ( null === $source ) {
			self::fail( $id, 'no_original', __( 'The image\'s file or its backup went missing while it was on its way.', 'iminify-image-optimizer' ) );

			return;
		}

		$sent = $client->send_group_source( (string) $group['id'], $source, (string) $group['type'] );

		if ( is_wp_error( $sent ) && 'group_closed' !== $sent->get_error_code() ) {
			self::refused( $id, $sent, self::CHECK_HOOK, array( $id ) );

			return;
		}

		Table::update( $id, array( 'group' => array_merge( $group, array( 'sent' => ! is_wp_error( $sent ) ) ) ) );
		self::later( $id, self::CHECK_HOOK, array( $id ), is_wp_error( $sent ) ? 0 : 15, false );
	}

	/**
	 * Downloads a finished group, writes its file and copies, and deletes it from Iminify.
	 *
	 * @param int                  $id     The file's row id.
	 * @param Client               $client The client.
	 * @param array<string, mixed> $group  The open group as its row keeps it.
	 * @param array<string, mixed> $answer The group as the service answered it.
	 */
	protected static function finish( int $id, Client $client, array $group, array $answer ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$zip        = wp_tempnam( 'iminify-group.zip' );
		$downloaded = $client->download_group( (string) $group['id'], $zip );

		if ( is_wp_error( $downloaded ) ) {
			wp_delete_file( $zip );
			self::refused( $id, $downloaded, self::CHECK_HOOK, array( $id ) );

			return;
		}

		/* A restore, a scan that found the file changed, or a folder taken off the list came along during the download. */
		$row  = Table::get( $id );
		$file = null === $row ? null : Folders::guard( $row['path'] );

		if ( null === $row || (string) ( $row['group']['id'] ?? '' ) !== (string) $group['id'] || $row['generation'] !== (int) $group['generation'] || null === $file ) {
			wp_delete_file( $zip );

			if ( null !== $row && null === $file && (string) ( $row['group']['id'] ?? '' ) === (string) $group['id'] ) {
				self::fail( $id, 'outside_folders', __( 'Not written: the image is no longer in a folder Iminify optimizes.', 'iminify-image-optimizer' ) );
			}

			return;
		}

		$outputs = isset( $group['outputs'] ) && is_array( $group['outputs'] ) ? $group['outputs'] : array();
		$put     = Writer::put(
			$outputs,
			$answer,
			$zip,
			$row['written'],
			$row['copies'],
			Folders::root(),
			'',
			array(),
			static function ( array $pending ) use ( $id ): void {
				Table::update( $id, array( 'pending' => $pending ) );
			}
		);

		wp_delete_file( $zip );

		if ( is_wp_error( $put ) ) {
			self::drop_group( $id, (string) $group['id'] );

			if ( 'changed' === $put->get_error_code() ) {
				self::requeue( $id, true );

				return;
			}

			self::fail( $id, (string) $put->get_error_code(), $put->get_error_message() );

			return;
		}

		/* Nothing written for want of room isn't "optimized": it shows why, and goes again once there is some. */
		if ( $put['no_room'] > 0 && ! Writer::wrote_any( $put['outputs'] ) ) {
			Table::update( $id, array( 'pending' => null ) );
			self::drop_group( $id, (string) $group['id'] );
			self::no_room( $id );

			return;
		}

		$result = array(
			'level'   => isset( $answer['settings']['level'] ) ? (string) $answer['settings']['level'] : null,
			'formats' => Media_Optimizer::asked_formats( $answer, (string) ( $group['type'] ?? '' ) ),
			'outputs' => $put['outputs'],
			'skipped' => $put['skipped'],
			'kept'    => count( $put['kept'] ),
			'at'      => time(),
		);

		clearstatcache( true, $file );

		Table::update(
			$id,
			array(
				'status'   => Records::OPTIMIZED,
				'bytes'    => (int) filesize( $file ),
				'group'    => null,
				'error'    => null,
				'attempts' => 0,
				'reopens'  => 0,
				'written'  => $put['written'],
				'copies'   => $put['copies'],
				'pending'  => null,
				'result'   => $result,
			)
		);

		Media_Optimizer::delete_group( (string) $group['id'] );

		/**
		 * A file in another folder was optimized: the plugin's output and its WebP and AVIF copies are in place.
		 *
		 * @param string               $path   The file's path relative to `wp-content`.
		 * @param array<string, mixed> $result What was written: `level`, `formats`, and the file's `before` and `after` bytes in `outputs`.
		 */
		do_action( 'iminify_file_optimized', (string) $row['path'], $result );
	}

	/**
	 * The source a file's group is made from: the file itself while it isn't the
	 * plugin's output, else its backup, never the plugin's output for a second pass.
	 *
	 * @param array<string, mixed> $row The file's row.
	 * @return array{path: string, kind: string}|null
	 */
	public static function source( array $row ): ?array {
		$file = Folders::guard( (string) ( $row['path'] ?? '' ) );

		if ( null === $file ) {
			return null;
		}

		clearstatcache( true, $file );

		$written = isset( $row['written'] ) && is_array( $row['written'] ) ? $row['written'] : array();

		if ( ! isset( $written[ $row['path'] ] ) || (int) filesize( $file ) !== (int) $written[ $row['path'] ] ) {
			return array(
				'path' => $file,
				'kind' => self::FILE,
			);
		}

		$backup = self::source_path( $row, self::BACKUP );

		return null === $backup ? null : array(
			'path' => $backup,
			'kind' => self::BACKUP,
		);
	}

	/**
	 * Where a group's source is now: the file, or its backup, which the row must name.
	 *
	 * @param array<string, mixed> $row  The file's row.
	 * @param string               $kind FILE or BACKUP.
	 */
	protected static function source_path( array $row, string $kind ): ?string {
		if ( self::FILE === $kind ) {
			return Folders::guard( (string) $row['path'] );
		}

		$backup = Paths::backup( (string) $row['path'], Paths::FOLDERS_BACKUP_FOLDER );
		$kept   = Paths::relative( $backup );

		return null !== $kept && in_array( $kept, (array) $row['backups'], true ) && is_file( $backup ) && ! is_link( $backup ) ? $backup : null;
	}

	/**
	 * The declaration of a file's group: its source and one output, the file as it is, at its own dimensions.
	 *
	 * @param array<string, mixed>              $row        The file's row.
	 * @param array{path: string, kind: string} $source     The source.
	 * @param bool                              $background Whether nobody waits on it, which takes the slower lane.
	 * @return array{body: array<string, mixed>, outputs: array<string, array{file: string, size: int, width: int, height: int}>, type: string}|WP_Error
	 */
	protected static function declaration( array $row, array $source, bool $background ) {
		$file = Folders::guard( (string) $row['path'] );
		$type = wp_get_image_mime( $source['path'] );

		if ( null === $file || ! is_string( $type ) || ! isset( Declaration::FORMATS[ $type ] ) ) {
			return new WP_Error( 'unsupported_format', __( 'Kept as it is: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ) );
		}

		$source_size = Declaration::displayed( $source['path'], $type );
		$file_size   = Declaration::displayed( $file, (string) wp_get_image_mime( $file ) );

		if ( null === $source_size || null === $file_size ) {
			return new WP_Error( 'unreadable_image', __( 'The image couldn\'t be read.', 'iminify-image-optimizer' ) );
		}

		$name = wp_check_invalid_utf8( (string) pathinfo( $file, PATHINFO_FILENAME ), true );
		$body = array(
			'name'            => '' === $name ? 'image' : mb_substr( $name, 0, 125 ),
			'source'          => array(
				'format' => Declaration::FORMATS[ $type ],
				'size'   => (int) filesize( $source['path'] ),
				'width'  => $source_size[0],
				'height' => $source_size[1],
			),
			'outputs'         => array(
				array(
					'name'   => Declaration::MAIN,
					'width'  => $file_size[0],
					'height' => $file_size[1],
					'size'   => (int) filesize( $file ),
				),
			),
			'keep_dimensions' => true,
		);

		if ( $background ) {
			$body['priority'] = 'background';
		}

		return array(
			'body'    => $body,
			'outputs' => array(
				Declaration::MAIN => array(
					'file'   => (string) $row['path'],
					'size'   => (int) filesize( $file ),
					'width'  => $file_size[0],
					'height' => $file_size[1],
				),
			),
			'type'    => $type,
		);
	}

	/**
	 * Why a file isn't one the plugin sends, or null when it is.
	 *
	 * @param int $id The file's row id.
	 * @return array{code: string, message: string, other?: string}|null
	 */
	public static function why_not( int $id ): ?array {
		$row = Table::get( $id );

		if ( null === $row || ! Folders::contains( $row['path'] ) ) {
			return array(
				'code'    => 'outside_folders',
				'message' => __( 'Not sent: the image is no longer in a folder Iminify optimizes.', 'iminify-image-optimizer' ),
			);
		}

		$file = Folders::guard( $row['path'] );

		if ( null === $file ) {
			return array(
				'code'    => 'file_missing',
				'message' => __( 'Not sent: the image\'s file isn\'t there, or is a link to another place.', 'iminify-image-optimizer' ),
			);
		}

		$type = wp_get_image_mime( $file );

		if ( ! is_string( $type ) || ! isset( Declaration::FORMATS[ $type ] ) ) {
			return array(
				'code'    => 'unsupported_format',
				'message' => __( 'Kept as it is: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ),
			);
		}

		/* A file another plugin optimized in a folder of its own is never sent for a second lossy pass (W140). */
		$owner = Others::file_owner( $file );

		if ( null !== $owner ) {
			return array(
				'code'    => Others::CODE,
				'message' => Others::label( $owner ),
				'other'   => $owner,
			);
		}

		/**
		 * Whether the plugin sends a file in another folder, in bulk or at someone's press.
		 *
		 * @param bool   $optimize Whether to send it.
		 * @param string $path     The file's path relative to `wp-content`.
		 */
		if ( ! apply_filters( 'iminify_optimize_file', true, $row['path'] ) ) {
			return array(
				'code'    => 'left_out',
				'message' => __( 'Kept as it is: this site leaves this image out.', 'iminify-image-optimizer' ),
			);
		}

		return null;
	}

	/**
	 * Action Scheduler's run for RESUME_HOOK: sends on a page of the files that
	 * waited for the next batch or the connection, then the next page.
	 *
	 * @param mixed $after The id to start after.
	 */
	public static function resume( $after = 0 ): void {
		if ( ! Plugin::connected() ) {
			return;
		}

		$statuses = null === Pause::until() ? array( Records::PARKED, Records::PAUSED ) : array( Records::PARKED );
		$ids      = Table::anywhere( $statuses, self::RESUME_PAGE, (int) $after );

		foreach ( $ids as $id ) {
			$row = Table::get( $id );

			self::queue( $id, null === $row ? Media_Optimizer::MANUAL : (string) $row['trigger'], 0 );
		}

		if ( self::RESUME_PAGE === count( $ids ) ) {
			as_schedule_single_action( time(), self::RESUME_HOOK, array( (int) end( $ids ) ), Media_Optimizer::GROUP );
		}
	}

	/**
	 * Whether a file has a run waiting, whatever asked for it.
	 *
	 * @param int $id The file's row id.
	 */
	public static function has_run( int $id ): bool {
		foreach ( array( Media_Optimizer::MANUAL, Media_Optimizer::BULK ) as $trigger ) {
			if ( Media_Optimizer::pending( self::OPTIMIZE_HOOK, array( $id, $trigger ) ) ) {
				return true;
			}
		}

		return Media_Optimizer::pending( self::CHECK_HOOK, array( $id ) );
	}

	/**
	 * What a refusal or a failure does, read as it is for an attachment (`Refusal`).
	 *
	 * @param int           $id    The file's row id.
	 * @param WP_Error|null $error What went wrong, or null for no answer at all.
	 * @param string        $hook  The step to run again.
	 * @param list<mixed>   $args  Its arguments.
	 */
	protected static function refused( int $id, ?WP_Error $error, string $hook, array $args ): void {
		$row     = Table::get( $id );
		$refusal = Refusal::decide( $error, self::OPTIMIZE_HOOK === $hook, null === $row ? 0 : $row['attempts'] );

		switch ( $refusal['then'] ) {
			case Refusal::PARK:
				self::park( $id, $refusal['code'] );
				return;
			case Refusal::PAUSE:
				self::waits_for_batch( $id );
				return;
			case Refusal::WAIT:
				/* A bulk group the service turned away for the work already waiting there holds the bulk run back too. */
				if ( $refusal['throttled'] && null !== $row && Media_Optimizer::BULK === $row['trigger'] ) {
					Bulk::throttled( $refusal['seconds'] );
				}

				self::later( $id, $hook, $args, $refusal['seconds'], false, $error );
				return;
			case Refusal::RETRY:
				self::later( $id, $hook, $args, $refusal['seconds'], true, $error );
				return;
			default:
				self::fail( $id, $refusal['code'], $refusal['message'] );
		}
	}

	/**
	 * Marks the file as waiting for the next batch, if one is shut.
	 *
	 * @phpstan-impure
	 *
	 * @param int $id The file's row id.
	 * @return bool Whether it waits.
	 */
	protected static function waits_for_batch( int $id ): bool {
		if ( null === Pause::until() ) {
			return false;
		}

		self::wait( $id, Records::PAUSED, 'day_spent', __( 'Waiting for the next batch.', 'iminify-image-optimizer' ) );

		return true;
	}

	/**
	 * Marks the file as waiting for the site's connection. A group it has open stays on its row.
	 *
	 * @param int    $id   The file's row id.
	 * @param string $code Why the service can't be reached with this key.
	 */
	public static function park( int $id, string $code ): void {
		self::wait(
			$id,
			Records::PARKED,
			$code,
			'deactivated' === $code
				? __( 'Waiting to go on: the plugin was turned off while this image was on its way.', 'iminify-image-optimizer' )
				: __( 'Waiting for the site to be connected to Iminify.', 'iminify-image-optimizer' )
		);
	}

	/**
	 * Records why a file waits, keeping an open group.
	 *
	 * @param int    $id      The file's row id.
	 * @param string $status  PAUSED or PARKED.
	 * @param string $code    The reason's code.
	 * @param string $message The reason, in words.
	 */
	protected static function wait( int $id, string $status, string $code, string $message ): void {
		Table::update(
			$id,
			array(
				'status' => $status,
				'error'  => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * Leaves the file as it is for want of disk space, with nothing counted.
	 *
	 * @param int $id The file's row id.
	 */
	protected static function no_room( int $id ): void {
		self::stop( $id, Records::NOT_OPTIMIZED, 'disk_full', __( 'Not optimized: the server is low on disk space. Free some up, then optimize this image.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Sets a file aside as one the plugin doesn't send, with the reason beside it:
	 * one another plugin optimized reads as that plugin's.
	 *
	 * @param int                                                $id  The file's row id.
	 * @param array{code: string, message: string, other?: string} $why Why, as `why_not()` answers.
	 */
	public static function set_aside( int $id, array $why ): void {
		self::stop( $id, isset( $why['other'] ) ? Records::OTHER : Records::SKIPPED, $why['code'], $why['message'] );
	}

	/**
	 * Records where a file stops, with the reason to show.
	 *
	 * @param int    $id      The file's row id.
	 * @param string $status  Where it stands now.
	 * @param string $code    The reason's code.
	 * @param string $message The reason, in words.
	 */
	public static function stop( int $id, string $status, string $code, string $message ): void {
		Table::update(
			$id,
			array(
				'status' => $status,
				'group'  => null,
				'error'  => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * Runs a step again after a wait; a failure on the way counts toward the file's tries.
	 *
	 * @param int           $id      The file's row id.
	 * @param string        $hook    The step.
	 * @param list<mixed>   $args    Its arguments.
	 * @param int           $seconds How long to wait.
	 * @param bool          $counts  Whether the wait counts as a failed try.
	 * @param WP_Error|null $error   The reason, kept on the row.
	 */
	protected static function later( int $id, string $hook, array $args, int $seconds, bool $counts, ?WP_Error $error = null ): void {
		$row = Table::get( $id );

		if ( null === $row ) {
			return;
		}

		$attempts = $row['attempts'] + ( $counts ? 1 : 0 );

		if ( $counts && $attempts > Media_Optimizer::MAX_ATTEMPTS ) {
			self::fail( $id, null === $error ? 'unreachable' : (string) $error->get_error_code(), null === $error ? '' : $error->get_error_message() );

			return;
		}

		Table::update(
			$id,
			array(
				'status'   => self::CHECK_HOOK === $hook ? Records::WAITING : Records::QUEUED,
				'attempts' => $attempts,
				'error'    => null === $error ? null : array(
					'code'    => (string) $error->get_error_code(),
					'message' => $error->get_error_message(),
				),
			)
		);

		if ( ! Media_Optimizer::pending( $hook, $args ) ) {
			as_schedule_single_action( time() + $seconds, $hook, $args, Media_Optimizer::GROUP, false, self::priority( (string) $row['trigger'] ) );
		}
	}

	/**
	 * Queues the file again with what first asked for it; a group lost on the service's side counts toward its reopens.
	 *
	 * @param int  $id     The file's row id.
	 * @param bool $counts Whether this reopen counts toward the cap.
	 */
	protected static function requeue( int $id, bool $counts ): void {
		$row = Table::get( $id );

		if ( null === $row ) {
			return;
		}

		$reopens = $row['reopens'] + ( $counts ? 1 : 0 );

		if ( $reopens > Media_Optimizer::MAX_REOPENS ) {
			self::fail( $id, 'reopened', __( 'Iminify couldn\'t finish this image after several tries.', 'iminify-image-optimizer' ) );

			return;
		}

		Table::update( $id, array( 'reopens' => $reopens ) );
		self::queue( $id, (string) $row['trigger'], 0 );
	}

	/**
	 * Shows an error for the file, deleting any group it had open from Iminify.
	 *
	 * @param int    $id      The file's row id.
	 * @param string $code    The reason's code.
	 * @param string $message The reason, in words.
	 */
	protected static function fail( int $id, string $code, string $message ): void {
		$row = Table::get( $id );

		if ( null !== $row && null !== $row['group'] ) {
			self::drop_group( $id, (string) ( $row['group']['id'] ?? '' ) );
		}

		self::stop( $id, Records::ERROR, $code, $message );
	}

	/**
	 * Forgets the file's open group, deleting it from Iminify in the background.
	 *
	 * @param int    $id    The file's row id.
	 * @param string $group The group's id.
	 */
	protected static function drop_group( int $id, string $group ): void {
		Table::update( $id, array( 'group' => null ) );
		as_unschedule_all_actions( self::CHECK_HOOK, array( $id ), Media_Optimizer::GROUP );

		if ( '' !== $group ) {
			as_schedule_single_action( time(), Media_Optimizer::DELETE_GROUP_HOOK, array( $group, 0 ), Media_Optimizer::GROUP );
		}
	}

	/**
	 * A file's place in the queue: a press goes ahead of older work, bulk behind it.
	 *
	 * @param string $trigger What asked for it.
	 */
	protected static function priority( string $trigger ): int {
		return Media_Optimizer::BULK === $trigger ? Media_Optimizer::PRIORITY_LATER : Media_Optimizer::PRIORITY_UPLOAD;
	}
}
