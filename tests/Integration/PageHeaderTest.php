<?php
/**
 * The header of the plugin's own pages.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Page_Header;
use Iminify\WordPress\Plugin;
use WP_UnitTestCase;

/**
 * Covers the connection the header prints before the page's script runs, and
 * the marker core moves other plugins' notices below.
 */
class PageHeaderTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Plugin::keys()->forget();
		parent::tear_down();
	}

	public function test_the_header_says_whether_the_site_is_connected_and_ends_with_the_notices_marker(): void {
		$this->assertStringContainsString( 'data-state="off"', $this->header() );
		$this->assertStringContainsString( 'Not connected', $this->header() );

		Plugin::keys()->save( '7|site-key' );
		$html = $this->header();

		$this->assertStringContainsString( 'data-state="on"', $html );
		$this->assertStringContainsString( '<h1>Bulk Optimization</h1>', $html );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
		$this->assertStringNotContainsString( '7|site-key', $html );
	}

	/**
	 * The header the bulk page prints.
	 */
	protected function header(): string {
		ob_start();
		Page_Header::open( 'Bulk Optimization', 'Every image already in your media library, in one run.' );
		Page_Header::close();

		return (string) ob_get_clean();
	}
}
