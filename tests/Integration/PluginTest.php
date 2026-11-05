<?php
/**
 * What the plugin brings with it when WordPress loads it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Privacy;
use Iminify\WordPress\Plugin;
use WP_Privacy_Policy_Content;
use WP_UnitTestCase;

/**
 * Covers the bundled queue, the unconnected start and the privacy policy's text.
 */
class PluginTest extends WP_UnitTestCase {

	public function test_action_scheduler_loads_with_the_plugin(): void {
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
		$this->assertTrue( class_exists( 'ActionScheduler' ) );
	}

	public function test_a_site_without_a_key_has_no_client(): void {
		$this->assertNull( Plugin::client() );
	}

	public function test_the_privacy_policy_guide_offers_the_plugins_text(): void {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		/* Core takes the text only in wp-admin, from admin_init on. */
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_actions']['admin_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test case puts the hooks back after each test.

		Privacy::register();
		set_current_screen( 'front' );

		$texts = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );

		$this->assertArrayHasKey( 'Iminify Image Optimizer', $texts );
		$this->assertStringContainsString( 'https://www.iminify.com/privacy-policy', $texts['Iminify Image Optimizer'] );
		$this->assertStringContainsString( 'Nothing about the people who visit this site', $texts['Iminify Image Optimizer'] );
	}
}
