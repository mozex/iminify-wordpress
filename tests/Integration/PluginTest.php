<?php
/**
 * What the plugin brings with it when WordPress loads it.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Plugin;
use WP_UnitTestCase;

/**
 * Covers the bundled queue and the unconnected start.
 */
class PluginTest extends WP_UnitTestCase {

	public function test_action_scheduler_loads_with_the_plugin(): void {
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
		$this->assertTrue( class_exists( 'ActionScheduler' ) );
	}

	public function test_a_site_without_a_key_has_no_client(): void {
		$this->assertNull( Plugin::client() );
	}
}
