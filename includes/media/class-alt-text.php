<?php
/**
 * AI alt text for an attachment, written only where it has none.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the site's setting is on, an attachment with an empty alt asks its group
 * for one (`Optimizer::open()`). The service writes it from a small copy of the
 * image, a little after the group's files are done, so a group that finished
 * with its text still on the way stays on Iminify until the text comes: the
 * group is deleted once it is saved, and deleting it sooner would lose a text
 * the day's count has already paid for.
 *
 * An image optimized before the setting was on has no group left to ask, so it
 * goes on its own (`ask()`, the bulk run's `alt_text`): a small copy of it is
 * sent, and the record the service answers is polled. A translation sharing the
 * files keeps its own alt, in its own language, and is never sent.
 *
 * An alt text someone wrote is never replaced, whenever they wrote it: the
 * attachment's alt is read again right before the text is saved, so one typed
 * while the text was on its way stays.
 *
 * The record's `alt` says where it stands. Nothing here touches an attachment's
 * files.
 */
class Alt_Text {

	/**
	 * WordPress's own meta for an attachment's alt text.
	 */
	const META = '_wp_attachment_image_alt';

	const CHECK_HOOK = 'iminify_check_alt_text';

	/**
	 * Sends the small copy of an image optimized earlier.
	 */
	const SEND_HOOK = 'iminify_send_alt_text';

	/**
	 * Polls the record the service answered for a small copy.
	 */
	const REQUEST_HOOK = 'iminify_check_alt_request';

	/**
	 * The service's refusal while the site's setting is off.
	 */
	const OFF = 'site_alt_text_off';

	/**
	 * The small copy is waiting to go out.
	 */
	const QUEUED = 'queued';

	/**
	 * The text is on its way, and the group or the record is kept until it comes.
	 */
	const WAITING = 'waiting';

	const WRITTEN = 'written';

	/**
	 * The text came, and someone had written their own meanwhile.
	 */
	const KEPT = 'kept';

	const FAILED = 'failed';

	/**
	 * The account's alt texts for the day were spent when the image was sent.
	 */
	const SKIPPED = 'skipped';

	/**
	 * A translation sharing another attachment's files: never sent, and left out of what the bulk run counts.
	 */
	const SHARED = 'shared';

	/**
	 * Why a bulk run holds when the service turned its key away: the run's `hold_reason`.
	 */
	const CONNECTION = 'connection';

	/**
	 * How long a run holds for that before it tries again.
	 */
	const CONNECTION_WAIT = 5 * MINUTE_IN_SECONDS;

	/**
	 * The actions that write an image's alt text, run by the bulk page for what is on its way.
	 */
	const HOOKS = array( self::CHECK_HOOK, self::SEND_HOOK, self::REQUEST_HOOK );

	/**
	 * What an image reads while its text is still to come.
	 */
	const ON_ITS_WAY = array( self::QUEUED, self::WAITING );

	/**
	 * How long a text is waited on. The service writes an account's one at a
	 * time, so a large run's last ones come hours after their files.
	 */
	const MAX_WAIT = 6 * HOUR_IN_SECONDS;

	/**
	 * How many failed sends of one small copy a run allows.
	 */
	const MAX_TRIES = 3;

	/**
	 * Hooks the runs into Action Scheduler.
	 */
	public static function register(): void {
		add_action( self::CHECK_HOOK, array( self::class, 'check' ), 10, 2 );
		add_action( self::REQUEST_HOOK, array( self::class, 'check_request' ), 10, 2 );
		add_action( self::SEND_HOOK, array( self::class, 'send' ), 10, 1 );
	}

	/**
	 * Whether the site writes alt texts, as the service last said.
	 */
	public static function on(): bool {
		return Site_Record::writes_alt_text();
	}

	/**
	 * Whether an attachment's alt is empty, read past what this request kept, since
	 * someone can write one in another request at any moment.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function is_empty( int $attachment ): bool {
		wp_cache_delete( $attachment, 'post_meta' );

		return self::lacks_alt( $attachment );
	}

	/**
	 * Whether a group opened for an attachment now asks for its alt text: the site
	 * writes them, it has none, and none is on its way.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $settings   The site's settings.
	 */
	public static function wanted( int $attachment, array $settings ): bool {
		return ! empty( $settings['alt_text'] )
			&& ! in_array( self::status( Records::get( $attachment )['alt'] ), self::ON_ITS_WAY, true )
			&& self::is_empty( $attachment );
	}

	/**
	 * Whether an attachment can be asked for an alt text on its own: it holds its
	 * files, it has none, none is on its way and no group of its own is coming.
	 *
	 * @param int  $attachment The attachment's id.
	 * @param bool $fresh      Whether to read past what this request kept, for a path that sends or counts it.
	 */
	public static function can_ask( int $attachment, bool $fresh = false ): bool {
		$record = Records::get( $attachment );

		return Shared::holder( $attachment, $fresh ) === $attachment
			&& ! in_array( self::status( $record['alt'] ), self::ON_ITS_WAY, true )
			&& ! in_array( $record['status'], array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ), true )
			&& in_array( get_post_mime_type( $attachment ), Declaration::MIME_TYPES, true )
			&& ( $fresh ? self::is_empty( $attachment ) : self::lacks_alt( $attachment ) );
	}

	/**
	 * Where a record's alt text stands: a wait that outlived its checks (the plugin
	 * was turned off while it lasted, which drops every scheduled run) reads as failed.
	 *
	 * @param array{status: string, group: string|null, request: string|null, since: int, language: string|null, tries: int}|null $alt The record's `alt`.
	 */
	public static function status( ?array $alt ): ?string {
		if ( null === $alt ) {
			return null;
		}

		return in_array( $alt['status'], self::ON_ITS_WAY, true ) && time() - $alt['since'] > self::MAX_WAIT + HOUR_IN_SECONDS ? self::FAILED : $alt['status'];
	}

	/**
	 * What a finished group said of its alt text, saved if it has come.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param string               $group      The group's id.
	 * @param array<string, mixed> $answer     The group as the service answered it.
	 * @return bool Whether the group can be deleted now.
	 */
	public static function settle( int $attachment, string $group, array $answer ): bool {
		$alt = isset( $answer['alt_text'] ) && is_array( $answer['alt_text'] ) ? $answer['alt_text'] : null;

		/* The group asked for none, or the service can't write them now: nothing is coming. */
		if ( null === $alt ) {
			return true;
		}

		/* A text waits for its output, so one whose main output failed would read `pending` for as long as it was asked. */
		if ( in_array( $answer['outputs'][0]['status'] ?? null, array( 'failed', 'cancelled' ), true ) ) {
			self::save( $attachment, array() );

			return true;
		}

		if ( 'pending' !== ( $alt['status'] ?? null ) ) {
			self::save( $attachment, $alt );

			return true;
		}

		self::wait_for( $attachment, array( 'group' => $group ), $alt );
		self::look_later( $attachment, $group, 0, false );

		return false;
	}

	/**
	 * Asks for an image's alt text from its panel: it goes on its own, right away.
	 *
	 * @param int $attachment The attachment's id.
	 * @return true|WP_Error
	 */
	public static function ask( int $attachment ) {
		if ( ! Plugin::connected() ) {
			return new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( ! self::on() ) {
			return new WP_Error( self::OFF, __( 'AI alt text is off for this site. Turn it on in the plugin\'s settings first.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		if ( ! self::can_ask( $attachment, true ) ) {
			return new WP_Error( 'alt_text_not_needed', __( 'This image already has alt text, or its text is on the way.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		self::queue( $attachment );

		return true;
	}

	/**
	 * Queues an image's small copy to go out.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function queue( int $attachment ): void {
		Records::update(
			$attachment,
			array(
				'alt' => array(
					'status'   => self::QUEUED,
					'group'    => null,
					'request'  => null,
					'since'    => time(),
					'language' => null,
					'tries'    => 0,
				),
			)
		);

		self::schedule_send( $attachment, 0 );
	}

	/**
	 * Action Scheduler's run for SEND_HOOK: sends an image's small copy and keeps
	 * the record the service answers, or the text, when the same bytes were
	 * already described.
	 *
	 * @param mixed $attachment The attachment's id.
	 */
	public static function send( $attachment ): void {
		$attachment = (int) $attachment;
		$alt        = Records::get( $attachment )['alt'];

		if ( null === $alt || self::QUEUED !== $alt['status'] ) {
			return;
		}

		$client = Plugin::client();

		/* A key the service stopped takes the site's record with it, so a run holds here rather than reading its setting as off and forgetting every image it queued. */
		if ( null === $client || ( null === Site_Record::cached() && is_wp_error( Site_Record::fetch() ) ) ) {
			if ( Bulk::hold_alt_texts( self::CONNECTION_WAIT, self::CONNECTION ) ) {
				self::hold( $attachment, self::CONNECTION_WAIT );

				return;
			}

			self::forget( $attachment );

			return;
		}

		/* The setting off since, a text someone wrote since, or a translation by now: nothing is asked, and nothing counted. */
		if ( ! self::on() || ! self::is_empty( $attachment ) || Shared::holder( $attachment, true ) !== $attachment ) {
			self::forget( $attachment );

			return;
		}

		try {
			$copy   = self::small_copy( $attachment );
			$answer = null === $copy ? null : $client->write_alt_text( $copy['path'], $copy['type'] );
		} finally {
			Offload_Media::put_away( $attachment );
		}

		if ( null === $answer ) {
			self::save( $attachment, array() );

			return;
		}

		if ( $answer instanceof WP_Error ) {
			self::refused( $attachment, $answer, $alt['tries'] );

			return;
		}

		if ( 'pending' === ( $answer['status'] ?? null ) && isset( $answer['id'] ) && is_string( $answer['id'] ) ) {
			self::wait_for( $attachment, array( 'request' => $answer['id'] ), $answer );
			self::look_later( $attachment, $answer['id'], 0, true );

			return;
		}

		self::save( $attachment, $answer );
	}

	/**
	 * Looks at a kept group or record again, the plugin having been off (which drops
	 * every scheduled run), or sends a small copy still waiting to go.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function resume( int $attachment ): void {
		$alt = Records::get( $attachment )['alt'];

		if ( null === $alt ) {
			return;
		}

		if ( self::QUEUED === $alt['status'] ) {
			self::schedule_send( $attachment, 0 );

			return;
		}

		foreach ( array(
			self::CHECK_HOOK   => $alt['group'],
			self::REQUEST_HOOK => $alt['request'],
		) as $hook => $id ) {
			if ( is_string( $id ) && '' !== $id && ! Optimizer::pending( $hook, array( $attachment, $id ) ) ) {
				as_schedule_single_action( time(), $hook, array( $attachment, $id ), Optimizer::GROUP );
			}
		}
	}

	/**
	 * Picks up an image whose run died with its request, or ends a wait that outlived its checks.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function heal( int $attachment ): void {
		$alt = Records::get( $attachment )['alt'];

		if ( null === $alt || ! in_array( $alt['status'], self::ON_ITS_WAY, true ) ) {
			return;
		}

		if ( self::FAILED === self::status( $alt ) ) {
			self::save( $attachment, array() );

			return;
		}

		if ( time() - $alt['since'] > Bulk::STALL_SECONDS ) {
			self::resume( $attachment );
		}
	}

	/**
	 * Takes back the small copies a stopped run hadn't sent yet, with nothing counted;
	 * those already with the service finish.
	 */
	public static function take_back(): void {
		$after = 0;

		do {
			$ids = Records::waiting_for_alt_text( 200, $after );

			foreach ( $ids as $id ) {
				$after = $id;
				$alt   = Records::get( $id )['alt'];

				if ( null !== $alt && self::QUEUED === $alt['status'] ) {
					as_unschedule_all_actions( self::SEND_HOOK, array( $id ), Optimizer::GROUP );
					self::forget( $id );
				}
			}
		} while ( array() !== $ids );
	}

	/**
	 * Sets aside an image the bulk run passed over: a translation sharing another
	 * attachment's files would read as lacking an alt text for ever.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function pass_over( int $attachment ): void {
		if ( Shared::holder( $attachment, true ) === $attachment ) {
			return;
		}

		Records::update(
			$attachment,
			array(
				'alt' => array(
					'status'   => self::SHARED,
					'group'    => null,
					'request'  => null,
					'since'    => time(),
					'language' => null,
					'tries'    => 0,
				),
			)
		);
	}

	/**
	 * Action Scheduler's run for CHECK_HOOK: asks a kept group for its alt text
	 * and, once it has settled, deletes the group.
	 *
	 * @param mixed $attachment The attachment's id.
	 * @param mixed $group      The group's id.
	 */
	public static function check( $attachment, $group ): void {
		self::follow( (int) $attachment, (string) $group, false );
	}

	/**
	 * Action Scheduler's run for REQUEST_HOOK: asks the record of a small copy for its alt text.
	 *
	 * @param mixed $attachment The attachment's id.
	 * @param mixed $request    The record's id.
	 */
	public static function check_request( $attachment, $request ): void {
		self::follow( (int) $attachment, (string) $request, true );
	}

	/**
	 * Asks a kept group or record for its alt text and saves it once it has settled.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $id         The group's or record's id.
	 * @param bool   $request    Whether it is a record of a small copy, which the service deletes by itself.
	 */
	protected static function follow( int $attachment, string $id, bool $request ): void {
		$alt = Records::get( $attachment )['alt'];

		/* The attachment went, or something else settled it: all that's left is the group. */
		if ( null === $alt || self::WAITING !== $alt['status'] || $id !== (string) $alt[ $request ? 'request' : 'group' ] ) {
			self::let_go( $id, $request );

			return;
		}

		$elapsed = time() - $alt['since'];
		$client  = Plugin::client();
		$answer  = null;

		if ( null !== $client ) {
			$answer = $request ? $client->alt_text( $id ) : $client->group( $id );
		}

		if ( is_array( $answer ) ) {
			$text = isset( $answer['alt_text'] ) && is_array( $answer['alt_text'] ) ? $answer['alt_text'] : null;
			$text = $request ? $answer : $text;

			if ( null !== $text && 'pending' === ( $text['status'] ?? null ) && $elapsed < self::MAX_WAIT ) {
				self::look_later( $attachment, $id, $elapsed, $request );

				return;
			}

			self::save( $attachment, $text ?? array() );
			self::let_go( $id, $request );

			return;
		}

		/* Lost on the way, the group gone or the site disconnected: a wait for the service is tried again until it runs out, and the group is the service's to delete after that. */
		$lost = is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'not_found', 'http_404' ), true );

		if ( ! $lost && null !== $client && $elapsed < self::MAX_WAIT ) {
			self::look_later( $attachment, $id, max( $elapsed, 300 ), $request );

			return;
		}

		self::save( $attachment, array() );
	}

	/**
	 * Lets go of what a settled text was kept for: a group is deleted; a small copy's
	 * record goes by itself, a day on.
	 *
	 * @param string $id      The group's or record's id.
	 * @param bool   $request Whether it is a record of a small copy.
	 */
	protected static function let_go( string $id, bool $request ): void {
		if ( ! $request ) {
			Optimizer::delete_group( $id );
		}
	}

	/**
	 * Saves that a text is on its way.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $holder     What it is kept on: `group` or `request`.
	 * @param array<string, mixed> $answer     The service's words on it.
	 */
	protected static function wait_for( int $attachment, array $holder, array $answer ): void {
		Records::update(
			$attachment,
			array(
				'alt' => array_merge(
					array(
						'status'   => self::WAITING,
						'group'    => null,
						'request'  => null,
						'since'    => time(),
						'language' => isset( $answer['language'] ) && is_string( $answer['language'] ) ? $answer['language'] : null,
						'tries'    => 0,
					),
					$holder
				),
			)
		);
	}

	/**
	 * What a send the service refused or never answered does next. A spent day holds
	 * a bulk run and waits, since the run goes on when the day opens; on a press of
	 * its own it ends there.
	 *
	 * @param int      $attachment The attachment's id.
	 * @param WP_Error $error      The refusal.
	 * @param int      $tries      The failed tries so far.
	 */
	protected static function refused( int $attachment, WP_Error $error, int $tries ): void {
		if ( self::OFF === $error->get_error_code() ) {
			self::forget( $attachment );

			return;
		}

		$data = (array) $error->get_error_data();

		if ( Optimizer::DAILY_LIMIT === $error->get_error_code() ) {
			$seconds = isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : HOUR_IN_SECONDS;

			if ( Bulk::hold_alt_texts( $seconds ) ) {
				self::hold( $attachment, $seconds );

				return;
			}

			self::save( $attachment, array( 'status' => 'skipped' ) );

			return;
		}

		$why = Refusal::decide( $error, false, $tries );

		switch ( $why['then'] ) {
			case Refusal::PARK:
				/* A key the service stopped or a site that moved holds a run, which would otherwise walk the library forgetting each image. */
				if ( Bulk::hold_alt_texts( self::CONNECTION_WAIT, self::CONNECTION ) ) {
					self::hold( $attachment, self::CONNECTION_WAIT );

					return;
				}

				self::forget( $attachment );
				return;
			case Refusal::WAIT:
				self::schedule_send( $attachment, $why['seconds'] );
				return;
			case Refusal::RETRY:
				if ( $tries + 1 < self::MAX_TRIES ) {
					$alt          = Records::get( $attachment )['alt'];
					$alt['tries'] = $tries + 1;

					Records::update( $attachment, array( 'alt' => $alt ) );
					self::schedule_send( $attachment, $why['seconds'] );

					return;
				}
				// Out of tries: it fails, as a refusal does.
		}

		self::save( $attachment, array() );
	}

	/**
	 * Keeps an image queued through a run's hold: its wait starts when the hold ends,
	 * so a hold of a day doesn't read as a text that never came.
	 *
	 * @param int $attachment The attachment's id.
	 * @param int $seconds    How long the run holds.
	 */
	protected static function hold( int $attachment, int $seconds ): void {
		$alt = Records::get( $attachment )['alt'];

		if ( null !== $alt ) {
			$alt['since'] = time() + $seconds;

			Records::update( $attachment, array( 'alt' => $alt ) );
		}

		self::schedule_send( $attachment, $seconds + 5 );
	}

	/**
	 * Schedules the send of an image's small copy, once.
	 *
	 * @param int $attachment The attachment's id.
	 * @param int $delay      Seconds to wait first.
	 */
	protected static function schedule_send( int $attachment, int $delay ): void {
		if ( ! Optimizer::pending( self::SEND_HOOK, array( $attachment ) ) ) {
			as_schedule_single_action( time() + $delay, self::SEND_HOOK, array( $attachment ), Optimizer::GROUP );
		}
	}

	/**
	 * Clears an alt text that was never asked for, nothing having been counted.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function forget( int $attachment ): void {
		Records::update( $attachment, array( 'alt' => null ) );
	}

	/**
	 * The smallest of an attachment's files on the disk that shows it at least as
	 * large as the service looks at it, else the largest one there is, and within
	 * what the service takes. A site with its images only in WP Offload Media's
	 * bucket brings the attached file back for it (`Offload_Media::put_away()`
	 * deletes it again after the send).
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{path: string, type: string}|null The file, or null when none will do.
	 */
	protected static function small_copy( int $attachment ): ?array {
		$site     = Site_Record::cached() ?? Site_Record::fetch();
		$limits   = is_array( $site ) && isset( $site['alt_text_source'] ) && is_array( $site['alt_text_source'] ) ? $site['alt_text_source'] : array();
		$side     = (int) ( $limits['side'] ?? 768 );
		$max_side = (int) ( $limits['max_side'] ?? 4096 );
		$max_size = (int) ( $limits['max_bytes'] ?? 5242880 );
		$attached = (string) get_attached_file( $attachment, true );
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$files    = array();

		if ( '' === $attached || ! is_array( $metadata ) ) {
			return null;
		}

		$files[] = array(
			'path' => $attached,
			'type' => (string) get_post_mime_type( $attachment ),
			'side' => max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) ),
		);

		foreach ( isset( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
			$files[] = array(
				'path' => dirname( $attached ) . '/' . wp_basename( $size['file'] ),
				'type' => $size['mime-type'],
				'side' => max( $size['width'], $size['height'] ),
			);
		}

		/* The smallest that is large enough first, then the rest from the largest down. */
		usort(
			$files,
			static function ( array $one, array $two ) use ( $side ): int {
				$first = $one['side'] >= $side;

				if ( ( $two['side'] >= $side ) !== $first ) {
					return $first ? -1 : 1;
				}

				return $first ? $one['side'] <=> $two['side'] : $two['side'] <=> $one['side'];
			}
		);

		foreach ( $files as $file ) {
			if ( in_array( $file['type'], Declaration::MIME_TYPES, true ) && $file['side'] <= $max_side && is_file( $file['path'] ) && (int) filesize( $file['path'] ) <= $max_size ) {
				return array(
					'path' => $file['path'],
					'type' => $file['type'],
				);
			}
		}

		if ( ! Offload_Media::bring_back( $attachment, $attached ) || (int) filesize( $attached ) > $max_size ) {
			return null;
		}

		return array(
			'path' => $attached,
			'type' => (string) get_post_mime_type( $attachment ),
		);
	}

	/**
	 * Whether an attachment's alt is empty, as far as this request knows.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function lacks_alt( int $attachment ): bool {
		return '' === trim( (string) get_post_meta( $attachment, self::META, true ) );
	}

	/**
	 * Saves an alt text that has settled: the text where the attachment still has
	 * none, and where it stands in the record.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $alt        The group's `alt_text`, or a record's answer.
	 */
	protected static function save( int $attachment, array $alt ): void {
		/* As core saves an alt typed in the media library: tags out and nothing else, since a percent sign before a number is ordinary Turkish. */
		$text   = isset( $alt['text'] ) && is_string( $alt['text'] ) ? trim( wp_strip_all_tags( $alt['text'], true ) ) : '';
		$status = self::FAILED;

		if ( 'skipped' === ( $alt['status'] ?? null ) ) {
			$status = self::SKIPPED;
		} elseif ( 'written' === ( $alt['status'] ?? null ) && '' !== $text ) {
			$status = self::write( $attachment, $text ) ? self::WRITTEN : self::KEPT;
		}

		Records::update(
			$attachment,
			array(
				'alt' => array(
					'status'   => $status,
					'group'    => null,
					'request'  => null,
					'since'    => time(),
					'language' => isset( $alt['language'] ) && is_string( $alt['language'] ) ? $alt['language'] : null,
					'tries'    => 0,
				),
			)
		);
	}

	/**
	 * Writes a text as the attachment's alt, unless it has one by now.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $text       The text.
	 * @return bool Whether it was written.
	 */
	protected static function write( int $attachment, string $text ): bool {
		if ( ! self::is_empty( $attachment ) ) {
			return false;
		}

		update_post_meta( $attachment, self::META, wp_slash( $text ) );

		/**
		 * An image's alt text was written, in a field that had none.
		 *
		 * @param int    $attachment The attachment's id.
		 * @param string $text       The text.
		 */
		do_action( 'iminify_alt_text_written', $attachment, $text );

		return true;
	}

	/**
	 * Schedules the next look at a kept group or record: every 10 s at first, easing
	 * to a minute as the text takes longer, and more for a service that didn't answer.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $id         The group's or record's id.
	 * @param int    $elapsed    Seconds it has been waited on.
	 * @param bool   $request    Whether it is a record of a small copy.
	 */
	protected static function look_later( int $attachment, string $id, int $elapsed, bool $request ): void {
		$seconds = (int) ( $elapsed >= 300 ? 5 * MINUTE_IN_SECONDS : min( MINUTE_IN_SECONDS, 10 + $elapsed / 6 ) );

		as_schedule_single_action( time() + $seconds, $request ? self::REQUEST_HOOK : self::CHECK_HOOK, array( $attachment, $id ), Optimizer::GROUP );
	}
}
