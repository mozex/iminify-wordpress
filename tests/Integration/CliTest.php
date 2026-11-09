<?php
/**
 * The WP-CLI commands, against the stub of Iminify's API and a stand-in for WP-CLI.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Cli;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use RuntimeException;
use WP_CLI;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Wp_Cli_Stub.php';

/**
 * Covers which images a command takes, that it goes through the bulk run with what
 * the page shows, that a shell never sits through a spent day, and who may run it.
 */
class CliTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	/**
	 * The command, which waits for nothing and can run one thing between looks.
	 *
	 * @var Cli
	 */
	protected $cli;

	public function set_up(): void {
		parent::set_up();
		WP_CLI::$lines = array();
		$this->api     = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Connection::REVOKED_OPTION );
		$site = Api_Stub::fixture( 'site' );
		$site['data']['settings']['optimize_on_upload'] = false;
		$this->api->on( 'GET', 'site', 200, $site );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );

		$this->cli = new class() extends Cli {

			/**
			 * What runs between looks, in place of waiting.
			 *
			 * @var callable|null
			 */
			public $between;

			/**
			 * Looks taken, so a test that goes wrong ends.
			 *
			 * @var int
			 */
			public $looks = 0;

			/**
			 * Looks that do nothing first, as a look that runs out of time does.
			 *
			 * @var int
			 */
			public $idle = 0;

			protected function look(): int {
				if ( $this->idle > 0 ) {
					--$this->idle;

					return 1;
				}

				return parent::look();
			}

			protected function pause( int $seconds ): void {
				unset( $seconds );

				if ( ++$this->looks > 40 ) {
					throw new RuntimeException( 'The command never ended.' );
				}

				if ( null !== $this->between ) {
					( $this->between )();
				}
			}
		};
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		delete_option( Bulk::OPTION );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();

		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}

		parent::tear_down();
	}

	public function test_optimize_needs_ids_or_all_and_a_named_id_must_be_an_attachment(): void {
		$this->fails_with( 'Name the attachments', fn () => $this->cli->optimize( array(), array() ) );
		$this->fails_with( 'Name the attachments', fn () => $this->cli->optimize( array( '5' ), array( 'all' => true ) ) );
		$this->fails_with( 'isn\'t an attachment', fn () => $this->cli->optimize( array( 'abc' ), array() ) );
		$this->fails_with( 'isn\'t an attachment', fn () => $this->cli->optimize( array( (string) self::factory()->post->create() ), array() ) );
		$this->assertNull( Bulk::run() );
	}

	public function test_optimize_in_the_background_leaves_the_run_to_the_sites_tasks_and_refuses_a_second(): void {
		$one = $this->fresh();
		$two = $this->fresh();

		$this->cli->optimize( array(), array( 'all' => true, 'background' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.

		$run = Bulk::run();

		$this->assertSame( Bulk::BACKGROUND, $run['mode'] );
		$this->assertSame( Bulk::RUNNING, $run['status'] );
		$this->assertSame( 2, $run['total'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $one, Optimizer::BULK ) ) );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $two, Optimizer::BULK ) ) );
		$this->assertSame( 'success', WP_CLI::$lines[0][0] );

		$this->fails_with( 'already going', fn () => $this->cli->optimize( array(), array( 'all' => true ) ) );
	}

	public function test_named_images_leave_out_what_is_optimized_so_a_repeat_spends_nothing(): void {
		$done = $this->optimized();

		$this->fails_with( 'already optimized or on its way', fn () => $this->cli->optimize( array( (string) $done ), array() ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );

		$restored = $this->fresh();
		Records::update(
			$restored,
			array(
				'status' => Records::NOT_OPTIMIZED,
				'error'  => array(
					'code'    => Records::RESTORED,
					'message' => 'Restored.',
				),
			)
		);
		$left_alone = $this->fresh();

		/* Someone naming an image they restored is asking for it; the library's run would leave it out. */
		$this->cli->optimize( array( (string) $restored, (string) $done ), array( 'background' => true ) );

		$run = Bulk::run();

		$this->assertSame( 1, $run['total'] );
		$this->assertSame( array( $done, $restored ), $run['only'] );
		$this->assertSame( Records::QUEUED, Records::get( $restored )['status'] );
		$this->assertSame( Records::OPTIMIZED, Records::get( $done )['status'] );
		$this->assertFalse( Optimizer::has_run( $left_alone ) );
	}

	public function test_restore_asks_for_the_whole_library_and_runs_through_to_the_end_with_no_connection(): void {
		$one    = $this->optimized();
		$two    = $this->optimized();
		$file   = (string) get_attached_file( $one );
		$backup = Paths::backup( (string) Paths::relative( $file ) );

		$editor = wp_get_image_editor( $file );
		$editor->set_quality( 10 );
		$editor->save( $file );
		clearstatcache( true, $file );
		Records::update( $one, array( 'written' => array( (string) Paths::relative( $file ) => (int) filesize( $file ) ) ) );
		Plugin::keys()->forget();

		$this->fails_with( 'Asked.', fn () => $this->cli->restore( array(), array( 'all' => true ) ) );
		$this->assertNull( Bulk::run() );

		$this->cli->restore( array(), array( 'all' => true, 'yes' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( Bulk::PAGE, Bulk::run()['mode'] );
		$this->assertSame( 2, Bulk::run()['sent'] );
		$this->assertTrue( Backups::same( $backup, $file ) );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $one )['status'] );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $two )['status'] );
		$this->assertContains( array( 'bar', '2' ), WP_CLI::$lines );
		$this->assertSame( 'success', end( WP_CLI::$lines )[0] );

		$this->fails_with( 'No optimized image is left', fn () => $this->cli->restore( array( (string) $one ), array() ) );
	}

	public function test_a_spent_day_hands_the_run_to_the_sites_tasks_instead_of_waiting(): void {
		$one = $this->fresh();

		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_REFUSAL, 'free' );

		$this->cli->optimize( array(), array( 'all' => true ) );

		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );
		$this->assertSame( Bulk::BACKGROUND, Bulk::run()['mode'] );
		$this->assertTrue( Optimizer::pending( Bulk::FEED_HOOK, array() ) );
		$this->assertSame( 'warning', end( WP_CLI::$lines )[0] );
		$this->assertStringContainsString( 'next batch', end( WP_CLI::$lines )[1] );
		$this->assertSame( 0, $this->cli->looks, 'It waited for nothing.' );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertFalse( Optimizer::has_run( $one ), 'Nothing was queued only to wait.' );
	}

	public function test_alt_text_sends_the_images_named_and_leaves_a_spent_day_to_the_sites_tasks(): void {
		$site = Api_Stub::fixture( 'site' );

		$site['data']['settings']['alt_text']           = true;
		$site['data']['settings']['optimize_on_upload'] = false;

		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();
		$this->api->on(
			'POST',
			'site/alt-text',
			429,
			array(
				'message' => 'Spent.',
				'code'    => 'daily_limit_reached',
			),
			array( 'Retry-After' => '3600' )
		);

		$id = $this->fresh();

		$this->cli->alt_text( array( (string) $id ), array() );

		$this->assertSame( Bulk::ALT_TEXT, Bulk::run()['action'] );
		$this->assertSame( Bulk::BACKGROUND, Bulk::run()['mode'] );
		$this->assertCount( 1, $this->api->calls( 'POST', 'site/alt-text' ), 'The page\'s step ran the send.' );
		$this->assertSame( 'warning', end( WP_CLI::$lines )[0] );
		$this->assertStringContainsString( 'AI alt texts for the day', end( WP_CLI::$lines )[1] );
		$this->assertLessThan( 5, $this->cli->looks, 'It waited for nothing.' );
	}

	public function test_a_text_on_its_way_doesnt_keep_the_shell_through_a_spent_day_of_images(): void {
		$image = $this->fresh();
		$text  = $this->fresh();

		Records::update(
			$text,
			array(
				'alt' => array(
					'status' => Alt_Text::WAITING,
					'since'  => time(),
				),
			)
		);
		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_REFUSAL, 'free' );

		$this->cli->optimize( array( (string) $image ), array() );

		$this->assertSame( Bulk::BACKGROUND, Bulk::run()['mode'] );
		$this->assertStringContainsString( 'next batch', end( WP_CLI::$lines )[1] );
		$this->assertSame( 0, $this->cli->looks, 'It waited for nothing.' );
	}

	public function test_alt_text_needs_ids_or_all(): void {
		$this->expectException( RuntimeException::class );

		$this->cli->alt_text( array(), array() );
	}

	public function test_a_run_stopped_from_the_page_while_a_look_is_under_way_is_never_written_back_as_running(): void {
		$one = $this->fresh();

		/* The page's Stop lands in the database while this process holds the run it read, as another request's would. */
		$stop = static function ( $preempt, array $args, string $url ) {
			global $wpdb;

			if ( 'POST' === ( $args['method'] ?? '' ) && '/groups' === substr( $url, -7 ) ) {
				$run = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Bulk::OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test's stand-in for another request.

				$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array_merge( (array) $run, array( 'status' => Bulk::STOPPED ) ) ) ), array( 'option_name' => Bulk::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test's stand-in for another request.
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $stop, 1, 3 );

		/* The service turns the group away, which holds the run back: the write that must not undo the Stop. */
		$this->api->on( 'POST', 'groups', 429, array( 'code' => 'rate_limited', 'message' => 'Busy.' ), array( 'Retry-After' => '30' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's answer.

		/* The Stop's own request takes the image back. */
		$this->cli->between = static function () use ( $one ): void {
			Optimizer::cancel( $one );
			Records::fail( $one, Records::NOT_OPTIMIZED, 'stopped', 'Stopped.' );
		};

		$this->cli->optimize( array(), array( 'all' => true ) );
		remove_filter( 'pre_http_request', $stop, 1 );

		wp_cache_delete( Bulk::OPTION, 'options' );
		$this->assertSame( Bulk::STOPPED, Bulk::run()['status'] );
		$this->assertSame( 0, Bulk::run()['hold'], 'The refusal found the run stopped and held nothing.' );
		$this->assertSame( 'warning', end( WP_CLI::$lines )[0] );

		/* Any other write of the run reads it from the database too: here a pause another request made stays. */
		global $wpdb;

		Bulk::run();
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array_merge( (array) Bulk::run(), array( 'status' => Bulk::PAUSED ) ) ) ), array( 'option_name' => Bulk::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test's stand-in for another request.
		Bulk::switch_mode( Bulk::BACKGROUND );

		wp_cache_delete( Bulk::OPTION, 'options' );
		$this->assertSame( Bulk::PAUSED, Bulk::run()['status'] );
		$this->assertSame( Bulk::BACKGROUND, Bulk::run()['mode'] );
	}

	public function test_a_restore_that_takes_several_looks_needs_no_connection_and_ends_however_the_queue_stands(): void {
		$one    = $this->optimized();
		$parked = $this->fresh();

		/* A restore never waits for the connection: a parked image and no key would hand any other run to the background. */
		Records::wait( $parked, Records::PARKED, 'not_connected', 'Waiting.' );
		Plugin::keys()->forget();
		$this->cli->idle = 2;

		$this->cli->restore( array(), array( 'all' => true, 'yes' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( Bulk::PAGE, Bulk::run()['mode'], 'It was never handed to the background.' );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $one )['status'] );
		$this->assertSame( 'success', end( WP_CLI::$lines )[0] );

		/* Nor for an image another run has on its way, which a restore never runs. */
		$two     = $this->optimized();
		$waiting = $this->fresh();

		Records::update( $waiting, array( 'status' => Records::QUEUED ) );
		delete_option( Bulk::OPTION );
		$this->cli->idle = 2;

		$this->cli->restore( array(), array( 'all' => true, 'yes' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $two )['status'] );
	}

	public function test_run_carries_on_whatever_run_is_going_and_stop_ends_it(): void {
		$this->fails_with( 'No bulk run is going', fn () => $this->cli->run( array(), array() ) );
		$this->fails_with( 'No bulk run is going', fn () => $this->cli->stop( array(), array() ) );

		$one = $this->optimized();

		Bulk::start( Bulk::RESTORE, Bulk::BACKGROUND );
		$this->assertSame( Bulk::BACKGROUND, Bulk::run()['mode'] );

		$this->cli->run( array(), array() );

		$this->assertSame( Bulk::PAGE, Bulk::run()['mode'] );
		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $one )['status'] );

		$this->fresh();
		$this->cli->optimize( array(), array( 'all' => true, 'background' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.
		$this->cli->stop( array(), array() );

		$this->assertSame( Bulk::STOPPED, Bulk::run()['status'] );
	}

	public function test_missing_makes_only_what_it_is_told_and_status_reports_as_the_page_does(): void {
		$this->fails_with( 'formats or sizes', fn () => $this->cli->missing( array( 'nothing' ), array() ) );
		$this->fails_with( 'lacks anything', fn () => $this->cli->missing( array( 'sizes' ), array() ) );

		$id = $this->optimized();

		$this->cli->missing( array( 'formats', (string) $id ), array( 'background' => true ) );

		$this->assertSame( Bulk::FORMATS, Bulk::run()['action'] );
		$this->assertSame( array( $id ), Bulk::run()['only'] );
		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );
		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );

		WP_CLI::$lines = array();
		$this->cli->status( array(), array() );

		$rows = array_column(
			array_filter(
				WP_CLI::$lines,
				static function ( array $line ): bool {
					return 'row' === $line[0]; }
			),
			1
		); // phpcs:ignore Generic.Formatting.DisallowMultipleStatements.SameLine, Squiz.PHP.DisallowMultipleAssignments.Found -- a test's short filter.

		$this->assertContains( 'connected=yes', $rows );
		$this->assertContains( 'bytes_before=' . Records::saved()['before'], $rows, 'A script reads the bytes, never a size written for people.' );
		$this->assertContains( 'run_action=formats', $rows );
		$this->assertContains( 'run_status=running', $rows );
		$this->assertContains( 'waiting=1', $rows );

		/* Without `--estimate`, the status asks the service nothing the page's poll wouldn't. */
		$this->assertSame(
			array(),
			array_filter(
				$this->api->requests,
				static function ( array $call ): bool {
					return false !== strpos( $call['url'], 'waiting=' ); }
			)
		); // phpcs:ignore Generic.Formatting.DisallowMultipleStatements.SameLine, Squiz.PHP.DisallowMultipleAssignments.Found -- a test's short filter.
	}

	public function test_no_user_is_the_shells_own_gate_and_a_named_user_needs_the_capability(): void {
		$this->fresh();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->fails_with( 'can\'t run bulk commands', fn () => $this->cli->optimize( array(), array( 'all' => true ) ) );
		$this->fails_with( 'can\'t run bulk commands', fn () => $this->cli->status( array(), array() ) );
		$this->assertNull( Bulk::run() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->cli->optimize( array(), array( 'all' => true, 'background' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.
		$this->assertSame( Bulk::RUNNING, Bulk::run()['status'] );

		wp_set_current_user( 0 );
		$this->cli->stop( array(), array() );
		$this->assertSame( Bulk::STOPPED, Bulk::run()['status'] );
	}

	/**
	 * Asserts a command ends in an error saying this.
	 *
	 * @param string   $words   The words it says.
	 * @param callable $command The command.
	 */
	protected function fails_with( string $words, callable $command ): void {
		try {
			$command();
		} catch ( RuntimeException $stopped ) {
			$this->assertStringContainsString( $words, $stopped->getMessage() );

			return;
		}

		$this->fail( 'The command went on: ' . $words );
	}

	/**
	 * An image with no record.
	 */
	protected function fresh(): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		as_unschedule_all_actions( '', array(), Optimizer::GROUP );

		foreach ( Records::ALL_META as $key ) {
			delete_post_meta( $id, $key );
		}

		return $id;
	}

	/**
	 * An image the plugin optimized, with a backup of its main file.
	 */
	protected function optimized(): int {
		$id   = $this->fresh();
		$file = (string) get_attached_file( $id );

		$backups = Backups::keep( $file, (string) Paths::relative( $file ), array() );
		$this->assertIsArray( $backups );

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( (string) Paths::relative( $file ) => (int) filesize( $file ) ),
				'backups' => $backups,
				'result'  => array(
					'level'   => 'smart',
					'formats' => array(),
					'outputs' => array(
						'full' => array(
							'before' => 40000,
							'after'  => (int) filesize( $file ),
						),
					),
				),
			)
		);

		return $id;
	}
}
