<?php
/**
 * What an optimized image lacks, and sending it for that, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Bulk_Controller;
use Iminify\WordPress\Admin\Bulk_State;
use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers which images read as lacking a format or a size, that a run finds exactly
 * the filter's images and never spends on one twice, and that an image sent for what
 * it lacks stays optimized, with its savings, however the sending ends.
 */
class MissingTest extends WP_UnitTestCase {

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
		delete_option( Connection::REVOKED_OPTION );
		$this->site(
			array(
				'webp' => true,
				'avif' => true,
			)
		);
		Site_Record::fetch();
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		delete_option( Bulk::OPTION );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();
		remove_image_size( 'iminify_wide' );
		remove_image_size( 'iminify_huge' );

		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}

		parent::tear_down();
	}

	public function test_a_run_for_formats_takes_the_filters_images_and_counts_them_with_one_query_each(): void {
		$lacks_avif = $this->optimized( array( 'webp' ) );
		$complete   = $this->optimized( array( 'webp', 'avif' ) );
		$lacks_both = $this->optimized( array() );
		$fresh      = $this->fresh();

		$this->assertSame( array( 'avif' ), Missing::of( $lacks_avif, Missing::FORMATS ) );
		$this->assertSame( array(), Missing::of( $complete, Missing::FORMATS ) );
		$this->assertSame( array( 'webp', 'avif' ), Missing::of( $lacks_both, Missing::FORMATS ) );
		$this->assertSame( array(), Missing::of( $fresh, Missing::FORMATS ) );
		$this->assertSame( 2, Bulk::counts()['missing_formats'] );

		$filter = new WP_Query(
			array_merge(
				Media_View::query_for( Media_View::FILTER_MISSING ),
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			)
		);

		$this->assertSame( array( $lacks_avif, $lacks_both ), array_map( 'intval', $filter->posts ) );
		$this->assertSame( array( $lacks_avif, $lacks_both ), Bulk::candidates( Bulk::FORMATS, 10, 0 )['ids'] );

		/* A site that asks for neither format has nothing missing, and asks the database nothing. */
		$this->site(
			array(
				'webp' => false,
				'avif' => false,
			)
		);
		Site_Record::fetch();

		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertSame( 0, Missing::count( Missing::FORMATS ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_a_size_registered_later_reads_as_missing_only_on_images_that_can_have_it(): void {
		$big   = $this->optimized( array( 'webp', 'avif' ) );
		$small = $this->optimized( array( 'webp', 'avif' ) );

		$this->assertSame( 0, Bulk::counts()['missing_sizes'] );

		add_image_size( 'iminify_wide', 400, 0 );

		$this->assertSame( 2, Bulk::counts()['missing_sizes'], 'Both may lack it, which only a look can tell.' );
		$this->assertSame( array( 'iminify_wide' ), Missing::of( $big, Missing::SIZES ) );

		/* The site sends only the sizes it names, whatever their case. */
		$this->site( array( 'sizes' => array( 'THUMBNAIL' ) ) );
		Site_Record::fetch();
		$this->assertSame( 0, Bulk::counts()['missing_sizes'] );
		$this->assertSame( array(), Missing::of( $big, Missing::SIZES ) );

		$this->site( array( 'sizes' => null ) );
		Site_Record::fetch();

		/* An image too small for a size is looked at once and never again, with no call to the service. */
		remove_image_size( 'iminify_wide' );
		add_image_size( 'iminify_huge', 5000, 5000 );
		$this->assertSame( 2, Bulk::counts()['missing_sizes'] );
		$this->assertSame( array(), Missing::of( $small, Missing::SIZES ) );

		Bulk::start( Bulk::SIZES, Bulk::BACKGROUND, array( $small ) );

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( 0, Bulk::run()['sent'] );
		$this->assertSame( 0, Bulk::run()['passed'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertStringContainsString( '|iminify_huge|', (string) get_post_meta( $small, Records::SIZES_META, true ) );
		$this->assertSame( array( $big ), Records::lacking( Records::SIZES_META, Missing::wanted_sizes(), 10 ), 'Only the image that was not looked at yet.' );
	}

	public function test_an_image_sent_for_a_missing_format_keeps_its_files_savings_and_level_and_gains_the_copy(): void {
		$id     = $this->optimized( array( 'webp' ) );
		$record = Records::get( $id );
		$saved  = Records::saved();
		$main   = (string) get_attached_file( $id );
		$bytes  = (int) filesize( $main );

		Records::update(
			$id,
			array(
				'result' => array_merge( $record['result'], array( 'level' => 'ultra' ) ),
			)
		);

		$this->assertTrue( Optimizer::fill( $id, Missing::FORMATS ) );
		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertSame( Missing::FORMATS, Records::get( $id )['fill'] );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$opened = json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );

		$this->assertSame( 'ultra', $opened['level'], 'It keeps the level it has, so its files are not replaced for a level nobody chose.' );
		$this->assertSame( (int) filesize( $main ), $opened['outputs'][0]['size'] );
		$this->assertGreaterThan( 1, count( $opened['outputs'] ), 'A missing format is made of every size.' );

		$heard = array();

		add_action(
			'iminify_image_optimized',
			static function ( int $attachment, array $result ) use ( &$heard ): void {
				$heard[] = $attachment;
				unset( $result );
			},
			10,
			2
		);

		$answer = $this->answer_without_replacing( $id, array( 'avif' ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );

		Optimizer::check( $id );

		$after = Records::get( $id );

		$this->assertSame( Records::OPTIMIZED, $after['status'] );
		$this->assertNull( $after['fill'] );
		$this->assertNull( $after['level'] );
		$this->assertSame( $bytes, (int) filesize( $main ), 'A file the service did not better stays.' );
		$this->assertSame( array( 'webp', 'avif' ), $after['result']['formats'] );
		$this->assertSame( 'ultra', $after['result']['level'] );
		$this->assertSame( $saved, Records::saved(), 'What the image saved stands.' );
		$this->assertSame( $heard, array( $id ) );
		$this->assertContains( 'avif', $after['result']['outputs']['full']['formats'] );
		$this->assertContains( 'webp', $after['result']['outputs']['full']['formats'] );
		$this->assertFileExists( $main . '.avif' );
		$this->assertArrayHasKey( Paths::relative( $main ) . '.avif', $after['copies'] );

		/* Nothing is left for a second run, which spends nothing. */
		$this->assertSame( array(), Missing::of( $id, Missing::FORMATS ) );
		$this->assertInstanceOf( 'WP_Error', Bulk::start( Bulk::FORMATS, Bulk::BACKGROUND ) );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		remove_all_actions( 'iminify_image_optimized' );
	}

	public function test_a_webp_source_never_reads_as_lacking_webp(): void {
		$id = $this->fresh();

		Records::update( $id, array( 'status' => Records::QUEUED ) );
		Records::update(
			$id,
			array(
				'group' => array(
					'id'         => self::GROUP,
					'generation' => Records::get( $id )['generation'],
					'outputs'    => array(),
					'source'     => '',
					'type'       => 'image/webp',
				),
			)
		);

		/* The service answers a WebP source's own format as `original`, so it never names `webp`. */
		$answer                        = $this->answer_without_replacing( $id, array() );
		$answer['settings']['formats'] = array( 'original', 'avif' );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );

		Records::update( $id, array( 'group' => array_merge( Records::get( $id )['group'], array( 'sent' => true ) ) ) );
		Optimizer::check( $id );

		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), Missing::of( $id, Missing::FORMATS ) );
		$this->assertSame( 0, Bulk::counts()['missing_formats'] );
	}

	public function test_an_image_sent_for_a_missing_size_declares_only_that_size_with_no_bytes_and_stays_optimized(): void {
		$id    = $this->optimized( array( 'webp' ) );
		$saved = Records::saved();

		add_image_size( 'iminify_wide', 400, 0 );

		$run = Bulk::start( Bulk::SIZES, Bulk::PAGE );

		$this->assertIsArray( $run );
		$this->assertSame( Optimizer::BULK, Records::get( $id )['trigger'] );

		Optimizer::optimize( $id, Optimizer::BULK );

		$metadata = wp_get_attachment_metadata( $id );

		$this->assertArrayHasKey( 'iminify_wide', $metadata['sizes'], 'WordPress made the size.' );

		$opened = json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$names  = array_column( $opened['outputs'], 'name' );

		$this->assertSame( array( 'full', 'iminify_wide' ), $names, 'Only the main file, which a group always makes, and what is missing.' );
		$this->assertArrayNotHasKey( 'size', $opened['outputs'][1], 'A size WordPress just made names no bytes, so the file made from the original always replaces it.' );

		$answer = $this->answer_without_replacing( $id, array( 'iminify_wide' => 'original' ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		/* The service's file from the original can weigh more than what WordPress made from the plugin's output. */
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer, array( 'iminify_wide' => 100 ) ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );

		Optimizer::check( $id );

		$after = Records::get( $id );

		$this->assertSame( Records::OPTIMIZED, $after['status'] );
		$this->assertArrayHasKey( 'iminify_wide', $after['result']['outputs'] );
		$this->assertGreaterThan( (int) $metadata['sizes']['iminify_wide']['filesize'], $after['result']['outputs']['iminify_wide']['after'], 'The heavier file replaced the one WordPress made.' );
		$this->assertSame( $after['result']['outputs']['iminify_wide']['after'], $after['result']['outputs']['iminify_wide']['before'], 'A size with no bytes of its own claims no saving, and no loss.' );
		$this->assertArrayHasKey( 'thumbnail', $after['result']['outputs'], 'The sizes it had stay in its record.' );
		$this->assertSame( array(), Missing::of( $id, Missing::SIZES ) );
		$this->assertSame( array( 'avif' ), Missing::of( $id, Missing::FORMATS ), 'Its other sizes still lack the AVIF only the new size got.' );
		$this->assertSame( array(), $after['made'] );
		$this->assertSame( 0, Bulk::counts()['missing_sizes'] );
		$this->assertGreaterThanOrEqual( $saved['before'], Records::saved()['before'] );

		/* The plugin's own hooks ignored WordPress making the size, so nothing was queued again. */
		$this->assertFalse( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ) ) );
	}

	public function test_a_size_made_from_a_true_original_names_its_bytes_and_one_made_from_the_output_never_does_even_on_a_retry(): void {
		$written   = $this->optimized( array( 'webp', 'avif' ) );
		$untouched = $this->optimized( array( 'webp', 'avif' ) );

		add_image_size( 'iminify_wide', 400, 0 );

		/* The main file is the plugin's: WordPress makes the size from it, so the size names no bytes, in every try. */
		$this->api->on( 'POST', 'groups', 429, array( 'code' => 'rate_limited', 'message' => 'Busy.' ), array( 'Retry-After' => '30' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's answer.

		$this->assertTrue( Optimizer::fill( $written, Missing::SIZES ) );
		Optimizer::optimize( $written, Optimizer::MANUAL );

		$this->assertSame( array( 'iminify_wide' ), Records::get( $written )['made'] );
		$this->assertSame( Records::QUEUED, Records::get( $written )['status'], 'It waits for its next try.' );

		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		Optimizer::optimize( $written, Optimizer::MANUAL );

		$retried = $this->api->calls( 'POST', 'groups' );
		$body    = json_decode( (string) end( $retried )['args']['body'], true );

		$this->assertSame( array( 'full', 'iminify_wide' ), array_column( $body['outputs'], 'name' ) );
		$this->assertArrayNotHasKey( 'size', $body['outputs'][1], 'The retry knows WordPress made it from the plugin\'s output.' );

		/* The main file is still the upload: what WordPress makes from it stands against the service's file. */
		Records::update( $untouched, array( 'written' => array() ) );
		Optimizer::cancel( $written );
		Records::end_fill( $written );

		$this->assertTrue( Optimizer::fill( $untouched, Missing::SIZES ) );
		Optimizer::optimize( $untouched, Optimizer::MANUAL );

		$calls = $this->api->calls( 'POST', 'groups' );
		$body  = json_decode( (string) end( $calls )['args']['body'], true );

		$this->assertSame( array( 'full', 'iminify_wide' ), array_column( $body['outputs'], 'name' ) );
		$this->assertArrayHasKey( 'size', $body['outputs'][1] );
	}

	public function test_a_size_no_group_can_take_settles_the_image_without_a_call(): void {
		$id = $this->optimized( array( 'webp', 'avif' ) );

		add_image_size( 'iminify_wide', 400, 0 );

		/* The new size's entry names the main file, which a group already makes: nothing is left to send. */
		$metadata                          = wp_get_attachment_metadata( $id );
		$metadata['sizes']['iminify_wide'] = array(
			'file'      => wp_basename( (string) get_attached_file( $id ) ),
			'width'     => 400,
			'height'    => 300,
			'mime-type' => 'image/jpeg',
		);
		wp_update_attachment_metadata( $id, $metadata );
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		Records::update( $id, array( 'status' => Records::OPTIMIZED ) );

		$this->assertSame( array( 'iminify_wide' ), Missing::of( $id, Missing::SIZES ) );
		$this->assertTrue( Optimizer::fill( $id, Missing::SIZES ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), Missing::of( $id, Missing::SIZES ) );
	}

	public function test_restoring_named_images_takes_back_only_theirs_and_a_trashed_image_lacks_nothing(): void {
		$one = $this->optimized( array( 'webp' ) );
		$two = $this->optimized( array( 'webp' ) );

		Optimizer::send_for_missing( $one, Missing::FORMATS, Optimizer::MANUAL );
		Optimizer::send_for_missing( $two, Missing::FORMATS, Optimizer::MANUAL );

		$run = Bulk::start( Bulk::RESTORE, Bulk::PAGE, array( $one ) );

		$this->assertIsArray( $run );
		$this->assertSame( 1, $run['total'] );
		$this->assertSame( Records::QUEUED, Records::get( $two )['status'] );
		$this->assertSame( Missing::FORMATS, Records::get( $two )['fill'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $two, Optimizer::MANUAL ) ) );

		/* Media trash on: a trashed image is never sent for what it lacks. */
		delete_option( Bulk::OPTION );
		Optimizer::cancel( $two );
		Records::end_fill( $two );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $two ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test's stand-in for media trash.
		clean_post_cache( $two );

		$this->assertSame( 1, Missing::count( Missing::FORMATS ), 'The first image, never restored here; the second is in the trash.' );
		$this->assertSame( array( $one ), Missing::images( Missing::FORMATS, 10, 0 ) );
	}

	public function test_an_image_a_filter_leaves_out_after_it_was_queued_is_set_aside_and_never_stays_queued(): void {
		$fresh  = $this->fresh();
		$filled = $this->optimized( array( 'webp' ) );

		Optimizer::queue( $fresh, Optimizer::BULK, 0 );
		Optimizer::send_for_missing( $filled, Missing::FORMATS, Optimizer::BULK );

		add_filter( 'iminify_optimize_image', '__return_false' );
		Optimizer::optimize( $fresh, Optimizer::BULK );
		Optimizer::optimize( $filled, Optimizer::BULK );
		remove_filter( 'iminify_optimize_image', '__return_false' );

		$this->assertSame( Records::SKIPPED, Records::get( $fresh )['status'] );
		$this->assertSame( 'left_out', Records::get( $fresh )['error']['code'] );
		$this->assertSame( Records::OPTIMIZED, Records::get( $filled )['status'], 'An image sent for what it lacked is still optimized.' );
		$this->assertSame( 'left_out', Records::get( $filled )['error']['code'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
	}

	public function test_a_size_the_site_only_now_sends_goes_with_its_bytes(): void {
		$this->site( array( 'sizes' => array( 'thumbnail' ) ) );
		Site_Record::fetch();

		$id = $this->optimized( array( 'webp', 'avif' ) );

		$this->site( array( 'sizes' => null ) );
		Site_Record::fetch();

		$this->assertSame( array( 'medium' ), Missing::of( $id, Missing::SIZES ) );
		$this->assertTrue( Optimizer::fill( $id, Missing::SIZES ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$opened = json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );

		$this->assertSame( array( 'full', 'medium' ), array_column( $opened['outputs'], 'name' ) );
		$this->assertArrayHasKey( 'size', $opened['outputs'][1], 'A file WordPress made from the original stands against the service\'s.' );
	}

	/**
	 * However the sending ends, the image reads optimized, keeps its savings, and Restore all finds it.
	 *
	 * @dataProvider endings
	 *
	 * @param string $ending How the sending ends.
	 */
	public function test_an_image_sent_for_what_it_lacks_stays_optimized_however_that_ends( string $ending ): void {
		$id     = $this->optimized( array( 'webp' ) );
		$other  = $this->optimized( array( 'webp', 'avif' ) );
		$saved  = Records::saved();
		$result = Records::get( $id )['result'];

		Bulk::start( Bulk::FORMATS, Bulk::BACKGROUND );

		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );

		switch ( $ending ) {
			case 'stop':
				Bulk::stop();
				break;

			case 'refusal':
				$this->api->on(
					'POST',
					'groups',
					422,
					array(
						'message' => 'Refused.',
						'code'    => 'outputs_refused',
					)
				);
				Optimizer::optimize( $id, Optimizer::BULK );
				$this->assertSame( 'outputs_refused', Records::get( $id )['error']['code'] );
				$this->assertStringContainsString( 'Refused.', Media_View::cell( $id ), 'The reason shows beside the image.' );
				break;

			case 'no room':
				add_filter( 'iminify_disk_free_space', '__return_zero' );
				Optimizer::optimize( $id, Optimizer::BULK );
				remove_filter( 'iminify_disk_free_space', '__return_zero' );
				break;

			case 'restore all, while it waits for the next batch':
				Bulk::stop();
				Records::update(
					$id,
					array(
						'status'  => Records::PAUSED,
						'fill'    => Missing::FORMATS,
						'trigger' => Optimizer::MANUAL,
					)
				);
				break;
		}

		if ( 'restore all, while it waits for the next batch' !== $ending ) {
			$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'], $ending );
			$this->assertNull( Records::get( $id )['fill'], $ending );
			$this->assertSame( $result['outputs'], Records::get( $id )['result']['outputs'], $ending );
			$this->assertSame( $saved, Records::saved(), $ending );
			$this->assertSame( array( 'avif' ), Missing::of( $id, Missing::FORMATS ), 'It is still lacking, for the next run.' );
		}

		/* Restore all reaches it whichever way it ended. */
		delete_option( Bulk::OPTION );

		$run = Bulk::start( Bulk::RESTORE, Bulk::PAGE );

		$this->assertIsArray( $run );
		$this->assertSame( 2, $run['total'], $ending );
		$this->assertSame( array( $id, $other ), Bulk::candidates( Bulk::RESTORE, 10, 0 )['ids'], $ending );
	}

	/**
	 * How the sending of an image can end.
	 *
	 * @return array<string, array{string}>
	 */
	public function endings(): array {
		return array(
			'a stop'        => array( 'stop' ),
			'a refusal'     => array( 'refusal' ),
			'no room'       => array( 'no room' ),
			'a waiting one' => array( 'restore all, while it waits for the next batch' ),
		);
	}

	public function test_an_image_with_no_original_is_passed_over_and_the_run_says_so(): void {
		$id = $this->optimized( array( 'webp' ) );

		foreach ( Records::get( $id )['backups'] as $backup ) {
			wp_delete_file( Paths::absolute( $backup ) );
		}

		wp_delete_file( Paths::backup( (string) Paths::relative( (string) get_attached_file( $id ) ) ) );

		$run = Bulk::start( Bulk::FORMATS, Bulk::BACKGROUND );

		$this->assertIsArray( $run );

		$after = Bulk::run();

		$this->assertSame( 0, $after['sent'] );
		$this->assertSame( 0, $after['total'] );
		$this->assertSame( 1, $after['passed'] );
		$this->assertSame( Bulk::DONE, $after['status'] );
		$this->assertSame( 1, Bulk_State::build()['run']['passed'] );
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
	}

	public function test_a_person_is_asked_to_have_rights_on_the_image_and_refused_what_it_does_not_lack(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$id     = $this->optimized( array( 'webp' ) );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $editor,
			)
		);

		wp_set_current_user( $author );
		$this->assertSame( 403, $this->press( $id, Missing::FORMATS )->get_status() );
		$this->assertFalse( isset( Media_View::row_actions( array(), get_post( $id ) )['iminify_missing_formats'] ) );

		wp_set_current_user( $editor );
		$this->assertArrayHasKey( 'iminify_missing_formats', Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertStringContainsString( 'Missing formats: AVIF', Media_View::cell( $id ) );
		$this->assertStringContainsString( 'data-iminify-what="formats"', Media_View::panel( $id ) );

		$this->assertSame( 200, $this->press( $id, Missing::FORMATS )->get_status() );
		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertSame( Optimizer::MANUAL, Records::get( $id )['trigger'] );

		/* A size it doesn't lack spends nothing. */
		Records::end_fill( $id );

		$refused = $this->press( $id, Missing::SIZES );

		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'nothing_missing', $refused->get_data()['code'] );
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
	}

	public function test_the_capability_a_part_asks_for_can_be_changed_and_an_image_can_be_left_out(): void {
		$id = $this->optimized( array( 'webp' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( ( new Bulk_Controller() )->can_manage() );
		$this->assertFalse( Capabilities::can( Capabilities::SETTINGS ) );
		$this->assertTrue( Capabilities::can_change( $id ) );

		$open = static function ( string $capability, string $context ): string {
			return Capabilities::BULK === $context ? 'edit_others_posts' : $capability;
		};

		add_filter( 'iminify_capability', $open, 10, 2 );
		$this->assertTrue( ( new Bulk_Controller() )->can_manage() );
		$this->assertFalse( Capabilities::can( Capabilities::SETTINGS ) );
		remove_filter( 'iminify_capability', $open );

		$this->assertTrue( Optimizer::is_optimizable( $id ) );

		$leave = static function ( bool $optimize, int $attachment ) use ( $id ): bool {
			return $attachment === $id ? false : $optimize;
		};

		add_filter( 'iminify_optimize_image', $leave, 10, 2 );
		$this->assertFalse( Optimizer::is_optimizable( $id ) );
		$this->assertFalse( Missing::can_fill( $id ) );
		remove_filter( 'iminify_optimize_image', $leave );
	}

	public function test_the_ends_of_an_image_and_of_a_run_are_told(): void {
		$id    = $this->optimized( array( 'webp' ) );
		$heard = array();

		add_action(
			'iminify_image_restored',
			static function ( int $attachment ) use ( &$heard ): void {
				$heard[] = array( 'restored', $attachment );
			}
		);
		add_action(
			'iminify_bulk_finished',
			static function ( array $run ) use ( &$heard ): void {
				$heard[] = array( 'finished', $run['action'] );
			}
		);

		Bulk::start( Bulk::RESTORE, Bulk::PAGE );
		Bulk::step();
		Bulk::step();

		$this->assertSame(
			array(
				array( 'restored', $id ),
				array( 'finished', Bulk::RESTORE ),
			),
			$heard
		);

		remove_all_actions( 'iminify_image_restored' );
		remove_all_actions( 'iminify_bulk_finished' );
	}

	public function test_the_grid_narrows_its_list_to_a_known_filter_alone(): void {
		$_REQUEST['query'] = array( Media_View::QUERY => Media_View::FILTER_OPTIMIZED );
		$narrowed          = Media_View::apply_grid_filter( array( 'post_type' => 'attachment' ) );

		$this->assertArrayHasKey( 'meta_query', $narrowed );
		$this->assertSame( 'attachment', $narrowed['post_type'] );

		$_REQUEST['query'] = array( Media_View::QUERY => 'nothing_known' );
		$this->assertSame( array( 'post_type' => 'attachment' ), Media_View::apply_grid_filter( array( 'post_type' => 'attachment' ) ) );

		unset( $_REQUEST['query'] );
		$this->assertSame( array( 'post_type' => 'attachment' ), Media_View::apply_grid_filter( array( 'post_type' => 'attachment' ) ) );
	}

	/**
	 * A press of the media library's button.
	 *
	 * @param int    $id   The attachment's id.
	 * @param string $what What is missing.
	 */
	protected function press( int $id, string $what ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/iminify/v1/media/' . $id . '/missing' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'what' => $what ) ) );

		return rest_do_request( $request );
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
	 * An image the plugin optimized, with a backup of its main file, asked for these formats.
	 *
	 * @param list<string> $formats The formats its group was asked for.
	 * @param string       $image   The test image.
	 */
	protected function optimized( array $formats, string $image = 'canola.jpg' ): int {
		$id   = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $image );
		$file = (string) get_attached_file( $id );

		as_unschedule_all_actions( '', array(), Optimizer::GROUP );

		foreach ( Records::ALL_META as $key ) {
			delete_post_meta( $id, $key );
		}

		$backups = Backups::keep( $file, (string) Paths::relative( $file ), array() );
		$this->assertIsArray( $backups );

		$metadata = (array) wp_get_attachment_metadata( $id );
		$outputs  = array(
			'full' => array(
				'before'  => 400000,
				'after'   => (int) filesize( $file ),
				'width'   => (int) $metadata['width'],
				'height'  => (int) $metadata['height'],
				'formats' => array_slice( $formats, 0, 1 ),
			),
		);

		foreach ( $metadata['sizes'] ?? array() as $name => $size ) {
			$outputs[ $name ] = array(
				'before'  => 90000,
				'after'   => (int) filesize( dirname( $file ) . '/' . $size['file'] ),
				'width'   => (int) $size['width'],
				'height'  => (int) $size['height'],
				'formats' => array_slice( $formats, 0, 1 ),
			);
		}

		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( (string) Paths::relative( $file ) => (int) filesize( $file ) ),
				'backups' => $backups,
				'result'  => array(
					'level'   => 'smart',
					'formats' => $formats,
					'outputs' => $outputs,
					'sizes'   => Missing::wanted_sizes(),
				),
			)
		);

		return $id;
	}


	/**
	 * The service's answer for an open group that betters none of the files the
	 * image has, and makes what is named: a format's copy of the main file, or a size.
	 *
	 * @param int                  $id     The attachment's id.
	 * @param array<mixed, string> $making Formats by position, or formats by size.
	 * @return array<string, mixed>
	 */
	protected function answer_without_replacing( int $id, array $making ): array {
		$answer                        = Api_Stub::fixture( 'group' )['data'];
		$answer['outputs']             = array();
		$answer['settings']['level']   = Records::get( $id )['level'] ?? 'smart';
		$answer['settings']['formats'] = array( 'original', 'webp', 'avif' );

		foreach ( Records::get( $id )['group']['outputs'] as $name => $output ) {
			$formats = isset( $making[ $name ] ) ? array( $making[ $name ] ) : array();

			if ( 'full' === $name ) {
				$formats = array_merge( $formats, array_filter( $making, 'is_int', ARRAY_FILTER_USE_KEY ) );
			}

			foreach ( array( 'original', 'webp', 'avif' ) as $format ) {
				$replace             = in_array( $format, $formats, true );
				$answer['outputs'][] = array(
					'name'    => $name,
					'format'  => $format,
					'status'  => 'completed',
					'file'    => $replace ? $name . '.' . ( 'original' === $format ? 'jpg' : $format ) : null,
					'width'   => $output['width'],
					'height'  => $output['height'],
					'size'    => 1,
					'replace' => $replace,
				);
			}
		}

		return $answer;
	}

	/**
	 * A zip holding every file the answer marks `replace`, as the service's would.
	 *
	 * @param array<string, mixed> $answer  The service's answer.
	 * @param array<string, int>   $quality The quality of an output's files, by name, where it isn't the editor's.
	 */
	protected function zip( array $answer, array $quality = array() ): string {
		$path    = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$archive->addFromString( 'manifest.csv', 'output' );

		foreach ( $answer['outputs'] as $output ) {
			if ( ! $output['replace'] ) {
				continue;
			}

			$editor = wp_get_image_editor( DIR_TESTDATA . '/images/canola.jpg' );
			$editor->resize( $output['width'], $output['height'], true );

			if ( isset( $quality[ $output['name'] ] ) ) {
				$editor->set_quality( $quality[ $output['name'] ] );
			}

			$type  = 'original' === $output['format'] ? 'image/jpeg' : 'image/' . $output['format'];
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.' . pathinfo( $output['file'], PATHINFO_EXTENSION ), $type );

			$archive->addFromString( $output['file'], (string) file_get_contents( $saved['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
			wp_delete_file( $saved['path'] );
		}

		$archive->close();

		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test zip.
		wp_delete_file( $path );

		return $bytes;
	}

	/**
	 * The site's record, with these settings.
	 *
	 * @param array<string, mixed> $settings The settings to change.
	 */
	protected function site( array $settings ): void {
		$site                     = Api_Stub::fixture( 'site' );
		$site['data']['settings'] = array_merge( $site['data']['settings'], array( 'optimize_on_upload' => false ), $settings );
		$this->api->on( 'GET', 'site', 200, $site );
	}
}
