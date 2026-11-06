<?php
/**
 * The service's messages and refusals, in the site's language.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iminify writes its messages as a code with English text. For a code it
 * knows, the plugin shows its own words, which translate with the plugin; on
 * an English admin it shows the service's text instead, which names the plan
 * or trial that would carry on with the service's own numbers, since the
 * plugin never types a number into its copy. A code it doesn't know shows the
 * service's text as sent.
 */
class Messages {

	/**
	 * The service's compression levels, with a short name and a line saying what each does.
	 *
	 * @return array<string, array{name: string, label: string}>
	 */
	public static function levels(): array {
		return array(
			'smart'    => array(
				'name'  => __( 'Smart', 'iminify-image-optimizer' ),
				'label' => __( 'Smart: a balance between quality and size', 'iminify-image-optimizer' ),
			),
			'ultra'    => array(
				'name'  => __( 'Ultra', 'iminify-image-optimizer' ),
				'label' => __( 'Ultra: the smallest files, with some loss of quality', 'iminify-image-optimizer' ),
			),
			'lossless' => array(
				'name'  => __( 'Lossless', 'iminify-image-optimizer' ),
				'label' => __( 'Lossless: no visible change in quality', 'iminify-image-optimizer' ),
			),
			'none'     => array(
				'name'  => __( 'None', 'iminify-image-optimizer' ),
				'label' => __( 'None: no compression, only the other settings', 'iminify-image-optimizer' ),
			),
		);
	}

	/**
	 * The message `GET /site` answered, ready to show, or null. With the account
	 * kept out of sight (Key_Store::hides_account()), only the plugin's own words
	 * show, since the service's name the account's plan, trial and numbers.
	 *
	 * @param mixed $message The record's `message`.
	 * @param bool  $hidden  Whether wp-config.php keeps the account out of sight.
	 * @return array{code: string, text: string}|null
	 */
	public static function for_site( $message, bool $hidden = false ): ?array {
		if ( ! is_array( $message ) || ! isset( $message['code'] ) || ! is_string( $message['code'] ) || '' === $message['code'] ) {
			return null;
		}

		$code   = $message['code'];
		$server = isset( $message['text'] ) && is_string( $message['text'] ) ? $message['text'] : '';
		$own    = self::site_message( $code );

		if ( null === $own ) {
			return '' === $server || $hidden ? null : array(
				'code' => $code,
				'text' => $server,
			);
		}

		return array(
			'code' => $code,
			'text' => '' !== $server && ! $hidden && self::is_english() ? $server : $own,
		);
	}

	/**
	 * A refusal from Iminify, in the plugin's words for the codes a person can act on.
	 *
	 * @param WP_Error $error The refusal.
	 */
	public static function for_error( WP_Error $error ): string {
		switch ( $error->get_error_code() ) {
			case 'unauthenticated':
				return __( 'Iminify doesn\'t know this key. Check that you copied all of it, or make a new one.', 'iminify-image-optimizer' );
			case 'site_key_required':
				return __( 'That is an API key. A site needs a site key: make one under Settings, Sites on iminify.com, or press Connect instead.', 'iminify-image-optimizer' );
			case 'site_moved':
				return __( 'This key belongs to the site at another address, so this copy of the site can\'t use it. Connect this copy as a site of its own, or move the connection here.', 'iminify-image-optimizer' );
			case 'rate_limited':
				$data = (array) $error->get_error_data();
				$wait = isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0;

				return $wait > 0
					/* translators: %s: how long to wait, such as "10 minutes". */
					? sprintf( __( 'Iminify asked the plugin to wait. Try again in %s.', 'iminify-image-optimizer' ), human_time_diff( time(), time() + $wait ) )
					: __( 'Iminify asked the plugin to wait. Try again later.', 'iminify-image-optimizer' );
			case 'pairing_closed':
				return __( 'The connection can no longer be used: it expired, was turned down, or was used already. Press Connect to start again.', 'iminify-image-optimizer' );
			case 'network_required':
				return __( 'The network\'s key was made for a single site, so the network\'s sites can\'t join with it. A super admin connects the network again from the network admin.', 'iminify-image-optimizer' );
			case 'network_full':
				return __( 'The network already has as many sites as one network can hold. Remove a site you no longer use under Settings, Sites on iminify.com, or connect this site on its own.', 'iminify-image-optimizer' );
			case 'site_connected_elsewhere':
				return __( 'This site is already connected to the account on its own, or in another network. Disconnect it under Settings, Sites on iminify.com to add it to this network, or connect it here on its own.', 'iminify-image-optimizer' );
			default:
				return $error->get_error_message();
		}
	}

	/**
	 * The plugin's own words for a site message's code, or null for one it doesn't know.
	 *
	 * @param string $code The message's code.
	 */
	protected static function site_message( string $code ): ?string {
		switch ( $code ) {
			case 'update_required':
				return __( 'This version of Iminify Image Optimizer is too old to keep optimizing images. Update it from the Plugins page.', 'iminify-image-optimizer' );
			case 'day_spent':
				return __( 'Your account\'s images for the day are used up. New uploads wait and go out by themselves when the next batch opens.', 'iminify-image-optimizer' );
			case 'site_trial_used':
				return __( 'This site had its free trial of Pro on another Iminify account, so its uploads get the free plan\'s numbers here.', 'iminify-image-optimizer' );
			default:
				return null;
		}
	}

	/**
	 * Whether the person reads the admin in English, the language of the service's text.
	 */
	protected static function is_english(): bool {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();

		return 'en' === $locale || 0 === strpos( $locale, 'en_' );
	}
}
