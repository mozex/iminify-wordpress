<?php
/**
 * Unit tests run without WordPress; Brain Monkey stands in for its functions.
 *
 * @package Iminify\WordPress
 */

define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, which the plugin's files require.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';

Iminify\WordPress\Autoloader::register();
