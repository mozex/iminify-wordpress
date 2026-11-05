<?php
/**
 * Optimizing an attachment through an upload group, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Hooks;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers an upload from its hook to its files written back, the lookup of the
 * original, a file that changed under an open group, the service's refusals,
 * the site's own switch for uploads, and deleting an attachment.
 */
class OptimizerTest extends WP_UnitTestCase {

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
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );
	}

	public function tear_down(): void {
		$this->api->stop();
		Plugin::keys()->forget();
		Site_Record::forget();
		$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		parent::tear_down();
	}

	public function test_an_upload_is_sent_once_and_its_files_written_back_with_their_copies_and_its_backup(): void {
		add_image_size( 'card', 100, 100, array( 'left', 'top' ) );
		$id = $this->attachment();
		remove_image_size( 'card' );

		$attached = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$original = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		$this->assertTrue( $this->queued( $id ) );
		add_image_size( 'card', 100, 100, array( 'left', 'top' ) );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );
		remove_image_size( 'card' );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$upload   = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' )[0]['args'];
		$outputs  = array_column( $declared['outputs'], null, 'name' );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' ) );
		$this->assertSame(
			array(
				'format' => 'jpg',
				'size'   => strlen( $original ),
				'width'  => 640,
				'height' => 480,
			),
			$declared['source']
		);
		$names = array_keys( $outputs );
		sort( $names );

		$this->assertSame( 'full', $declared['outputs'][0]['name'] );
		$this->assertSame( array( 'card', 'full', 'medium', 'thumbnail' ), $names );
		$this->assertSame( array( 'center', 'center' ), $outputs['thumbnail']['crop'] );
		$this->assertSame( array( 'start', 'start' ), $outputs['card']['crop'] );
		$this->assertArrayNotHasKey( 'crop', $outputs['medium'] );
		$this->assertSame( $metadata['sizes']['medium']['filesize'], $outputs['medium']['size'] );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $upload['headers']['Content-Type'] );
		$this->assertStringContainsString( $original, $upload['body'] );
		$this->assertSame( $original, file_get_contents( Paths::backup( (string) Paths::relative( $attached ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.
		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );

		/*
		 * The site's maximum width narrowed the main file, and the thumbnail's
		 * WebP came out heavier than the thumbnail. The card's file in the zip
		 * isn't the size the answer says, and an answer naming a path outside
		 * the folder is never followed.
		 */
		$card   = dirname( $attached ) . '/' . $metadata['sizes']['card']['file'];
		$before = (string) file_get_contents( $card ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test size.
		$answer = $this->finished(
			$id,
			array(
				'full'      => array( 600, 450 ),
				'thumbnail' => array( 150, 150, false ),
			)
		);
		$zip    = $this->zip( $attached, $answer, array( 'card.jpg' => array( 90, 90 ) ) );

		$answer['outputs'][] = array(
			'name'    => 'full',
			'format'  => 'avif',
			'status'  => 'completed',
			'file'    => '../outside.jpg',
			'width'   => 600,
			'height'  => 450,
			'size'    => 10,
			'replace' => true,
		);
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $zip['bytes'] );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		/* Another plugin that answers a metadata save by generating the metadata again, as some offload plugins do. */
		$regenerate = static function ( $data, $attachment ) {
			return apply_filters( 'wp_generate_attachment_metadata', $data, $attachment, 'update' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as another plugin would run it.
		};
		add_filter( 'wp_update_attachment_metadata', $regenerate, 100, 2 );

		Optimizer::check( $id );

		remove_filter( 'wp_update_attachment_metadata', $regenerate, 100 );

		$folder   = dirname( $attached );
		$metadata = wp_get_attachment_metadata( $id );
		$record   = Records::get( $id );

		$this->assertSame( $zip['entries']['full.jpg'], file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the written file.
		$this->assertSame( $zip['entries']['full.webp'], file_get_contents( $attached . '.webp' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the written copy.
		$this->assertSame( $zip['entries']['medium.jpg'], file_get_contents( $folder . '/' . $metadata['sizes']['medium']['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the written size.
		$this->assertFileDoesNotExist( $folder . '/' . $metadata['sizes']['thumbnail']['file'] . '.webp' );
		$this->assertSame( array( 600, 450, strlen( $zip['entries']['full.jpg'] ) ), array( $metadata['width'], $metadata['height'], $metadata['filesize'] ) );
		$this->assertSame( strlen( $zip['entries']['medium.jpg'] ), $metadata['sizes']['medium']['filesize'] );
		$this->assertSame( Records::OPTIMIZED, $record['status'] );
		$this->assertNull( $record['group'] );
		$this->assertSame( strlen( $zip['entries']['full.jpg'] ), $record['written'][ Paths::relative( $attached ) ] );
		$this->assertSame( Records::ORIGIN, $record['copies'][ Paths::relative( $attached ) . '.webp' ]['origin'] );
		$this->assertSame( $before, file_get_contents( $card ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the size left alone.
		$this->assertSame( 1, $record['result']['skipped'] );
		$this->assertFileDoesNotExist( $attached . '.avif' );
		$this->assertFileDoesNotExist( dirname( $folder ) . '/outside.jpg' );
		$this->assertCount( 1, $this->api->calls( 'DELETE', 'groups/' . self::GROUP ) );

		/* Its own save of the metadata queued nothing, even through another plugin, and the file it wrote is never an original: the backup is. */
		$this->assertFalse( $this->queued( $id ) );
		$this->assertSame( Originals::BACKUP, Originals::find( $id, $metadata, null )['kind'] ?? null );
	}

	public function test_the_original_is_the_untouched_file_and_never_the_plugins_own_output(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$scaled = $this->attachment();
		remove_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );

		$metadata = wp_get_attachment_metadata( $scaled );
		$attached = (string) get_attached_file( $scaled );

		/* WordPress's scaled copy stands for the original it keeps beside it, unless that is past the plan's file size. */
		$this->assertStringEndsWith( '-scaled.jpg', $attached );
		$this->assertSame( Originals::ORIGINAL, Originals::find( $scaled, $metadata, null )['kind'] ?? null );
		$this->assertSame( $attached, Originals::find( $scaled, $metadata, (int) filesize( $attached ) )['path'] ?? null );

		/* An edit keeps the old original_image, which no longer shows what the attachment does. */
		$edited = dirname( $attached ) . '/canola-e1700000000000.jpg';
		copy( $attached, $edited );
		update_attached_file( $scaled, $edited );
		$this->assertSame( $edited, Originals::find( $scaled, $metadata, null )['path'] ?? null );

		/* A file the plugin wrote is sent only through its backup, and with none, not at all. */
		$plain = $this->attachment();
		$file  = (string) get_attached_file( $plain );
		Records::update( $plain, array( 'written' => array( Paths::relative( $file ) => (int) filesize( $file ) ) ) );

		$this->assertNull( Originals::find( $plain, wp_get_attachment_metadata( $plain ), null ) );

		/* A file something else put there since is the original as it stands. */
		file_put_contents( $file, (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) . ' ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- changes a test image's bytes.
		$this->assertSame( Originals::ATTACHED, Originals::find( $plain, wp_get_attachment_metadata( $plain ), null )['kind'] ?? null );
	}

	public function test_a_group_made_for_files_that_changed_since_writes_nothing_and_the_attachment_goes_again(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		Optimizer::optimize( $id, Optimizer::AUTOMATIC );
		$answer = $this->finished( $id );
		$zip    = $this->zip( $attached, $answer );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $zip['bytes'] );

		/* The classic image editor saves an edit under another name. */
		$metadata         = wp_get_attachment_metadata( $id );
		$metadata['file'] = str_replace( 'canola', 'canola-e1700000000000', $metadata['file'] );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertTrue( $this->queued( $id ) );

		Optimizer::check( $id );

		$this->assertSame( array(), $this->api->calls( 'GET', 'groups/' . self::GROUP . '/download' ) );
		$this->assertNull( Records::get( $id )['group'] );
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::DELETE_GROUP_HOOK, array( self::GROUP, 0 ), Optimizer::GROUP ) );

		/* The same file written over in place, as the image editor does with IMAGE_EDIT_OVERWRITE, is caught by its new size. */
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		$metadata             = wp_get_attachment_metadata( $id );
		$metadata['filesize'] = (int) $metadata['filesize'] + 1;
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertTrue( $this->queued( $id ) );

		/* A file changed outside any hook is caught when the group's files are about to be written. */
		$other = $this->attachment();
		Optimizer::optimize( $other, Optimizer::AUTOMATIC );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $this->finished( $other ) ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( (string) get_attached_file( $other ), $this->finished( $other ) )['bytes'] );
		file_put_contents( (string) get_attached_file( $other ), 'not the image the group was made from' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- changes a test image behind the plugin's back.
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::check( $other );

		$this->assertSame( 'not the image the group was made from', file_get_contents( (string) get_attached_file( $other ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
		$this->assertTrue( $this->queued( $other ) );
	}

	/**
	 * Each refusal and failure: whether the step runs again, when, and what the attachment shows.
	 *
	 * @dataProvider refusals
	 *
	 * @param int                   $status  The HTTP status.
	 * @param array<string, string> $body    The answer.
	 * @param array<string, string> $headers The answer's headers.
	 * @param string|null           $waits   The record's status when the step waits to run again, or null when it fails for good.
	 * @param int                   $after   The seconds the next run waits at least.
	 */
	public function test_a_refused_group_waits_as_the_service_asks_or_fails_with_its_reason( int $status, array $body, array $headers, ?string $waits, int $after ): void {
		$id = $this->attachment();
		$this->api->on( 'POST', 'groups', $status, $body, $headers );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Records::update( $id, array( 'status' => null ) );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$record = Records::get( $id );
		$next   = as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP );

		$this->assertSame( $body['code'] ?? 'unreachable', $record['error']['code'] ?? null );

		if ( null === $waits ) {
			$this->assertSame( Records::ERROR, $record['status'] );
			$this->assertFalse( $next );

			return;
		}

		$this->assertSame( $waits, $record['status'] );
		$this->assertIsInt( $next );
		$this->assertGreaterThanOrEqual( time() + $after - 1, $next );
	}

	/**
	 * @return array<string, array{int, array<string, string>, array<string, string>, string|null, int}>
	 */
	public function refusals(): array {
		return array(
			'a wait the service asks for'     => array(
				429,
				array(
					'code'    => 'rate_limited',
					'message' => 'Too many requests.',
				),
				array( 'Retry-After' => '30' ),
				Records::QUEUED,
				30,
			),
			'the service failing on the way'  => array(
				503,
				array(
					'code'    => 'http_503',
					'message' => 'Unavailable.',
				),
				array(),
				Records::QUEUED,
				60,
			),
			'outputs the service never makes' => array(
				422,
				array(
					'code'    => 'outputs_refused',
					'message' => 'A group makes at most 30 outputs.',
				),
				array(),
				null,
				0,
			),
		);
	}

	public function test_the_sites_switch_for_uploads_and_a_missing_connection_send_nothing(): void {
		$site = Api_Stub::fixture( 'site' );
		$site['data']['settings']['optimize_on_upload'] = false;
		$this->api->on( 'GET', 'site', 200, $site );
		$id = $this->attachment();

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array( Records::NOT_OPTIMIZED, 'off' ), array( Records::get( $id )['status'], Records::get( $id )['error']['code'] ?? null ) );

		/* Asked for by a person, it goes whatever the switch says. */
		Optimizer::optimize( $id, Optimizer::MANUAL );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );

		/* An upload while the site is disconnected waits for the bulk page, sending nothing. */
		Plugin::keys()->forget();
		$offline = $this->attachment();

		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $offline )['status'] );
		$this->assertFalse( $this->queued( $offline ) );
	}

	public function test_deleting_an_attachment_deletes_only_what_the_plugin_made(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );
		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$own   = $attached . '.webp';
		$other = $attached . '.avif';
		file_put_contents( $own, 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
		file_put_contents( $other, 'avif' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test copy.
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
						'origin' => 'another-plugin',
					),
				),
			)
		);

		$this->assertFileExists( Paths::backup( $relative ) );

		wp_delete_attachment( $id, true );

		$this->assertFileDoesNotExist( $own );
		$this->assertFileExists( $other );
		$this->assertFileDoesNotExist( Paths::backup( $relative ) );
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::DELETE_GROUP_HOOK, array( self::GROUP, 0 ), Optimizer::GROUP ) );
		$this->assertFalse( as_next_scheduled_action( Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP ) );

		wp_delete_file( $other );
	}

	public function test_a_source_whose_answer_went_missing_is_never_sent_into_a_second_group(): void {
		$id = $this->attachment();
		$this->api->fail( 'POST', 'groups/' . self::GROUP . '/source' );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertFalse( Records::get( $id )['group']['sent'] );

		/* The source did arrive: the group is being made, so it is polled, never sent again or opened again. */
		$answer           = Api_Stub::fixture( 'group' )['data'];
		$answer['status'] = 'in_progress';
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		as_unschedule_all_actions( Optimizer::CHECK_HOOK );

		Optimizer::check( $id );

		$this->assertCount( 1, $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' ) );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertTrue( Records::get( $id )['group']['sent'] );
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::CHECK_HOOK, array( $id ), Optimizer::GROUP ) );

		/* A group that expired is deleted and the attachment queued again, as often as the cap allows. */
		$answer['status'] = 'expired';
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Records::update( $id, array( 'reopens' => Optimizer::MAX_REOPENS ) );

		Optimizer::check( $id );

		$this->assertSame( array( Records::ERROR, 'reopened' ), array( Records::get( $id )['status'], Records::get( $id )['error']['code'] ?? null ) );
		$this->assertFalse( $this->queued( $id ) );
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::DELETE_GROUP_HOOK, array( self::GROUP, 0 ), Optimizer::GROUP ) );

		/* A wait asked of the source's upload is waited out by the check, with the group kept. */
		$other = $this->attachment();
		$this->api->on(
			'POST',
			'groups/' . self::GROUP . '/source',
			429,
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many requests.',
			),
			array( 'Retry-After' => '45' )
		);

		Optimizer::optimize( $other, Optimizer::AUTOMATIC );

		$this->assertNotNull( Records::get( $other )['group'] );
		$this->assertGreaterThanOrEqual( time() + 44, as_next_scheduled_action( Optimizer::CHECK_HOOK, array( $other ), Optimizer::GROUP ) );
	}

	public function test_a_backup_of_a_file_replaced_in_place_is_moved_aside_and_the_new_original_kept(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );
		Optimizer::optimize( $id, Optimizer::AUTOMATIC );
		$first = (string) file_get_contents( Paths::backup( $relative ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.

		/* Another image put in the same file, and the attachment optimized again. */
		copy( DIR_TESTDATA . '/images/test-image-large.jpg', $attached );
		Records::update( $id, array( 'group' => null ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$backups = Records::get( $id )['backups'];
		$aside   = array_values( array_diff( $backups, array( Paths::BACKUP_FOLDER . '/' . $relative ) ) );

		$this->assertSame( (string) file_get_contents( $attached ), file_get_contents( Paths::backup( $relative ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the new backup.
		$this->assertCount( 2, $backups );
		$this->assertCount( 1, $aside );
		$this->assertSame( $first, file_get_contents( Paths::absolute( $aside[0] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the old backup kept aside.
	}

	public function test_with_backups_off_a_backup_of_a_replaced_file_is_moved_aside_so_a_restore_never_puts_it_back(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $attached );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );
		copy( DIR_TESTDATA . '/images/test-image-large.jpg', $attached );
		Records::update( $id, array( 'group' => null ) );
		Local_Settings::update( array( 'backups' => false ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		Local_Settings::update( array( 'backups' => true ) );

		$this->assertFileDoesNotExist( Paths::backup( $relative ) );
		$this->assertCount( 2, Records::get( $id )['backups'] );
	}

	public function test_a_disk_that_fills_up_while_writing_skips_what_has_no_room_and_records_only_what_it_wrote(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$answer = $this->finished( $id );
		$zip    = $this->zip( $attached, $answer );

		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $zip['bytes'] );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		/* Room for the smallest file alone. */
		$room = Disk::RESERVE + min( array_map( 'strlen', $zip['entries'] ) );
		$free = static function () use ( $room ) {
			return $room;
		};

		add_filter( 'iminify_disk_free_space', $free );
		Optimizer::check( $id );
		remove_filter( 'iminify_disk_free_space', $free );

		$record = Records::get( $id );

		$this->assertSame( Records::OPTIMIZED, $record['status'] );
		$this->assertSame( count( $zip['entries'] ) - 1, $record['result']['skipped'] );
		$this->assertSame( 1, count( $record['written'] ) + count( $record['copies'] ) );

		foreach ( $record['copies'] as $copy => $about ) {
			$this->assertFileExists( Paths::absolute( (string) $copy ) );
		}

		/* With room for nothing, it isn't called optimized, and its files stay as they were. */
		$other  = $this->attachment();
		$file   = (string) get_attached_file( $other );
		$before = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		Optimizer::optimize( $other, Optimizer::AUTOMATIC );

		$answer = $this->finished( $other );

		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $file, $answer )['bytes'] );

		$reserve = static function () {
			return Disk::RESERVE;
		};

		add_filter( 'iminify_disk_free_space', $reserve );
		Optimizer::check( $other );
		remove_filter( 'iminify_disk_free_space', $reserve );

		$record = Records::get( $other );

		$this->assertSame( array( Records::NOT_OPTIMIZED, 'disk_full' ), array( $record['status'], $record['error']['code'] ?? null ) );
		$this->assertSame( array(), $record['written'] );
		$this->assertSame( array(), $record['copies'] );
		$this->assertNull( $record['group'] );
		$this->assertSame( $before, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
	}

	public function test_a_restore_during_the_download_keeps_the_files_it_put_back(): void {
		$id       = $this->attachment();
		$attached = (string) get_attached_file( $id );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$answer = $this->finished( $id );

		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $attached, $answer )['bytes'] );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		$before = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		/* What Restorer::restore() does to the record, happening while the zip comes down. */
		$restored = static function ( $preempt, $args, $url ) use ( $id ) {
			if ( false !== strpos( (string) $url, '/download' ) ) {
				Records::bump( $id );
				Records::update(
					$id,
					array(
						'group'  => null,
						'status' => Records::NOT_OPTIMIZED,
					)
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $restored, 5, 3 );
		Optimizer::check( $id );
		remove_filter( 'pre_http_request', $restored, 5 );

		$this->assertSame( $before, file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left alone.
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), Records::get( $id )['written'] );
		$this->assertFalse( $this->queued( $id ) );
	}

	public function test_a_browser_made_upload_waits_for_its_sizes_and_an_iphone_upload_goes_from_its_jpeg(): void {
		$id                = $this->attachment();
		$metadata          = wp_get_attachment_metadata( $id );
		$metadata['sizes'] = array();
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertGreaterThanOrEqual( time() + 59, as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		/* WordPress keeps image/heic on an iPhone upload it turned into a JPEG; the file is what counts. */
		$heic = $this->attachment();
		wp_update_post(
			array(
				'ID'             => $heic,
				'post_mime_type' => 'image/heic',
			)
		);

		$this->assertTrue( Optimizer::is_optimizable( $heic ) );
	}

	/**
	 * Scales an upload wider than 500 pixels, as WordPress scales one past 2,560.
	 */
	public function scale_at_500(): int {
		return 500;
	}

	/**
	 * A 640 by 480 JPEG uploaded to the media library, which makes its thumbnail and medium size.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Whether the attachment waits to be optimized.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function queued( int $id ): bool {
		return false !== as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ), Optimizer::GROUP );
	}

	/**
	 * The service's answer for the attachment's open group, finished: each
	 * declared output in its own format and WebP, marked `replace`, at the
	 * dimensions given for it or its declared ones.
	 *
	 * @param int                                   $id      The attachment's id.
	 * @param array<string, array{int, int, bool}|array{int, int}> $changes Width, height and whether the WebP replaces, by output.
	 * @return array<string, mixed>
	 */
	protected function finished( int $id, array $changes = array() ): array {
		$answer            = Api_Stub::fixture( 'group' )['data'];
		$answer['outputs'] = array();

		foreach ( Records::get( $id )['group']['outputs'] as $name => $output ) {
			$size = $changes[ $name ] ?? array( $output['width'], $output['height'] );

			foreach ( array( 'jpg', 'webp' ) as $format ) {
				$replace             = 'jpg' === $format || ( $size[2] ?? true );
				$answer['outputs'][] = array(
					'name'    => $name,
					'format'  => 'jpg' === $format ? 'original' : 'webp',
					'status'  => 'completed',
					'file'    => $replace ? $name . '.' . $format : null,
					'width'   => $size[0],
					'height'  => $size[1],
					'size'    => 1,
					'replace' => $replace,
				);
			}
		}

		return $answer;
	}

	/**
	 * A zip holding every file the answer marks `replace`, made from the
	 * image at its dimensions, as the service's would be.
	 *
	 * @param string                         $image  The image to make them from.
	 * @param array<string, mixed>           $answer The service's answer.
	 * @param array<string, array{int, int}> $sizes  Files made at other dimensions than the answer gives.
	 * @return array{bytes: string, entries: array<string, string>}
	 */
	protected function zip( string $image, array $answer, array $sizes = array() ): array {
		$entries = array();

		foreach ( $answer['outputs'] as $output ) {
			if ( ! $output['replace'] ) {
				continue;
			}

			$size   = $sizes[ $output['file'] ] ?? array( $output['width'], $output['height'] );
			$editor = wp_get_image_editor( $image );
			$editor->resize( $size[0], $size[1], true );
			$editor->set_quality( 10 );
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.' . ( 'webp' === $output['format'] ? 'webp' : 'jpg' ), 'webp' === $output['format'] ? 'image/webp' : 'image/jpeg' );

			$entries[ $output['file'] ] = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
			wp_delete_file( $saved['path'] );
		}

		$path    = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $bytes ) {
			$archive->addFromString( $name, $bytes );
		}

		$archive->addFromString( '../outside.jpg', 'never read' );
		$archive->close();

		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test zip.
		wp_delete_file( $path );

		return array(
			'bytes'   => $bytes,
			'entries' => $entries,
		);
	}
}
