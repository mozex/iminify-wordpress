<?php
/**
 * Optimizing again, from the originals they kept, the images another optimizer compressed.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Hooks;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_UnitTestCase;

/**
 * Covers D11's "optimize again from the originals": an image another optimizer
 * compressed is put back from the original it kept (W250) before it is sent, at
 * the press in the media library and in its own run for the bulk run's `redo`,
 * after every refusal that costs nothing, and keeps a backup whatever the
 * setting; a stop gives back what wasn't put back yet; every automatic path still
 * sends nothing. And LiteSpeed Cache's put-back on a regeneration (W134): copies
 * it deleted are forgotten, and a file it put back reads as changed.
 */
class RedoTest extends WP_UnitTestCase {

	use Others_Fixture;

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

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
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Others::STAMP_OPTION );
		update_option( 'active_plugins', array() );
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Others::forget();
	}

	public function tear_down(): void {
		$this->api->stop();
		$this->forget_others();
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Others::STAMP_OPTION );
		delete_option( Local_Settings::OPTION );
		update_option( 'active_plugins', array() );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();

		foreach ( Paths::BACKUP_FOLDERS as $folder ) {
			if ( is_dir( Paths::uploads() . '/' . $folder ) ) {
				$this->rmdir( Paths::uploads() . '/' . $folder );
			}
		}

		parent::tear_down();
	}

	public function test_a_press_puts_the_original_back_only_past_every_refusal_that_costs_nothing_then_sends_it_keeping_a_backup_with_backups_off(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id       = $this->fresh( $this->plain_upload() );
		$attached = (string) get_attached_file( $id );
		$original = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.
		$numbers  = $this->as_ewww( $id );
		$backups  = (array) Others::trace( $id )['backups'];
		$kept     = array_map( 'md5_file', $backups );
		$squeezed = md5_file( $attached );
		$no_room  = static function (): int {
			return 0;
		};

		Local_Settings::update( array( 'backups' => false ) );

		$this->assertNotSame( md5( $original ), $squeezed );
		$this->assertTrue( Optimizer::redoable( $id ) );
		$this->assertArrayHasKey( 'iminify_optimize', Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertStringContainsString( 'Optimize again from the original at', Media_View::panel( $id ) );

		/* Low on disk: refused before anything is put back, so the image keeps EWWW's savings. */
		add_filter( 'iminify_disk_free_space', $no_room );
		$refused = Optimizer::ask( $id );
		remove_filter( 'iminify_disk_free_space', $no_room );

		$this->assertSame( 'disk_full', $refused->get_error_code() );
		$this->assertSame( $squeezed, md5_file( $attached ), 'A refusal leaves the image as EWWW left it.' );

		/* Disconnected: the same. */
		Plugin::keys()->forget();
		Site_Record::forget();

		$this->assertSame( 'not_connected', Optimizer::ask( $id )->get_error_code() );
		$this->assertSame( $squeezed, md5_file( $attached ) );

		Plugin::keys()->save( self::KEY );

		$this->assertTrue( Optimizer::ask( $id, 'smart' ) );

		$record = Records::get( $id );

		clearstatcache();
		$this->assertSame( md5( $original ), md5_file( $attached ), 'The original EWWW kept is back before anything is sent.' );
		$this->assertSame( $kept, array_map( 'md5_file', $backups ), 'EWWW\'s backups stay where they were.' );
		$this->assertTrue( $record['redo'] );
		$this->assertSame( Records::QUEUED, $record['status'] );
		$this->assertSame( 'smart', $record['level'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::MANUAL ) ) );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$backup   = Paths::backup( (string) Paths::relative( $attached ) );

		$this->assertSame( strlen( $original ), $declared['source']['size'], 'The original goes, never EWWW\'s output.' );
		$this->assertSame( (int) filesize( $attached ), $declared['outputs'][0]['size'], 'What it declares is the original back in place, so the service replaces it with a smaller file.' );
		$this->assertNotSame( $numbers['full']['after'], $declared['outputs'][0]['size'] );
		$this->assertFileExists( $backup, 'Its original is kept with backups off, or a restore would end with EWWW\'s backup.' );
		$this->assertSame( md5( $original ), md5_file( $backup ) );
		$this->assertContains( (string) Paths::relative( $backup ), Records::get( $id )['backups'] );
		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );
		$this->assertSame( 'smart', $declared['level'] );
	}

	public function test_the_bulk_run_takes_the_images_with_an_original_puts_each_back_in_its_own_run_never_under_a_spent_day_and_never_sends_one_automatically(): void {
		$ewww      = $this->fresh( $this->plain_upload() );
		$bare      = $this->fresh( $this->plain_upload() );
		$converted = $this->fresh( $this->plain_upload() );
		$smushed   = $this->fresh( $this->scaled_upload() );
		$attached  = (string) get_attached_file( $ewww );
		$original  = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		$this->as_ewww( $ewww );
		$this->as_imagify( $bare, false );
		$this->as_elementor( $converted, true );
		$this->as_smush( $smushed );
		Others::look();

		$squeezed = md5_file( $attached );
		$counts   = Bulk::counts();
		$left_out = static function ( bool $optimize, int $attachment ) use ( $converted ): bool {
			return $optimize && $attachment !== $converted;
		};

		/* Imagify kept no original of its image; the site leaves the image Elementor converted out (ConvertedTest sends one), so the run passes it over. */
		add_filter( 'iminify_optimize_image', $left_out, 10, 2 );
		$this->assertSame( 3, $counts['redo'] );
		$this->assertFalse( Optimizer::redoable( $bare ) );
		$this->assertFalse( Optimizer::redoable( $converted ) );

		$found = Bulk::candidates( Bulk::REDO, 10, 0 );

		$this->assertSame( array( $ewww, $smushed ), $found['ids'] );
		$this->assertSame( array( $converted ), $found['passed'] );

		/* No image another plugin optimized is ever sent by an automatic path, the bulk run's own optimize included. */
		Optimizer::optimize( $smushed, Optimizer::AUTOMATIC );

		$this->assertSame( Records::OTHER, Records::get( $smushed )['status'] );
		$this->assertNotContains( $ewww, Bulk::candidates( Bulk::OPTIMIZE, 50, 0 )['ids'] );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );

		Site_Record::fetch();
		$run = Bulk::start( Bulk::REDO, Bulk::BACKGROUND );

		$this->assertSame( Bulk::REDO, $run['action'] );
		$this->assertSame( 2, $run['total'], 'The image the site leaves out is passed over.' );
		$this->assertSame( 1, $run['passed'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $ewww, Optimizer::BULK ) ) );
		$this->assertTrue( Records::get( $ewww )['redo'] );
		$this->assertSame( Records::OTHER, Records::get( $converted )['status'] );
		$this->assertSame( $squeezed, md5_file( $attached ), 'The feeder only queues: each image is put back in its own run.' );

		/* Another optimizer comes on and goes: the run looks again from its start, and the image it passed over, still in its query, counts once. */
		update_option( 'active_plugins', array( 'wp-smushit/wp-smush.php' ) );
		Others::forget();
		Bulk::feed();
		update_option( 'active_plugins', array() );
		Others::forget();
		Bulk::feed();

		$this->assertFalse( Bulk::run()['rewind'] );
		$this->assertSame( 1, Bulk::run()['passed'] );
		$this->assertSame( 2, Bulk::run()['total'] );

		/* A spent day holds the run before anything is put back. */
		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_REFUSAL, 'free' );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $ewww, Optimizer::BULK ), Optimizer::GROUP );
		Optimizer::optimize( $ewww, Optimizer::BULK );

		$this->assertSame( Records::PAUSED, Records::get( $ewww )['status'] );
		$this->assertSame( $squeezed, md5_file( $attached ), 'Waiting for the next batch, it keeps EWWW\'s savings.' );

		Pause::lift();
		Optimizer::optimize( $ewww, Optimizer::BULK );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );

		clearstatcache();
		$this->assertSame( md5( $original ), md5_file( $attached ) );
		$this->assertSame( strlen( $original ), $declared['source']['size'] );
		$this->assertSame( 'background', $declared['priority'] );
		$this->assertSame( Records::WAITING, Records::get( $ewww )['status'] );
		$this->assertSame( Optimizer::BULK, Records::get( $ewww )['trigger'] );
		$this->assertNull( Others::verdict( $ewww, true ) );
	}

	public function test_a_stop_gives_another_plugin_back_an_image_not_put_back_yet(): void {
		$id = $this->fresh( $this->plain_upload() );

		$this->as_ewww( $id );
		Others::look();
		Site_Record::fetch();
		Bulk::start( Bulk::REDO, Bulk::BACKGROUND );

		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );

		/* A regeneration while it waits leaves it on its way, so the stop still finds it. */
		Others::forget();
		Hooks::generated( wp_get_attachment_metadata( $id ), $id );

		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertTrue( Records::get( $id )['redo'] );

		Bulk::stop();

		$record = Records::get( $id );

		$this->assertSame( Records::OTHER, $record['status'] );
		$this->assertSame( 'ewww', $record['other']['plugin'] );
		$this->assertFalse( $record['redo'] );
		$this->assertSame( 'ewww:1', get_post_meta( $id, Records::OTHER_META, true ) );
		$this->assertFalse( Optimizer::has_run( $id ) );
		$this->assertSame( 1, Bulk::counts()['redo'], 'The next run finds it again.' );
	}

	public function test_an_image_another_plugin_starts_acting_on_meanwhile_stays_its_and_the_page_counts_and_estimates_the_choice(): void {
		$id = $this->fresh( $this->plain_upload() );

		$this->as_ewww( $id );
		Others::look();
		Site_Record::fetch();
		Bulk::start( Bulk::REDO, Bulk::BACKGROUND );

		/* Smush comes on with its automatic compression before the image's run. */
		update_option( 'active_plugins', array( 'wp-smushit/wp-smush.php' ) );
		Others::forget();
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ), Optimizer::GROUP );
		Optimizer::optimize( $id, Optimizer::BULK );

		$this->assertSame( Records::OTHER, Records::get( $id )['status'] );
		$this->assertSame( 'ewww:1', get_post_meta( $id, Records::OTHER_META, true ) );
		$this->assertSame( 0, Bulk::run()['sent'] );

		update_option( 'active_plugins', array() );
		Others::forget();
		Bulk::stop();

		$site                     = Api_Stub::fixture( 'site' );
		$site['data']['estimate'] = array(
			'days'   => 3,
			'faster' => array(),
		);
		$this->api->on( 'GET', 'site', 200, $site );

		$state = Bulk_State::build( true );
		$asked = $this->api->calls( 'GET', 'site' );

		$this->assertSame( 1, $state['counts']['redo'] );
		$this->assertSame( 3, $state['redo_estimate']['days'], 'The choice has its own estimate, as the service counts it.' );
		$this->assertStringContainsString( 'waiting=1&', (string) end( $asked )['url'] );
	}

	public function test_litespeed_putting_its_files_back_on_a_regeneration_reads_as_changed_and_the_copies_it_deleted_are_forgotten(): void {
		$id       = $this->fresh( $this->plain_upload() );
		$files    = $this->named( $id );
		$attached = $files['full'];

		$this->as_litespeed( $id );
		$backup = dirname( $attached ) . '/' . wp_basename( $attached, '.jpg' ) . '.bk.jpg';

		/* LiteSpeed's original back, then Iminify's output written over it with its WebP copy, as a redo leaves it. */
		$this->assertTrue( Optimizer::ask( $id ) );
		$this->written_by_iminify( $id, $attached );

		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertNull( Others::verdict( $id, true ), 'A file with the bytes WordPress records is never LiteSpeed\'s output.' );

		/* LiteSpeed Cache, active, answers a regeneration's `create` context first (its priority 10): it deletes every WebP and AVIF beside the files, then puts its `.bk.` files back. */
		$putback   = true;
		$litespeed = static function ( $metadata, $attachment, $context = 'create' ) use ( &$putback, $files, $backup ) {
			if ( 'create' !== $context ) {
				return $metadata;
			}

			foreach ( $files as $file ) {
				foreach ( array( '.webp', '.avif' ) as $copy ) {
					if ( is_file( $file . $copy ) ) {
						wp_delete_file( $file . $copy );
					}
				}
			}

			if ( $putback && is_file( $backup ) ) {
				rename( $backup, $files['full'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- LiteSpeed's own move, as it makes it.
				delete_post_meta( (int) $attachment, 'litespeed-optimize-size' );
				delete_post_meta( (int) $attachment, 'litespeed-optimize-set' );
			}

			return $metadata;
		};
		add_filter( 'wp_generate_attachment_metadata', $litespeed, 10, 3 );

		/* A regeneration that skips every existing size, with no backup to put back: only the copies go. Each regeneration is a request of its own, which reads the other plugins afresh. */
		$putback = false;
		Others::forget();
		apply_filters( 'wp_generate_attachment_metadata', wp_get_attachment_metadata( $id ), $id, 'create' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as a regeneration runs it.

		$record = Records::get( $id );

		$this->assertSame( Records::OPTIMIZED, $record['status'], 'Its files are as Iminify wrote them.' );
		$this->assertSame( array(), $record['copies'], 'The copy LiteSpeed deleted is forgotten.' );
		$this->assertNotContains( 'webp', $record['result']['formats'] );
		$this->assertContains( $id, Missing::images( Missing::FORMATS, 50, 0 ), 'It lacks its WebP now, and the bulk page says so.' );
		Picture::flush();
		$this->assertFalse( Picture::has_copies( $id ), 'Nothing gone is served.' );

		/* Then one with its `.bk.` there: the file it puts back is no file Iminify wrote. */
		$putback = true;
		Others::forget();
		apply_filters( 'wp_generate_attachment_metadata', wp_get_attachment_metadata( $id ), $id, 'create' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as a regeneration runs it.
		remove_filter( 'wp_generate_attachment_metadata', $litespeed, 10 );

		$this->assertNotSame( Records::OPTIMIZED, Records::get( $id )['status'], 'Never "optimized" on a file Iminify didn\'t write.' );
		$this->assertTrue( Optimizer::has_run( $id ), 'Read as changed, it goes again from what is there now.' );

		/* The bulk page says so while LiteSpeed Cache is active, for its cache alone too. */
		$this->assertFalse( Bulk_State::build()['putback'] );

		update_option( 'active_plugins', array( 'litespeed-cache/litespeed-cache.php' ) );
		Others::forget();

		$this->assertTrue( Bulk_State::build()['putback'] );
	}

	public function test_a_redo_image_is_never_sent_once_its_plugin_forgot_it_and_one_too_large_since_is_passed_over_unrestored(): void {
		global $wpdb;

		$forgotten = $this->fresh( $this->plain_upload() );
		$large     = $this->fresh( $this->plain_upload() );
		$attached  = (string) get_attached_file( $large );

		$this->as_ewww( $forgotten );
		$this->as_ewww( $large );
		Others::look();
		Site_Record::fetch();
		Bulk::start( Bulk::REDO, Bulk::BACKGROUND );

		$this->assertSame( 2, Bulk::run()['sent'] );

		/* EWWW's records of one image gone while it waits (as its uninstall leaves the files): the plugin's own memory of EWWW's output still guards it. */
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ewwwio_images WHERE attachment_id = %d", $forgotten ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, as its uninstall empties it.
		Others::forget();

		$squeezed = md5_file( (string) get_attached_file( $forgotten ) );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $forgotten, Optimizer::BULK ), Optimizer::GROUP );
		Optimizer::optimize( $forgotten, Optimizer::BULK );

		$this->assertSame( Records::OTHER, Records::get( $forgotten )['status'] );
		$this->assertSame( 'ewww', Records::get( $forgotten )['other']['plugin'] );
		$this->assertSame( $squeezed, md5_file( (string) get_attached_file( $forgotten ) ), 'Nothing is put back with no original to put back.' );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ), 'EWWW\'s output is never sent for a second lossy pass.' );

		/* The plan's file size went down since the run looked: passed over before anything is put back. */
		$site                          = Api_Stub::fixture( 'site' );
		$site['data']['max_file_size'] = 1000;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();

		$squeezed = md5_file( $attached );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK, array( $large, Optimizer::BULK ), Optimizer::GROUP );
		Optimizer::optimize( $large, Optimizer::BULK );

		$run = Bulk::run();

		$this->assertSame( Records::OTHER, Records::get( $large )['status'] );
		$this->assertSame( $squeezed, md5_file( $attached ), 'Too large to send, it keeps EWWW\'s savings.' );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( 0, $run['sent'], 'Neither counts as sent.' );
		$this->assertSame( 2, $run['passed'] );
	}

	/**
	 * Writes the plugin's output over an image put back for a redo, and its WebP
	 * copy, and records them as a finished group would.
	 *
	 * @param int    $id       The attachment's id.
	 * @param string $attached The attached file.
	 */
	protected function written_by_iminify( int $id, string $attached ): void {
		$this->squeeze( $attached );

		file_put_contents( $attached . '.webp', 'iminify-webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- the plugin's copy, as it writes it.
		clearstatcache();

		$bytes    = (int) filesize( $attached );
		$relative = (string) Paths::relative( $attached );
		$metadata = wp_get_attachment_metadata( $id );

		$metadata['filesize'] = $bytes;
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'redo'    => false,
				'written' => array( $relative => $bytes ),
				'copies'  => array(
					$relative . '.webp' => array(
						'for'       => $relative,
						'format'    => 'webp',
						'bytes'     => (int) filesize( $attached . '.webp' ),
						'origin'    => Records::ORIGIN,
						'for_bytes' => $bytes,
					),
				),
				'result'  => array(
					'level'   => 'smart',
					'formats' => array( 'webp' ),
					'outputs' => array(
						'full' => array(
							'before'  => $bytes + 100,
							'after'   => $bytes,
							'width'   => 640,
							'height'  => 480,
							'formats' => array( 'webp' ),
						),
					),
					'sizes'   => array_keys( wp_get_registered_image_subsizes() ),
				),
			)
		);
	}

	/**
	 * An attachment with no record or run of its own yet, whatever its upload queued.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function fresh( int $id ): int {
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		delete_post_meta( $id, Records::META );
		delete_post_meta( $id, Records::STATUS_META );

		return $id;
	}
}
