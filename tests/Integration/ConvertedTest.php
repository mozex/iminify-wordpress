<?php
/**
 * Optimizing again, in their own format, the images another optimizer converted.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Elementor;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Others_Fixture;
use WP_UnitTestCase;

/**
 * Covers the redo of a converted image: an image Image Optimizer by Elementor converted to WebP, or
 * whose files Modern Image Formats replaced with AVIF, is made again in its own
 * format and names from the JPEG that plugin kept (`output_format`), with nothing
 * put back; the JPEG is kept in the plugin's own backup whatever the setting, and
 * every later send goes from it; the readers stop claiming the files Iminify
 * wrote; and Restore never puts the JPEG over them.
 */
class ConvertedTest extends WP_UnitTestCase {

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
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );
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

	public function test_an_image_elementor_converted_is_made_again_in_webp_from_the_jpeg_it_kept_at_the_press_and_is_never_restored_over(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id     = $this->fresh( $this->plain_upload() );
		$jpeg   = (string) get_attached_file( $id );
		$kept   = md5_file( $jpeg );
		$placed = $this->as_elementor( $id, true );
		$webp   = $placed['full'];
		$theirs = md5_file( $webp );

		Others::look();
		Local_Settings::update( array( 'backups' => false ) );

		$this->assertTrue( Optimizer::redoable( $id ) );
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $id ) );
		$this->assertArrayHasKey( 'iminify_optimize', Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertArrayNotHasKey( 'iminify_restore', Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertStringContainsString( 'Optimize again from the original at', Media_View::panel( $id ) );
		$this->assertStringContainsString( 'doesn&#039;t restore this image', Media_View::panel( $id ) );
		$this->assertStringContainsString( 'keeping their format and names so your posts still link to them', Media_View::panel( $id ) );
		$this->assertStringNotContainsString( 'Restore original', Media_View::panel( $id ) );

		$this->assertTrue( Optimizer::ask( $id, 'smart' ) );

		clearstatcache();
		$this->assertSame( $theirs, md5_file( $webp ), 'Nothing is put back: the WebP stays until Iminify\'s replaces it.' );
		$this->assertSame( $kept, md5_file( $jpeg ) );
		$this->assertTrue( Records::get( $id )['redo'] );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$record   = Records::get( $id );
		$backup   = Paths::backup( (string) Paths::relative( $jpeg ) );

		$this->assertSame( 'jpg', $declared['source']['format'] );
		$this->assertSame( (int) filesize( $jpeg ), $declared['source']['size'], 'The JPEG Elementor kept goes.' );
		$this->assertSame( 'webp', $declared['output_format'] );
		$this->assertSame( array( 'full', 'medium' ), array_column( $declared['outputs'], 'name' ), 'Its sizes are read in WebP, the JPEG ones Elementor left out.' );
		$this->assertSame( array(), array_filter( array_column( $declared['outputs'], 'size' ) ), 'No output names bytes: Elementor\'s files are made again from the original.' );
		$this->assertSame( 'image/webp', $record['group']['made'] );
		$this->assertSame( 'image/jpeg', $record['group']['type'] );
		$this->assertFileExists( $backup, 'The JPEG is kept with backups off.' );
		$this->assertSame( $kept, md5_file( $backup ) );
		$this->assertSame(
			array(
				'file'   => (string) Paths::relative( $jpeg ),
				'plugin' => 'elementor',
				'type'   => 'image/webp',
			),
			$record['converted']
		);

		$this->finish( $id, 'webp', $this->webp_entries( $jpeg, $record['group']['outputs'] ) );

		$record = Records::get( $id );

		clearstatcache();
		$this->assertSame( Records::OPTIMIZED, $record['status'] );
		$this->assertNotSame( $theirs, md5_file( $webp ) );
		$this->assertSame( 'image/webp', wp_get_image_mime( $webp ), 'Its files keep their format and names.' );
		$this->assertSame( (int) filesize( $webp ), $record['written'][ (string) Paths::relative( $webp ) ] );
		$this->assertSame( array( 'webp' ), $record['result']['formats'] );
		$this->assertSame( (int) filesize( $jpeg ), $record['result']['outputs']['full']['before'], 'Its savings count from the JPEG Elementor recorded, not from Elementor\'s WebP.' );
		$this->assertSame( $kept, md5_file( $jpeg ), 'Elementor\'s JPEG is never written over.' );

		/* Read again afterwards it is Iminify's: Elementor's reader claims the file at its converted name, but not one with the bytes Iminify wrote. */
		Others::forget();
		$this->assertNull( Others::verdict( $id, true ) );
		$this->assertTrue( Missing::can_fill( $id ), 'What it lacks is made from the plugin\'s backup of the JPEG.' );

		/* Restore stays Elementor's: the JPEG never goes over the WebP, whatever asks. */
		$refused = Restorer::restore( $id );

		clearstatcache();
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $id ) );
		$this->assertSame( 'restore_elsewhere', $refused->get_error_code() );
		$this->assertStringContainsString( 'Elementor', $refused->get_error_message() );
		$this->assertSame( (int) filesize( $webp ), Records::get( $id )['written'][ (string) Paths::relative( $webp ) ] );
		$this->assertSame( 'image/webp', wp_get_image_mime( $webp ) );
		$this->assertStringContainsString( 'doesn&#039;t restore this image', Media_View::panel( $id ) );
		$this->assertStringContainsString( 'Optimize again at', Media_View::panel( $id ) );
		$this->assertStringContainsString( 'keeping their format and names, and this counts', Media_View::panel( $id ) );
		$this->assertStringNotContainsString( 'Restore original', Media_View::panel( $id ) );
		$this->assertArrayNotHasKey( 'iminify_restore', Media_View::row_actions( array(), get_post( $id ) ) );
		$this->assertSame( array( $id ), Bulk::candidates( Bulk::RESTORE, 10, 0, array( $id ) )['ids'], 'A restore someone names is refused with why.' );

		/* Restore all leaves it as it is, and counts it as passed over rather than restored or failed. */
		Bulk::start( Bulk::RESTORE, Bulk::PAGE );
		Bulk::step();
		Bulk::step();

		$run = Bulk::run();

		clearstatcache();
		$this->assertSame( Bulk::DONE, $run['status'] );
		$this->assertSame( 0, $run['total'] );
		$this->assertSame( 1, $run['passed'] );
		$this->assertSame( 0, $run['failed'] );
		$this->assertSame( Records::OPTIMIZED, Records::get( $id )['status'] );
		$this->assertSame( (int) filesize( $webp ), Records::get( $id )['written'][ (string) Paths::relative( $webp ) ] );
	}

	public function test_optimizing_it_again_goes_from_the_plugins_backup_naming_no_bytes_and_a_file_replaced_since_ends_it(): void {
		$id     = $this->fresh( $this->plain_upload() );
		$jpeg   = (string) get_attached_file( $id );
		$placed = $this->as_elementor( $id, true );

		Others::look();
		Optimizer::ask( $id );
		Optimizer::optimize( $id, Optimizer::MANUAL );
		$this->finish( $id, 'webp', $this->webp_entries( $jpeg, Records::get( $id )['group']['outputs'] ) );

		$backup = Paths::backup( (string) Paths::relative( $jpeg ) );

		/* Elementor deleted, its JPEG and records gone: the plugin's own backup is the original now. */
		delete_post_meta( $id, Elementor::META );
		unlink( $jpeg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Elementor's kept file, gone with it.
		Others::forget();
		$opened = count( $this->api->calls( 'POST', 'groups' ) );

		$this->assertSame( $backup, Originals::converted( $id )['path'] );

		/* Sent for a format it lacks, it names the bytes of Iminify's own files, which the service then replaces only with smaller ones. */
		$site = Api_Stub::fixture( 'site' );

		$site['data']['settings']['avif'] = true;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();

		$this->assertSame( array( 'avif' ), Missing::of( $id, Missing::FORMATS ) );
		$this->assertTrue( Optimizer::fill( $id, Missing::FORMATS ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$filled  = json_decode( $this->api->calls( 'POST', 'groups' )[ $opened ]['args']['body'], true );
		$written = Records::get( $id )['written'];

		$this->assertSame( 'webp', $filled['output_format'] );
		$this->assertSame( $written[ (string) Paths::relative( $placed['full'] ) ], $filled['outputs'][0]['size'] );
		$this->assertSame( $written[ (string) Paths::relative( $placed['medium'] ) ], $filled['outputs'][1]['size'] );

		++$opened;
		$this->assertTrue( Optimizer::ask( $id, 'lossless' ) );

		Optimizer::optimize( $id, Optimizer::MANUAL );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[ $opened ]['args']['body'], true );

		$this->assertSame( (int) filesize( $backup ), $declared['source']['size'] );
		$this->assertSame( (string) Paths::relative( $backup ), Records::get( $id )['group']['source'] );
		$this->assertSame( 'webp', $declared['output_format'] );
		$this->assertSame( 'lossless', $declared['level'] );
		$this->assertSame( array(), array_filter( array_column( $declared['outputs'], 'size' ) ), 'Nothing went back first, so a gentler level replaces Iminify\'s own files too.' );

		/* A picture put at its name since (an edit, that plugin's own Restore) is no longer the one Iminify made, so its JPEG is no longer its original. */
		Optimizer::cancel( $id );
		file_put_contents( $placed['full'], (string) file_get_contents( $placed['medium'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- another picture at the image's name.
		clearstatcache();

		$this->assertNull( Originals::converted( $id ) );
		$this->assertNotSame( Restorer::THEIRS, Restorer::availability( $id ) );

		/* That plugin's own Restore turns the attachment back into its JPEG, whose files are no longer in the format the record names. */
		copy( $backup, $jpeg );
		update_post_meta( $id, '_wp_attached_file', (string) Paths::relative( $jpeg ) );

		$this->assertNull( Originals::converted( $id ) );
		$this->assertNotSame( Restorer::THEIRS, Restorer::availability( $id ) );
	}

	public function test_a_jpeg_iminify_optimized_before_elementor_converted_it_is_never_its_original_and_an_edit_since_is_not_elementors(): void {
		$id       = $this->fresh( $this->plain_upload() );
		$jpeg     = (string) get_attached_file( $id );
		$relative = (string) Paths::relative( $jpeg );
		$backup   = Paths::backup( $relative );
		$original = md5_file( $jpeg );

		/* Iminify optimized it first, keeping its original; Elementor then converted Iminify's output, recording its bytes as the original's. */
		$this->squeeze( $jpeg, $backup );
		clearstatcache();
		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( $relative => (int) filesize( $jpeg ) ),
				'backups' => array( (string) Paths::relative( $backup ) ),
			)
		);
		$placed = $this->as_elementor( $id, true );

		$this->assertSame( 'elementor', Others::verdict( $id, true )['plugin'] );
		$this->assertSame( $backup, Originals::converted( $id )['path'], 'The JPEG Elementor kept is Iminify\'s output: Iminify\'s own backup is the original.' );
		$this->assertSame( $original, md5_file( Originals::converted( $id )['path'] ) );

		/* An edit WordPress saved under its own name since is the picture as edited, not Elementor's conversion, so nothing redoes it from the JPEG before the edit. */
		$edited   = dirname( $placed['full'] ) . '/' . pathinfo( $placed['full'], PATHINFO_FILENAME ) . '-e1699999999999.webp';
		$metadata = wp_get_attachment_metadata( $id );

		copy( $placed['full'], $edited );
		$this->others_made[]  = $edited;
		$metadata['file']     = dirname( $metadata['file'] ) . '/' . wp_basename( $edited );
		$metadata['filesize'] = (int) filesize( $edited );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		update_post_meta( $id, '_wp_attached_file', $metadata['file'] );
		Others::forget();

		$this->assertNull( Originals::converted( $id ) );
		$this->assertNull( Others::verdict( $id, true ) );
		$this->assertFalse( Optimizer::redoable( $id ) );
	}

	public function test_an_image_modern_image_formats_replaced_with_avif_is_made_again_in_avif_by_the_bulk_run_and_lacks_no_webp(): void {
		$id   = $this->modern_formats();
		$avif = (string) get_attached_file( $id );

		Others::look();

		$this->assertSame( 'modern-formats', Others::verdict( $id )['plugin'] );
		$this->assertTrue( Optimizer::redoable( $id ) );

		$found = Bulk::candidates( Bulk::REDO, 10, 0 );

		$this->assertSame( array( $id ), $found['ids'], 'The run takes it rather than passing it over.' );
		$this->assertSame( array(), $found['passed'] );

		Site_Record::fetch();
		$run = Bulk::start( Bulk::REDO, Bulk::BACKGROUND );

		$this->assertSame( 1, $run['total'] );
		$this->assertTrue( Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Optimizer::BULK ) ) );

		Optimizer::optimize( $id, Optimizer::BULK );

		$declared = json_decode( $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );

		$this->assertSame( 'avif', $declared['output_format'] );
		$this->assertSame( 'jpg', $declared['source']['format'] );
		$this->assertSame( array( 'full', 'medium' ), array_column( $declared['outputs'], 'name' ) );
		$this->assertSame( array(), array_filter( array_column( $declared['outputs'], 'size' ) ) );

		$this->finish(
			$id,
			'avif',
			array(
				'full'   => $this->image( 'ours-64x48.avif' ),
				'medium' => $this->image( 'ours-32x24.avif' ),
			)
		);

		$record = Records::get( $id );

		clearstatcache();
		$this->assertSame( Records::OPTIMIZED, $record['status'] );
		$this->assertSame( md5( $this->image( 'ours-64x48.avif' ) ), md5_file( $avif ) );
		$this->assertSame( array( 'webp', 'avif' ), $record['result']['formats'], 'An AVIF file has no WebP beside it, so it lacks none.' );
		$this->assertSame( (int) filesize( dirname( $avif ) . '/modern.jpg' ), $record['result']['outputs']['full']['before'], 'The main file\'s savings count from the upload WordPress kept.' );
		$this->assertSame( array(), Missing::of( $id, Missing::FORMATS ) );
		$this->assertNotContains( $id, Missing::images( Missing::FORMATS, 10, 0 ) );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ), 'One daily image.' );

		/* Its `sources` still give Modern Image Formats' bytes, so it lets go of the files Iminify wrote. */
		Others::forget();
		$this->assertNull( Others::verdict( $id, true ) );
		$this->assertSame( Restorer::THEIRS, Restorer::availability( $id ) );
		$this->assertStringContainsString( 'Modern Image Formats', Restorer::restore( $id )->get_error_message() );
	}

	public function test_a_kept_original_past_the_plans_file_size_is_refused_at_the_press_with_nothing_changed(): void {
		$id     = $this->fresh( $this->plain_upload() );
		$placed = $this->as_elementor( $id, true );
		$site   = Api_Stub::fixture( 'site' );
		$theirs = md5_file( $placed['full'] );

		$site['data']['max_file_size'] = 1000;
		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Others::look();

		$refused = Optimizer::ask( $id );

		$this->assertSame( 'file_too_large', $refused->get_error_code() );
		$this->assertSame( $theirs, md5_file( $placed['full'] ) );
		$this->assertSame( Records::OTHER, Records::get( $id )['status'] );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );
	}

	/**
	 * An image Modern Image Formats made in AVIF with no JPEG fallback, as it leaves
	 * it: the attachment still says JPEG, its files are AVIF, and WordPress keeps the
	 * upload as its original.
	 */
	protected function modern_formats(): int {
		$folder = gmdate( 'Y/m' );
		$id     = (int) self::factory()->attachment->create_object(
			array(
				'file'           => $folder . '/modern.avif',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$path   = Paths::uploads() . '/' . $folder;
		$editor = wp_get_image_editor( DIR_TESTDATA . '/images/canola.jpg' );

		wp_mkdir_p( $path );
		$editor->resize( 64, 48, true );
		$editor->save( $path . '/modern.jpg', 'image/jpeg' );
		copy( dirname( __DIR__ ) . '/fixtures/images/theirs-64x48.avif', $path . '/modern.avif' );
		copy( dirname( __DIR__ ) . '/fixtures/images/theirs-32x24.avif', $path . '/modern-32x24.avif' );
		array_push( $this->others_made, $path . '/modern.jpg', $path . '/modern.avif', $path . '/modern-32x24.avif' );

		update_post_meta( $id, '_wp_attached_file', $folder . '/modern.avif' );
		update_post_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'width'          => 64,
				'height'         => 48,
				'file'           => $folder . '/modern.avif',
				'filesize'       => (int) filesize( $path . '/modern.avif' ),
				'original_image' => 'modern.jpg',
				'sources'        => array(
					'image/avif' => array(
						'file'     => 'modern.avif',
						'filesize' => (int) filesize( $path . '/modern.avif' ),
					),
				),
				'sizes'          => array(
					'medium' => array(
						'file'      => 'modern-32x24.avif',
						'width'     => 32,
						'height'    => 24,
						'mime-type' => 'image/avif',
						'filesize'  => (int) filesize( $path . '/modern-32x24.avif' ),
						'sources'   => array(
							'image/avif' => array(
								'file'     => 'modern-32x24.avif',
								'filesize' => (int) filesize( $path . '/modern-32x24.avif' ),
							),
						),
					),
				),
			)
		);
		Others::forget();

		return $id;
	}

	/**
	 * Answers the open group as finished, each declared output made in the given
	 * format from the bytes given for it, then lets the plugin look.
	 *
	 * @param int                   $id      The attachment's id.
	 * @param string                $format  The extension of the files the service made.
	 * @param array<string, string> $entries Each output's file, by its name.
	 */
	protected function finish( int $id, string $format, array $entries ): void {
		$answer = Api_Stub::fixture( 'group' )['data'];
		$zip    = wp_tempnam( 'group.zip' );
		$open   = new \ZipArchive();

		$answer['settings']['formats'] = array( 'original' );
		$answer['outputs']             = array();
		$open->open( $zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( Records::get( $id )['group']['outputs'] as $name => $output ) {
			$answer['outputs'][] = array(
				'name'    => $name,
				'format'  => 'original',
				'status'  => 'completed',
				'file'    => $name . '.' . $format,
				'width'   => $output['width'],
				'height'  => $output['height'],
				'size'    => strlen( $entries[ $name ] ),
				'replace' => true,
			);
			$open->addFromString( $name . '.' . $format, $entries[ $name ] );
		}

		$open->close();
		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, (string) file_get_contents( $zip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test zip.
		wp_delete_file( $zip );

		Optimizer::check( $id );
	}

	/**
	 * WebP files made from an image at each declared output's dimensions, turned
	 * over so none has the bytes of a WebP made from it before.
	 *
	 * @param string                              $image   The image.
	 * @param array<string, array<string, mixed>> $outputs The declared outputs.
	 * @return array<string, string>
	 */
	protected function webp_entries( string $image, array $outputs ): array {
		$entries = array();

		foreach ( $outputs as $name => $output ) {
			$editor = wp_get_image_editor( $image );
			$editor->resize( (int) $output['width'], (int) $output['height'], true );
			$editor->rotate( 180 );
			$editor->set_quality( 10 );
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.webp', 'image/webp' );

			$entries[ $name ] = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
			wp_delete_file( $saved['path'] );
		}

		return $entries;
	}

	/**
	 * A test image's bytes from `tests/fixtures/images`.
	 *
	 * @param string $name Its file name.
	 */
	protected function image( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/images/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a fixture.
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
