<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * WordPress loads this file on its own, without the plugin's main file, so it
 * sets up what the cleanup needs (`Lifecycle::uninstall()` says what it removes
 * and what it keeps).
 *
 * @package Iminify\WordPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

if ( ! defined( 'IMINIFY_FILE' ) ) {
	define( 'IMINIFY_FILE', __DIR__ . '/iminify-image-optimizer.php' );
	define( 'IMINIFY_DIR', __DIR__ );
	define( 'IMINIFY_VERSION', (string) get_file_data( IMINIFY_FILE, array( 'version' => 'Version' ) )['version'] );
}

require_once __DIR__ . '/includes/class-autoloader.php';

Iminify\WordPress\Autoloader::register();
Iminify\WordPress\Lifecycle::uninstall();
