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
 * author gets their own images and an editor everyone's. The files in other folders
 * (`Folders`) are the site's themes and plugins, so they ask what bulk asks, and on
 * a network, where every site shares them, what managing the network asks; so does
 * the network's own connection, which the main site's is (`Network`). A page audit
 * spends the account's page scans, so it asks what the settings ask.
 */
class Capabilities {

	const SETTINGS = 'settings';

	const BULK = 'bulk';

	const MEDIA = 'media';

	const FOLDERS = 'folders';

	const AUDIT = 'audit';

	const NETWORK = 'network';

	const DEFAULTS = array(
		self::SETTINGS => 'manage_options',
		self::BULK     => 'manage_options',
		self::MEDIA    => 'upload_files',
		self::FOLDERS  => 'manage_options',
		self::AUDIT    => 'manage_options',
		self::NETWORK  => 'manage_network_options',
	);

	/**
	 * The capability a part of the plugin asks for.
	 *
	 * @param string $context SETTINGS, BULK, MEDIA, FOLDERS, AUDIT or NETWORK.
	 */
	public static function required( string $context ): string {
		$default = self::FOLDERS === $context && is_multisite() ? 'manage_network_options' : ( self::DEFAULTS[ $context ] ?? 'manage_options' );

		/**
		 * The capability a part of the plugin asks for.
		 *
		 * @param string $capability The capability: `manage_options` for `settings`, `bulk`, `folders` and `audit` (`manage_network_options` for `folders` on a network), `upload_files` for `media`, `manage_network_options` for `network` (the network's connection).
		 * @param string $context    `settings`, `bulk`, `media`, `folders`, `audit` or `network`.
		 */
		return (string) apply_filters( 'iminify_capability', $default, $context );
	}

	/**
	 * Whether the person may use a part of the plugin.
	 *
	 * @param string $context SETTINGS, BULK, MEDIA, FOLDERS, AUDIT or NETWORK.
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
