<?php
/**
 * Plugins that remake or replace an image's files, and sites behind a password.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Compat\Woocommerce;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Loopback;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers Regenerate Thumbnails' and Enable Media Replace's calls into core, as
 * each plugin makes them, the credentials a site behind HTTP basic auth needs
 * on the requests its background queue makes to itself, and the script that
 * keeps WooCommerce's variation images working inside `<picture>`.
 */
class CompatibilityTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . Api_Stub::fixture( 'group-open' )['data']['id'] . '/source', 202, Api_Stub::fixture( 'group-open' ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		Plugin::keys()->forget();
		Site_Record::forget();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_a_regeneration_from_the_plugins_output_goes_again_from_the_backup_naming_no_bytes_for_the_remade_sizes(): void {
		$id       = $this->optimized();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );

		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		/* Regenerate Thumbnails: the sizes made again from the file WordPress keeps, here the plugin's output, then the metadata saved. */
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, (string) wp_get_original_image_path( $id ) );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$declared = array_column( json_decode( $this->api->calls( 'POST', 'groups' )[1]['args']['body'], true )['outputs'], null, 'name' );
		$upload   = $this->api->calls( 'POST', 'groups/' . Api_Stub::fixture( 'group-open' )['data']['id'] . '/source' )[1]['args'];

		$this->assertSame( Paths::backup( $relative ), $upload[ Upload::ARGUMENT ]['path'] );
		$this->assertSame( (int) filesize( $attached ), $declared['full']['size'] );
		$this->assertArrayHasKey( 'medium', $declared );
		$this->assertArrayHasKey( 'thumbnail', $declared );
		$this->assertArrayNotHasKey( 'size', $declared['medium'] );
		$this->assertArrayNotHasKey( 'size', $declared['thumbnail'] );
	}

	public function test_a_regeneration_that_skips_every_existing_size_sends_nothing(): void {
		$id       = $this->optimized();
		$attached = (string) get_attached_file( $id );

		/* Regenerate Thumbnails' default: core is asked for no size that exists, and the metadata generated anyway. */
		require_once ABSPATH . 'wp-admin/includes/image.php';
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		wp_generate_attachment_metadata( $id, $attached );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );

		/* A regeneration that makes the sizes again from the plugin's output changes them, and the image goes. */
		wp_generate_attachment_metadata( $id, $attached );

		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
	}

	public function test_a_file_enable_media_replace_puts_under_the_same_name_is_sent_as_the_new_original(): void {
		$id       = $this->optimized();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );
		$first    = (string) file_get_contents( Paths::backup( $relative ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.

		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		/* Enable Media Replace keeping the name: the new file copied over, its metadata made and saved. */
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $attached );
		update_attached_file( $id, $attached );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $attached ) );

		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$declared = array_column( json_decode( $this->api->calls( 'POST', 'groups' )[1]['args']['body'], true )['outputs'], null, 'name' );
		$upload   = $this->api->calls( 'POST', 'groups/' . Api_Stub::fixture( 'group-open' )['data']['id'] . '/source' )[1]['args'];
		$aside    = array_values( array_diff( Records::get( $id )['backups'], array( Paths::BACKUP_FOLDER . '/' . $relative ) ) );

		$this->assertSame( $attached, $upload[ Upload::ARGUMENT ]['path'] );
		$this->assertArrayHasKey( 'size', $declared['medium'] );
		$this->assertSame( (string) file_get_contents( $attached ), file_get_contents( Paths::backup( $relative ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the new backup.
		$this->assertCount( 1, $aside );
		$this->assertSame( $first, file_get_contents( Paths::absolute( $aside[0] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the old backup, kept aside.
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- runs WP-Cron's and Action Scheduler's own filters, as they run them.
	public function test_woocommerces_variation_script_carries_the_watch_on_its_product_image_only_while_copies_are_served(): void {
		wp_register_script(
			Woocommerce::HANDLE,
			'https://example.org/add-to-cart-variation.js',
			array(),
			'1',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		try {
			Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_OFF ) );
			Woocommerce::attach();

			$this->assertFalse( wp_scripts()->get_data( Woocommerce::HANDLE, 'before' ) );

			Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
			Woocommerce::attach();

			/* WordPress keeps a handle's first inline script after a `false`, which it filters out when it prints them. */
			$this->assertSame( array( Woocommerce::SCRIPT ), array_values( array_filter( (array) wp_scripts()->get_data( Woocommerce::HANDLE, 'before' ) ) ) );

			/* WooCommerce's script, enqueued as WooCommerce does on a variable product, keeps its `defer`: an inline script after it would take it off. */
			wp_enqueue_script( Woocommerce::HANDLE );
			ob_start();
			wp_scripts()->do_item( Woocommerce::HANDLE );
			$printed = (string) ob_get_clean();

			$this->assertMatchesRegularExpression( '#<script(?=[^>]*\ssrc="https://example\.org/add-to-cart-variation\.js)(?=[^>]*\sdefer[\s>])[^>]*>#', $printed );
		} finally {
			wp_deregister_script( Woocommerce::HANDLE );
			Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		}
	}

	public function test_the_background_queues_requests_to_the_site_itself_carry_its_basic_auth_password(): void {
		$cron = array(
			'url'  => site_url( 'wp-cron.php?doing_wp_cron=1' ),
			'key'  => '1',
			'args' => array( 'timeout' => 0.01 ),
		);

		/* No password asked of this request, and none set: nothing is added. */
		$this->assertNull( Loopback::authorization() );
		$this->assertSame( $cron, apply_filters( 'cron_request', $cron, 0 ) );

		$_SERVER['PHP_AUTH_USER'] = 'staging';
		$_SERVER['PHP_AUTH_PW']   = 'p4ss:word';
		$expected                 = 'Basic ' . base64_encode( 'staging:p4ss:word' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- basic auth's encoding.

		$this->assertSame( $expected, apply_filters( 'cron_request', $cron, 0 )['args']['headers']['Authorization'] );
		$this->assertSame( $expected, apply_filters( 'as_async_request_queue_runner_post_args', array( 'body' => array() ) )['headers']['Authorization'] );

		/* Another host never gets them, and a request with its own credentials keeps them. */
		$elsewhere        = $cron;
		$elsewhere['url'] = 'https://example.org/wp-cron.php';
		$this->assertSame( $elsewhere, apply_filters( 'cron_request', $elsewhere, 0 ) );
		$this->assertSame( 'Bearer x', apply_filters( 'as_async_request_queue_runner_post_args', array( 'headers' => array( 'Authorization' => 'Bearer x' ) ) )['headers']['Authorization'] );
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	/**
	 * An attachment the plugin optimized: its backup kept and its files written over
	 * with smaller ones, as the record says.
	 */
	protected function optimized(): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$attached = (string) get_attached_file( $id );
		$metadata = (array) wp_get_attachment_metadata( $id );
		$files    = array( $attached );
		$written  = array();

		foreach ( $metadata['sizes'] as $size ) {
			$files[] = dirname( $attached ) . '/' . $size['file'];
		}

		foreach ( $files as $file ) {
			$editor = wp_get_image_editor( $file );
			$editor->set_quality( 30 );
			$editor->save( $file );
			clearstatcache( true, $file );
			$written[ (string) Paths::relative( $file ) ] = (int) filesize( $file );
		}

		$outputs = array( 'full' => array( 'after' => $written[ (string) Paths::relative( $attached ) ] ) );

		foreach ( $metadata['sizes'] as $name => $size ) {
			$outputs[ $name ] = array( 'after' => $written[ (string) Paths::relative( dirname( $attached ) . '/' . $size['file'] ) ] );
		}

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'group'   => null,
				'written' => $written,
				'result'  => array( 'outputs' => $outputs ),
			)
		);

		/* The upload's own run is spent, so a run found later was queued by what the test does. */
		Optimizer::cancel( $id );

		return $id;
	}
}
