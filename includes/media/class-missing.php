<?php
/**
 * What an optimized image lacks: a format or a size the site asks for now.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use Iminify\WordPress\Site_Record;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A format is missing when the site asks for it now and the image's group wasn't
 * asked for it, as when the site turned AVIF on later; a copy the service left out
 * for coming out heavier isn't missing, since nothing more can be made. A size is
 * missing when the site sends it now and the image's record doesn't account for it,
 * as when a theme registered a new one: WordPress can make it, or has made it.
 *
 * An image sent for what it lacks isn't optimized again: it goes from its original
 * as any image does, at the level it has, and keeps every file the service doesn't
 * better, so its record is added to rather than replaced. It reads optimized again
 * however that ends (`Records::fail()`, `Records::end_fill()`), since its files
 * never stopped being the plugin's.
 *
 * The record's `result.sizes` names the sizes it accounts for, including those the
 * image is too small for, and `_iminify_sizes` holds them for a query, so finding the
 * images to look at costs one query, and a complete image is looked at once.
 */
class Missing {

	const FORMATS = 'formats';

	const SIZES = 'sizes';

	/**
	 * The sizes the site sends: the registered ones the service can name, narrowed
	 * to the site's choice, whose case the service ignores too.
	 *
	 * @return list<string>
	 */
	public static function wanted_sizes(): array {
		$picked = Site_Record::sizes();
		$picked = null === $picked ? null : array_map( 'strtolower', $picked );
		$names  = array();

		foreach ( array_keys( wp_get_registered_image_subsizes() ) as $name ) {
			$name = (string) $name;

			if ( preg_match( Declaration::OUTPUT_NAME, $name ) && ( null === $picked || in_array( strtolower( $name ), $picked, true ) ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * A page of the optimized images to look at for what they lack, in the order of their ids.
	 *
	 * @param string $what  FORMATS or SIZES.
	 * @param int    $limit How many at most.
	 * @param int    $after The id to start after.
	 * @return list<int>
	 */
	public static function images( string $what, int $limit, int $after ): array {
		return self::FORMATS === $what
			? Records::lacking( Records::FORMATS_META, Site_Record::formats(), $limit, $after )
			: Records::lacking( Records::SIZES_META, self::wanted_sizes(), $limit, $after );
	}

	/**
	 * How many optimized images may lack this.
	 *
	 * @param string $what FORMATS or SIZES.
	 */
	public static function count( string $what ): int {
		return self::FORMATS === $what
			? Records::count_lacking( Records::FORMATS_META, Site_Record::formats() )
			: Records::count_lacking( Records::SIZES_META, self::wanted_sizes() );
	}

	/**
	 * What an optimized image lacks now, or nothing for an image that isn't optimized.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $what       FORMATS or SIZES.
	 * @return list<string>
	 */
	public static function of( int $attachment, string $what ): array {
		if ( Records::OPTIMIZED !== Records::get( $attachment )['status'] ) {
			return array();
		}

		return self::FORMATS === $what ? self::formats( $attachment ) : self::sizes( $attachment );
	}

	/**
	 * Whether an image can be sent for what it lacks: it has an original to go from,
	 * and nothing replaced its files since.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function can_fill( int $attachment ): bool {
		if ( ! Optimizer::is_optimizable( $attachment ) || ! in_array( Restorer::availability( $attachment ), array( Restorer::AVAILABLE, Restorer::NOTHING ), true ) ) {
			return false;
		}

		$metadata = wp_get_attachment_metadata( $attachment, true );

		return null !== Originals::find( $attachment, is_array( $metadata ) ? $metadata : array(), null );
	}

	/**
	 * Makes the sizes WordPress hasn't, for an image about to be sent for them, and
	 * answers what to declare. WordPress makes them from the original it keeps, or
	 * from the plugin's output where it keeps none, so those only hold the place
	 * until the service's files, made from the true original, replace them.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{only: list<string>, fresh: list<string>, lacking: bool} The sizes to declare, those just made, and whether any size is still lacking.
	 */
	public static function make_sizes( int $attachment ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$missing = self::wanted_by_core( $attachment );

		if ( array() !== $missing && array() !== self::sizes( $attachment ) ) {
			Writer::$writing = $attachment;

			try {
				wp_update_image_subsizes( $attachment );
			} finally {
				Writer::$writing = null;
			}
		}

		/* A try that ended before its group opened (a full queue, an outage, a stop) left them made, so the next one has to know. */
		$made    = array_values( array_unique( array_merge( Records::get( $attachment )['made'], array_diff( $missing, self::wanted_by_core( $attachment ) ) ) ) );
		$lacking = self::sizes( $attachment );
		$only    = array_values( array_intersect( $lacking, self::with_files( $attachment ) ) );
		$source  = wp_get_original_image_path( $attachment );

		Records::update( $attachment, array( 'made' => $made ) );

		return array(
			'only'    => $only,
			/* WordPress made them from the file its original stands in; only from the plugin's own output does a size hold no bytes worth keeping. */
			'fresh'   => is_string( $source ) && Records::wrote( $attachment, $source ) ? array_values( array_intersect( $only, $made ) ) : array(),
			'lacking' => array() !== $lacking,
		);
	}

	/**
	 * Marks an image as looked at: the sizes it can never have stop finding it.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function settle( int $attachment ): void {
		$result = Records::get( $attachment )['result'];

		if ( null === $result ) {
			return;
		}

		$result['sizes'] = self::accounted_for( $attachment );

		Records::update( $attachment, array( 'result' => $result ) );
	}

	/**
	 * The sizes an image's record accounts for once a group finished or a look found
	 * nothing: what it accounted for before, and every size the site sends that
	 * WordPress has no more to make of.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<string>
	 */
	public static function accounted_for( int $attachment ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		return array_values(
			array_unique(
				array_merge(
					self::covered( Records::get( $attachment )['result'] ),
					array_diff( self::wanted_sizes(), self::wanted_by_core( $attachment ) )
				)
			)
		);
	}

	/**
	 * A result that only adds to the one the image has: every file keeps the bytes it
	 * had before the plugin ever touched it, so what the image saved stands, and each
	 * format and size it had stays beside the new ones.
	 *
	 * @param array<string, mixed> $old   The image's result.
	 * @param array<string, mixed> $added The group's result.
	 * @return array<string, mixed>
	 */
	public static function merged( array $old, array $added ): array {
		$outputs = isset( $old['outputs'] ) && is_array( $old['outputs'] ) ? $old['outputs'] : array();

		foreach ( isset( $added['outputs'] ) && is_array( $added['outputs'] ) ? $added['outputs'] : array() as $name => $output ) {
			$had = isset( $outputs[ $name ] ) && is_array( $outputs[ $name ] ) ? $outputs[ $name ] : null;

			if ( null !== $had ) {
				$output['before']  = (int) ( $had['before'] ?? $output['before'] );
				$output['formats'] = array_values( array_unique( array_merge( (array) ( $had['formats'] ?? array() ), (array) $output['formats'] ) ) );
			} else {
				/* A size WordPress just made has no bytes of its own to save on: made from the plugin's output, it can weigh less than the file made from the original. */
				$output['before'] = max( (int) $output['before'], (int) $output['after'] );
			}

			$outputs[ $name ] = $output;
		}

		$added['outputs'] = $outputs;
		$added['formats'] = array_values( array_unique( array_merge( isset( $old['formats'] ) && is_array( $old['formats'] ) ? $old['formats'] : array(), isset( $added['formats'] ) && is_array( $added['formats'] ) ? $added['formats'] : array() ) ) );

		return $added;
	}

	/**
	 * The formats the site asks for that the image's group wasn't asked for.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<string>
	 */
	protected static function formats( int $attachment ): array {
		$result = Records::get( $attachment )['result'];
		$asked  = isset( $result['formats'] ) && is_array( $result['formats'] ) ? $result['formats'] : array();

		return array_values( array_diff( Site_Record::formats(), $asked ) );
	}

	/**
	 * The sizes the site sends that the image's record doesn't account for, and that
	 * WordPress can make or has made. The usual image accounts for them all, which
	 * reads no file.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<string>
	 */
	protected static function sizes( int $attachment ): array {
		$open = array_diff( self::wanted_sizes(), self::covered( Records::get( $attachment )['result'] ) );

		if ( array() === $open ) {
			return array();
		}

		return array_values( array_intersect( $open, array_merge( self::wanted_by_core( $attachment ), self::with_files( $attachment ) ) ) );
	}

	/**
	 * The sizes a result names, or, before results named them, those it has outputs for.
	 *
	 * @param array<string, mixed>|null $result The record's result.
	 * @return list<string>
	 */
	protected static function covered( ?array $result ): array {
		if ( isset( $result['sizes'] ) && is_array( $result['sizes'] ) ) {
			return array_values( array_map( 'strval', $result['sizes'] ) );
		}

		return isset( $result['outputs'] ) && is_array( $result['outputs'] ) ? array_map( 'strval', array_keys( $result['outputs'] ) ) : array();
	}

	/**
	 * The registered sizes WordPress would make of this image and hasn't.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<string>
	 */
	protected static function wanted_by_core( int $attachment ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		return array_map( 'strval', array_keys( wp_get_missing_image_subsizes( $attachment ) ) );
	}

	/**
	 * The sizes the image's metadata names whose file is there.
	 *
	 * @param int $attachment The attachment's id.
	 * @return list<string>
	 */
	protected static function with_files( int $attachment ): array {
		$metadata = wp_get_attachment_metadata( $attachment, true );
		$folder   = dirname( (string) get_attached_file( $attachment, true ) );
		$names    = array();

		foreach ( is_array( $metadata ) ? ( $metadata['sizes'] ?? array() ) : array() as $name => $size ) {
			if ( is_file( $folder . '/' . wp_basename( $size['file'] ) ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}
}
