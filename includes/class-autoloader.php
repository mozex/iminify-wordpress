<?php
/**
 * Loads the plugin's classes from includes/ by WordPress's file naming.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps `Iminify\WordPress\Api\Client` to `includes/api/class-client.php`.
 */
class Autoloader {

	const PREFIX = 'Iminify\\WordPress\\';

	/**
	 * Registers the autoloader once.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Requires the file of one of the plugin's classes, and ignores any other class.
	 *
	 * @param string $class_name The fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$path = self::path( $class_name );

		if ( null !== $path && is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * The file a class of the plugin's lives in, or null for a class outside its namespace.
	 *
	 * @param string $class_name The fully qualified class name.
	 */
	public static function path( string $class_name ): ?string {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$class = (string) array_pop( $parts );
		$file  = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$dirs  = array_map(
			static function ( string $part ): string {
				return str_replace( '_', '-', strtolower( $part ) );
			},
			$parts
		);

		return implode( DIRECTORY_SEPARATOR, array_merge( array( __DIR__ ), $dirs, array( $file ) ) );
	}
}
