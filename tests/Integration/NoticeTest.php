<?php
/**
 * The plugin's one notice outside its own page, and the service's messages.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Notice;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers where and for whom the notice shows, what it says, that a dismissal
 * holds until it says something new, that it never calls the service, and
 * the language of the service's messages.
 */
class NoticeTest extends WP_UnitTestCase {

	/**
	 * The stand-in API, which no notice may call.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$this->assertSame( array(), $this->api->requests, 'The notice called the service.' );
		$this->api->stop();
		( new Connection() )->cancel();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_a_site_that_isnt_connected_is_asked_to_connect_on_the_dashboard_plugins_and_media_screens(): void {
		foreach ( Notice::SCREENS as $screen ) {
			set_current_screen( $screen );

			$this->assertSame( 'connect', Notice::current()['key'] ?? null, $screen );
		}

		ob_start();
		Notice::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'data-notice="connect"', $html );
		$this->assertStringContainsString( 'iminify-notice__mark', $html );
		$this->assertStringContainsString( 'options-general.php?page=iminify-image-optimizer', $html );
	}

	public function test_no_notice_elsewhere_for_people_who_cant_act_on_it_or_while_connecting(): void {
		set_current_screen( 'edit-post' );
		$this->assertNull( Notice::current() );

		set_current_screen( 'settings_page_iminify-image-optimizer' );
		$this->assertNull( Notice::current() );

		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNull( Notice::current() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			Connection::PAIRING_OPTION,
			array(
				'id'          => 'waiting',
				'secret'      => 'sealed',
				'connect_url' => 'https://www.iminify.com/connect/wordpress/waiting',
				'expires_at'  => time() + 600,
			),
			false
		);
		$this->assertNull( Notice::current() );
	}

	public function test_a_dismissed_notice_stays_dismissed_until_it_says_something_new(): void {
		Notice::dismiss( 'connect' );
		$this->assertNull( Notice::current() );

		update_option( Connection::REVOKED_OPTION, time(), false );
		$this->assertSame( 'revoked', Notice::current()['key'] );

		( new Key_Store() )->save( '41|key' );
		$site                  = Api_Stub::fixture( 'site' )['data'];
		$first                 = gmdate( 'c', time() + 3600 );
		$site['next_batch_at'] = $first;
		$site['message']       = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up.',
		);
		Site_Record::remember( $site );

		$this->assertSame( 'day_spent|' . $first, Notice::current()['key'] );

		Notice::dismiss( 'day_spent|' . $first );
		$this->assertNull( Notice::current() );

		$second                = gmdate( 'c', time() + 90000 );
		$site['next_batch_at'] = $second;
		Site_Record::remember( $site );
		$this->assertSame( 'day_spent|' . $second, Notice::current()['key'] );
	}

	public function test_the_services_message_outlives_the_kept_record_until_its_next_batch_opens(): void {
		( new Key_Store() )->save( '41|key' );
		$site                  = Api_Stub::fixture( 'site' )['data'];
		$site['next_batch_at'] = gmdate( 'c', time() + 3600 );
		$site['message']       = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up.',
		);
		Site_Record::remember( $site );
		delete_transient( Site_Record::TRANSIENT );

		$this->assertSame( 'day_spent|' . $site['next_batch_at'], Notice::current()['key'] ?? null );

		$site['next_batch_at'] = gmdate( 'c', time() - 60 );
		Site_Record::remember( $site );

		$this->assertNull( Notice::current() );

		Site_Record::remember( Api_Stub::fixture( 'site' )['data'] );

		$this->assertNull( Site_Record::message() );
	}

	public function test_a_connected_site_without_a_message_shows_nothing(): void {
		( new Key_Store() )->save( '41|key' );

		$this->assertNull( Notice::current() );

		Site_Record::remember( Api_Stub::fixture( 'site' )['data'] );
		$this->assertNull( Notice::current() );
	}

	public function test_a_known_message_is_the_services_text_in_english_and_the_plugins_words_otherwise(): void {
		$message = array(
			'code' => 'day_spent',
			'text' => 'Your account\'s uploads for the day are used up. Pro makes 1,000 images a day.',
		);

		$this->assertSame( $message, Messages::for_site( $message ) );

		add_filter( 'locale', array( $this, 'german' ) );
		$translated = Messages::for_site( $message );
		remove_filter( 'locale', array( $this, 'german' ) );

		$this->assertSame( 'day_spent', $translated['code'] );
		$this->assertStringNotContainsString( '1,000', $translated['text'] );
		$this->assertStringContainsString( 'next batch', $translated['text'] );

		$unknown = array(
			'code' => 'something_new',
			'text' => 'A message this version doesn\'t know.',
		);

		add_filter( 'locale', array( $this, 'german' ) );
		$this->assertSame( $unknown, Messages::for_site( $unknown ) );
		remove_filter( 'locale', array( $this, 'german' ) );

		$this->assertNull( Messages::for_site( null ) );
		$this->assertNull( Messages::for_site( array( 'code' => '' ) ) );
	}

	/**
	 * A German admin.
	 */
	public function german(): string {
		return 'de_DE';
	}
}
