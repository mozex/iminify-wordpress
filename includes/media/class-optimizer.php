<?php
/**
 * Optimizes one attachment through an upload group, in the background.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two Action Scheduler actions per attachment, neither of which ever waits:
 * the first opens a group for the attachment's original and sends it, the
 * second reads the group and, once it has finished, writes its files and
 * deletes it from Iminify. A wait the service asks for (`Retry-After`), a
 * group still being made or the service out of reach becomes the next run's
 * time. An upload itself never waits on any of it.
 *
 * Opening a group counts an image, so a group is opened again only when the
 * one open can no longer finish (it expired, it was lost, or the files it was
 * made for changed), never on an answer that went missing on the way: the
 * group is read first, and only a group still waiting for its source gets it.
 *
 * While the account's day is spent (Pause) no group opens and the attachment
 * reads `paused`; while the site has no working key it reads `parked`, keeping
 * any group it has open. Neither keeps a run scheduled: Queue sends them on.
 */
class Optimizer {

	const OPTIMIZE_HOOK = 'iminify_optimize_attachment';

	const CHECK_HOOK = 'iminify_check_group';

	const DELETE_GROUP_HOOK = 'iminify_delete_group';

	const GROUP = 'iminify';

	/**
	 * The service's refusal for a spent day, whichever count it was: images, or more files than are left.
	 */
	const DAILY_LIMIT = 'daily_limit_reached';

	/**
	 * Sent by an upload, an edit or regenerated sizes, which only runs while the site optimizes uploads.
	 */
	const AUTOMATIC = 'auto';

	/**
	 * Asked for by a person, which runs whatever that setting says.
	 */
	const MANUAL = 'manual';

	/**
	 * Sent by the bulk run (Bulk), which runs whatever that setting says and always takes the slower lane.
	 */
	const BULK = 'bulk';

	/**
	 * Every trigger, each a run's argument of its own.
	 */
	const TRIGGERS = array( self::AUTOMATIC, self::MANUAL, self::BULK );

	/**
	 * Action Scheduler's priorities: lower runs first, so a new upload goes before older work.
	 */
	const PRIORITY_UPLOAD = 5;

	const PRIORITY_LATER = 15;

	/**
	 * Tries for a call that failed on the way (the service out of reach, or a 5xx) before the attachment shows the error.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * Groups opened again for one change of the attachment's files before it shows an error, so nothing loops on the service's count.
	 */
	const MAX_REOPENS = 3;

	/**
	 * Tries to delete a group the service is still making, five minutes apart; its own prune deletes what is left.
	 */
	const MAX_DELETE_TRIES = 12;

	/**
	 * How long a client-side upload (WordPress 7.1's browser processing) is waited on for its sizes.
	 */
	const CLIENT_SIDE_WAIT = 600;

	/**
	 * Queues an attachment, once: a run already waiting for it covers this one.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $trigger    What asked for it: AUTOMATIC, MANUAL or BULK.
	 * @param int    $delay      Seconds before it runs.
	 */
	public static function queue( int $attachment, string $trigger = self::AUTOMATIC, int $delay = 10 ): void {
		$trigger = self::trigger( $trigger );
		$args    = array( $attachment, $trigger );

		/* The bulk run never takes the place of a run already waiting for the image, whatever asked for it. */
		if ( self::BULK === $trigger && self::has_run( $attachment ) ) {
			return;
		}

		/* Decided once, so an upload that waited for a batch doesn't come back behind older work for having waited. */
		$kept     = Records::get( $attachment )['priority'];
		$posted   = get_post_time( 'U', true, $attachment );
		$recent   = is_int( $posted ) && time() - $posted <= Declaration::RECENT_SECONDS;
		$priority = self::MANUAL === $trigger ? self::PRIORITY_UPLOAD : ( self::BULK === $trigger ? self::PRIORITY_LATER : ( $kept ?? ( $recent ? self::PRIORITY_UPLOAD : self::PRIORITY_LATER ) ) );

		if ( self::pending( self::OPTIMIZE_HOOK, $args ) ) {
			/* An upload's second event (the sizes a browser made) clears its place while its first run still waits. */
			if ( null === $kept ) {
				Records::update( $attachment, array( 'priority' => $priority ) );
			}

			return;
		}

		Records::update(
			$attachment,
			array(
				'status'   => Records::QUEUED,
				'trigger'  => $trigger,
				'priority' => $priority,
				'error'    => null,
			)
		);

		as_schedule_single_action( time() + $delay, self::OPTIMIZE_HOOK, $args, self::GROUP, false, $priority );
	}

	/**
	 * Someone asked for an attachment to be optimized, here and now and at any
	 * level. An image the plugin already wrote goes back to its original first, so
	 * it comes out as a fresh upload at that level would: the service only replaces
	 * a file with a smaller one, and the plugin's last output would stand against a
	 * gentler level. Nothing changes when the service can't take it now.
	 *
	 * @param int         $attachment The attachment's id.
	 * @param string|null $level      The level, or null for the site's.
	 * @return true|WP_Error
	 */
	public static function ask( int $attachment, ?string $level = null ) {
		$refusal = self::refusal( $attachment );

		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( Restorer::AVAILABLE === Restorer::availability( $attachment ) ) {
			$restored = Restorer::restore( $attachment );

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
		}

		self::cancel( $attachment );
		Records::update(
			$attachment,
			array(
				'level' => $level,
				'fill'  => null,
			)
		);
		self::queue( $attachment, self::MANUAL, 0 );

		return true;
	}

	/**
	 * Someone asked for what an optimized image lacks, here and now. Unlike
	 * optimizing it again, nothing goes back first: the image is sent from its
	 * original at the level it has, and keeps its files unless the service betters
	 * them. It counts as one more image.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $what       Missing::FORMATS or Missing::SIZES.
	 * @return true|WP_Error
	 */
	public static function fill( int $attachment, string $what ) {
		$refusal = self::refusal( $attachment );

		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( ! Missing::can_fill( $attachment ) ) {
			return new WP_Error( 'image_changed', __( 'This image has changed since Iminify optimized it, so optimize it again instead.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( array() === Missing::of( $attachment, $what ) ) {
			return new WP_Error( 'nothing_missing', __( 'This image lacks nothing your site asks for now.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		self::send_for_missing( $attachment, $what, self::MANUAL );

		return true;
	}

	/**
	 * Queues an optimized image for what it lacks, at the level it has, so its
	 * files aren't replaced for a level nobody chose.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $what       Missing::FORMATS or Missing::SIZES.
	 * @param string $trigger    What asked for it: MANUAL or BULK.
	 */
	public static function send_for_missing( int $attachment, string $what, string $trigger ): void {
		$record = Records::get( $attachment );

		Records::update(
			$attachment,
			array(
				'fill'  => $what,
				'level' => isset( $record['result']['level'] ) && is_string( $record['result']['level'] ) ? $record['result']['level'] : null,
			)
		);

		self::queue( $attachment, $trigger, 0 );
	}

	/**
	 * Why someone can't send an image right now, or null when they can.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function refusal( int $attachment ): ?WP_Error {
		$why = self::why_not( $attachment );

		if ( null !== $why ) {
			return new WP_Error( 'not_optimizable', $why['message'], array( 'status' => 422 ) );
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

		if ( Restorer::NO_ORIGINAL === Restorer::availability( $attachment ) ) {
			return new WP_Error( 'no_original', __( 'No original of this image was kept, so it can\'t be optimized again.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( ! Disk::has_room_for( 0 ) ) {
			return new WP_Error( 'disk_full', __( 'The server is low on disk space. Free some up, then optimize this image.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment, true );
		$source   = Originals::find( $attachment, is_array( $metadata ) ? $metadata : array(), null );

		/* An original WordPress kept can stand aside for its `-scaled` copy, but any other source is the one that has to go. */
		$max = self::max_source( $site );

		if ( null !== $source && Originals::ORIGINAL !== $source['kind'] && null !== $max && (int) filesize( $source['path'] ) > $max ) {
			return new WP_Error( 'file_too_large', __( 'This image\'s original is too large to send: past your plan\'s file size, or more than this server\'s memory can hold.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		return null;
	}

	/**
	 * The sizes WordPress made again from the plugin's own output, as a regeneration
	 * does (Regenerate Thumbnails, `wp media regenerate`) when the main file is the
	 * plugin's: the file WordPress makes sizes from is one the plugin wrote, and the
	 * size's file isn't. They name no bytes, so the service's file made from the
	 * original takes their place, rather than standing against a copy of a copy.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 * @return list<string>
	 */
	protected static function regenerated( int $attachment, array $metadata ): array {
		$from = wp_get_original_image_path( $attachment );

		if ( ! is_string( $from ) || ! Records::wrote( $attachment, $from ) ) {
			return array();
		}

		$folder = dirname( (string) get_attached_file( $attachment, true ) );
		$names  = array();

		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $name => $size ) {
			$path = $folder . '/' . wp_basename( (string) ( $size['file'] ?? '' ) );

			if ( is_file( $path ) && ! Records::wrote( $attachment, $path ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * The largest original this site can send: the plan's file size, and where the
	 * site can't stream a file, what its memory holds (`Upload::max_bytes()`), so a
	 * larger original gives way to WordPress's `-scaled` copy.
	 *
	 * @param array<string, mixed> $site The site's record.
	 */
	protected static function max_source( array $site ): ?int {
		$plan = isset( $site['max_file_size'] ) ? (int) $site['max_file_size'] : null;
		$here = Upload::max_bytes();

		return null === $plan || null === $here ? ( $plan ?? $here ) : min( $plan, $here );
	}

	/**
	 * Drops everything waiting for an attachment: its runs, and any group it has
	 * open, which is deleted from Iminify in the background.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function cancel( int $attachment ): void {
		foreach ( self::TRIGGERS as $trigger ) {
			as_unschedule_all_actions( self::OPTIMIZE_HOOK, array( $attachment, $trigger ), self::GROUP );
		}

		as_unschedule_all_actions( self::CHECK_HOOK, array( $attachment ), self::GROUP );

		$group = Records::get( $attachment )['group'];

		if ( null !== $group ) {
			self::drop_group( $attachment, (string) ( $group['id'] ?? '' ) );
		}
	}

	/**
	 * Action Scheduler's run for OPTIMIZE_HOOK: opens the attachment's group and sends its source.
	 *
	 * @param mixed $attachment The attachment's id.
	 * @param mixed $trigger    What asked for it.
	 */
	public static function optimize( $attachment, $trigger = self::AUTOMATIC ): void {
		$attachment = (int) $attachment;
		$trigger    = self::trigger( $trigger );

		if ( 'attachment' !== get_post_type( $attachment ) ) {
			return;
		}

		/* An image that stopped being one to send since it was queued would otherwise stay queued, and hold its run open. Who shares its files is asked afresh, since this run writes them. */
		$why = self::why_not( $attachment, true );

		if ( null !== $why ) {
			Records::fail( $attachment, Records::SKIPPED, $why['code'], $why['message'] );

			return;
		}

		$client = Plugin::client();

		Records::update( $attachment, array( 'trigger' => $trigger ) );

		if ( null === $client ) {
			self::park( $attachment, 'not_connected' );

			return;
		}

		$record = Records::get( $attachment );

		if ( null !== $record['group'] ) {
			/* A group already open for the files the attachment has now is the one to finish. */
			if ( (int) ( $record['group']['generation'] ?? -1 ) === $record['generation'] ) {
				self::later( $attachment, self::CHECK_HOOK, array( $attachment ), 0, false );

				return;
			}

			self::drop_group( $attachment, (string) ( $record['group']['id'] ?? '' ) );
		}

		/* A batch that is shut asks nothing of the service, however many images come here while it is. */
		if ( self::waits_for_batch( $attachment ) ) {
			return;
		}

		$site = Site_Record::cached() ?? Site_Record::fetch();

		if ( ! is_array( $site ) ) {
			self::refused( $attachment, $site instanceof WP_Error ? $site : null, self::OPTIMIZE_HOOK, array( $attachment, $trigger ) );

			return;
		}

		$settings = isset( $site['settings'] ) && is_array( $site['settings'] ) ? $site['settings'] : array();
		$message  = isset( $site['message']['code'] ) ? (string) $site['message']['code'] : '';

		if ( 'update_required' === $message ) {
			Records::fail( $attachment, Records::NOT_OPTIMIZED, 'update_required', __( 'Not optimized: update the Iminify plugin first.', 'iminify-image-optimizer' ) );

			return;
		}

		if ( self::AUTOMATIC === $trigger && empty( $settings['optimize_on_upload'] ) ) {
			Records::fail( $attachment, Records::NOT_OPTIMIZED, 'off', __( 'Not optimized: the site doesn\'t optimize uploads.', 'iminify-image-optimizer' ) );

			return;
		}

		/* The read above may have just found the day spent. */
		if ( self::waits_for_batch( $attachment ) ) {
			return;
		}

		if ( ! Zip::available() ) {
			Records::fail( $attachment, Records::SKIPPED, 'zip_unavailable', __( 'Not sent: this server\'s PHP can\'t open zip files. Ask your host to turn on PHP\'s zip extension.', 'iminify-image-optimizer' ) );

			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment, true );
		$metadata = is_array( $metadata ) ? $metadata : array();

		if ( self::awaits_client_sizes( $attachment, $metadata ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::OPTIMIZE_HOOK, array( $attachment, $trigger ), self::GROUP, false, self::priority( $attachment ) );

			return;
		}

		if ( ! Disk::has_room_for( 0 ) ) {
			self::no_room( $attachment );

			return;
		}

		$source = Originals::find( $attachment, $metadata, self::max_source( $site ) );

		if ( null === $source ) {
			Records::fail( $attachment, Records::SKIPPED, 'no_original', __( 'Not sent: no original of this image is left to make the optimized files from.', 'iminify-image-optimizer' ) );

			return;
		}

		$only  = null;
		$fresh = array();

		if ( Missing::SIZES === $record['fill'] ) {
			$sizes = Missing::make_sizes( $attachment );

			if ( array() === $sizes['only'] ) {
				if ( $sizes['lacking'] ) {
					Records::fail( $attachment, Records::NOT_OPTIMIZED, 'sizes_failed', __( 'WordPress couldn\'t make the missing sizes of this image.', 'iminify-image-optimizer' ) );
				} else {
					Missing::settle( $attachment );
					Records::end_fill( $attachment );
				}

				return;
			}

			$only     = $sizes['only'];
			$fresh    = $sizes['fresh'];
			$metadata = wp_get_attachment_metadata( $attachment, true );
			$metadata = is_array( $metadata ) ? $metadata : array();
		} elseif ( null === $record['fill'] ) {
			$fresh = self::regenerated( $attachment, $metadata );
		}

		$declaration = Declaration::build( $attachment, $metadata, $source, $record['level'], self::BULK === $trigger || ( self::AUTOMATIC === $trigger && self::PRIORITY_LATER === self::priority( $attachment ) ), $only, $fresh );

		if ( is_wp_error( $declaration ) ) {
			Records::fail( $attachment, Records::SKIPPED, (string) $declaration->get_error_code(), $declaration->get_error_message() );

			return;
		}

		/* A size no group can take (a file in another format, one another size shares) leaves nothing to send but the main file, which would count an image for nothing. */
		if ( null !== $only && array() === array_diff( array_keys( $declaration['outputs'] ), array( Declaration::MAIN ) ) ) {
			Missing::settle( $attachment );
			Records::end_fill( $attachment );

			return;
		}

		$backups = $record['backups'];

		/* Before the group opens, so a backup that can't be trusted costs no image. */
		if ( Originals::BACKUP !== $source['kind'] ) {
			$keep = Local_Settings::get()['backups'];

			if ( $keep && ! Disk::has_room_for( (int) filesize( $source['path'] ) ) ) {
				self::no_room( $attachment );

				return;
			}

			$backups = $keep ? Backups::keep( $source['path'], $source['file'], $backups ) : Backups::retire( $source['path'], $source['file'], $backups );

			if ( is_wp_error( $backups ) ) {
				Records::fail( $attachment, Records::ERROR, (string) $backups->get_error_code(), $backups->get_error_message() );

				return;
			}

			Records::update( $attachment, array( 'backups' => $backups ) );
		}

		$group = $client->open_group( $declaration['body'] );

		if ( is_wp_error( $group ) ) {
			self::refused( $attachment, $group, self::OPTIMIZE_HOOK, array( $attachment, $trigger ) );

			return;
		}

		Records::update(
			$attachment,
			array(
				'status'   => Records::WAITING,
				'attempts' => 0,
				'error'    => null,
				'group'    => array(
					'id'         => (string) ( $group['id'] ?? '' ),
					'generation' => Records::get( $attachment )['generation'],
					'outputs'    => $declaration['outputs'],
					'source'     => (string) Paths::relative( $source['path'] ),
					'type'       => $declaration['type'],
					'sent'       => false,
					'opened'     => time(),
				),
			)
		);

		self::send( $attachment, $client );
	}

	/**
	 * Action Scheduler's run for CHECK_HOOK: reads the group, sends its source
	 * if it is still waiting for it, and writes its files once it has finished.
	 *
	 * @param mixed $attachment The attachment's id.
	 */
	public static function check( $attachment ): void {
		$attachment = (int) $attachment;
		$record     = Records::get( $attachment );
		$group      = $record['group'];
		$client     = Plugin::client();

		if ( null === $group || '' === (string) ( $group['id'] ?? '' ) ) {
			return;
		}

		if ( null === $client ) {
			self::park( $attachment, 'not_connected' );

			return;
		}

		if ( (int) $group['generation'] !== $record['generation'] ) {
			self::drop_group( $attachment, (string) $group['id'] );
			self::requeue( $attachment, false );

			return;
		}

		$answer = $client->group( (string) $group['id'] );

		if ( is_wp_error( $answer ) ) {
			if ( in_array( $answer->get_error_code(), array( 'not_found', 'http_404' ), true ) ) {
				Records::update( $attachment, array( 'group' => null ) );
				self::requeue( $attachment, true );

				return;
			}

			self::refused( $attachment, $answer, self::CHECK_HOOK, array( $attachment ) );

			return;
		}

		$status = (string) ( $answer['status'] ?? '' );

		if ( 'expired' === $status ) {
			self::drop_group( $attachment, (string) $group['id'] );
			self::requeue( $attachment, true );

			return;
		}

		if ( 'awaiting_source' === $status ) {
			self::send( $attachment, $client );

			return;
		}

		if ( empty( $group['sent'] ) ) {
			/* The source arrived although its answer never did. */
			Records::update( $attachment, array( 'group' => array_merge( $group, array( 'sent' => true ) ) ) );
		}

		if ( 'finished' !== $status ) {
			/* Every 10 s at first, easing to a minute as the group takes longer. */
			self::later( $attachment, self::CHECK_HOOK, array( $attachment ), (int) min( MINUTE_IN_SECONDS, 10 + ( time() - (int) ( $group['opened'] ?? time() ) ) / 6 ), false );

			return;
		}

		self::finish( $attachment, $client, $group, $answer );
	}

	/**
	 * Action Scheduler's run for DELETE_GROUP_HOOK: removes a group the plugin
	 * no longer needs from Iminify, trying again while the service is still
	 * making it.
	 *
	 * @param mixed $group The group's id.
	 * @param mixed $tries The tries made so far.
	 */
	public static function delete_group( $group, $tries = 0 ): void {
		$client = Plugin::client();

		if ( null === $client || ! is_string( $group ) || '' === $group ) {
			return;
		}

		$deleted = $client->delete_group( $group );

		if ( is_wp_error( $deleted ) && ! in_array( $deleted->get_error_code(), array( 'not_found', 'http_404' ), true ) && (int) $tries + 1 < self::MAX_DELETE_TRIES ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::DELETE_GROUP_HOOK, array( $group, (int) $tries + 1 ), self::GROUP );
		}
	}

	/**
	 * Sends the open group's source, then checks back on it. A group that no
	 * longer takes it is read by the check, which tells a source that arrived
	 * from a group that expired.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param Client $client     The client.
	 */
	protected static function send( int $attachment, Client $client ): void {
		$group = Records::get( $attachment )['group'];

		if ( null === $group ) {
			return;
		}

		$sent = $client->send_group_source( (string) $group['id'], Paths::absolute( (string) $group['source'] ), (string) $group['type'] );

		if ( is_wp_error( $sent ) && 'group_closed' !== $sent->get_error_code() ) {
			self::refused( $attachment, $sent, self::CHECK_HOOK, array( $attachment ) );

			return;
		}

		Records::update( $attachment, array( 'group' => array_merge( $group, array( 'sent' => ! is_wp_error( $sent ) ) ) ) );
		self::later( $attachment, self::CHECK_HOOK, array( $attachment ), is_wp_error( $sent ) ? 0 : 15, false );
	}

	/**
	 * Downloads a finished group, writes its files, and deletes it from Iminify.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param Client               $client     The client.
	 * @param array<string, mixed> $group      The open group as its record keeps it.
	 * @param array<string, mixed> $answer     The group as the service answered it.
	 */
	protected static function finish( int $attachment, Client $client, array $group, array $answer ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$zip        = wp_tempnam( 'iminify-group.zip' );
		$downloaded = $client->download_group( (string) $group['id'], $zip );

		if ( is_wp_error( $downloaded ) ) {
			wp_delete_file( $zip );
			self::refused( $attachment, $downloaded, self::CHECK_HOOK, array( $attachment ) );

			return;
		}

		/* A restore or an edit that came along during the download dropped this group, and its files are no longer wanted. */
		$current = Records::get( $attachment );

		if ( (string) ( $current['group']['id'] ?? '' ) !== (string) $group['id'] || $current['generation'] !== (int) $group['generation'] ) {
			wp_delete_file( $zip );

			return;
		}

		$written = Writer::write( $attachment, $group, $answer, $zip );
		wp_delete_file( $zip );

		if ( is_wp_error( $written ) ) {
			self::drop_group( $attachment, (string) $group['id'] );

			if ( 'changed' === $written->get_error_code() ) {
				self::requeue( $attachment, true );

				return;
			}

			Records::fail( $attachment, Records::ERROR, (string) $written->get_error_code(), $written->get_error_message() );

			return;
		}

		/* Nothing written for want of room isn't "optimized": it shows why, and goes again once there is some. */
		if ( $written['no_room'] > 0 && ! Writer::wrote_any( $written['outputs'] ) ) {
			self::drop_group( $attachment, (string) $group['id'] );
			self::no_room( $attachment );

			return;
		}

		$result = array(
			'level'   => isset( $answer['settings']['level'] ) ? (string) $answer['settings']['level'] : null,
			'formats' => self::asked_formats( $answer, (string) ( $group['type'] ?? '' ) ),
			'outputs' => $written['outputs'],
			'skipped' => $written['skipped'],
			'at'      => time(),
		);

		if ( null !== $current['fill'] && null !== $current['result'] ) {
			$result = Missing::merged( $current['result'], $result );

			/* A group for sizes made its copies of those sizes alone, so the image's other sizes still lack what they lacked. */
			if ( Missing::SIZES === $current['fill'] ) {
				$result['formats'] = isset( $current['result']['formats'] ) && is_array( $current['result']['formats'] ) ? $current['result']['formats'] : array();
			}
		}

		$result['sizes'] = Missing::accounted_for( $attachment );

		Records::update(
			$attachment,
			array(
				'status'   => Records::OPTIMIZED,
				'group'    => null,
				'error'    => null,
				'attempts' => 0,
				'reopens'  => 0,
				'level'    => null,
				'fill'     => null,
				'made'     => array(),
				'priority' => null,
				'result'   => $result,
			)
		);

		self::delete_group( (string) $group['id'] );

		/**
		 * An image's files were written: the plugin's output and its WebP and AVIF copies are in place.
		 *
		 * @param int                  $attachment The attachment's id.
		 * @param array<string, mixed> $result     What was written: `level`, `formats`, and each size's `before` and `after` bytes in `outputs`.
		 */
		do_action( 'iminify_image_optimized', $attachment, $result );
	}

	/**
	 * The copies' formats a finished group was asked for, read from its answer. The
	 * service answers a source's own format as `original`, so a WebP source's `webp`
	 * reads as asked too; it would otherwise read as missing on every run.
	 *
	 * @param array<string, mixed> $answer The group as the service answered it.
	 * @param string               $type   The source's MIME type.
	 * @return list<string>
	 */
	protected static function asked_formats( array $answer, string $type ): array {
		$asked = array();
		$own   = array_search( $type, Writer::COPIES, true );

		foreach ( isset( $answer['settings']['formats'] ) && is_array( $answer['settings']['formats'] ) ? $answer['settings']['formats'] : array() as $format ) {
			$format = 'original' === $format && is_string( $own ) ? $own : $format;

			if ( isset( Writer::COPIES[ $format ] ) ) {
				$asked[] = (string) $format;
			}
		}

		return array_values( array_unique( $asked ) );
	}

	/**
	 * What a refusal or a failure does: a spent day stops every new group; a wait
	 * the service asks for, or one on the way, runs the same step again later; a
	 * key the service stopped disconnects the site and parks the attachment; anything
	 * else is the attachment's error to show.
	 *
	 * @param int           $attachment The attachment's id.
	 * @param WP_Error|null $error      What went wrong, or null for no answer at all.
	 * @param string        $hook       The step to run again.
	 * @param list<mixed>   $args       Its arguments.
	 */
	protected static function refused( int $attachment, ?WP_Error $error, string $hook, array $args ): void {
		$code   = null === $error ? 'unexpected_response' : (string) $error->get_error_code();
		$data   = null === $error ? array() : $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = (int) ( $data['status'] ?? 0 );

		if ( 'unauthenticated' === $code ) {
			( new Connection() )->revoked_by_service();
			self::park( $attachment, $code );

			return;
		}

		if ( in_array( $code, array( 'not_connected', 'site_moved' ), true ) ) {
			self::park( $attachment, $code );

			return;
		}

		if ( self::DAILY_LIMIT === $code && self::OPTIMIZE_HOOK === $hook ) {
			$site = Site_Record::fetch();

			Pause::refused( isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : HOUR_IN_SECONDS, is_array( $site ) && isset( $site['plan'] ) ? (string) $site['plan'] : '' );
			self::waits_for_batch( $attachment );

			return;
		}

		if ( 429 === $status ) {
			$wait = isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : MINUTE_IN_SECONDS;

			/* A bulk group the service turned away for the work already waiting there holds the bulk run back too. */
			if ( 'rate_limited' === $code && self::OPTIMIZE_HOOK === $hook && self::BULK === Records::get( $attachment )['trigger'] ) {
				Bulk::throttled( $wait );
			}

			self::later( $attachment, $hook, $args, $wait, false, $error );

			return;
		}

		if ( 'unreachable' === $code || $status >= 500 ) {
			self::later( $attachment, $hook, $args, (int) min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, Records::get( $attachment )['attempts'] ) ), true, $error );

			return;
		}

		self::fail( $attachment, $code, null === $error ? __( 'Iminify sent an answer this version of the plugin can\'t read.', 'iminify-image-optimizer' ) : $error->get_error_message() );
	}

	/**
	 * Marks the attachment as waiting for the next batch, if one is shut.
	 *
	 * @phpstan-impure
	 *
	 * @param int $attachment The attachment's id.
	 * @return bool Whether it waits.
	 */
	protected static function waits_for_batch( int $attachment ): bool {
		if ( null === Pause::until() ) {
			return false;
		}

		Records::wait( $attachment, Records::PAUSED, 'day_spent', __( 'Waiting for the next batch.', 'iminify-image-optimizer' ) );

		return true;
	}

	/**
	 * Marks the attachment as waiting for the site's connection. A group it has open stays on its record.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $code       Why the service can't be reached with this key.
	 */
	protected static function park( int $attachment, string $code ): void {
		Records::wait( $attachment, Records::PARKED, $code, __( 'Waiting for the site to be connected to Iminify.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Leaves the attachment as it is for want of disk space, with nothing counted.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function no_room( int $attachment ): void {
		Records::fail( $attachment, Records::NOT_OPTIMIZED, 'disk_full', __( 'Not optimized: the server is low on disk space. Free some up, then optimize this image.', 'iminify-image-optimizer' ) );
	}

	/**
	 * Runs a step again after a wait. A failure on the way counts toward the
	 * attachment's tries, and past them the error stays for the person to see;
	 * a wait the service asked for never runs out.
	 *
	 * @param int           $attachment The attachment's id.
	 * @param string        $hook       The step.
	 * @param list<mixed>   $args       Its arguments.
	 * @param int           $seconds    How long to wait.
	 * @param bool          $counts     Whether the wait counts as a failed try.
	 * @param WP_Error|null $error      The reason, kept on the record.
	 */
	protected static function later( int $attachment, string $hook, array $args, int $seconds, bool $counts, ?WP_Error $error = null ): void {
		$attempts = Records::get( $attachment )['attempts'] + ( $counts ? 1 : 0 );

		if ( $counts && $attempts > self::MAX_ATTEMPTS ) {
			self::fail( $attachment, null === $error ? 'unreachable' : (string) $error->get_error_code(), null === $error ? '' : $error->get_error_message() );

			return;
		}

		Records::update(
			$attachment,
			array(
				'status'   => self::CHECK_HOOK === $hook ? Records::WAITING : Records::QUEUED,
				'attempts' => $attempts,
				'error'    => null === $error ? null : array(
					'code'    => (string) $error->get_error_code(),
					'message' => $error->get_error_message(),
				),
			)
		);

		if ( ! self::pending( $hook, $args ) ) {
			as_schedule_single_action( time() + $seconds, $hook, $args, self::GROUP, false, self::priority( $attachment ) );
		}
	}

	/**
	 * Queues the attachment again with what first asked for it. A group lost
	 * on the service's side counts toward the attachment's reopens, which end
	 * in an error past the cap; files the attachment changed to don't.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $counts     Whether this reopen counts toward the cap.
	 */
	protected static function requeue( int $attachment, bool $counts ): void {
		$record  = Records::get( $attachment );
		$reopens = $record['reopens'] + ( $counts ? 1 : 0 );

		if ( $reopens > self::MAX_REOPENS ) {
			self::fail( $attachment, 'reopened', __( 'Iminify couldn\'t finish this image after several tries.', 'iminify-image-optimizer' ) );

			return;
		}

		Records::update( $attachment, array( 'reopens' => $reopens ) );
		self::queue( $attachment, self::trigger( $record['trigger'] ), 0 );
	}

	/**
	 * Shows an error for the attachment, deleting any group it had open from Iminify.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $code       The reason's code.
	 * @param string $message    The reason, in words.
	 */
	protected static function fail( int $attachment, string $code, string $message ): void {
		$group = Records::get( $attachment )['group'];

		if ( null !== $group ) {
			self::drop_group( $attachment, (string) ( $group['id'] ?? '' ) );
		}

		Records::fail( $attachment, Records::ERROR, $code, $message );
	}

	/**
	 * Forgets the attachment's open group, deleting it from Iminify in the background.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $group      The group's id.
	 */
	protected static function drop_group( int $attachment, string $group ): void {
		Records::update( $attachment, array( 'group' => null ) );
		as_unschedule_all_actions( self::CHECK_HOOK, array( $attachment ), self::GROUP );

		if ( '' !== $group ) {
			as_schedule_single_action( time(), self::DELETE_GROUP_HOOK, array( $group, 0 ), self::GROUP );
		}
	}

	/**
	 * Whether an attachment is an image the service optimizes, read from its
	 * file rather than its MIME type: WordPress keeps `image/heic` on an
	 * iPhone upload it turned into a JPEG.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function is_optimizable( int $attachment ): bool {
		return null === self::why_not( $attachment );
	}

	/**
	 * Why an attachment isn't an image the plugin sends, or null when it is.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to ask afresh who shares its files, as a run that writes them must (`Shared`).
	 * @return array{code: string, message: string}|null
	 */
	public static function why_not( int $attachment, bool $fresh = false ): ?array {
		$unsupported = array(
			'code'    => 'unsupported_format',
			'message' => __( 'Kept as uploaded: Iminify optimizes JPEG, PNG, WebP and GIF images.', 'iminify-image-optimizer' ),
		);

		if ( 'attachment' !== get_post_type( $attachment ) || ! wp_attachment_is_image( $attachment ) ) {
			return $unsupported;
		}

		$file = (string) get_attached_file( $attachment, true );

		if ( '' === $file || ! is_file( $file ) ) {
			return array(
				'code'    => 'file_missing',
				'message' => __( 'Not sent: the image\'s file isn\'t in the uploads folder.', 'iminify-image-optimizer' ),
			);
		}

		$type = wp_get_image_mime( $file );

		if ( ! is_string( $type ) || ! isset( Declaration::FORMATS[ $type ] ) ) {
			return $unsupported;
		}

		$holder = Shared::holder( $attachment, $fresh );

		if ( $holder !== $attachment ) {
			return array(
				'code'    => 'shared',
				'message' => sprintf(
					/* translators: %d: the id of the attachment whose files this one shares. */
					__( 'Its files are attachment #%d\'s, as a translation\'s are, so Iminify optimizes them there.', 'iminify-image-optimizer' ),
					$holder
				),
			);
		}

		/**
		 * Whether the plugin sends an image at all, on upload, in bulk or at someone's press.
		 *
		 * @param bool $optimize   Whether to send it.
		 * @param int  $attachment The attachment's id.
		 */
		if ( ! apply_filters( 'iminify_optimize_image', true, $attachment ) ) {
			return array(
				'code'    => 'left_out',
				'message' => __( 'Kept as uploaded: this site leaves this image out.', 'iminify-image-optimizer' ),
			);
		}

		return null;
	}

	/**
	 * Whether WordPress 7.1's browser processing is still making the sizes of
	 * a fresh upload: its metadata has none yet while the site makes sizes
	 * smaller than the image.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $metadata   Its metadata.
	 */
	protected static function awaits_client_sizes( int $attachment, array $metadata ): bool {
		$posted = get_post_time( 'U', true, $attachment );

		if ( ! empty( $metadata['sizes'] ) || ! is_int( $posted ) || time() - $posted > self::CLIENT_SIDE_WAIT ) {
			return false;
		}

		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		foreach ( wp_get_registered_image_subsizes() as $size ) {
			if ( ( $size['width'] > 0 && $size['width'] < $width ) || ( $size['height'] > 0 && $size['height'] < $height ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A trigger as one of TRIGGERS, an unknown one read as an upload's.
	 *
	 * @param mixed $trigger What a run or a record says asked for it.
	 */
	public static function trigger( $trigger ): string {
		return in_array( $trigger, self::TRIGGERS, true ) ? (string) $trigger : self::AUTOMATIC;
	}

	/**
	 * Whether the attachment has a run waiting, whatever asked for it.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function has_run( int $attachment ): bool {
		foreach ( self::TRIGGERS as $trigger ) {
			if ( self::pending( self::OPTIMIZE_HOOK, array( $attachment, $trigger ) ) ) {
				return true;
			}
		}

		return self::pending( self::CHECK_HOOK, array( $attachment ) );
	}

	/**
	 * Whether a run of a step is already waiting.
	 *
	 * @param string      $hook The step.
	 * @param list<mixed> $args Its arguments.
	 */
	public static function pending( string $hook, array $args ): bool {
		return array() !== as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * The attachment's place in the queue, as queue() decided it.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function priority( int $attachment ): int {
		return Records::get( $attachment )['priority'] ?? self::PRIORITY_LATER;
	}
}
