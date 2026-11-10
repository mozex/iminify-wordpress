<?php
/**
 * Plugin Name:       Iminify Image Optimizer
 * Plugin URI:        https://www.iminify.com
 * Description:       Compress, convert and resize the images in your media library with Iminify, and serve them as WebP and AVIF.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Iminify
 * Author URI:        https://www.iminify.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iminify-image-optimizer
 * Domain Path:       /languages
 *
 * @package Iminify\WordPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMINIFY_VERSION', '1.0.0' );
define( 'IMINIFY_FILE', __FILE__ );
define( 'IMINIFY_DIR', __DIR__ );

/*
 * Action Scheduler registers its version as it loads and the newest copy on the
 * site runs, so it has to load before plugins_loaded and is never prefixed.
 */
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/includes/class-autoloader.php';

Iminify\WordPress\Autoloader::register();

register_activation_hook( __FILE__, array( Iminify\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Iminify\WordPress\Lifecycle::class, 'deactivate' ) );

Iminify\WordPress\Plugin::boot();
