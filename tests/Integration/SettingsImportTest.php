<?php
/**
 * "Use <plugin>'s settings": the readers' settings, the local copy and the two presses.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Settings_Copy;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers what each reader sends, cut to the keys Iminify's import reads (another
 * service's key or token never among them), an option never saved left out, the
 * networks' rules, the copy kept once the other plugin deleted its options, and
 * the routes: the preview sends the copy and changes nothing, the use sends the
 * proposal through `PATCH /site`, and another plugin's "off" never turns the
 * backups off.
 */
class SettingsImportTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	/**
	 * Values another service keeps beside the settings, which must never leave the site.
	 */
	const SECRETS = array( 'imagify-secret-key', 'optimole-secret-key', 'converter-secret-token', 'elementor-secret-token', 'ewww-secret-key', 'shortpixel-secret-key', 'tinypng-secret-key', 'decoy-value' );

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Others::forget();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		( new Connection() )->cancel();
		Others::forget();
		parent::tear_down();
	}

	public function test_each_reader_sends_its_settings_cut_to_the_keys_the_import_reads(): void {
		$this->other_plugins_settings();

		$expected = array(
			'imagify'        => array(
				'imagify_settings' => array(
					'optimization_level'  => 2,
					'resize_larger'       => 1,
					'resize_larger_w'     => 1920,
					'optimization_format' => 'webp',
					'disallowed-sizes'    => array( 'medium' => 1 ),
					'backup'              => 1,
				),
			),
			'ewww'           => array(
				'ewww_image_optimizer_jpg_level'           => '30',
				'ewww_image_optimizer_maxmediawidth'       => '2560',
				'ewww_image_optimizer_metadata_remove'     => '1',
				'ewww_image_optimizer_webp'                => '',
				'ewww_image_optimizer_disable_resizes_opt' => array( 'thumbnail' => 'true' ),
				'ewww_image_optimizer_backup_files'        => 'local',
			),
			'shortpixel'     => array(
				'spio_settings' => array(
					'compressionType' => 1,
					'exif'            => 0,
					'createWebp'      => 1,
					'excludeSizes'    => array( 'large' ),
					'backupImages'    => 1,
				),
			),
			'smush'          => array(
				'wp-smush-settings'     => array(
					'lossy'      => 2,
					'resize'     => true,
					'strip_exif' => true,
					'backup'     => false,
				),
				'wp-smush-resize_sizes' => array( 'width' => 2048 ),
				'wp-smush-image_sizes'  => array( 'thumbnail', 'medium' ),
			),
			'tinypng'        => array(
				'tinypng_sizes'           => array(
					0           => 'on',
					'thumbnail' => 'on',
				),
				'tinypng_resize_original' => array(
					'enabled' => 'on',
					'width'   => '2048',
				),
				'tinypng_preserve_data'   => '',
				'tinypng_convert_format'  => array(
					'convert'    => 'on',
					'convert_to' => 'smallest',
				),
			),
			'elementor'      => array(
				'image_optimizer_compression_level' => 'lossy',
				'image_optimizer_exif_metadata'     => '1',
				'image_optimizer_convert_to_format' => 'webp',
				'image_optimizer_custom_sizes'      => 'all',
			),
			'converter'      => array(
				'webpc_settings' => array(
					'features'       => array( 'keep_metadata' ),
					'output_formats' => array( 'webp', 'avif' ),
				),
			),
			'optimole'       => array(
				'optml_settings' => array(
					'autoquality'    => 'disabled',
					'quality'        => 55,
					'strip_metadata' => 'enabled',
					'avif'           => 'disabled',
				),
			),
			'modern-formats' => array(),
			'litespeed'      => array(
				'litespeed.conf.img_optm-ori'           => '1',
				'litespeed.conf.img_optm-lossless'      => '',
				'litespeed.conf.img_optm-webp'          => '2',
				'litespeed.conf.img_optm-sizes_skipped' => '{"1":"medium"}',
			),
		);

		foreach ( Others::readers() as $reader ) {
			$sent = $reader->settings();

			$this->assertSame( $expected[ $reader->slug() ], $sent, $reader->slug() );

			foreach ( self::SECRETS as $secret ) {
				$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $sent ), $reader->slug() . ' sent ' . $secret );
			}
		}
	}

	public function test_an_option_holding_a_value_where_the_settings_belong_is_left_out(): void {
		update_option( 'imagify_settings', 'a:1:{s:7:"api_key";s:18:"imagify-secret-key";}broken' );
		update_option( 'optml_settings', array( 'api_key' => 'optimole-secret-key' ) );

		$this->assertSame( array(), Others::reader( 'imagify' )->settings() );
		$this->assertSame( array(), Others::reader( 'optimole' )->settings(), 'An array option with none of the keys read is left out.' );
	}

	public function test_a_network_shortpixel_sends_only_what_the_network_saved_while_its_override_is_on(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		update_option(
			'spio_settings',
			array(
				'compressionType' => 2,
				'exif'            => 0,
				'resizeType'      => 'inner',
			)
		);
		update_site_option(
			'spio_wpmu',
			array(
				'compressionType'                   => 0,
				'network_settings_override_enabled' => false,
				'apiKey'                            => 'shortpixel-secret-key',
			)
		);

		$this->assertSame(
			array(
				'spio_settings' => array(
					'compressionType' => 2,
					'exif'            => 0,
					'resizeType'      => 'inner',
				),
			),
			Others::reader( 'shortpixel' )->settings()
		);

		update_site_option(
			'spio_wpmu',
			array(
				'compressionType'                   => 0,
				'network_settings_override_enabled' => true,
				'apiKey'                            => 'shortpixel-secret-key',
			)
		);

		$this->assertSame(
			array(
				'spio_settings' => array(
					'compressionType' => 0,
					'resizeType'      => 'inner',
				),
			),
			Others::reader( 'shortpixel' )->settings(),
			'A setting the network never saved reads as ShortPixel\'s default there, so the site\'s is never sent; only the resize mode, which has none, comes from the site.'
		);
	}

	public function test_litespeeds_install_defaults_are_offered_only_once_it_optimized_images(): void {
		update_option( 'litespeed.conf.img_optm-ori', '1' );
		update_option( 'litespeed.conf.img_optm-exif', '1' );
		update_option( 'litespeed.conf.img_optm-auto', '' );
		Settings_Copy::take_all();

		$this->assertNull( Settings_Copy::options( 'litespeed' ), 'Kept for its cache, its image defaults were never chosen.' );

		update_option( 'litespeed.conf.img_optm-auto', '1' );
		Settings_Copy::take_all();

		$this->assertSame( '1', Settings_Copy::options( 'litespeed' )['litespeed.conf.img_optm-exif'] );
	}

	public function test_a_network_litespeed_using_the_primary_sites_configuration_reads_the_main_sites_image_settings(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		$site = (int) self::factory()->blog->create( array( 'path' => '/litespeed-' . wp_generate_password( 8, false ) . '/' ) );

		update_option( 'litespeed.conf.img_optm-ori', '1' );
		update_option( 'litespeed.conf.img_optm-lossless', '1' );
		update_option( 'litespeed.conf.img_optm-exif', '' );
		update_option( 'litespeed.conf.img_optm-auto', '1' );
		update_site_option( 'litespeed.conf.img_optm-webp', '2' );
		update_site_option( 'active_sitewide_plugins', array( 'litespeed-cache/litespeed-cache.php' => time() ) );

		switch_to_blog( $site );

		try {
			update_option( 'litespeed.conf.img_optm-ori', '' );
			update_option( 'litespeed.conf.img_optm-lossless', '' );
			update_option( 'litespeed.conf.img_optm-exif', '1' );
			update_option( 'litespeed.conf.img_optm-webp', '0' );

			/* Its own, while the network leaves each site its settings: no records and no "Auto Request Cron" of its own. */
			$this->assertFalse( Others::reader( 'litespeed' )->settings_chosen() );
			$this->assertSame(
				array(
					'litespeed.conf.img_optm-ori'      => '',
					'litespeed.conf.img_optm-lossless' => '',
					'litespeed.conf.img_optm-exif'     => '1',
					'litespeed.conf.img_optm-webp'     => '2',
				),
				Others::reader( 'litespeed' )->settings()
			);

			update_site_option( 'litespeed.conf.use_primary_settings', '1' );

			/* The main site's, its WebP the network's: what LiteSpeed itself runs this site on. */
			$this->assertTrue( Others::reader( 'litespeed' )->settings_chosen(), 'The main site\'s "Auto Request Cron" is this site\'s.' );
			$this->assertSame(
				array(
					'litespeed.conf.img_optm-ori'      => '1',
					'litespeed.conf.img_optm-lossless' => '1',
					'litespeed.conf.img_optm-exif'     => '',
					'litespeed.conf.img_optm-webp'     => '2',
				),
				Others::reader( 'litespeed' )->settings()
			);
			$this->assertSame( $site, get_current_blog_id(), 'The read leaves the site it ran on.' );

			/* Turned off for the network, which keeps its settings: still what this site last ran on, never its own install defaults. */
			update_site_option( 'litespeed.conf._version', '7.9.3' );
			update_site_option( 'active_sitewide_plugins', array() );

			$this->assertSame( '1', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-lossless'] );
			$this->assertSame( '2', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-webp'] );

			/* Uninstalled, which deletes the network's settings; or active on this site alone: its own. */
			delete_site_option( 'litespeed.conf._version' );

			$this->assertSame( '', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-lossless'] );
			$this->assertSame( '0', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-webp'] );

			update_site_option( 'litespeed.conf._version', '7.9.3' );
			update_option( 'active_plugins', array( 'litespeed-cache/litespeed-cache.php' ) );

			$this->assertSame( '', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-lossless'] );
			$this->assertSame( '0', Others::reader( 'litespeed' )->settings()['litespeed.conf.img_optm-webp'] );
		} finally {
			restore_current_blog();
		}
	}

	public function test_a_network_smush_reads_the_networks_settings_unless_it_hands_its_bulk_settings_down(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		update_site_option(
			'wp-smush-settings',
			array(
				'lossy'  => 1,
				'backup' => true,
			)
		);
		update_site_option( 'wp-smush-image_sizes', array( 'thumbnail' ) );
		update_option( 'wp-smush-settings', array( 'lossy' => 2 ) );
		update_option( 'wp-smush-image_sizes', array( 'large' ) );
		update_site_option( 'wp-smush-networkwide', array( 'cdn' ) );

		$this->assertSame(
			array(
				'wp-smush-settings'    => array(
					'lossy'  => 1,
					'backup' => true,
				),
				'wp-smush-image_sizes' => array( 'thumbnail' ),
			),
			Others::reader( 'smush' )->settings()
		);

		update_site_option( 'wp-smush-networkwide', array( 'bulk' ) );

		$this->assertSame(
			array(
				'wp-smush-settings'    => array(
					'lossy'  => 2,
					'backup' => true,
				),
				'wp-smush-image_sizes' => array( 'large' ),
			),
			Others::reader( 'smush' )->settings()
		);
	}

	public function test_a_network_active_ewww_reads_the_networks_settings_but_the_sizes_it_skips_and_an_override(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		update_option( 'ewww_image_optimizer_jpg_level', '10' );
		update_option( 'ewww_image_optimizer_disable_resizes_opt', array( 'thumbnail' => 'true' ) );
		update_site_option( 'ewww_image_optimizer_jpg_level', '30' );
		update_site_option( 'ewww_image_optimizer_disable_resizes_opt', array( 'large' => 'true' ) );
		update_site_option( 'active_sitewide_plugins', array( 'ewww-image-optimizer/ewww-image-optimizer.php' => time() ) );

		$sent = Others::reader( 'ewww' )->settings();

		$this->assertSame( '30', $sent['ewww_image_optimizer_jpg_level'] );
		$this->assertSame( array( 'thumbnail' => 'true' ), $sent['ewww_image_optimizer_disable_resizes_opt'] );

		update_site_option( 'ewww_image_optimizer_allow_multisite_override', true );

		$this->assertSame( '10', Others::reader( 'ewww' )->settings()['ewww_image_optimizer_jpg_level'] );
	}

	public function test_the_copy_taken_before_another_plugin_deletes_its_options_is_still_offered(): void {
		update_option( 'perflab_modern_image_format', 'avif' );

		do_action( 'pre_uninstall_plugin', 'webp-uploads/load.php', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's action, fired as WordPress fires it before another plugin's uninstall.
		delete_option( 'perflab_modern_image_format' );
		Settings_Copy::take_all();

		$this->assertSame( array( 'perflab_modern_image_format' => 'avif' ), Settings_Copy::options( 'modern-formats' ) );
		$this->assertSame(
			array(
				array(
					'plugin' => 'modern-formats',
					'name'   => 'Modern Image Formats',
				),
			),
			Settings_Copy::offers()
		);
		$this->assertFalse( wp_load_alloptions()[ Settings_Copy::OPTION ] ?? false, 'The copy is kept out of the options every request loads.' );

		update_option( 'perflab_modern_image_format', 'webp' );
		Settings_Copy::take_all();

		$this->assertSame( array( 'perflab_modern_image_format' => 'webp' ), Settings_Copy::options( 'modern-formats' ), 'The copy follows the plugin\'s settings while they are there.' );
	}

	public function test_the_preview_sends_the_copy_and_the_sizes_and_changes_nothing(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'site/import', 200, Api_Stub::fixture( 'site-import' ) );
		update_option( 'ewww_image_optimizer_jpg_level', '20' );
		update_option( 'ewww_image_optimizer_cloud_key', 'ewww-secret-key' );
		Local_Settings::update( array( 'backups' => false ) );

		$this->assertSame( array( 'ewww' ), array_column( $this->call( 'GET', '/state' )->get_data()['imports'], 'plugin' ) );

		$state = $this->call( 'POST', '/import', array( 'plugin' => 'ewww' ) )->get_data();
		$sent  = json_decode( $this->api->calls( 'POST', 'site/import' )[0]['args']['body'], true );

		$this->assertSame( 'ewww', $sent['plugin'] );
		$this->assertSame( array( 'ewww_image_optimizer_jpg_level' => '20' ), $sent['settings'] );
		$this->assertContains( 'medium_large', $sent['sizes'] );
		$this->assertStringNotContainsString( 'ewww-secret-key', $this->api->calls( 'POST', 'site/import' )[0]['args']['body'] );
		$this->assertSame( array(), $this->api->calls( 'PATCH', 'site' ) );
		$this->assertSame( 'EWWW Image Optimizer', $state['import']['name'] );
		$this->assertSame(
			array(
				'label'   => 'Compression level',
				'now'     => 'Smart',
				'then'    => 'Lossless',
				'changes' => true,
			),
			$state['import']['rows'][0]
		);
		$this->assertSame(
			array(
				'label'   => 'Make WebP copies',
				'now'     => 'On',
				'then'    => 'On',
				'changes' => false,
			),
			$state['import']['rows'][1]
		);
		$sizes = array_values( wp_list_filter( $state['import']['rows'], array( 'label' => 'Sizes to optimize' ) ) );
		$this->assertSame( 'Thumbnail, Medium', $sizes[0]['then'], 'The sizes read as the editor names them, not by their code names.' );
		$this->assertFalse( Local_Settings::get()['backups'] );
	}

	public function test_using_the_proposal_sends_it_as_it_is_and_another_plugins_off_never_turns_the_backups_off(): void {
		( new Key_Store() )->save( self::KEY );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'PATCH', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'site/import', 200, Api_Stub::fixture( 'site-import' ) );
		update_option( 'ewww_image_optimizer_backup_files', '' );

		$this->assertSame( 409, $this->call( 'POST', '/import/use', array( 'plugin' => 'ewww' ) )->get_status(), 'Nothing to use before Iminify was asked.' );

		$this->call( 'POST', '/import', array( 'plugin' => 'ewww' ) );
		$state = $this->call( 'POST', '/import/use', array( 'plugin' => 'ewww' ) )->get_data();

		$this->assertSame(
			Api_Stub::fixture( 'site-import' )['data']['settings'],
			json_decode( $this->api->calls( 'PATCH', 'site' )[0]['args']['body'], true )
		);
		$this->assertTrue( Local_Settings::get()['backups'], 'The answer\'s false leaves the backups on.' );
		$this->assertSame( 'EWWW Image Optimizer', $state['used'] );
		$this->assertSame( array(), $state['imports'], 'An offer used is no longer made.' );

		$import                    = Api_Stub::fixture( 'site-import' );
		$import['data']['backups'] = true;
		$this->api->on( 'POST', 'site/import', 200, $import );
		Local_Settings::update( array( 'backups' => false ) );
		update_option( 'ewww_image_optimizer_backup_files', 'local' );
		Settings_Copy::take_all();

		$this->call( 'POST', '/import', array( 'plugin' => 'ewww' ) );
		$this->call( 'POST', '/import/use', array( 'plugin' => 'ewww' ) );

		$this->assertTrue( Local_Settings::get()['backups'], 'That plugin\'s backups turn them on.' );

		$this->call( 'POST', '/import', array( 'plugin' => 'ewww' ) );
		update_option( 'ewww_image_optimizer_jpg_level', '30' );
		$patches = count( $this->api->calls( 'PATCH', 'site' ) );

		$this->assertSame( 409, $this->call( 'POST', '/import/use', array( 'plugin' => 'ewww' ) )->get_status(), 'Settings changed since they were shown are shown again first.' );
		$this->assertCount( $patches, $this->api->calls( 'PATCH', 'site' ) );
	}

	public function test_an_offer_turned_down_or_out_of_reach_changes_nothing(): void {
		update_option( 'perflab_modern_image_format', 'avif' );

		$this->assertSame( 409, $this->call( 'POST', '/import', array( 'plugin' => 'modern-formats' ) )->get_status(), 'A site that isn\'t connected asks nothing.' );
		$this->assertSame( 404, $this->call( 'POST', '/import', array( 'plugin' => 'imagify' ) )->get_status(), 'No settings of that plugin are here.' );
		$this->assertSame( 404, $this->call( 'POST', '/import', array( 'plugin' => 'unknown' ) )->get_status() );
		$this->assertSame( array(), $this->api->requests );

		$state = $this->call( 'DELETE', '/import', array( 'plugin' => 'modern-formats' ) )->get_data();

		$this->assertSame( array(), $state['imports'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( array( 'POST', '/import' ), array( 'POST', '/import/use' ), array( 'DELETE', '/import' ) ) as $route ) {
			$this->assertSame( 403, $this->call( $route[0], $route[1], array( 'plugin' => 'modern-formats' ) )->get_status() );
		}
	}

	/**
	 * Every other plugin's settings as each keeps them, with what it keeps beside them.
	 */
	protected function other_plugins_settings(): void {
		update_option(
			'imagify_settings',
			array(
				'api_key'             => 'imagify-secret-key',
				'optimization_level'  => 2,
				'resize_larger'       => 1,
				'resize_larger_w'     => 1920,
				'optimization_format' => 'webp',
				'disallowed-sizes'    => array( 'medium' => 1 ),
				'backup'              => 1,
				'auto_optimize'       => 1,
			)
		);
		update_option( 'ewww_image_optimizer_cloud_key', 'ewww-secret-key' );
		update_option( 'ewww_image_optimizer_jpg_level', '30' );
		update_option( 'ewww_image_optimizer_maxmediawidth', '2560' );
		update_option( 'ewww_image_optimizer_metadata_remove', '1' );
		update_option( 'ewww_image_optimizer_webp', '' );
		update_option( 'ewww_image_optimizer_disable_resizes_opt', array( 'thumbnail' => 'true' ) );
		update_option( 'ewww_image_optimizer_backup_files', 'local' );
		update_option( 'spio_key', array( 'apiKey' => 'shortpixel-secret-key' ) );
		update_option(
			'spio_settings',
			array(
				'compressionType'  => 1,
				'exif'             => 0,
				'createWebp'       => 1,
				'excludeSizes'     => array( 'large' ),
				'backupImages'     => 1,
				'autoMediaLibrary' => 1,
				'apiKey'           => 'shortpixel-secret-key',
			)
		);
		update_option(
			'wp-smush-settings',
			array(
				'lossy'      => 2,
				'resize'     => true,
				'strip_exif' => true,
				'backup'     => false,
				'auto'       => true,
			)
		);
		update_option(
			'wp-smush-resize_sizes',
			array(
				'width'  => 2048,
				'height' => 2048,
			)
		);
		update_option( 'wp-smush-image_sizes', array( 'thumbnail', 'medium' ) );
		if ( is_multisite() ) {
			/* A network hands its bulk settings down to every site, so these are the site's own. */
			update_site_option( 'wp-smush-networkwide', 1 );
		}
		update_option( 'tinypng_api_key', 'tinypng-secret-key' );
		update_option(
			'tinypng_sizes',
			array(
				0           => 'on',
				'thumbnail' => 'on',
			)
		);
		update_option(
			'tinypng_resize_original',
			array(
				'enabled' => 'on',
				'width'   => '2048',
				'height'  => '2048',
			)
		);
		update_option( 'tinypng_preserve_data', '' );
		update_option(
			'tinypng_convert_format',
			array(
				'convert'    => 'on',
				'convert_to' => 'smallest',
			)
		);
		update_option( 'image_optimizer_access_token', 'elementor-secret-token' );
		update_option( 'image_optimizer_connect_data', array( 'access_token' => 'elementor-secret-token' ) );
		update_option( 'image_optimizer_compression_level', 'lossy' );
		update_option( 'image_optimizer_exif_metadata', '1' );
		update_option( 'image_optimizer_convert_to_format', 'webp' );
		update_option( 'image_optimizer_custom_sizes', 'all' );
		$converter = array(
			'access_token'   => 'converter-secret-token',
			'features'       => array( 'keep_metadata' ),
			'output_formats' => array( 'webp', 'avif' ),
			'quality'        => 85,
		);
		if ( is_multisite() ) {
			update_site_option( 'webpc_settings', $converter );
		} else {
			update_option( 'webpc_settings', $converter );
		}
		update_option(
			'optml_settings',
			array(
				'api_key'        => 'optimole-secret-key',
				'autoquality'    => 'disabled',
				'quality'        => 55,
				'strip_metadata' => 'enabled',
				'avif'           => 'disabled',
				'cdn'            => 'decoy-value',
			)
		);
		update_option( 'litespeed.conf.img_optm-ori', '1' );
		update_option( 'litespeed.conf.img_optm-lossless', '' );
		update_option( 'litespeed.conf.img_optm-webp', '2' );
		update_option( 'litespeed.conf.img_optm-sizes_skipped', '{"1":"medium"}' );
		update_option( 'litespeed.conf.img_optm-jpg_quality', '82' );
	}

	/**
	 * Sends a request to one of the plugin's routes.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The path under the namespace.
	 * @param array<string, mixed> $body   The JSON body.
	 */
	protected function call( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/iminify/v1' . $path );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
