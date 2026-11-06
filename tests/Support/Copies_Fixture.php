<?php
/**
 * An uploaded image with WebP and AVIF copies recorded as the plugin's, as the writer leaves them.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;

/**
 * For the tests of what serves the copies (`PictureTest`, `BufferTest`). A test
 * using it deletes the copy files it wrote with `delete_copies()`.
 */
trait Copies_Fixture {

	/**
	 * The copy files the test wrote.
	 *
	 * @var list<string>
	 */
	protected $files = array();

	/**
	 * A 1,920 by 1,080 JPEG with its sizes, uploaded to the media library.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/33772.jpg' );
	}

	/**
	 * Each of the attachment's files by its size's name, `full` for the main one.
	 *
	 * @param int $id The attachment's id.
	 * @return array<string, string>
	 */
	protected function files_of( int $id ): array {
		$main     = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$files    = array( 'full' => $main );

		foreach ( $metadata['sizes'] as $size => $about ) {
			$files[ $size ] = dirname( $main ) . '/' . $about['file'];
		}

		return $files;
	}

	/**
	 * Writes copies beside files and records them as the plugin's own, as the writer does.
	 *
	 * @param int                               $id     The attachment's id.
	 * @param list<array{0: string, 1: string}> $copies Each file and the format of its copy.
	 */
	protected function copies( int $id, array $copies ): void {
		$record = Records::get( $id );

		foreach ( $copies as $copy ) {
			list( $file, $format ) = $copy;

			$path = $file . '.' . $format;
			file_put_contents( $path, $format . ' bytes of ' . wp_basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
			$this->files[] = $path;
			clearstatcache( true, $path );

			$record['copies'][ (string) Paths::relative( $path ) ] = array(
				'for'       => (string) Paths::relative( $file ),
				'format'    => $format,
				'bytes'     => (int) filesize( $path ),
				'origin'    => Records::ORIGIN,
				'for_bytes' => (int) filesize( $file ),
			);
		}

		Records::update( $id, array( 'copies' => $record['copies'] ) );
	}

	/**
	 * Deletes the copy files the test wrote.
	 */
	protected function delete_copies(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		$this->files = array();
	}
}
