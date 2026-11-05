<?php
/**
 * Who may do what with the plugin.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Someone who can manage the site's options reaches its settings and its bulk run,
 * which spends the account's day for the whole library. Someone who can upload files
 * may optimize and restore an image they can edit, as core asks of editing one, so an
 * author gets their own images and an editor everyone's.
 */
class Capabilities {

	const SETTINGS = 'settings';

	const BULK = 'bulk';

	const MEDIA = 'media';

	const DEFAULTS = array(
		self::SETTINGS => 'manage_options',
		self::BULK     => 'manage_options',
		self::MEDIA    => 'upload_files',
	);

	/**
	 * The capability a part of the plugin asks for.
	 *
	 * @param string $context SETTINGS, BULK or MEDIA.
	 */
	public static function required( string $context ): string {
		/**
		 * The capability a part of the plugin asks for.
		 *
		 * @param string $capability The capability: `manage_options` for `settings` and `bulk`, `upload_files` for `media`.
		 * @param string $context    `settings`, `bulk` or `media`.
		 */
		return (string) apply_filters( 'iminify_capability', self::DEFAULTS[ $context ] ?? 'manage_options', $context );
	}

	/**
	 * Whether the person may use a part of the plugin.
	 *
	 * @param string $context SETTINGS, BULK or MEDIA.
	 */
	public static function can( string $context ): bool {
		return current_user_can( self::required( $context ) );
	}

	/**
	 * Whether the person may optimize or restore this attachment.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function can_change( int $attachment ): bool {
		return self::can( self::MEDIA ) && current_user_can( 'edit_post', $attachment );
	}
}
