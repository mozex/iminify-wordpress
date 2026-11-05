<?php
/**
 * The admin colour scheme's colour, for keyboard focus.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Scheme;
use WP_UnitTestCase;

/**
 * Covers the scheme colour the plugin's stylesheets get before WordPress 7.0,
 * and that from 7.0 core's own variable is left to do it.
 */
class SchemeTest extends WP_UnitTestCase {

	/**
	 * The WordPress version the test library runs.
	 *
	 * @var string
	 */
	protected $version;

	public function set_up(): void {
		parent::set_up();
		$this->version = $GLOBALS['wp_version'];
		wp_set_current_user( self::factory()->user->create( array( 'admin_color' => 'sunrise' ) ) );
		wp_register_style( 'iminify-scheme-test', false, array(), '1' );
	}

	public function tear_down(): void {
		$GLOBALS['wp_version'] = $this->version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what the test changed.
		wp_deregister_style( 'iminify-scheme-test' );
		parent::tear_down();
	}

	public function test_the_scheme_colour_is_printed_before_7_0_and_left_to_core_after(): void {
		$GLOBALS['wp_version'] = '6.9.1'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the version under test.
		Scheme::attach( 'iminify-scheme-test' );

		$this->assertSame( array( ':root{--iminify-scheme:#ad631e}' ), wp_styles()->get_data( 'iminify-scheme-test', 'after' ) );

		wp_deregister_style( 'iminify-scheme-test' );
		wp_register_style( 'iminify-scheme-test', false, array(), '1' );
		$GLOBALS['wp_version'] = '7.0'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the version under test.
		Scheme::attach( 'iminify-scheme-test' );

		$this->assertFalse( wp_styles()->get_data( 'iminify-scheme-test', 'after' ) );
	}
}
