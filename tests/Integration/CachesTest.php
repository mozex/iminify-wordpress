<?php
/**
 * Emptying the page caches after the site's images changed.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Cache_Plugins.php';

/**
 * Covers each cache plugin's purge at a bulk run's end, the purge a restore
 * outside a run owes the end of its request (a file's in another folder too, and
 * an update that replaced a chosen folder), the one a run owes its end or its
 * stop, and the filter that turns them off.
 */
class CachesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		/* A test before this one may have restored an image outside a run, leaving a purge owed to a request that never ends here. */
		Caches::at_shutdown();
		$GLOBALS['iminify_test_purged'] = array();
		$GLOBALS['cloudflareHooks']     = new class() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Cloudflare's plugin's own global.
			/**
			 * Cloudflare's plugin's purge of the whole zone.
			 */
			public function purgeCacheEverything(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Cloudflare's plugin's own name.
				$GLOBALS['iminify_test_purged'][] = 'cloudflare';
			}

			/**
			 * The callback Cloudflare's plugin always registers on `init`.
			 */
			public function initAutomaticPlatformOptimization(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Cloudflare's plugin's own name.
			}
		};
		add_action( 'litespeed_purge_all', array( $this, 'litespeed' ) );
		delete_option( Bulk::OPTION );
		delete_option( Caches::OWED );
	}

	public function tear_down(): void {
		remove_action( 'litespeed_purge_all', array( $this, 'litespeed' ) );
		unset( $GLOBALS['cloudflareHooks'] );
		Caches::at_shutdown();
		delete_option( Bulk::OPTION );
		delete_option( Caches::OWED );
		parent::tear_down();
	}

	/**
	 * LiteSpeed Cache's purge, which listens to its own action.
	 */
	public function litespeed(): void {
		$GLOBALS['iminify_test_purged'][] = 'litespeed';
	}

	public function test_a_bulk_runs_end_empties_each_cache_once_unless_the_site_turns_it_off(): void {
		do_action( 'iminify_bulk_finished', array( 'action' => Bulk::OPTIMIZE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );

		$GLOBALS['iminify_test_purged'] = array();
		$asked                          = array();
		$off                            = static function ( $purge, $why ) use ( &$asked ) {
			$asked[] = $why;

			return false;
		};
		add_filter( 'iminify_purge_caches', $off, 10, 2 );
		do_action( 'iminify_bulk_finished', array( 'action' => Bulk::RESTORE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.
		remove_filter( 'iminify_purge_caches', $off, 10 );

		$this->assertSame( array(), $GLOBALS['iminify_test_purged'] );
		$this->assertSame( array( 'bulk' ), $asked );
	}

	public function test_images_put_back_outside_a_run_empty_the_caches_once_at_the_end_of_the_request(): void {
		do_action( 'iminify_image_restored', 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.
		do_action( 'iminify_image_restored', 11 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.
		do_action( 'iminify_copies_deleted', 12 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertSame( array(), $GLOBALS['iminify_test_purged'], 'Nothing before the request ends.' );
		$this->assertSame( 10, has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
		$this->assertFalse( get_option( Caches::OWED ) );

		Caches::at_shutdown();

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );
		$this->assertFalse( has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
	}

	public function test_a_file_put_back_in_another_folder_empties_the_caches_at_the_end_of_its_request_or_of_a_folders_restore_run(): void {
		do_action( 'iminify_file_restored', 'themes/theme/photo.jpg' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertSame( 10, has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );

		Caches::at_shutdown();

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );

		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::RESTORE,
				'scope'  => Bulk::FOLDERS,
				'status' => Bulk::RUNNING,
			)
		);
		do_action( 'iminify_file_restored', 'themes/theme/photo.jpg' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertFalse( has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
		$this->assertSame( '1', (string) get_option( Caches::OWED ) );
	}

	public function test_an_update_that_replaced_a_chosen_folder_empties_the_caches_and_any_other_update_leaves_them(): void {
		update_option( Folders::OPTION, array( 'themes/twentytwentyfive/assets', 'plugins/kept' ) );

		try {
			foreach (
				array(
					'another theme'  => array(
						'type'   => 'theme',
						'themes' => array( 'twentytwentyfour' ),
					),
					'another plugin' => array(
						'type'    => 'plugin',
						'plugins' => array( 'other/other.php', 'hello.php' ),
					),
					'the theme'      => array(
						'type'   => 'theme',
						'themes' => array( 'twentytwentyfive' ),
					),
					'the plugin'     => array(
						'type'   => 'plugin',
						'plugin' => 'kept/kept.php',
					),
				) as $case => $options
			) {
				Caches::at_shutdown();
				/* Called as `upgrader_process_complete` calls it, without core's other listeners, which need the upgrader classes loaded. */
				Scanner::after_update( null, array_merge( array( 'action' => 'update' ), $options ) );

				$this->assertSame( 0 === strpos( $case, 'the ' ) ? 10 : false, has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ), $case );
			}
		} finally {
			delete_option( Folders::OPTION );
			delete_option( Scanner::OPTION );
			as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		}
	}

	public function test_images_put_back_during_a_run_empty_the_caches_at_its_stop_and_a_stop_owing_nothing_leaves_them(): void {
		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::RESTORE,
				'status' => Bulk::RUNNING,
			)
		);

		$stops = array();
		$heard = static function ( $run ) use ( &$stops ) {
			$stops[] = $run['status'];
		};
		add_action( 'iminify_bulk_stopped', $heard );

		do_action( 'iminify_image_restored', 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertFalse( has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
		$this->assertSame( '1', (string) get_option( Caches::OWED ) );

		Bulk::stop();

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );
		$this->assertFalse( get_option( Caches::OWED ) );

		/* A stopped optimize run put nothing back, so the caches stay. */
		$GLOBALS['iminify_test_purged'] = array();
		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::OPTIMIZE,
				'status' => Bulk::PAUSED,
			)
		);
		Bulk::stop();
		remove_action( 'iminify_bulk_stopped', $heard );

		$this->assertSame( array( Bulk::STOPPED, Bulk::STOPPED ), $stops );
		$this->assertSame( array(), $GLOBALS['iminify_test_purged'] );
	}

	public function test_pausing_a_restore_run_empties_the_caches_it_owes_and_a_restore_while_paused_waits_for_nothing(): void {
		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::RESTORE,
				'status' => Bulk::RUNNING,
			)
		);

		do_action( 'iminify_image_restored', 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.
		Bulk::pause();

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );
		$this->assertFalse( get_option( Caches::OWED ) );

		do_action( 'iminify_copies_deleted', 11 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertFalse( get_option( Caches::OWED ) );
		$this->assertSame( 10, has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
	}

	public function test_a_restore_during_a_run_that_restores_nothing_empties_the_caches_at_the_end_of_its_request(): void {
		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::OPTIMIZE,
				'status' => Bulk::RUNNING,
			)
		);

		/* An optimize run waiting out a spent day can go on for days. */
		do_action( 'iminify_image_restored', 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own action.

		$this->assertFalse( get_option( Caches::OWED ) );
		$this->assertSame( 10, has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ) );
	}

	public function test_cloudflares_plugin_is_found_by_its_init_callback_where_its_object_isnt_a_global(): void {
		/* WP-CLI loads plugins inside a method, so the plugin's `$cloudflareHooks` stays local there. */
		$hooks = $GLOBALS['cloudflareHooks'];
		unset( $GLOBALS['cloudflareHooks'] );

		$this->assertFalse( has_action( 'init', array( $hooks, 'initAutomaticPlatformOptimization' ) ) );

		Caches::purge( 'bulk' );

		$this->assertNotContains( 'cloudflare', $GLOBALS['iminify_test_purged'] );

		add_action( 'init', array( $hooks, 'initAutomaticPlatformOptimization' ) );
		$GLOBALS['iminify_test_purged'] = array();
		Caches::purge( 'bulk' );

		$this->assertSame( array( 'litespeed', 'wp-rocket', 'w3tc', 'cloudflare' ), $GLOBALS['iminify_test_purged'] );
	}
}
