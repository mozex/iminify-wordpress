<?php
/**
 * Sending an image without holding it in memory, and reading a zip the same way.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Zip;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers the streamed source and its fallback, how large a file the fallback
 * takes, and a zip entry copied into a file without reaching PHP's memory.
 */
class UploadTest extends WP_UnitTestCase {

	/**
	 * The memory limit before a test changed it.
	 *
	 * @var string
	 */
	protected $limit;

	/**
	 * Files a test made.
	 *
	 * @var list<string>
	 */
	protected $files = array();

	public function set_up(): void {
		parent::set_up();
		$this->limit = (string) ini_get( 'memory_limit' );
	}

	public function tear_down(): void {
		ini_set( 'memory_limit', $this->limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- puts back the limit a test lowered.

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		remove_all_filters( 'iminify_stream_source' );
		parent::tear_down();
	}

	public function test_a_source_streams_over_curl_and_is_built_into_a_body_only_where_it_cant(): void {
		$path = DIR_TESTDATA . '/images/canola.jpg';

		/* This host sends over cURL: no body, and the file for the cURL hook to stream. */
		$streamed = Upload::arguments( $path, 'image/jpeg' );

		$this->assertIsArray( $streamed );
		$this->assertSame( '', $streamed['body'] );
		$this->assertArrayNotHasKey( 'headers', $streamed );
		$this->assertSame(
			array(
				'path' => $path,
				'type' => 'image/jpeg',
				'name' => 'source.jpg',
			),
			$streamed[ Upload::ARGUMENT ]
		);
		$this->assertNull( Upload::max_bytes( $path ) );

		/* cURL is handed the file as the form's `file` field for a request that carries one, and nothing for any other. */
		$fields = Upload::fields( $streamed );

		$this->assertInstanceOf( \CURLFile::class, $fields['file'] ?? null );
		$this->assertSame( array( $path, 'image/jpeg', 'source.jpg' ), array( $fields['file']->getFilename(), $fields['file']->getMimeType(), $fields['file']->getPostFilename() ) );
		$this->assertNull( Upload::fields( array( 'body' => '' ) ) );
		$this->assertNotFalse( has_action( 'http_api_curl', array( Upload::class, 'attach' ) ) );

		/* A host that can't stream builds the body in memory, as a multipart form. */
		add_filter( 'iminify_stream_source', '__return_false' );
		$built = Upload::arguments( $path, 'image/jpeg' );

		$this->assertIsArray( $built );
		$this->assertArrayNotHasKey( Upload::ARGUMENT, $built );
		$this->assertMatchesRegularExpression( '/^multipart\/form-data; boundary=(iminify-[A-Za-z0-9]{24})$/', $built['headers']['Content-Type'] );
		$boundary = substr( $built['headers']['Content-Type'], strlen( 'multipart/form-data; boundary=' ) );
		$this->assertSame(
			'--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"source.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"
				. file_get_contents( $path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test image's bytes.
				. "\r\n--" . $boundary . "--\r\n",
			$built['body']
		);

		$missing = Upload::arguments( $path . '.gone', 'image/jpeg' );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'source_unreadable', $missing->get_error_code() );
	}

	public function test_where_it_cant_stream_the_largest_source_is_what_memory_holds_and_the_plans_size_still_caps_it(): void {
		add_filter( 'iminify_stream_source', '__return_false' );
		$max_source = new \ReflectionMethod( Optimizer::class, 'max_source' );

		/* PHP 8.1 made it a no-op and 8.5 deprecates it, but PHP 7.4 still needs it to call a protected method. */
		if ( PHP_VERSION_ID < 80100 ) {
			$max_source->setAccessible( true );
		}

		ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- no limit, so memory caps nothing.
		$this->assertNull( Upload::max_bytes() );
		$this->assertSame( 5000, $max_source->invoke( null, array( 'max_file_size' => 5000 ) ) );

		/* About twice the file has to fit beside what is in use and the reserve. */
		ini_set( 'memory_limit', (string) ( memory_get_usage() + Upload::MEMORY_RESERVE + 20 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- a limit the test can reason about.
		$max = (int) Upload::max_bytes();

		$this->assertGreaterThan( 9 * MB_IN_BYTES, $max );
		$this->assertLessThanOrEqual( 10 * MB_IN_BYTES, $max );
		$this->assertLessThanOrEqual( 10 * MB_IN_BYTES, $max_source->invoke( null, array( 'max_file_size' => 90 * MB_IN_BYTES ) ) );
		$this->assertSame( 5000, $max_source->invoke( null, array( 'max_file_size' => 5000 ) ) );
		$this->assertLessThanOrEqual( 10 * MB_IN_BYTES, $max_source->invoke( null, array() ) );

		/* A file past it is refused rather than read. */
		$large = $this->file( 12 * MB_IN_BYTES );
		$this->assertSame( 'source_too_large', Upload::arguments( $large, 'image/jpeg' )->get_error_code() );
	}

	public function test_a_zip_entry_is_copied_into_a_file_without_passing_through_memory(): void {
		$zip     = $this->path( 'zip' );
		$archive = new \ZipArchive();
		$archive->open( $zip, \ZipArchive::CREATE );
		$archive->addFile( $this->file( 40 * MB_IN_BYTES ), 'full.jpg' );
		$archive->close();

		$archive = Zip::open( $zip );
		$this->assertInstanceOf( \ZipArchive::class, $archive );

		/* Read into memory, the entry alone would pass the limit. */
		ini_set( 'memory_limit', (string) ( memory_get_usage() + 16 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- leaves too little room to hold the entry.
		$into   = $this->path( 'jpg' );
		$copied = Zip::extract( $archive, 'full.jpg', $into );
		ini_set( 'memory_limit', $this->limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- puts the limit back.

		clearstatcache( true, $into );
		$this->assertSame( 40 * MB_IN_BYTES, $copied );
		$this->assertSame( 40 * MB_IN_BYTES, filesize( $into ) );
		$this->assertSame( 40 * MB_IN_BYTES, Zip::size( $archive, 'full.jpg' ) );
		$this->assertNull( Zip::extract( $archive, 'Full.jpg', $this->path( 'jpg' ) ) );
		$this->assertNull( Zip::size( $archive, '../full.jpg' ) );
		$archive->close();
	}

	/**
	 * A file of the given size in the temporary folder, written a piece at a time.
	 *
	 * @param int $bytes Its size.
	 */
	protected function file( int $bytes ): string {
		$path  = $this->path( 'bin' );
		$piece = str_repeat( 'i', MB_IN_BYTES );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writes a large test file a piece at a time.
		$handle = fopen( $path, 'wb' );

		for ( $written = 0; $written < $bytes; $written += MB_IN_BYTES ) {
			fwrite( $handle, $piece ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the test file.
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the test file.

		return $path;
	}

	/**
	 * A new path in the temporary folder, removed after the test.
	 *
	 * @param string $extension Its extension.
	 */
	protected function path( string $extension ): string {
		$path          = get_temp_dir() . 'iminify-test-' . wp_generate_password( 8, false ) . '.' . $extension;
		$this->files[] = $path;

		return $path;
	}
}
