<?php
/**
 * Empties the page caches once the site's images changed in bulk.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Compat;

use Iminify\WordPress\Media\Bulk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page a cache kept from before a bulk run still prints the old markup: no
 * `<picture>` for the copies a run made, or worse, a `<picture>` whose WebP and
 * AVIF copies a restore deleted since, which a browser shows as a broken image
 * (it never falls back from a `<source>` that fails). So each cache plugin the
 * site runs is told to empty itself, through its own call: once a bulk run ends,
 * once a restore run that had put images back is stopped or paused, and once
 * someone restores an image or deletes its copies outside a running restore
 * run. An image optimized on its own (an upload) purges nothing: a page cached
 * before it names its old file, which is still there, and emptying every cache
 * on every upload would cost more than it gives.
 *
 * LiteSpeed Cache, WP Rocket and W3 Total Cache are called as each documents
 * it, Cloudflare's plugin through its hooks object; a cache none of them runs
 * is left alone, and `iminify_purge_caches` turns the whole thing off.
 */
class Caches {

	/**
	 * Set while a bulk run restored images or deleted copies, so the run's end
	 * or its stop empties the caches once rather than every image doing it.
	 */
	const OWED = 'iminify_purge_owed';

	/**
	 * Whether this request already asked for a purge at its end.
	 *
	 * @var bool
	 */
	protected static $pending = false;

	/**
	 * Hooks the purges in.
	 */
	public static function register(): void {
		add_action( 'iminify_bulk_finished', array( self::class, 'run_finished' ) );
		add_action( 'iminify_bulk_stopped', array( self::class, 'run_held' ) );
		add_action( 'iminify_bulk_paused', array( self::class, 'run_held' ) );
		add_action( 'iminify_image_restored', array( self::class, 'copies_gone' ) );
		add_action( 'iminify_copies_deleted', array( self::class, 'copies_gone' ) );
		add_action( 'iminify_file_restored', array( self::class, 'copies_gone' ) );
	}

	/**
	 * A bulk run went through everything it had to.
	 */
	public static function run_finished(): void {
		delete_option( self::OWED );
		self::purge( 'bulk' );
	}

	/**
	 * A bulk run was stopped or paused: what it had restored is restored, so the
	 * caches that still name the copies it deleted are emptied now, rather than
	 * whenever the run goes on.
	 */
	public static function run_held(): void {
		if ( false === get_option( self::OWED ) ) {
			return;
		}

		delete_option( self::OWED );
		self::purge( 'bulk' );
	}

	/**
	 * An image's WebP and AVIF copies are gone, or a file's in another folder,
	 * which the whole-page mode names in a page. During a bulk restore the run's
	 * end empties the caches; otherwise the end of this request does, once,
	 * however many images it put back.
	 */
	public static function copies_gone(): void {
		$run = Bulk::run();

		/* Only a running restore run puts images back by the hundred, the library's or the folders'; a restore during any other run (an optimize waiting out a spent day) or a paused one purges now. */
		if ( null !== $run && Bulk::RESTORE === $run['action'] && Bulk::RUNNING === $run['status'] ) {
			update_option( self::OWED, 1, false );

			return;
		}

		if ( self::$pending ) {
			return;
		}

		self::$pending = true;
		add_action( 'shutdown', array( self::class, 'at_shutdown' ) );
	}

	/**
	 * The end of a request that put images back outside a run.
	 */
	public static function at_shutdown(): void {
		remove_action( 'shutdown', array( self::class, 'at_shutdown' ) );

		if ( ! self::$pending ) {
			return;
		}

		self::$pending = false;
		self::purge( 'restore' );
	}

	/**
	 * Tells each cache plugin the site runs to empty itself, on the site this
	 * request is on.
	 *
	 * @param string $why `bulk` after a bulk run, `restore` after images were put back outside one, `uninstall` after the plugin deleted its copies.
	 */
	public static function purge( string $why ): void {
		/**
		 * Whether to empty the site's page caches after the plugin changed its images.
		 *
		 * @param bool   $purge Whether to purge. Default true.
		 * @param string $why   `bulk` after a bulk run, `restore` after images were put back outside one, `uninstall` after the plugin deleted its copies.
		 */
		if ( ! apply_filters( 'iminify_purge_caches', true, $why ) ) {
			return;
		}

		/* LiteSpeed Cache's own purge action; nothing listens to it when the plugin isn't active. */
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge action.

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		/* Cloudflare's plugin reads its list of purging actions when its own file loads, before this plugin's, so its hooks object is called instead. It purges only when its automatic cache management or APO is on. */
		$cloudflare = self::cloudflare();

		if ( null !== $cloudflare ) {
			call_user_func( $cloudflare );
		}
	}

	/**
	 * Cloudflare's plugin's purge of its whole zone, on its hooks object, or null
	 * when the plugin isn't active.
	 * Its file keeps it in `$cloudflareHooks`, which is a global only where
	 * WordPress loads plugins at the top level: WP-CLI loads them inside a method,
	 * so there the object is found through the `init` callback the plugin always
	 * registers on it.
	 */
	protected static function cloudflare(): ?callable {
		$global = $GLOBALS['cloudflareHooks'] ?? null;

		if ( is_object( $global ) && method_exists( $global, 'purgeCacheEverything' ) ) {
			return array( $global, 'purgeCacheEverything' );
		}

		$init = $GLOBALS['wp_filter']['init'] ?? null;

		foreach ( $init instanceof \WP_Hook ? $init->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && is_object( $function[0] ) && 'initAutomaticPlatformOptimization' === $function[1] && method_exists( $function[0], 'purgeCacheEverything' ) ) {
					return array( $function[0], 'purgeCacheEverything' );
				}
			}
		}

		return null;
	}
}
