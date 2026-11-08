<?php
/**
 * A multisite network connected once, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Key_Store;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Network;
use Iminify\WordPress\Option_Lock;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Sealer;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Runs only when the test library installs a network (`WP_MULTISITE=1`,
 * `npm run test:integration:multisite`). Covers where each site's key lives,
 * a site joining the network on its first need, the refusals that hold it or
 * make it wait, the Disconnect and the stopped keys that keep it out, the
 * network's pairing and who may make it, and turning the plugin off for the
 * network and deleting it.
 */
class NetworkTest extends WP_UnitTestCase {

	const NETWORK_KEY = '51|Nw9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const SITE_KEY = '52|St9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const OWN_KEY = '53|Ow9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	/**
	 * Another site of the network.
	 *
	 * @var int
	 */
	protected $member = 0;

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		$this->api = ( new Api_Stub() )->start();
		/* A site's tables are made outside the test's transaction, so each test gets a path of its own. */
		$this->member = (int) self::factory()->blog->create( array( 'path' => '/member-' . wp_generate_password( 8, false ) . '/' ) );
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( IMINIFY_FILE ) => time() ) );

		switch_to_blog( $this->member );
		$this->action_scheduler_tables();
		restore_current_blog();
	}

	public function tear_down(): void {
		if ( null !== $this->api ) {
			$this->api->stop();
		}

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		delete_site_option( Network::KEY_OPTION );
		delete_site_option( 'active_sitewide_plugins' );
		parent::tear_down();
	}

	public function test_the_main_sites_key_is_the_networks_and_another_site_keeps_only_its_own(): void {
		$this->assertTrue( Network::is_main() );
		$this->assertTrue( Plugin::keys()->save( self::NETWORK_KEY ) );
		$this->assertSame( self::NETWORK_KEY, Sealer::open( get_site_option( Network::KEY_OPTION ) ) );
		$this->assertFalse( get_option( Key_Store::OPTION ) );

		switch_to_blog( $this->member );

		$this->assertTrue( Network::is_member() );
		$this->assertNull( Plugin::keys()->get() );
		$this->assertSame( self::NETWORK_KEY, Network::key() );
		$this->assertTrue( Plugin::keys()->save( self::OWN_KEY ) );
		$this->assertSame( self::OWN_KEY, Plugin::keys()->get() );

		restore_current_blog();

		$this->assertSame( self::NETWORK_KEY, Plugin::keys()->get() );

		/* With the plugin active site by site, the main site keeps a key of its own like any site. */
		delete_site_option( 'active_sitewide_plugins' );

		$this->assertFalse( Network::active() );
		$this->assertNull( Plugin::keys()->get() );
	}

	public function test_a_site_joins_the_network_the_first_time_it_needs_iminify_and_then_calls_with_its_own_key(): void {
		$this->connect_network();
		$this->api->on( 'POST', 'site/sites', 201, $this->joined_answer() );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );

		switch_to_blog( $this->member );
		update_option( 'blogname', 'Member &amp; Co' );

		$this->assertTrue( Plugin::connected() );
		$this->assertSame( array(), $this->api->requests, 'Asking whether the site is connected calls nothing.' );

		$client = Plugin::client();
		$join   = $this->api->calls( 'POST', 'site/sites' );

		$this->assertNotNull( $client );
		$this->assertCount( 1, $join );
		$this->assertSame( 'Bearer ' . self::NETWORK_KEY, $join[0]['args']['headers']['Authorization'] );
		$this->assertSame( get_home_url( get_main_site_id() ), $join[0]['args']['headers']['Iminify-Site'] );
		$this->assertSame(
			array(
				'url'  => home_url(),
				'name' => 'Member & Co',
			),
			json_decode( $join[0]['args']['body'], true )
		);
		$this->assertSame( self::SITE_KEY, Plugin::keys()->get() );
		$this->assertTrue( Network::joined() );

		Site_Record::fetch();
		Plugin::client();

		$site = $this->api->calls( 'GET', 'site' );

		$this->assertCount( 1, $this->api->calls( 'POST', 'site/sites' ), 'A site with a key never asks the network again.' );
		$this->assertSame( 'Bearer ' . self::SITE_KEY, $site[0]['args']['headers']['Authorization'] );
		$this->assertSame( home_url(), $site[0]['args']['headers']['Iminify-Site'] );
	}

	public function test_an_upload_on_a_site_that_will_join_is_queued_rather_than_left_unoptimized(): void {
		$this->connect_network();

		switch_to_blog( $this->member );

		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/sites' ), 'The upload itself never waits on the join.' );
	}

	public function test_a_refusal_holds_the_site_until_someone_asks_or_the_network_gets_a_new_key(): void {
		$this->connect_network();
		$this->api->on(
			'POST',
			'site/sites',
			409,
			array(
				'message' => 'This network already has 50 sites, as many as one network can have.',
				'code'    => 'network_full',
			)
		);

		switch_to_blog( $this->member );

		$this->assertNull( Plugin::client() );
		$this->assertFalse( Network::joinable() );
		$this->assertFalse( Plugin::connected() );
		$this->assertNull( Plugin::client() );
		$this->assertCount( 1, $this->api->calls( 'POST', 'site/sites' ), 'A held site asks nothing by itself.' );
		$this->assertSame( 'network_full', State::build()['network']['refusal']['code'] );
		$this->assertStringNotContainsString( '50', State::build()['network']['refusal']['message'] );

		$asked = Network::join( true );

		$this->assertInstanceOf( WP_Error::class, $asked );
		$this->assertCount( 2, $this->api->calls( 'POST', 'site/sites' ), 'Join the network always asks.' );

		restore_current_blog();
		Plugin::keys()->save( '54|Nx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r' );
		switch_to_blog( $this->member );

		$this->assertTrue( Network::joinable(), 'A new network key lifts the hold.' );
	}

	public function test_a_wait_keeps_the_site_connected_asks_nothing_until_it_ends_and_wakes_what_parked(): void {
		$this->connect_network();
		$this->api->on(
			'POST',
			'site/sites',
			429,
			array(
				'message' => 'This network added too many sites in the last hour. Try again later.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '600' )
		);

		switch_to_blog( $this->member );

		$this->assertNull( Plugin::client() );
		$this->assertNull( Plugin::client() );
		$this->assertCount( 1, $this->api->calls( 'POST', 'site/sites' ) );
		$this->assertTrue( Plugin::connected(), 'Uploads still queue while the site waits to join.' );

		$wake = as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP );

		$this->assertIsInt( $wake );
		$this->assertGreaterThanOrEqual( time() + 590, $wake );

		/* The connection reads back meanwhile (the plugin turned on again for the network), which brings the wake forward; the run then finds no key yet, and puts the wake back at the wait's end. */
		Queue::resume_soon();

		$this->assertLessThanOrEqual( time(), as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );

		$run = \ActionScheduler::store()->query_action(
			array(
				'hook'   => Queue::RESUME_HOOK,
				'args'   => array( 0 ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		\ActionScheduler::runner()->process_action( (int) $run );
		$wake = as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP );

		$this->assertCount( 1, $this->api->calls( 'POST', 'site/sites' ) );
		$this->assertIsInt( $wake );
		$this->assertGreaterThanOrEqual( time() + 590, $wake );
		$this->assertCount( 1, $this->wakes() );

		/* The wait is over and the join is refused again: the refusal's own wake is the only one, so the parked images never go on in several runs at once. */
		update_option( Network::JOIN_OPTION, array_merge( (array) get_option( Network::JOIN_OPTION ), array( 'retry_at' => time() - 1 ) ), false );
		\ActionScheduler::runner()->process_action( $this->wakes()[0] );

		$this->assertCount( 2, $this->api->calls( 'POST', 'site/sites' ) );
		$this->assertCount( 1, $this->wakes() );
	}

	public function test_a_site_disconnected_on_its_own_page_or_on_iminify_doesnt_join_again_by_itself(): void {
		$this->connect_network();
		$this->api->on( 'POST', 'site/sites', 201, $this->joined_answer() );
		$this->api->on( 'DELETE', 'site', 204, '' );

		switch_to_blog( $this->member );
		Plugin::client();

		$result = ( new Connection() )->disconnect();
		$delete = $this->api->calls( 'DELETE', 'site' );

		$this->assertSame( array( 'revoked' => true ), $result );
		$this->assertSame( 'Bearer ' . self::SITE_KEY, $delete[0]['args']['headers']['Authorization'] );
		$this->assertNull( Plugin::keys()->get() );
		$this->assertFalse( Network::joinable() );
		$this->assertNull( Plugin::client() );
		$this->assertCount( 1, $this->api->calls( 'POST', 'site/sites' ) );

		$this->assertSame( self::SITE_KEY, Network::join( true ) );
		$this->assertTrue( Network::joined() );

		/* Iminify stops the key: the next refusal disconnects and holds it. */
		( new Connection() )->revoked_by_service( new WP_Error( 'unauthenticated', 'Unauthenticated.', array( 'key' => Network::fingerprint( self::SITE_KEY ) ) ) );

		$this->assertNull( Plugin::keys()->get() );
		$this->assertTrue( Connection::was_revoked() );
		$this->assertFalse( Network::joinable() );
	}

	public function test_a_site_whose_key_stopped_with_its_networks_disconnect_joins_the_network_connected_again(): void {
		$this->connect_network();
		$this->api->on( 'POST', 'site/sites', 201, $this->joined_answer() );

		switch_to_blog( $this->member );
		Plugin::client();
		restore_current_blog();

		/* The network was disconnected, which stopped this site's key, and connected again before the site heard. */
		Plugin::keys()->save( '54|Nx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r' );

		switch_to_blog( $this->member );
		( new Connection() )->revoked_by_service( new WP_Error( 'unauthenticated', 'Unauthenticated.', array( 'key' => Network::fingerprint( self::SITE_KEY ) ) ) );

		$this->assertNull( Plugin::keys()->get() );
		$this->assertTrue( Network::joinable() );
	}

	public function test_a_refusal_of_a_key_since_replaced_leaves_the_new_key_alone(): void {
		$this->connect_network();

		switch_to_blog( $this->member );
		Plugin::keys()->save( self::SITE_KEY );

		( new Connection() )->revoked_by_service( new WP_Error( 'unauthenticated', 'Unauthenticated.', array( 'key' => Network::fingerprint( 'an older key' ) ) ) );

		$this->assertSame( self::SITE_KEY, Plugin::keys()->get() );
		$this->assertFalse( Connection::was_revoked() );
	}

	public function test_one_join_at_a_time_and_a_key_another_request_kept_is_taken_without_asking(): void {
		global $wpdb;

		$this->connect_network();
		$this->api->on( 'POST', 'site/sites', 201, $this->joined_answer() );

		switch_to_blog( $this->member );

		$this->assertTrue( Option_Lock::take( Network::LOCK_OPTION, Network::LOCK_SECONDS ) );
		$this->assertNull( Plugin::client() );
		$this->assertSame( array(), $this->api->requests );

		Option_Lock::release( Network::LOCK_OPTION );

		/* Another request kept a key while this one held the options it read at its start. */
		get_option( Key_Store::OPTION );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- what another request's write leaves, past this request's cache.
			$wpdb->options,
			array(
				'option_name'  => Key_Store::OPTION,
				'option_value' => (string) Sealer::seal( self::OWN_KEY ),
				'autoload'     => 'off',
			)
		);

		$this->assertSame( self::OWN_KEY, Network::join() );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_a_network_key_iminify_no_longer_knows_disconnects_the_network(): void {
		$this->connect_network();
		$this->api->on(
			'POST',
			'site/sites',
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		switch_to_blog( $this->member );

		$this->assertNull( Plugin::client() );
		$this->assertNull( Network::key() );
		$this->assertFalse( Network::joinable() );

		restore_current_blog();

		$this->assertNull( Plugin::keys()->get() );
		$this->assertTrue( Connection::was_revoked() );
	}

	public function test_the_main_site_pairs_the_network_and_only_someone_who_manages_the_network_may(): void {
		$this->api->on( 'POST', 'connect/start', 201, Api_Stub::fixture( 'connect-start' ) );
		update_site_option( 'site_name', 'Our &amp; Network' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/iminify/v1/connect' ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/iminify/v1/state' ) )->get_status() );
		$this->assertSame( array(), $this->api->calls( 'POST', 'connect/start' ) );

		grant_super_admin( $admin );

		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'POST', '/iminify/v1/connect' ) )->get_status() );
		$this->assertSame(
			array(
				'url'     => home_url(),
				'name'    => 'Our & Network',
				'network' => true,
			),
			json_decode( $this->api->last()['args']['body'], true )
		);

		( new Connection() )->cancel();
	}

	public function test_turning_it_off_for_the_network_parks_and_unschedules_every_sites_queue(): void {
		$sites = array( get_current_blog_id(), $this->member );
		$ids   = array();

		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			Lifecycle::resume();

			$id = (int) self::factory()->attachment->create_object( 'photo.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
			Records::update( $id, array( 'status' => Records::QUEUED ) );
			as_schedule_single_action( time() + 60, Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP );
			$ids[ $site ] = $id;
			restore_current_blog();
		}

		Lifecycle::deactivate( true );

		foreach ( $sites as $site ) {
			switch_to_blog( $site );

			$this->assertSame( Records::PARKED, Records::get( $ids[ $site ] )['status'] );
			$this->assertSame( 0, $this->plugin_actions(), 'Nothing of the plugin stays scheduled on site ' . $site );
			$this->assertFalse( get_option( Lifecycle::ACTIVATED_OPTION ) );
			$this->assertNotFalse( get_option( Local_Settings::OPTION ) );
			restore_current_blog();
		}

		/* On again, each site picks up on its first request. */
		switch_to_blog( $this->member );
		Plugin::keys()->save( self::SITE_KEY );
		Lifecycle::resume();

		$this->assertSame( '0', (string) get_option( Lifecycle::ACTIVATED_OPTION ) );
		$this->assertNotFalse( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP ) );
	}

	public function test_deleting_it_stops_the_networks_key_once_and_cleans_every_site(): void {
		$this->connect_network();
		$this->api->on( 'DELETE', 'site', 204, '' );
		$own = (int) self::factory()->blog->create( array( 'path' => '/own-' . wp_generate_password( 8, false ) . '/' ) );

		switch_to_blog( $own );
		$this->action_scheduler_tables();
		restore_current_blog();

		foreach ( array( get_current_blog_id(), $this->member, $own ) as $site ) {
			switch_to_blog( $site );
			Lifecycle::resume();
			update_option( Bulk::OPTION, array( 'status' => 'running' ) );
			as_schedule_single_action( time() + 60, Queue::RESUME_HOOK, array( 0 ), Optimizer::GROUP );
			restore_current_blog();
		}

		switch_to_blog( $this->member );
		Plugin::keys()->save( self::SITE_KEY );
		update_option( Network::JOIN_OPTION, array( 'joined' => true ), false );
		switch_to_blog( $own );
		Plugin::keys()->save( self::OWN_KEY );
		restore_current_blog();
		restore_current_blog();

		/* WordPress deletes a plugin only once it is off, and turning it off for the network removed each site's activation mark. */
		Lifecycle::deactivate( true );
		delete_site_option( 'active_sitewide_plugins' );

		Lifecycle::uninstall();

		$deletes = $this->api->calls( 'DELETE', 'site' );
		$sent    = array_map(
			static function ( array $call ): array {
				return array( $call['args']['headers']['Authorization'], $call['args']['headers']['Iminify-Site'] );
			},
			$deletes
		);

		$this->assertSame(
			array(
				array( 'Bearer ' . self::NETWORK_KEY, get_home_url( get_main_site_id() ) ),
				array( 'Bearer ' . self::OWN_KEY, get_home_url( $own ) ),
			),
			$sent
		);
		$this->assertFalse( get_site_option( Network::KEY_OPTION ) );

		foreach ( array( get_current_blog_id(), $this->member, $own ) as $site ) {
			switch_to_blog( $site );

			/* Read from the table: the settings are registered with a default, which get_option() answers for a missing row. */
			$this->assertSame( array(), $this->options_left(), 'Options stay on site ' . $site );

			$this->assertSame( 0, $this->plugin_actions(), 'Runs stay on site ' . $site );
			restore_current_blog();
		}
	}

	/**
	 * Runs alone, since a constant can't be undefined for the tests after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_key_is_the_networks_and_never_another_sites_own(): void {
		define( 'IMINIFY_API_KEY', ' ' . self::NETWORK_KEY . ' ' );
		define( 'IMINIFY_HIDE_ACCOUNT', true );
		$this->api->on( 'POST', 'site/sites', 201, $this->joined_answer() );
		$own = (int) self::factory()->blog->create( array( 'path' => '/own-' . wp_generate_password( 8, false ) . '/' ) );

		$this->assertSame( self::NETWORK_KEY, Plugin::keys()->get() );
		$this->assertTrue( Plugin::keys()->from_constant() );
		$this->assertTrue( Plugin::keys()->hides_account() );

		switch_to_blog( $this->member );

		$this->assertNull( Plugin::keys()->get() );
		$this->assertFalse( Plugin::keys()->from_constant() );
		$this->assertTrue( Plugin::keys()->hides_account(), 'A site that will join with the agency\'s key keeps its account out of sight.' );
		$this->assertNotNull( Plugin::client() );

		$join = $this->api->calls( 'POST', 'site/sites' );

		$this->assertSame( 'Bearer ' . self::NETWORK_KEY, $join[0]['args']['headers']['Authorization'] );
		$this->assertSame( get_home_url( get_main_site_id() ), $join[0]['args']['headers']['Iminify-Site'] );
		$this->assertSame( self::SITE_KEY, Plugin::keys()->get() );

		switch_to_blog( $own );
		Plugin::keys()->save( self::OWN_KEY );

		$this->assertFalse( Plugin::keys()->hides_account(), 'A site with an account of its own shows it.' );

		restore_current_blog();
		restore_current_blog();

		/* The key set in wp-config.php is never stopped by a deletion. */
		$this->api->on( 'DELETE', 'site', 204, '' );
		delete_site_option( 'active_sitewide_plugins' );
		Lifecycle::uninstall();

		foreach ( $this->api->calls( 'DELETE', 'site' ) as $call ) {
			$this->assertNotSame( 'Bearer ' . self::NETWORK_KEY, $call['args']['headers']['Authorization'] );
		}
	}

	public function test_the_copies_a_page_kept_for_one_site_are_never_served_for_another_sites_attachment(): void {
		$served = new \ReflectionProperty( Picture::class, 'served' );

		if ( PHP_VERSION_ID < 80100 ) {
			$served->setAccessible( true );
		}

		$kept = array( '/kept/photo.jpg' => array( 'webp' => 'photo.jpg.webp' ) );

		/* Kept as this site's attachment 7, in either shape a cache could key it by. */
		$served->setValue(
			null,
			array(
				7                            => $kept,
				get_current_blog_id() . ':7' => $kept,
			)
		);

		switch_to_blog( $this->member );

		$copies = new \ReflectionMethod( Picture::class, 'copies' );

		if ( PHP_VERSION_ID < 80100 ) {
			$copies->setAccessible( true );
		}

		$this->assertSame( array(), $copies->invoke( null, 7 ) );

		$served->setValue( null, array() );
	}

	/**
	 * Connects the network: the main site keeps the network's key.
	 */
	protected function connect_network(): void {
		$this->assertTrue( Plugin::keys()->save( self::NETWORK_KEY ) );
	}

	/**
	 * Iminify's answer to a site joining.
	 *
	 * @return array<string, mixed>
	 */
	protected function joined_answer(): array {
		return array(
			'data' => array(
				'key'  => self::SITE_KEY,
				'site' => array(
					'url'  => home_url(),
					'name' => 'Member',
				),
			),
		);
	}

	/**
	 * Makes Action Scheduler's tables on a site the test made, as its first request there would.
	 * Never on the main site: its tables are real ones, and the schema's changes to a real
	 * table commit the test's transaction, which then leaks into every later test.
	 */
	protected function action_scheduler_tables(): void {
		$this->assertFalse( is_main_site() );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
	}

	/**
	 * The plugin's options left in the switched site's table.
	 *
	 * @return list<string>
	 */
	protected function options_left(): array {
		global $wpdb;

		$in = implode( ', ', array_fill( 0, count( Lifecycle::OPTIONS ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per option, built above.
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ({$in})", Lifecycle::OPTIONS ) );
	}

	/**
	 * The runs waiting to send this site's parked images on.
	 *
	 * @return list<int>
	 */
	protected function wakes(): array {
		return array_values(
			array_map(
				'intval',
				as_get_scheduled_actions(
					array(
						'hook'     => Queue::RESUME_HOOK,
						'args'     => array( 0 ),
						'group'    => Optimizer::GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => -1,
					),
					'ids'
				)
			)
		);
	}

	/**
	 * How many of the plugin's runs this site's queue holds, read from its own table.
	 */
	protected function plugin_actions(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reads the switched site's own Action Scheduler table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN (%s, %s) AND status = %s", Optimizer::OPTIMIZE_HOOK, Queue::RESUME_HOOK, 'pending' ) );
	}
}
