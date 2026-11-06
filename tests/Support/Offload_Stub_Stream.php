<?php
/**
 * The stand-in for WP Offload Media's stream wrapper, which reads its bucket.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

/**
 * WP Offload Media answers a filtered path of a file that is only in its bucket
 * with a path its stream wrapper reads over the network. This one records each
 * read and refuses it, so a test sees anything that would have reached the
 * bucket that way.
 */
class Offload_Stub_Stream {

	const SCHEME = 'iminify-bucket';

	/**
	 * Every path something tried to read or measure.
	 *
	 * @var list<string>
	 */
	public static $reads = array();

	/**
	 * The stream's context, which PHP sets.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Opening a file in the bucket: recorded, and refused.
	 *
	 * @param string $path The path.
	 */
	public function stream_open( string $path ): bool {
		self::$reads[] = $path;

		return false;
	}

	/**
	 * Measuring a file in the bucket: recorded, and answered as missing.
	 *
	 * @param string $path The path.
	 * @return array<int|string, int>|false
	 */
	public function url_stat( string $path ) {
		self::$reads[] = $path;

		return false;
	}
}
