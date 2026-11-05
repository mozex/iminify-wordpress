<?php
/**
 * The plugin's one notice outside its own page.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Connection;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * At most one notice, on the dashboard and the plugin and media screens, for
 * people who manage the site's options (directory guideline 11): connect the
 * site, connect it again after Iminify stopped its key, or the service's
 * message. It reads only what the plugin keeps (the key, the pairing, the
 * service's message last read, Site_Record::message()), so it never calls Iminify, and
 * each person's dismissal holds until the notice says something new.
 */
class Notice {

	const META = 'iminify_dismissed_notices';

	const SCREENS = array( 'dashboard', 'plugins', 'upload', 'media' );

	const HANDLE = 'iminify-notice';

	/**
	 * The notice to show on this screen, or null.
	 *
	 * @return array{key: string, type: string, text: string, action: string}|null
	 */
	public static function current(): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return null;
		}

		$notice = self::pick();

		if ( null === $notice || in_array( $notice['key'], self::dismissed(), true ) ) {
			return null;
		}

		return $notice;
	}

	/**
	 * Prints the notice, on the `admin_notices` action.
	 */
	public static function render(): void {
		$notice = self::current();

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div id="iminify-notice" class="notice notice-%1$s is-dismissible" data-notice="%2$s"><p>%3$s <a href="%4$s">%5$s</a></p></div>',
			esc_attr( $notice['type'] ),
			esc_attr( $notice['key'] ),
			esc_html( $notice['text'] ),
			esc_url( Settings_Page::url() ),
			esc_html( $notice['action'] )
		);
	}

	/**
	 * Loads the script that remembers a dismissal, on screens that show the notice.
	 */
	public static function enqueue(): void {
		if ( null === self::current() ) {
			return;
		}

		Settings_Page::enqueue_entry( self::HANDLE, 'notice' );
	}

	/**
	 * Remembers that the person dismissed a notice.
	 *
	 * @param string $key The notice's key.
	 */
	public static function dismiss( string $key ): void {
		$key = substr( preg_replace( '/[^a-z0-9_|:.+-]/i', '', $key ) ?? '', 0, 100 );

		if ( '' === $key ) {
			return;
		}

		$dismissed = array_values( array_diff( self::dismissed(), array( $key ) ) );

		$dismissed[] = $key;

		update_user_meta( get_current_user_id(), self::META, array_slice( $dismissed, -20 ) );
	}

	/**
	 * The notice the plugin's state calls for, dismissed or not.
	 *
	 * @return array{key: string, type: string, text: string, action: string}|null
	 */
	protected static function pick(): ?array {
		if ( null === Plugin::keys()->get() ) {
			if ( null !== ( new Connection() )->pairing() ) {
				return null;
			}

			return Connection::was_revoked() ? array(
				'key'    => 'revoked',
				'type'   => 'warning',
				'text'   => __( 'Iminify disconnected this site, so new uploads aren\'t optimized.', 'iminify-image-optimizer' ),
				'action' => __( 'Connect it again', 'iminify-image-optimizer' ),
			) : array(
				'key'    => 'connect',
				'type'   => 'info',
				'text'   => __( 'Iminify Image Optimizer is ready. Connect it to your Iminify account to start optimizing your images.', 'iminify-image-optimizer' ),
				'action' => __( 'Connect', 'iminify-image-optimizer' ),
			);
		}

		$kept  = Site_Record::message();
		$until = Pause::until();

		/* A group the service refused for the day, when `/site` has nothing to say about it: too few of the day's files for it, or a plan's fair-use line. */
		if ( null === $kept && null !== $until ) {
			$kept = array(
				'message'         => array( 'code' => 'day_spent' ),
				'next_batch_at'   => gmdate( 'c', $until ),
				'minimum_version' => null,
			);
		}

		$message = null === $kept ? null : Messages::for_site( $kept['message'], Plugin::keys()->hides_account() );

		if ( null === $kept || null === $message ) {
			return null;
		}

		$next = null === $kept['next_batch_at'] ? false : strtotime( $kept['next_batch_at'] );

		/* A spent day's message holds only until its next batch opens. */
		if ( false !== $next && $next <= time() ) {
			return null;
		}

		return array(
			'key'    => $message['code'] . '|' . ( $kept['next_batch_at'] ?? $kept['minimum_version'] ?? '' ),
			'type'   => 'update_required' === $message['code'] ? 'error' : 'warning',
			'text'   => $message['text'],
			'action' => __( 'Open the Iminify settings', 'iminify-image-optimizer' ),
		);
	}

	/**
	 * The notices the current person dismissed.
	 *
	 * @return list<string>
	 */
	protected static function dismissed(): array {
		$dismissed = get_user_meta( get_current_user_id(), self::META, true );

		return is_array( $dismissed ) ? array_values( array_filter( $dismissed, 'is_string' ) ) : array();
	}
}
