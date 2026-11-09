<?php
/**
 * AI alt text for an attachment, written only where it has none.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Plugin;

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
 * An alt text someone wrote is never replaced, whenever they wrote it: the
 * attachment's alt is read again right before the text is saved, so one typed
 * while the text was on its way stays.
 *
 * The record's `alt` says where it stands. Nothing here touches an attachment's
 * files, and a translation sharing them keeps its own alt, in its own language.
 */
class Alt_Text {

	/**
	 * WordPress's own meta for an attachment's alt text.
	 */
	const META = '_wp_attachment_image_alt';

	const CHECK_HOOK = 'iminify_check_alt_text';

	/**
	 * The text is on its way, and the group is kept until it comes.
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
	 * How long a text is waited on. The service writes an account's one at a
	 * time, so a large run's last ones come hours after their files.
	 */
	const MAX_WAIT = 6 * HOUR_IN_SECONDS;

	/**
	 * Hooks the check into Action Scheduler.
	 */
	public static function register(): void {
		add_action( self::CHECK_HOOK, array( self::class, 'check' ), 10, 2 );
	}

	/**
	 * Whether an attachment's alt is empty, read past what this request kept, since
	 * someone can write one in another request at any moment.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function is_empty( int $attachment ): bool {
		wp_cache_delete( $attachment, 'post_meta' );

		return '' === trim( (string) get_post_meta( $attachment, self::META, true ) );
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
			&& self::WAITING !== self::status( Records::get( $attachment )['alt'] )
			&& self::is_empty( $attachment );
	}

	/**
	 * Where a record's alt text stands: a wait that outlived its checks (the plugin
	 * was turned off while it lasted, which drops every scheduled run) reads as failed.
	 *
	 * @param array{status: string, group: string|null, since: int, language: string|null}|null $alt The record's `alt`.
	 */
	public static function status( ?array $alt ): ?string {
		if ( null === $alt ) {
			return null;
		}

		return self::WAITING === $alt['status'] && time() - $alt['since'] > self::MAX_WAIT + HOUR_IN_SECONDS ? self::FAILED : $alt['status'];
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

		Records::update(
			$attachment,
			array(
				'alt' => array(
					'status'   => self::WAITING,
					'group'    => $group,
					'since'    => time(),
					'language' => isset( $alt['language'] ) && is_string( $alt['language'] ) ? $alt['language'] : null,
				),
			)
		);

		self::look_later( $attachment, $group, 0 );

		return false;
	}

	/**
	 * Looks at a kept group again, the plugin having been off: turning it off drops
	 * every scheduled run, and a text that came meanwhile is still the site's.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function resume( int $attachment ): void {
		$group = (string) ( Records::get( $attachment )['alt']['group'] ?? '' );

		if ( '' !== $group && ! Optimizer::pending( self::CHECK_HOOK, array( $attachment, $group ) ) ) {
			as_schedule_single_action( time(), self::CHECK_HOOK, array( $attachment, $group ), Optimizer::GROUP );
		}
	}

	/**
	 * Action Scheduler's run for CHECK_HOOK: asks a kept group for its alt text
	 * and, once it has settled, deletes the group.
	 *
	 * @param mixed $attachment The attachment's id.
	 * @param mixed $group      The group's id.
	 */
	public static function check( $attachment, $group ): void {
		$attachment = (int) $attachment;
		$group      = (string) $group;
		$alt        = Records::get( $attachment )['alt'];

		/* The attachment went, or something else settled it: all that's left is the group. */
		if ( null === $alt || self::WAITING !== $alt['status'] || $group !== (string) $alt['group'] ) {
			Optimizer::delete_group( $group );

			return;
		}

		$elapsed = time() - $alt['since'];
		$client  = Plugin::client();
		$answer  = null === $client ? null : $client->group( $group );

		if ( is_array( $answer ) ) {
			$text = isset( $answer['alt_text'] ) && is_array( $answer['alt_text'] ) ? $answer['alt_text'] : null;

			if ( null !== $text && 'pending' === ( $text['status'] ?? null ) && $elapsed < self::MAX_WAIT ) {
				self::look_later( $attachment, $group, $elapsed );

				return;
			}

			self::save( $attachment, $text ?? array() );
			Optimizer::delete_group( $group );

			return;
		}

		/* Lost on the way, the group gone or the site disconnected: a wait for the service is tried again until it runs out, and the group is the service's to delete after that. */
		$lost = is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'not_found', 'http_404' ), true );

		if ( ! $lost && null !== $client && $elapsed < self::MAX_WAIT ) {
			self::look_later( $attachment, $group, max( $elapsed, 300 ) );

			return;
		}

		self::save( $attachment, array() );
	}

	/**
	 * Saves an alt text that has settled: the text where the attachment still has
	 * none, and where it stands in the record.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $alt        The group's `alt_text`.
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
					'since'    => time(),
					'language' => isset( $alt['language'] ) && is_string( $alt['language'] ) ? $alt['language'] : null,
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
	 * Schedules the next look at a kept group: every 10 s at first, easing to a
	 * minute as the text takes longer, and more for a service that didn't answer.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $group      The group's id.
	 * @param int    $elapsed    Seconds it has been waited on.
	 */
	protected static function look_later( int $attachment, string $group, int $elapsed ): void {
		$seconds = (int) ( $elapsed >= 300 ? 5 * MINUTE_IN_SECONDS : min( MINUTE_IN_SECONDS, 10 + $elapsed / 6 ) );

		as_schedule_single_action( time() + $seconds, self::CHECK_HOOK, array( $attachment, $group ), Optimizer::GROUP );
	}
}
