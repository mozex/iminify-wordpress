<?php
/**
 * Optimizing and restoring images WP Offload Media keeps only in its bucket.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Offload_Stub;
use WP_UnitTestCase;

/**
 * With WP Offload Media's "Remove Local Media" on, an attachment's files are on
 * the disk only until they go up. Covers the reads that answer for a file only in
 * the bucket, the one file brought back for a send or a restore and taken away
 * again, the files going up once and leaving the disk after each, and `<picture>`
 * serving the copies from the bucket.
 */
class RemoveLocalMediaTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	/**
	 * The stand-in WP Offload Media.
	 *
	 * @var Offload_Stub
	 */
	protected $offload;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		Picture::flush();
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );
		$this->offload = new Offload_Stub();
	}

	public function tear_down(): void {
		$reads = $this->offload->reads();
		$this->offload->stop();
		$this->api->stop();
		Plugin::keys()->forget();
		Site_Record::forget();
		Local_Settings::update( array( 'backups' => true ) );
		remove_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		remove_image_size( 'card' );
		Bulk::stop();

		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}

		parent::tear_down();

		$this->assertSame( array(), $reads, 'Nothing read a file through its path in the bucket.' );
	}

	public function test_an_image_only_in_the_bucket_is_sent_from_the_one_file_brought_back_and_its_files_leave_the_disk_after_each_step(): void {
		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );
		$metadata = wp_get_attachment_metadata( $id, true );
		$original = (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test image.
		$medium   = dirname( $attached ) . '/' . $metadata['sizes']['medium']['file'];

		/* Nothing of it is on the disk, yet it reads as an image to send, which bulk takes. */
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertSame( $original, $this->offload->held( $attached ) );
		$this->assertNull( Optimizer::why_not( $id ) );

		$run = Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND );

		$this->assertSame( 1, is_array( $run ) ? $run['sent'] : null, is_wp_error( $run ) ? $run->get_error_message() : (string) wp_json_encode( $run ) );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );

		Optimizer::optimize( $id, Optimizer::BULK );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$outputs  = array_column( $declared['outputs'], null, 'name' );
		$upload   = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' )[0]['args'];

		/* Only the original came back, went from its backup, and left the disk again; the sizes were declared from the metadata. */
		$this->assertSame( array( (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertSame( strlen( $original ), $declared['source']['size'] );
		$this->assertSame( strlen( $original ), $outputs['full']['size'] );
		$this->assertSame( array( 300, 225, $metadata['sizes']['medium']['filesize'] ), array( $outputs['medium']['width'], $outputs['medium']['height'], $outputs['medium']['size'] ) );
		$this->assertSame( Paths::backup( (string) Paths::relative( $attached ) ), $upload[ Upload::ARGUMENT ]['path'] ?? null );
		$this->assertSame( Records::WAITING, Records::get( $id )['status'] );
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertSame( array(), Records::get( $id )['fetched'] );

		$answer = $this->finished( $id );
		$zip    = $this->zip( $answer );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $zip['bytes'] );

		Optimizer::check( $id );

		$record   = Records::get( $id );
		$metadata = wp_get_attachment_metadata( $id, true );
		$copy     = (string) Paths::relative( $attached ) . '.webp';

		/* Every file was written, went up in one upload with the copies typed, and left the disk. */
		$this->assertSame( Records::OPTIMIZED, $record['status'] );
		$this->assertCount( 2, $this->offload->uploads );
		$this->assertSame( $zip['entries']['full.jpg'], $this->offload->held( $attached ) );
		$this->assertSame( $zip['entries']['medium.jpg'], $this->offload->held( $medium ) );
		$this->assertSame( $zip['entries']['full.webp'], $this->offload->held( $attached . '.webp' ) );
		$this->assertSame( 'image/webp', $this->offload->meta[ 'iminify-' . wp_basename( $attached ) . '.webp' ]['ContentType'] ?? null );
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertFileDoesNotExist( $attached . '.webp' );
		$this->assertSame( strlen( $zip['entries']['full.webp'] ), $record['copies'][ $copy ]['offloaded'] );
		$this->assertSame( strlen( $zip['entries']['full.jpg'] ), $record['copies'][ $copy ]['for_bytes'] );
		$this->assertSame( strlen( $zip['entries']['full.jpg'] ), $metadata['filesize'] );
		$this->assertSame( strlen( $zip['entries']['medium.jpg'] ), $metadata['sizes']['medium']['filesize'] );

		/* Its files read as the plugin's, as they are in the bucket, and a save that changes nothing queues nothing. */
		$this->assertTrue( Records::unchanged( $id ) );
		$this->assertTrue( Records::wrote( $id, $attached ) );
		$generation = Records::get( $id )['generation'];
		apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, as another plugin runs it.
		wp_update_attachment_metadata( $id, $metadata );
		$this->assertFalse( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ) ) );
		$this->assertSame( array( Records::OPTIMIZED, $generation ), array( Records::get( $id )['status'], Records::get( $id )['generation'] ) );

		/* `<picture>` serves the copies from the bucket, each only while the file beside it is the one it was made for. */
		$this->go_to( '/' );
		$html = wp_get_attachment_image( $id, 'full' );
		$this->assertStringContainsString( '<picture', $html );
		$this->assertStringContainsString( Offload_Stub::HOST . '/' . $copy, $html );

		$changed             = $metadata;
		$changed['filesize'] = $metadata['filesize'] + 1;
		update_post_meta( $id, '_wp_attachment_metadata', $changed );
		Picture::flush();
		$html = wp_get_attachment_image( $id, 'full' );
		$this->assertStringContainsString( wp_basename( $medium ) . '.webp', $html, 'The medium size is as its copy was made for.' );
		$this->assertStringNotContainsString( wp_basename( $attached ) . '.webp', $html );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		/* Restoring brings nothing back but the backup's copy, makes the sizes from it, and uploads once. */
		$uploads = count( $this->offload->uploads );
		$this->assertSame( Restorer::AVAILABLE, Restorer::availability( $id ) );
		$this->assertTrue( Restorer::restore( $id ) );

		$this->assertCount( $uploads + 1, $this->offload->uploads );
		$this->assertSame( $original, $this->offload->held( $attached ) );
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertSame( array( (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), Records::get( $id )['copies'] );
		$this->assertFileExists( Paths::backup( (string) Paths::relative( $attached ) ) );
		$this->assertNull( Optimizer::why_not( $id ) );
	}

	public function test_without_backups_the_original_wordpress_kept_is_the_one_file_brought_back_to_send_and_to_restore(): void {
		Local_Settings::update( array( 'backups' => false ) );
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id = $this->offloaded();

		$attached = (string) get_attached_file( $id, true );
		$source   = Originals::kept( $id );
		$scaled   = (string) $this->offload->held( $attached );

		$this->assertStringEndsWith( '-scaled.jpg', $attached );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$outputs  = array_column( $declared['outputs'], null, 'name' );
		$upload   = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' )[0]['args'];

		/* The scaled main file stays in the bucket and is declared from the metadata; the original came back, was sent, and went. */
		$this->assertSame( array( (string) Paths::relative( $source ) ), $this->offload->downloads );
		$this->assertSame( array( 500, 375, strlen( $scaled ) ), array( $outputs['full']['width'], $outputs['full']['height'], $outputs['full']['size'] ) );
		$this->assertSame( array( 640, 480 ), array( $declared['source']['width'], $declared['source']['height'] ) );
		$this->assertSame( $source, $upload[ Upload::ARGUMENT ]['path'] ?? null );
		$this->assertSame( array(), $this->on_disk( $id ) );

		$answer = $this->finished( $id );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer )['bytes'] );
		Optimizer::check( $id );

		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertNotSame( $scaled, $this->offload->held( $attached ) );
		$this->assertSame( array(), $this->on_disk( $id ) );

		/* With no backup, the original in the bucket is what a restore makes the image from again. */
		$this->assertSame( Restorer::AVAILABLE, Restorer::availability( $id ) );
		$this->assertTrue( Restorer::restore( $id ) );

		$this->assertSame( array( (string) Paths::relative( $source ), (string) Paths::relative( $source ) ), $this->offload->downloads );
		$this->assertSame( $scaled, $this->offload->held( $attached ), 'WordPress made the scaled copy again from the original.' );
		$this->assertSame( array(), $this->on_disk( $id ) );
	}

	public function test_a_size_the_image_lacks_is_made_from_the_original_brought_back_onto_the_disk_and_never_from_the_bucket(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id       = $this->offloaded();
		$original = (string) Paths::relative( Originals::kept( $id ) );
		$this->optimize( $id );

		add_image_size( 'card', 100, 100, array( 'left', 'top' ) );

		$this->assertSame( array( 'card' ), Missing::of( $id, Missing::SIZES ) );
		$this->assertTrue( Optimizer::fill( $id, Missing::SIZES ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$this->assertCount( 2, $this->api->calls( 'POST', 'groups' ) );
		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[1]['args']['body'], true );
		$metadata = wp_get_attachment_metadata( $id, true );
		$card     = dirname( (string) get_attached_file( $id, true ) ) . '/' . $metadata['sizes']['card']['file'];

		/* WordPress made the new size on the disk from the original brought back; WP Offload Media took both up and off, and the original came back again to be sent. */
		$this->assertSame( array( 'card', 'full' ), $this->names( $declared ) );
		$this->assertSame( array( $original, $original, $original ), $this->offload->downloads );
		$this->assertNotNull( $this->offload->held( $card ) );
		$this->assertSame( array(), $this->on_disk( $id ) );

		$answer = $this->finished( $id );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer )['bytes'] );
		Optimizer::check( $id );

		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertSame( array(), Missing::of( $id, Missing::SIZES ) );
	}

	public function test_an_original_that_cant_be_the_source_gives_way_to_the_scaled_copy_each_brought_back_in_its_turn(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_500' ) );
		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );
		$scaled   = (string) $this->offload->held( $attached );
		$site     = Api_Stub::fixture( 'site' );

		/* The plan takes the scaled copy's bytes, and the original is larger. */
		$site['data']['max_file_size'] = strlen( $scaled );
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$upload = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' )[0]['args'];

		$this->assertSame( array( (string) Paths::relative( Originals::kept( $id ) ), (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertSame( $scaled, file_get_contents( Paths::backup( (string) Paths::relative( $attached ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.
		$this->assertSame( Paths::backup( (string) Paths::relative( $attached ) ), $upload[ Upload::ARGUMENT ]['path'] ?? null );
		$this->assertSame( array(), $this->on_disk( $id ) );

		/* An original in another format than the attached file (an iPhone's HEIC beside its JPEG) is never brought back at all. */
		$this->offload->downloads   = array();
		$metadata                   = wp_get_attachment_metadata( $id, true );
		$metadata['original_image'] = pathinfo( Originals::kept( $id ), PATHINFO_FILENAME ) . '.heic';
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		$this->assertSame( Originals::ATTACHED, Originals::fetch( $id, $metadata, null )['kind'] ?? null );
		$this->assertSame( array( (string) Paths::relative( $attached ) ), $this->offload->downloads );
	}

	public function test_a_restore_whose_upload_fails_keeps_the_original_it_put_back_on_the_disk(): void {
		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );
		$this->optimize( $id );

		/* The metadata doesn't measure the main file, so the restore brings the plugin's output back before it reads the backup instead. */
		$metadata = wp_get_attachment_metadata( $id, true );
		unset( $metadata['filesize'] );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		/* Brought back, a main file something else replaced in the bucket is never written over, and goes again. */
		$held = $this->offload->bucket . '/' . Paths::relative( $attached );
		$kept = (string) file_get_contents( $held ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's bucket.
		file_put_contents( $held, $kept . 'replaced' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- another tool's file in the bucket.

		$this->assertSame( 'image_changed', Restorer::restore( $id )->get_error_code() );
		$this->assertFileDoesNotExist( $attached );

		file_put_contents( $held, $kept ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- puts the plugin's output back.
		$this->offload->failing = true;

		$this->assertTrue( Restorer::restore( $id ) );

		$this->assertSame( array_fill( 0, 3, (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertSame( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ), file_get_contents( $attached ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the restored file.
		$this->assertSame( array(), Records::get( $id )['fetched'] );
	}

	public function test_an_unsent_source_brought_back_stays_until_it_is_sent_and_comes_back_again_if_it_went(): void {
		Local_Settings::update( array( 'backups' => false ) );
		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );
		$this->api->fail( 'POST', 'groups/' . self::GROUP . '/source' );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		/* The send failed on the way: the group waits for its source, which stays on the disk. */
		$this->assertFileExists( $attached );
		$this->assertArrayHasKey( (string) Paths::relative( $attached ), Records::get( $id )['fetched'] );

		/* Something took it off the disk meanwhile, as a save of the metadata does: the next look brings it back to send it, then lets it go. */
		wp_delete_file( $attached );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, Api_Stub::fixture( 'group-open' ) );

		Optimizer::check( $id );

		$this->assertCount( 2, $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' ) );
		$this->assertSame( array( (string) Paths::relative( $attached ), (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertFileDoesNotExist( $attached );
		$this->assertSame( array(), Records::get( $id )['fetched'] );

		/* Once WP Offload Media no longer serves the attachment, a file brought back is the one the site shows, and stays. */
		$this->assertTrue( Offload_Media::bring_back( $id, $attached ) );
		$this->offload->stop();
		Offload_Media::put_away( $id );

		$this->assertFileExists( $attached );
		$this->offload->start();
	}

	public function test_a_file_whose_bytes_the_metadata_doesnt_give_is_asked_again_once_back_so_the_plugins_own_output_is_never_sent(): void {
		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );
		$this->optimize( $id );

		/* The metadata no longer measures the main file the plugin wrote, as on an upload WordPress 6.0 never measured. */
		$metadata = wp_get_attachment_metadata( $id, true );
		unset( $metadata['filesize'] );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$upload = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' )[1]['args'];
		$backup = Paths::backup( (string) Paths::relative( $attached ) );

		/* Brought back, it reads as the plugin's own output by its bytes, so the backup goes and stays where it was. */
		$this->assertSame( array( (string) Paths::relative( $attached ), (string) Paths::relative( $attached ) ), $this->offload->downloads );
		$this->assertSame( $backup, $upload[ Upload::ARGUMENT ]['path'] ?? null );
		$this->assertSame( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ), file_get_contents( $backup ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.
		$this->assertSame( array( (string) Paths::relative( $backup ) ), Records::get( $id )['backups'] );
		$this->assertSame( array(), $this->on_disk( $id ) );
	}

	public function test_a_size_left_as_it_was_gets_its_bytes_in_the_metadata_so_its_copy_is_served_once_it_is_only_in_the_bucket(): void {
		$id       = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$metadata = wp_get_attachment_metadata( $id, true );
		$medium   = dirname( (string) get_attached_file( $id, true ) ) . '/' . $metadata['sizes']['medium']['file'];

		/* An upload WordPress made before 6.0 measured none of its sizes. */
		unset( $metadata['sizes']['medium']['filesize'] );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$answer = $this->finished( $id, array( 'medium' ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer )['bytes'] );
		Optimizer::check( $id );

		$this->assertSame( (int) filesize( $medium ), wp_get_attachment_metadata( $id, true )['sizes']['medium']['filesize'] ?? null );

		/* Once WP Offload Media has taken it off the disk, its copy is still proven by those bytes. */
		$this->offload->start()->offload( $id );
		$this->go_to( '/' );
		Picture::flush();

		$this->assertFileDoesNotExist( $medium );
		$this->assertStringContainsString( wp_basename( $medium ) . '.webp', wp_get_attachment_image( $id, 'full' ) );
	}

	public function test_a_file_only_in_the_bucket_that_changed_under_an_open_group_takes_nothing_and_goes_again(): void {
		$id       = $this->offloaded();
		$metadata = wp_get_attachment_metadata( $id, true );
		$medium   = dirname( (string) get_attached_file( $id, true ) ) . '/' . $metadata['sizes']['medium']['file'];
		$before   = $this->offload->held( $medium );

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		/* Another tool wrote a new medium size to the bucket and its size into the metadata meanwhile. */
		$metadata['sizes']['medium']['filesize'] = $metadata['sizes']['medium']['filesize'] + 1;
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		$answer = $this->finished( $id );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer )['bytes'] );
		Optimizer::check( $id );

		$this->assertSame( array( Records::QUEUED, 1, array() ), array( Records::get( $id )['status'], Records::get( $id )['reopens'], Records::get( $id )['written'] ) );
		$this->assertSame( $before, $this->offload->held( $medium ) );
		$this->assertSame( array(), $this->on_disk( $id ) );
	}

	public function test_without_wp_offload_media_a_missing_file_still_fails_to_make_sizes_rather_than_waiting_for_a_bucket(): void {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		wp_delete_file( Originals::kept( $id ) );
		add_image_size( 'card', 100, 100, array( 'left', 'top' ) );
		Records::update(
			$id,
			array(
				'status' => Records::OPTIMIZED,
				'result' => array( 'sizes' => array( 'thumbnail', 'medium' ) ),
			)
		);

		$sizes = Missing::make_sizes( $id );

		$this->assertSame( array( array(), true ), is_array( $sizes ) ? array( $sizes['only'], $sizes['lacking'] ) : null );
	}

	public function test_alt_text_for_an_image_only_in_the_bucket_puts_back_a_file_too_large_to_send(): void {
		$site = Api_Stub::fixture( 'site' );

		$site['data']['settings']['alt_text']         = true;
		$site['data']['alt_text_source']['max_bytes'] = 10;

		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();

		$id       = $this->offloaded();
		$attached = (string) get_attached_file( $id, true );

		$this->assertFileDoesNotExist( $attached );

		Alt_Text::queue( $id );
		Alt_Text::send( $id );

		$this->assertFileDoesNotExist( $attached );
		$this->assertSame( Alt_Text::FAILED, Records::get( $id )['alt']['status'] );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/alt-text' ) );
	}

	public function test_a_file_that_cant_be_brought_back_opens_nothing_leaves_nothing_on_the_disk_and_is_tried_again(): void {
		$id                    = $this->offloaded();
		$this->offload->broken = true;

		Optimizer::optimize( $id, Optimizer::AUTOMATIC );

		$record = Records::get( $id );

		$this->assertSame( array(), $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( array(), $this->on_disk( $id ) );
		$this->assertSame( array( Records::QUEUED, 1, 'bucket_unreachable' ), array( $record['status'], $record['attempts'], $record['error']['code'] ?? null ) );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::AUTOMATIC ) ) );
	}

	/**
	 * Scales an upload past 500 pixels, so WordPress keeps the original beside its `-scaled` copy.
	 */
	public function scale_at_500(): int {
		return 500;
	}

	/**
	 * A 640 by 480 JPEG uploaded with its sizes, then offloaded with its files taken off the disk.
	 */
	protected function offloaded(): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );
		$this->offload->start()->offload( $id );

		return $id;
	}

	/**
	 * Optimizes an offloaded image from start to finish.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function optimize( int $id ): void {
		Optimizer::optimize( $id, Optimizer::MANUAL );

		$answer = $this->finished( $id );
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, $this->zip( $answer )['bytes'] );
		Optimizer::check( $id );

		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
	}

	/**
	 * The attachment's files and copies that are on the disk, relative to the uploads folder.
	 *
	 * @param int $id The attachment's id.
	 * @return list<string>
	 */
	protected function on_disk( int $id ): array {
		$attached = (string) get_attached_file( $id, true );
		$metadata = wp_get_attachment_metadata( $id, true );
		$files    = array( $attached, Originals::kept( $id ) );

		foreach ( array_keys( Records::get( $id )['written'] ) as $written ) {
			$files[] = Paths::absolute( (string) $written );
		}

		foreach ( $metadata['sizes'] ?? array() as $size ) {
			$files[] = dirname( $attached ) . '/' . $size['file'];
		}

		$found = array();

		foreach ( array_unique( $files ) as $file ) {
			foreach ( array( $file, $file . '.webp', $file . '.avif' ) as $path ) {
				if ( file_exists( $path ) ) {
					$found[] = (string) Paths::relative( $path );
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * The outputs a group declared, by name, sorted.
	 *
	 * @param array<string, mixed> $declared The group's request.
	 * @return list<string>
	 */
	protected function names( array $declared ): array {
		$names = array_column( $declared['outputs'], 'name' );
		sort( $names );

		return $names;
	}

	/**
	 * The service's answer for the open group, finished: each declared output in
	 * its own format and WebP, at its declared dimensions, marked `replace`, but
	 * for the outputs whose own format is kept.
	 *
	 * @param int          $id   The attachment's id.
	 * @param list<string> $kept The outputs whose file in its own format the service doesn't replace.
	 * @return array<string, mixed>
	 */
	protected function finished( int $id, array $kept = array() ): array {
		$answer            = Api_Stub::fixture( 'group' )['data'];
		$answer['outputs'] = array();

		foreach ( Records::get( $id )['group']['outputs'] as $name => $output ) {
			foreach ( array( 'original', 'webp' ) as $format ) {
				$replace             = 'webp' === $format || ! in_array( $name, $kept, true );
				$answer['outputs'][] = array(
					'name'    => $name,
					'format'  => $format,
					'status'  => 'completed',
					'file'    => $replace ? $name . '.' . ( 'original' === $format ? 'jpg' : 'webp' ) : null,
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
	 * A zip holding every file the answer names, made from the test image at its dimensions.
	 *
	 * @param array<string, mixed> $answer The service's answer.
	 * @return array{bytes: string, entries: array<string, string>}
	 */
	protected function zip( array $answer ): array {
		$entries = array();

		foreach ( $answer['outputs'] as $output ) {
			if ( ! $output['replace'] ) {
				continue;
			}

			$webp   = 'webp' === $output['format'];
			$editor = wp_get_image_editor( DIR_TESTDATA . '/images/canola.jpg' );
			$editor->resize( $output['width'], $output['height'], true );
			$editor->set_quality( 10 );
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . ( $webp ? '.webp' : '.jpg' ), $webp ? 'image/webp' : 'image/jpeg' );

			$entries[ $output['file'] ] = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
			wp_delete_file( $saved['path'] );
		}

		$path    = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $bytes ) {
			$archive->addFromString( $name, $bytes );
		}

		$archive->close();
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test zip.
		wp_delete_file( $path );

		return array(
			'bytes'   => $bytes,
			'entries' => $entries,
		);
	}
}
