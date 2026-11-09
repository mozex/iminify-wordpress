<?php
/**
 * A stand-in for WP Offload Media with its "Remove Local Media" on.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

use Iminify\WordPress\Media\Paths;

/**
 * Does what WP Offload Media 3.4 does through the hooks the plugin meets, with a
 * folder as its bucket: a save of an attachment's metadata uploads every file of
 * it on the disk (`as3cf_attachment_file_paths`, each file's meta through
 * `as3cf_object_meta`) unless `as3cf_pre_update_attachment_metadata` cancels,
 * says it went up (`as3cf_post_handle_item_upload`), then takes every file it
 * holds off the disk (`as3cf_remove_local_files`,
 * `as3cf_preserve_file_from_local_removal`); an upload that fails says so and
 * takes nothing off. The attachment's address is then the bucket's, and a
 * filtered `get_attached_file()` or `wp_get_original_image_path()` of a file that
 * isn't on the disk brings it back when `as3cf_get_attached_file_copy_back_to_local`
 * asks for that file, or else answers what WP Offload Media's stream wrapper does,
 * a path in the bucket to the item's main file, which `Offload_Stub_Stream`
 * records any read of and refuses. An item keeps every file it ever held, as WP
 * Offload Media's does. Unfiltered reads go by it, as they go by WP Offload Media.
 */
class Offload_Stub {

	const HOST = 'https://bucket.example.com/wp-content/uploads';

	/**
	 * The bucket: a folder holding each file at its path in the uploads folder.
	 *
	 * @var string
	 */
	public $bucket = '';

	/**
	 * Each upload's files, by their key, relative to the uploads folder.
	 *
	 * @var list<array<string, string>>
	 */
	public $uploads = array();

	/**
	 * The meta each file was last sent with, by its key.
	 *
	 * @var array<string, mixed>
	 */
	public $meta = array();

	/**
	 * The files brought back from the bucket, relative to the uploads folder.
	 *
	 * @var list<string>
	 */
	public $downloads = array();

	/**
	 * Whether a copy back fails, leaving a piece of the file behind.
	 *
	 * @var bool
	 */
	public $broken = false;

	/**
	 * Whether an upload fails, taking nothing off the disk.
	 *
	 * @var bool
	 */
	public $failing = false;

	/**
	 * Every file each item holds, by attachment, then by key.
	 *
	 * @var array<int, array<string, string>>
	 */
	protected $items = array();

	/**
	 * A folder WP Offload Media's object versioning puts between an item's
	 * year and month folders and its files, or none.
	 *
	 * @var string
	 */
	public $version = '';

	/**
	 * A stand-in whose stream wrapper has recorded nothing yet.
	 */
	public function __construct() {
		Offload_Stub_Stream::$reads = array();
	}

	/**
	 * Hooks it in.
	 */
	public function start(): self {
		$this->bucket = untrailingslashit( get_temp_dir() ) . '/iminify-bucket-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->bucket );

		$GLOBALS['as3cf'] = new \stdClass(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP Offload Media's own global.

		if ( ! in_array( Offload_Stub_Stream::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( Offload_Stub_Stream::SCHEME, Offload_Stub_Stream::class );
		}

		add_filter( 'wp_update_attachment_metadata', array( $this, 'upload' ), 110, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'url' ), 99, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 99, 5 );
		add_filter( 'get_attached_file', array( $this, 'attached' ), 10, 2 );
		add_filter( 'wp_get_original_image_path', array( $this, 'attached' ), 10, 2 );

		return $this;
	}

	/**
	 * Takes it out, with its bucket.
	 */
	public function stop(): void {
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'upload' ), 110 );
		remove_filter( 'wp_get_attachment_url', array( $this, 'url' ), 99 );
		remove_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 99 );
		remove_filter( 'get_attached_file', array( $this, 'attached' ) );
		remove_filter( 'wp_get_original_image_path', array( $this, 'attached' ) );
		unset( $GLOBALS['as3cf'] );

		if ( is_dir( $this->bucket ) ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->bucket, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the test's bucket.
			}

			rmdir( $this->bucket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the test's bucket.
		}
	}

	/**
	 * Offloads an attachment WordPress has finished making, as its next save would.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public function offload( int $attachment ): void {
		$this->upload( wp_get_attachment_metadata( $attachment, true ), $attachment );
	}

	/**
	 * The bytes the bucket holds of a file, or null.
	 *
	 * @param string $path The file's absolute path on the disk.
	 */
	public function held( string $path ): ?string {
		$file = $this->bucket . '/' . Paths::relative( $path );

		return is_file( $file ) ? (string) file_get_contents( $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's bucket.
	}

	/**
	 * The `wp_update_attachment_metadata` filter at WP Offload Media's priority.
	 *
	 * @param mixed $data       The metadata.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public function upload( $data, $attachment ) {
		$attachment = (int) $attachment;

		if ( ! is_array( $data ) || false !== apply_filters( 'as3cf_pre_update_attachment_metadata', false, $data, $attachment, null ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
			return $data;
		}

		/* Like WP Offload Media, wait while WordPress is still making sizes, measured on the metadata being saved. */
		$saving = static function ( $value, $id, $key, $single ) use ( $attachment, $data ) {
			return (int) $id === $attachment && '_wp_attachment_metadata' === $key && $single ? array( $data ) : $value;
		};

		require_once ABSPATH . 'wp-admin/includes/image.php';
		add_filter( 'get_post_metadata', $saving, 10, 4 );
		$missing = wp_get_missing_image_subsizes( $attachment );
		remove_filter( 'get_post_metadata', $saving );

		if ( array() !== $missing ) {
			return $data;
		}

		$attached = (string) get_attached_file( $attachment, true );
		$folder   = dirname( $attached );
		$paths    = array( '__as3cf_primary' => $attached );

		if ( isset( $data['original_image'] ) ) {
			$paths['__original_image'] = $folder . '/' . wp_basename( (string) $data['original_image'] );
		}

		foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : array() as $name => $size ) {
			$paths[ (string) $name ] = $folder . '/' . wp_basename( (string) $size['file'] );
		}

		$paths = (array) apply_filters( 'as3cf_attachment_file_paths', $paths, $attachment, $data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
		$sent  = array();

		if ( $this->failing ) {
			$this->uploads[] = array();

			do_action( 'as3cf_post_handle_item_upload', new \WP_Error( 'upload-object-1', 'Access Denied' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action when an upload failed.

			return $data;
		}

		foreach ( $paths as $key => $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$source             = array(
				'id'          => $attachment,
				'source_type' => 'media-library',
			);
			$this->meta[ $key ] = apply_filters( 'as3cf_object_meta', array( 'SourceFile' => $path ), $attachment, $key, false, $source ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.

			$copy = $this->bucket . '/' . Paths::relative( $path );
			wp_mkdir_p( dirname( $copy ) );
			copy( $path, $copy );
			$sent[ (string) $key ] = (string) Paths::relative( $path );
		}

		$this->uploads[]            = $sent;
		$this->items[ $attachment ] = array_merge( $this->items[ $attachment ] ?? array(), $paths );

		do_action( 'as3cf_post_handle_item_upload', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's action once every file went up.

		$local = array_values( array_filter( $this->items[ $attachment ], 'file_exists' ) );

		foreach ( (array) apply_filters( 'as3cf_remove_local_files', $local, null, array( 'id' => $attachment ) ) as $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
			if ( false === apply_filters( 'as3cf_preserve_file_from_local_removal', false, $file ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
				wp_delete_file( $file );
			}
		}

		return $data;
	}

	/**
	 * The `wp_get_attachment_url` filter: an offloaded attachment's address is the bucket's.
	 *
	 * @param mixed $url        The address.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public function url( $url, $attachment ) {
		if ( ! isset( $this->items[ (int) $attachment ] ) ) {
			return $url;
		}

		$url = str_replace( (string) wp_get_upload_dir()['baseurl'], self::HOST, (string) $url );

		return '' === $this->version ? $url : dirname( $url ) . '/' . $this->version . '/' . wp_basename( $url );
	}

	/**
	 * The `wp_calculate_image_srcset` filter: an offloaded attachment's sizes are the bucket's too.
	 *
	 * @param mixed $sources    The srcset's sources.
	 * @param mixed $size       The image's size.
	 * @param mixed $src        Its address.
	 * @param mixed $metadata   Its metadata.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public function srcset( $sources, $size, $src, $metadata, $attachment ) {
		if ( ! is_array( $sources ) || ! isset( $this->items[ (int) $attachment ] ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			$sources[ $width ]['url'] = $this->url( $source['url'], $attachment );
		}

		return $sources;
	}

	/**
	 * The `get_attached_file` and `wp_get_original_image_path` filters: a file
	 * only in the bucket answers its address, or comes back when the copy-back
	 * filter asks for it.
	 *
	 * @param mixed $file       The file's path on the disk.
	 * @param mixed $attachment The attachment's id.
	 * @return mixed
	 */
	public function attached( $file, $attachment ) {
		if ( ! is_string( $file ) || ! isset( $this->items[ (int) $attachment ] ) || file_exists( $file ) ) {
			return $file;
		}

		$relative = (string) Paths::relative( $file );

		if ( true === apply_filters( 'as3cf_get_attached_file_copy_back_to_local', false, $file, (int) $attachment, null ) && is_file( $this->bucket . '/' . $relative ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Offload Media's filter, as it runs it.
			if ( $this->broken ) {
				file_put_contents( $file, 'a piece' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a download that stopped part of the way.

				return self::HOST . '/' . $relative;
			}

			copy( $this->bucket . '/' . $relative, $file );
			$this->downloads[] = $relative;

			return $file;
		}

		return Offload_Stub_Stream::SCHEME . '://bucket/' . Paths::relative( (string) $this->items[ (int) $attachment ]['__as3cf_primary'] );
	}

	/**
	 * The reads anything made of a file through its path in the bucket.
	 *
	 * @return list<string>
	 */
	public function reads(): array {
		return Offload_Stub_Stream::$reads;
	}
}
