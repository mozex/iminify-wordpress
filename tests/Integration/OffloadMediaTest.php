<?php
/**
 * The copies WP Offload Media uploads with an attachment's files.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers which copies join WP Offload Media's files and which of its uploads
 * the plugin types and records; a copy going up with a written image is
 * OptimizerTest's.
 */
class OffloadMediaTest extends WP_UnitTestCase {

	/**
	 * The copy files the test wrote.
	 *
	 * @var list<string>
	 */
	protected $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		parent::tear_down();
	}

	public function test_only_the_plugins_own_copies_on_the_disk_join_its_files_and_only_their_uploads_are_typed_and_recorded(): void {
		$id   = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$main = (string) get_attached_file( $id );
		$own  = $this->copy( $main . '.avif' );
		$gone = (string) Paths::relative( $main ) . '.webp';

		$copies = array(
			(string) Paths::relative( $own ) => array(
				'for'    => (string) Paths::relative( $main ),
				'format' => 'avif',
				'bytes'  => (int) filesize( $own ),
				'origin' => Records::ORIGIN,
			),
			$gone                            => array(
				'for'    => (string) Paths::relative( $main ),
				'format' => 'webp',
				'bytes'  => 10,
				'origin' => Records::ORIGIN,
			),
			(string) Paths::relative( $this->copy( $main . '.other.webp' ) ) => array(
				'for'    => (string) Paths::relative( $main ),
				'format' => 'webp',
				'bytes'  => 10,
				'origin' => 'other-plugin',
			),
		);
		Records::update( $id, array( 'copies' => $copies ) );

		$paths = apply_filters( 'as3cf_attachment_file_paths', array( 'thumbnail' => 'kept.jpg' ), $id, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.

		$this->assertSame(
			array(
				'thumbnail' => 'kept.jpg',
				'iminify-' . wp_basename( $main ) . '.avif' => $own,
			),
			$paths
		);

		$media = array(
			'id'          => $id,
			'source_type' => 'media-library',
		);
		$key   = 'iminify-' . wp_basename( $own );
		$meta  = static function ( string $file, string $key, array $source ) use ( $id ) {
			return apply_filters( 'as3cf_object_meta', array( 'SourceFile' => $file ), $id, $key, false, $source ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
		};

		/* Another key, another kind of item, and a file under a key that isn't its own are left as they come. */
		$this->assertSame( array( 'SourceFile' => $own ), $meta( $own, 'thumbnail', $media ) );
		$this->assertSame( array( 'SourceFile' => $own ), $meta( $own, $key, array( 'source_type' => 'bboss-user-media' ) ) );
		$this->assertSame( array( 'SourceFile' => $main ), $meta( $main, $key, $media ) );
		$this->assertArrayNotHasKey( 'offloaded', Records::get( $id )['copies'][ Paths::relative( $own ) ] );

		$this->assertSame(
			array(
				'SourceFile'  => $own,
				'ContentType' => 'image/avif',
			),
			$meta( $own, $key, $media )
		);

		/* An upload that failed records nothing, and forgets what it carried. */
		do_action( 'as3cf_post_handle_item_upload', new WP_Error( 'upload-object-1', 'Access Denied' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action, as it fires it.
		do_action( 'as3cf_post_handle_item_upload', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action, as it fires it.

		$this->assertArrayNotHasKey( 'offloaded', Records::get( $id )['copies'][ Paths::relative( $own ) ] );

		/* One that went up records the copy with the bytes that went, unless the copy was written again meanwhile. */
		$meta( $own, $key, $media );
		do_action( 'as3cf_post_handle_item_upload', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action, as it fires it.

		$this->assertSame( (int) filesize( $own ), Records::get( $id )['copies'][ Paths::relative( $own ) ]['offloaded'] );

		$meta( $own, $key, $media );
		$rewritten = Records::get( $id )['copies'];

		$rewritten[ Paths::relative( $own ) ] = array_merge( $copies[ Paths::relative( $own ) ], array( 'bytes' => 99 ) );
		Records::update( $id, array( 'copies' => $rewritten ) );
		do_action( 'as3cf_post_handle_item_upload', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action, as it fires it.

		$this->assertArrayNotHasKey( 'offloaded', Records::get( $id )['copies'][ Paths::relative( $own ) ] );
	}

	/**
	 * Writes a file the test deletes afterwards.
	 *
	 * @param string $path Where.
	 */
	protected function copy( string $path ): string {
		file_put_contents( $path, 'copy of ' . wp_basename( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$this->files[] = $path;

		return $path;
	}
}
