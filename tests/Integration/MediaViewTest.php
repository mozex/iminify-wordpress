<?php
/**
 * The media library's column, filters, row actions, panel and routes, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers each state's words, what each filter lists, who may press what, and
 * that optimizing again goes back to the original first.
 */
class MediaViewTest extends WP_UnitTestCase {

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
		Pause::lift();
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		Plugin::keys()->forget();
		Site_Record::forget();
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_the_column_says_where_each_image_stands_and_stays_empty_for_anything_else(): void {
		$id = $this->attachment();

		Records::update(
			$id,
			array(
				'status' => Records::OPTIMIZED,
				'copies' => array(
					'a.jpg.webp' => array(
						'format' => 'webp',
						'bytes'  => 1,
						'origin' => Records::ORIGIN,
					),
				),
				'result' => array(
					'level'   => 'smart',
					'formats' => array( 'webp' ),
					'skipped' => 2,
					'outputs' => array(
						'full'   => array(
							'before'  => 1000,
							'after'   => 600,
							'width'   => 640,
							'height'  => 480,
							'formats' => array( 'webp' ),
						),
						'medium' => array(
							'before'  => 1000,
							'after'   => 600,
							'width'   => 300,
							'height'  => 225,
							'formats' => array(),
						),
					),
				),
			)
		);

		$cell = Media_View::cell( $id );

		$this->assertStringContainsString( 'iminify-status-optimized', $cell );
		$this->assertStringContainsString( 'Saved 40%', $cell );
		$this->assertStringContainsString( 'across 2 files', $cell );
		$this->assertStringContainsString( 'Copies: WebP', $cell );
		$this->assertStringContainsString( '2 files couldn&#039;t be written', $cell );
		$this->assertStringNotContainsString( 'data-iminify-busy', $cell );

		$panel = Media_View::panel( $id );

		$this->assertStringContainsString( 'Level: Smart', $panel );
		$this->assertStringContainsString( 'Full size', $panel );
		$this->assertStringContainsString( '<small dir="ltr">640 × 480</small>', $panel );
		$this->assertStringContainsString( 'Delete the WebP and AVIF copies', $panel );
		$this->assertStringContainsString( 'data-iminify-level="ultra"', $panel );
		$this->assertStringNotContainsString( 'data-iminify-level="none"', $panel );
		$this->assertStringContainsString( 'iminify-chip--success', $cell );
		$this->assertStringContainsString( 'iminify-saving__bar', $cell );
		/* A level button on an optimized image asks first, and its confirm goes ahead as "Optimize again", not as the level's name. */
		$this->assertMatchesRegularExpression( '/data-iminify-yes="Optimize again" data-iminify-level="ultra"/', $panel );
		$this->assertStringContainsString( 'data-iminify-yes="Delete the copies"', $panel );

		/* The service's copies came out heavier: saying "saved 0%" would read as a failure. */
		Records::update(
			$id,
			array(
				'result' => array(
					'outputs' => array(
						'full' => array(
							'before'  => 1000,
							'after'   => 1000,
							'width'   => 640,
							'height'  => 480,
							'formats' => array(),
						),
					),
				),
			)
		);
		$this->assertStringContainsString( 'already smaller', Media_View::cell( $id ) );

		/* Waiting, with when it goes out and that the script should ask after it. */
		Pause::refused( 3600, 'free' );
		Records::wait( $id, Records::PAUSED, 'day_spent', 'Waiting for the next batch.' );
		$this->assertStringContainsString( 'Waiting for the next batch', Media_View::cell( $id ) );
		$this->assertStringContainsString( 'goes out by itself on', Media_View::cell( $id ) );

		Records::update( $id, array( 'status' => Records::QUEUED ) );
		$this->assertStringContainsString( 'data-iminify-busy="1"', Media_View::cell( $id ) );

		Records::fail( $id, Records::ERROR, 'outputs_refused', 'A group makes at most 30 outputs.' );
		$this->assertStringContainsString( 'A group makes at most 30 outputs.', Media_View::cell( $id ) );

		/* A PDF has nothing to do with the plugin. */
		$pdf = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
		$this->assertFalse( Media_View::handles( $pdf ) );
		ob_start();
		Media_View::print_column( Media_View::COLUMN, $pdf );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_each_filter_lists_its_own_images(): void {
		$optimized = $this->attachment();
		$missing   = $this->attachment();
		$failed    = $this->attachment();
		$skipped   = $this->attachment();
		$paused    = $this->attachment();
		$parked    = $this->attachment();
		$refused   = $this->attachment();
		$queued    = $this->attachment();
		$unknown   = $this->attachment();
		$pdf       = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );

		Records::wait( $parked, Records::PARKED, 'unauthenticated', '' );
		Records::fail( $refused, Records::NOT_OPTIMIZED, 'disk_full', '' );
		Records::update( $queued, array( 'status' => Records::WAITING ) );

		delete_post_meta( $unknown, Records::META );
		delete_post_meta( $unknown, Records::STATUS_META );

		Records::update(
			$optimized,
			array(
				'status' => Records::OPTIMIZED,
				'result' => array( 'formats' => array( 'webp', 'avif' ) ),
			)
		);
		Records::update(
			$missing,
			array(
				'status' => Records::OPTIMIZED,
				'result' => array( 'formats' => array( 'webp' ) ),
			)
		);
		Records::fail( $failed, Records::ERROR, 'x', 'x' );
		Records::fail( $skipped, Records::SKIPPED, 'x', 'x' );
		Records::wait( $paused, Records::PAUSED, 'day_spent', '' );

		$this->assertEqualSets( array( $optimized, $missing ), $this->listed( Media_View::FILTER_OPTIMIZED ) );
		$this->assertEqualSets( array( $failed, $skipped ), $this->listed( Media_View::FILTER_ERRORS ) );
		$this->assertEqualSets( array( $paused, $parked, $refused, $queued, $unknown ), $this->listed( Media_View::FILTER_NOT_OPTIMIZED ) );
		$this->assertNotContains( $pdf, $this->listed( Media_View::FILTER_NOT_OPTIMIZED ) );

		/* Formats the site asks for now that the group wasn't asked for: AVIF, turned on later, but a heavier WebP left out isn't missing. */
		$site                             = Api_Stub::fixture( 'site' );
		$site['data']['settings']['avif'] = true;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::fetch();

		$this->assertSame( array( $missing ), $this->listed( Media_View::FILTER_MISSING ) );

		$site['data']['settings']['avif'] = false;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::fetch();

		$this->assertSame( array(), $this->listed( Media_View::FILTER_MISSING ) );

		$site['data']['settings']['webp'] = false;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::fetch();

		$this->assertSame( array(), $this->listed( Media_View::FILTER_MISSING ) );
	}

	public function test_the_row_offers_what_the_image_can_do_to_someone_who_can_edit_it(): void {
		$id   = $this->attachment();
		$post = get_post( $id );

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );

		$this->assertStringContainsString( '>Optimize<', Media_View::row_actions( array(), $post )['iminify_optimize'] );
		$this->assertArrayNotHasKey( 'iminify_restore', Media_View::row_actions( array(), $post ) );

		$this->optimize_by_hand( $id );

		$actions = Media_View::row_actions( array(), $post );

		$this->assertStringContainsString( '>Optimize again<', $actions['iminify_optimize'] );
		$this->assertStringContainsString( 'one more image', $actions['iminify_optimize'] );
		$this->assertStringContainsString( '>Restore original<', $actions['iminify_restore'] );

		/* On its way, it isn't sent twice. */
		Records::update( $id, array( 'status' => Records::WAITING ) );
		$this->assertSame( array(), Media_View::row_actions( array(), $post ) );

		/* Waiting for the batch, it goes out by itself. */
		Records::update( $id, array( 'status' => Records::PAUSED ) );
		$this->assertArrayNotHasKey( 'iminify_optimize', Media_View::row_actions( array(), $post ) );
		Records::update( $id, array( 'status' => Records::OPTIMIZED ) );

		/* Disconnected, restoring still works and optimizing doesn't. */
		Plugin::keys()->forget();
		$actions = Media_View::row_actions( array(), $post );
		$this->assertArrayNotHasKey( 'iminify_optimize', $actions );
		$this->assertArrayHasKey( 'iminify_restore', $actions );
		$this->assertStringContainsString( 'Connect the site', Media_View::panel( $id ) );

		/* Someone who can't edit it sees neither. */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), Media_View::row_actions( array(), $post ) );
		$this->assertArrayNotHasKey( 'iminify', Media_View::attachment_field( array(), $post ) );
	}

	public function test_the_routes_are_for_someone_who_can_upload_and_edit_that_attachment(): void {
		$id = $this->attachment();

		$routes = array(
			array( 'POST', "/iminify/v1/media/{$id}/optimize" ),
			array( 'POST', "/iminify/v1/media/{$id}/restore" ),
			array( 'DELETE', "/iminify/v1/media/{$id}/copies" ),
		);

		wp_set_current_user( 0 );

		foreach ( $routes as $route ) {
			$this->assertSame( 401, rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status() );
		}

		$this->assertSame( 401, $this->statuses( array( $id ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( $routes as $route ) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status() );
		}

		/* An author edits only their own uploads, and doesn't learn about another's through the status read. */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		foreach ( $routes as $route ) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status() );
		}

		$this->assertSame( array(), $this->statuses( array( $id ) )->get_data()['items'] );
		$this->assertSame( array(), $this->api->calls( 'GET', 'site' ) );

		/* A post's id isn't an attachment's. */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create();
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$post}/restore" ) )->get_status() );
		$this->assertSame( array(), $this->statuses( array( $post ) )->get_data()['items'] );

		/* A level the service doesn't have. */
		$request = new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" );
		$request->set_param( 'level', 'turbo' );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_optimizing_again_goes_back_to_the_original_first_and_sends_the_level_asked_for(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$original = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		$this->optimize_by_hand( $id );

		$request = new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" );
		$request->set_param( 'level', 'lossless' );
		$answer = rest_do_request( $request );

		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( $original, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the original put back.
		$this->assertSame( Records::QUEUED, Records::get( $id )['status'] );
		$this->assertSame( array(), Records::get( $id )['written'] );
		$this->assertTrue( $answer->get_data()['busy'] );
		$this->assertIsInt( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::MANUAL ), Optimizer::GROUP ) );

		/* It runs ahead of older work on the lane someone is waiting on, however old the image, naming its level once. */
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$body = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );

		$this->assertSame( 'lossless', $body['level'] );
		$this->assertArrayNotHasKey( 'priority', $body );

		/* An upload's next group follows the site's level again. */
		Records::bump( $id );
		$this->assertNull( Records::get( $id )['level'] );
	}

	public function test_optimizing_changes_nothing_when_the_service_cant_take_it_now(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );

		$this->optimize_by_hand( $id );

		$written                       = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads what the plugin wrote.
		$site                          = Api_Stub::fixture( 'site' );
		$site['data']['next_batch_at'] = gmdate( 'c', time() + 3600 );
		$this->api->on( 'GET', 'site', 200, $site );

		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" ) );

		$this->assertSame( 409, $answer->get_status() );
		$this->assertSame( 'day_spent', $answer->as_error()->get_error_code() );
		$this->assertSame( $written, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );

		/* An original past the plan's file size would go back and then not go out. */
		Pause::lift();
		$site                          = Api_Stub::fixture( 'site' );
		$site['data']['max_file_size'] = 10;
		$this->api->on( 'GET', 'site', 200, $site );

		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" ) );

		$this->assertSame( 'file_too_large', $answer->as_error()->get_error_code() );
		$this->assertSame( $written, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.

		/* So would one with no room to work in. */
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		add_filter( 'iminify_disk_free_space', '__return_zero' );

		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" ) );

		remove_filter( 'iminify_disk_free_space', '__return_zero' );

		$this->assertSame( 'disk_full', $answer->as_error()->get_error_code() );
		$this->assertSame( $written, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );

		/* No original, with backups off: optimizing would compress its own output. */
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}

		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" ) );

		$this->assertSame( 'no_original', $answer->as_error()->get_error_code() );
		$this->assertStringContainsString( 'can\'t be optimized again or restored', html_entity_decode( Media_View::panel( $id ), ENT_QUOTES ) );

		/* Not connected. */
		Plugin::keys()->forget();
		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/optimize" ) );
		$this->assertSame( 'not_connected', $answer->as_error()->get_error_code() );
	}

	public function test_restoring_and_deleting_the_copies_answer_the_new_markup(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );

		$this->optimize_by_hand( $id );

		$copy = $attached . '.webp';
		file_put_contents( $copy, 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		$other = $attached . '.avif';
		file_put_contents( $other, 'bytes someone else wrote' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		Records::update(
			$id,
			array(
				'copies' => array(
					$relative . '.webp' => array(
						'for'    => $relative,
						'format' => 'webp',
						'bytes'  => 4,
						'origin' => Records::ORIGIN,
					),
					$relative . '.avif' => array(
						'for'    => $relative,
						'format' => 'avif',
						'bytes'  => 4,
						'origin' => Records::ORIGIN,
					),
				),
				'result' => array(
					'formats' => array( 'webp', 'avif' ),
					'outputs' => array(
						'full' => array(
							'before'  => 2000,
							'after'   => 21,
							'width'   => 640,
							'height'  => 480,
							'formats' => array( 'webp' ),
						),
					),
				),
			)
		);

		$deleted = array();
		$heard   = static function ( $attachment ) use ( &$deleted ) {
			$deleted[] = $attachment;
		};
		add_action( 'iminify_copies_deleted', $heard );

		$answer = rest_do_request( new WP_REST_Request( 'DELETE', "/iminify/v1/media/{$id}/copies" ) );

		remove_action( 'iminify_copies_deleted', $heard );

		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( array( $id ), $deleted, 'So the page caches that name the copies are emptied.' );
		$this->assertFileDoesNotExist( $copy );

		/* A file at a copy's name with other bytes than the plugin wrote isn't the plugin's any more. */
		$this->assertFileExists( $other );
		$this->assertSame( array(), Records::get( $id )['copies'] );
		$this->assertSame( array(), Records::get( $id )['result']['formats'] );
		$this->assertSame( array(), Records::get( $id )['result']['outputs']['full']['formats'] );
		$this->assertSame( '||', get_post_meta( $id, Records::FORMATS_META, true ) );
		$this->assertStringNotContainsString( 'Delete the WebP', $answer->get_data()['panel'] );
		wp_delete_file( $other );

		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/restore" ) );

		$this->assertSame( 200, $answer->get_status() );
		$this->assertStringContainsString( 'Restored', $answer->get_data()['cell'] );

		/* Connected, and making the sizes again didn't send the image straight back. */
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );

		/* Nothing left to restore. */
		$answer = rest_do_request( new WP_REST_Request( 'POST', "/iminify/v1/media/{$id}/restore" ) );
		$this->assertSame( 'nothing_to_restore', $answer->as_error()->get_error_code() );
	}

	/**
	 * Reads the status of some attachments.
	 *
	 * @param list<int> $ids The attachments.
	 */
	protected function statuses( array $ids ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/iminify/v1/media' );
		$request->set_param( 'ids', $ids );

		return rest_do_request( $request );
	}

	/**
	 * The attachments a filter lists.
	 *
	 * @param string $filter The filter.
	 * @return list<int>
	 */
	protected function listed( string $filter ): array {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 50,
				),
				Media_View::query_for( $filter )
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Leaves an attachment as the plugin would have, its backup kept and its main file written over.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function optimize_by_hand( int $id ): void {
		$file    = (string) get_attached_file( $id );
		$backups = Backups::keep( $file, (string) Paths::relative( $file ), array() );
		$editor  = wp_get_image_editor( $file );

		$editor->set_quality( 5 );

		$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );

		copy( $saved['path'], $file );
		wp_delete_file( $saved['path'] );
		clearstatcache( true, $file );
		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( (string) Paths::relative( $file ) => (int) filesize( $file ) ),
				'backups' => (array) $backups,
				'result'  => array( 'outputs' => array() ),
			)
		);
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
	}

	/**
	 * A 640 by 480 JPEG uploaded to the media library.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}
}
