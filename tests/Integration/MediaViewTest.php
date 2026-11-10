<?php
/**
 * The media library's column, filters, row actions, panel and routes, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Media\Alt_Text;
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
						'medium'       => array(
							'before'  => 1000,
							'after'   => 600,
							'width'   => 300,
							'height'  => 225,
							'formats' => array(),
						),
						'medium_large' => array(
							'before'  => 1000,
							'after'   => 600,
							'width'   => 640,
							'height'  => 360,
							'formats' => array(),
						),
						'full'         => array(
							'before'  => 1000,
							'after'   => 600,
							'width'   => 640,
							'height'  => 480,
							'formats' => array( 'webp' ),
						),
					),
				),
			)
		);

		$cell = Media_View::cell( $id );

		$this->assertStringContainsString( 'iminify-status-optimized', $cell );
		$this->assertStringContainsString( 'Saved <bdi>40%</bdi>', $cell );
		$this->assertStringContainsString( 'across 3 files', $cell );
		$this->assertStringContainsString( 'Copies: WebP', $cell );
		$this->assertStringContainsString( '2 files couldn&#039;t be written', $cell );
		$this->assertStringNotContainsString( 'data-iminify-busy', $cell );

		$panel = Media_View::panel( $id );

		$this->assertStringContainsString( 'Level: Smart', $panel );
		$this->assertStringContainsString( 'Full Size', $panel );
		$this->assertStringContainsString( '<small dir="ltr">640 × 480</small>', $panel );
		$this->assertStringContainsString( 'Delete the copies', $panel );
		$this->assertStringNotContainsString( 'iminify-btn--danger', $panel );
		/* Widest first, under the names the editor gives them, and a size it doesn't offer written out. */
		$this->assertMatchesRegularExpression( '/Full Size.*Medium Large.*Medium<br>/s', $panel );
		$this->assertStringNotContainsString( 'medium_large', $panel );
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
		Records::wait(
			$id,
			Records::PAUSED,
			array(
				'code'    => 'day_spent',
				'message' => 'Waiting for the next batch.',
			)
		);
		$this->assertStringContainsString( 'Waiting for the next batch', Media_View::cell( $id ) );
		$this->assertStringContainsString( 'goes out by itself on', Media_View::cell( $id ) );

		Records::update( $id, array( 'status' => Records::QUEUED ) );
		$this->assertStringContainsString( 'data-iminify-busy="1"', Media_View::cell( $id ) );

		Records::fail(
			$id,
			Records::ERROR,
			array(
				'code'    => 'outputs_refused',
				'message' => 'A group makes at most 30 outputs.',
			)
		);
		$this->assertStringContainsString( 'A group makes at most 30 outputs.', Media_View::cell( $id ) );

		/* A PDF has nothing to do with the plugin. */
		$pdf = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
		$this->assertFalse( Media_View::handles( $pdf ) );
		ob_start();
		Media_View::print_column( Media_View::COLUMN, $pdf );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_panel_says_where_an_alt_text_stands_and_says_nothing_when_none_was_asked_for(): void {
		$id = $this->attachment();

		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );
		$this->assertStringNotContainsString( 'iminify-alt-state', Media_View::panel( $id ) );

		$lines = array(
			Alt_Text::WAITING => 'being written',
			Alt_Text::WRITTEN => 'written by Iminify',
			Alt_Text::KEPT    => 'yours was kept',
			Alt_Text::FAILED  => 'write one for this image',
			Alt_Text::SKIPPED => 'used up',
		);

		foreach ( array( Records::NOT_OPTIMIZED, Records::OPTIMIZED ) as $standing ) {
			foreach ( $lines as $status => $line ) {
				Records::update(
					$id,
					array(
						'status' => $standing,
						'result' => array(
							'level'   => 'smart',
							'formats' => array(),
							'outputs' => array(
								'full' => array(
									'width'  => 10,
									'height' => 10,
									'before' => 10,
									'after'  => 5,
								),
							),
						),
						'alt'    => array(
							'status' => $status,
							'since'  => time(),
						),
					)
				);

				$this->assertStringContainsString( $line, Media_View::panel( $id ) );
			}
		}
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

		Records::wait(
			$parked,
			Records::PARKED,
			array(
				'code'    => 'unauthenticated',
				'message' => '',
			)
		);
		Records::fail(
			$refused,
			Records::NOT_OPTIMIZED,
			array(
				'code'    => 'disk_full',
				'message' => '',
			)
		);
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
		Records::fail(
			$failed,
			Records::ERROR,
			array(
				'code'    => 'x',
				'message' => 'x',
			)
		);
		Records::fail(
			$skipped,
			Records::SKIPPED,
			array(
				'code'    => 'x',
				'message' => 'x',
			)
		);
		Records::wait(
			$paused,
			Records::PAUSED,
			array(
				'code'    => 'day_spent',
				'message' => '',
			)
		);

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
			array( 'GET', "/iminify/v1/media/{$id}/compare" ),
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
		$this->assertStringNotContainsString( 'Delete the copies', $answer->get_data()['panel'] );
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

	public function test_the_panel_compares_the_original_with_each_file_the_plugin_made_from_it(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );

		$this->optimize_by_hand( $id );

		$webp = wp_get_image_editor( $attached )->save( $attached . '.webp', 'image/webp' );
		$this->assertIsArray( $webp, 'The test site makes WebP.' );
		file_put_contents( $attached . '.avif', 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a copy no browser could draw.
		$backup = Paths::backup( $relative );

		Records::update(
			$id,
			array(
				'copies' => array(
					$relative . '.webp' => array(
						'for'       => $relative,
						'format'    => 'webp',
						'bytes'     => (int) filesize( $attached . '.webp' ),
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( $attached ),
					),
					$relative . '.avif' => array(
						'for'       => $relative,
						'format'    => 'avif',
						'bytes'     => 12,
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( $attached ),
					),
				),
				'result' => array(
					'level'   => 'smart',
					'formats' => array( 'webp', 'avif' ),
					'outputs' => array(
						'full' => array(
							'before'  => (int) filesize( $backup ),
							'after'   => (int) filesize( $attached ),
							'width'   => 640,
							'height'  => 480,
							'formats' => array( 'webp', 'avif' ),
						),
					),
				),
			)
		);

		$compare = Media_View::compare( $id );
		$uploads = wp_get_upload_dir()['baseurl'];

		$this->assertIsArray( $compare );
		$this->assertStringStartsWith( $uploads . '/' . Paths::BACKUP_FOLDER . '/' . $relative . '?v=', $compare['before']['url'] );
		$this->assertSame( array( 640, 480 ), array( $compare['before']['width'], $compare['before']['height'] ) );
		$this->assertStringStartsWith( 'Original, ', $compare['before']['label'] );
		/* The main file, then its WebP copy; the AVIF at the copy's name is no image, so it isn't offered. */
		$this->assertCount( 2, $compare['after'] );
		$this->assertStringStartsWith( $uploads . '/' . $relative . '?v=', $compare['after'][0]['url'] );
		$this->assertMatchesRegularExpression( '/^JPEG, .+, \d+% smaller$/', $compare['after'][0]['label'] );
		$this->assertStringStartsWith( $uploads . '/' . $relative . '.webp?v=', $compare['after'][1]['url'] );
		$this->assertStringStartsWith( 'WebP, ', $compare['after'][1]['label'] );
		$this->assertStringContainsString( 'Optimized at Smart: a balance between quality and size.', $compare['note'] );
		$this->assertStringContainsString( 'data-iminify-compare="' . $id . '"', Media_View::panel( $id ) );
		$this->assertStringNotContainsString( 'data-iminify-compare', Media_View::cell( $id ), 'The column has no room for it.' );

		$answer = rest_do_request( new WP_REST_Request( 'GET', "/iminify/v1/media/{$id}/compare" ) );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( $compare, $answer->get_data() );

		/* The original went with the backup: the route finds nothing to compare with, though the panel, reading the record alone, offers it. */
		$kept = $backup . '.kept';
		rename( $backup, $kept ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test moving the backup aside.
		$this->assertNull( Media_View::compare( $id ) );
		$this->assertStringContainsString( 'data-iminify-compare', Media_View::panel( $id ) );
		$this->assertSame( 'no_compare', rest_do_request( new WP_REST_Request( 'GET', "/iminify/v1/media/{$id}/compare" ) )->as_error()->get_error_code() );
		rename( $kept, $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- putting it back.

		/* A copy that changed since is no copy of the plugin's: only the main file is compared. */
		$webp = (string) file_get_contents( $attached . '.webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- keeps the copy.
		file_put_contents( $attached . '.webp', $webp . 'changed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a copy another tool changed.
		clearstatcache();
		$this->assertSame( array( 'JPEG' ), $this->compared_names( $id ) );
		file_put_contents( $attached . '.webp', $webp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- putting it back.

		/* A main file another tool wrote since is a new picture: neither it nor the copies made of the old one are the plugin's result. */
		$wrote = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- keeps the file the plugin wrote.
		copy( $backup, $attached );
		clearstatcache();
		$this->assertNull( Media_View::compare( $id ) );
		file_put_contents( $attached, $wrote ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- putting it back.
		clearstatcache();
		$this->assertCount( 2, Media_View::compare( $id )['after'] );

		/* With backups off, a main file the plugin wrote over has no original to compare with: no press. */
		$backups = Records::get( $id )['backups'];
		Records::update( $id, array( 'backups' => array() ) );
		$this->assertNull( Media_View::compare( $id ) );
		$this->assertStringNotContainsString( 'data-iminify-compare', Media_View::panel( $id ) );
		Records::update( $id, array( 'backups' => $backups ) );

		/* Nothing written over and no copy made, an image on its way, and one another plugin optimized have none, and no press. */
		$result                             = Records::get( $id )['result'];
		$copies                             = Records::get( $id )['copies'];
		$result['outputs']['full']['after'] = $result['outputs']['full']['before'];
		Records::update( $id, array( 'result' => $result ) );
		$this->assertStringContainsString( 'data-iminify-compare', Media_View::panel( $id ), 'A copy it made is something to compare too.' );
		Records::update( $id, array( 'copies' => array() ) );
		$this->assertNull( Media_View::compare( $id ) );
		$this->assertStringNotContainsString( 'data-iminify-compare', Media_View::panel( $id ) );

		$result['outputs']['full']['after'] = (int) filesize( $attached );
		Records::update(
			$id,
			array(
				'result' => $result,
				'copies' => $copies,
			)
		);
		$this->assertIsArray( Media_View::compare( $id ) );

		Records::update( $id, array( 'status' => Records::QUEUED ) );
		$this->assertNull( Media_View::compare( $id ) );

		Records::update( $id, array( 'status' => Records::OTHER ) );
		$this->assertNull( Media_View::compare( $id ) );

		wp_delete_file( $attached . '.webp' );
		wp_delete_file( $attached . '.avif' );
	}

	public function test_the_panel_says_what_became_of_the_content_credentials_the_image_carried(): void {
		$id = $this->attachment();

		$this->optimize_by_hand( $id );

		$result = array(
			'outputs'     => array(
				'full' => array(
					'before'  => 2000,
					'after'   => 1000,
					'width'   => 640,
					'height'  => 480,
					'formats' => array(),
				),
			),
			'credentials' => array( 'generative_ai' => false ),
		);

		Records::update( $id, array( 'result' => $result ) );
		$panel = Media_View::panel( $id );
		$this->assertStringContainsString( 'The files and copies Iminify wrote leave them out, since a new file breaks their signature, and the original kept as a backup still has them.', $panel );
		$this->assertStringNotContainsString( 'generative AI', $panel );

		$result['credentials'] = array( 'generative_ai' => true );
		Records::update( $id, array( 'result' => $result ) );
		$this->assertStringContainsString( 'They say generative AI made it, wholly or in part.', Media_View::panel( $id ) );

		/* No backup to keep them. */
		Records::update( $id, array( 'backups' => array() ) );
		$panel = Media_View::panel( $id );
		$this->assertStringContainsString( 'The files and copies Iminify wrote leave them out, since a new file breaks their signature.', $panel );
		$this->assertStringNotContainsString( 'kept as a backup still has them', $panel );

		/* Nothing written over: the files are still the ones that carry them. */
		$result['outputs']['full']['after'] = 2000;
		Records::update( $id, array( 'result' => $result ) );
		$this->assertStringContainsString( 'This image carries Content Credentials, a signed record of where it came from, and its files keep them.', Media_View::panel( $id ) );

		Records::update( $id, array( 'status' => Records::QUEUED ) );
		$this->assertStringNotContainsString( 'Content Credentials', Media_View::panel( $id ), 'Said only of an optimized image.' );
		Records::update( $id, array( 'status' => Records::OPTIMIZED ) );

		unset( $result['credentials'] );
		Records::update( $id, array( 'result' => $result ) );
		$this->assertStringNotContainsString( 'Content Credentials', Media_View::panel( $id ) );
	}

	/**
	 * What each optimized file the compare offers is, by the first word of its label.
	 *
	 * @param int $id The attachment's id.
	 * @return list<string>
	 */
	protected function compared_names( int $id ): array {
		return array_map(
			static function ( array $file ): string {
				return (string) strtok( $file['label'], ',' );
			},
			Media_View::compare( $id )['after']
		);
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
