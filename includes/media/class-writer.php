<?php
/**
 * Writes a finished group's files over the attachment's.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes only what the service marked `replace`: each output in the source's
 * format over the file of the same name, each WebP or AVIF beside it as
 * `photo.jpg.webp`, every one checked before it is put in place. A file that
 * changed since the group was opened is never written over. The metadata is
 * read fresh just before it is saved, and only the plugin's own keys change in
 * it (`filesize`, and the main file's width and height when the site's maximum
 * width or size limit made it smaller), so every key another plugin keeps
 * there survives.
 */
class Writer {

	/**
	 * The attachment whose metadata the plugin is saving, so its own save never queues the attachment again.
	 *
	 * @var int|null
	 */
	public static $writing = null;

	/**
	 * The names a file in the group's answer may have.
	 */
	const FILE_NAME = '/^[A-Za-z0-9_][A-Za-z0-9._-]*\.(jpe?g|png|gif|webp|avif)$/D';

	/**
	 * The copies the plugin makes beside a file, by format, with their MIME types.
	 */
	const COPIES = array(
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);

	/**
	 * Writes a finished group's files over an attachment's, and saves what it wrote.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $group      The open group as its record keeps it.
	 * @param array<string, mixed> $answer     The group as the service answered it.
	 * @param string               $zip        The group's downloaded zip.
	 * @return array{outputs: array<string, array<string, mixed>>, skipped: int, no_room: int}|WP_Error
	 */
	public static function write( int $attachment, array $group, array $answer, string $zip ) {
		$declared = isset( $group['outputs'] ) && is_array( $group['outputs'] ) ? $group['outputs'] : array();
		$record   = Records::get( $attachment );
		$put      = self::put( $declared, $answer, $zip, $record['written'], $record['copies'], Paths::uploads() );

		if ( is_wp_error( $put ) ) {
			return $put;
		}

		Records::update(
			$attachment,
			array(
				'written' => $put['written'],
				'copies'  => $put['copies'],
			)
		);

		self::save_metadata( $attachment, $declared, $put['outputs'] );

		return array(
			'outputs' => $put['outputs'],
			'skipped' => $put['skipped'],
			'no_room' => $put['no_room'],
		);
	}

	/**
	 * Writes a finished group's files under a root, whose relative paths the
	 * declared outputs and the record name: the uploads folder for an attachment,
	 * `wp-content` for a file in another folder (`Folders\Optimizer`). Answers what
	 * it wrote with the record's `written` and `copies` as they are now; saving
	 * them is the caller's.
	 *
	 * @param array<string, array<string, mixed>> $declared The group's outputs as declared, each `file` relative to the root.
	 * @param array<string, mixed>                $answer   The group as the service answered it.
	 * @param string                              $zip      The group's downloaded zip.
	 * @param array<string, int>                  $written  The record's `written`.
	 * @param array<string, mixed>                $copies   The record's `copies`.
	 * @param string                              $root     The folder the paths are relative to, without a trailing slash.
	 * @return array{outputs: array<string, array<string, mixed>>, skipped: int, no_room: int, written: array<string, int>, copies: array<string, mixed>}|WP_Error
	 */
	public static function put( array $declared, array $answer, string $zip, array $written, array $copies, string $root ) {
		foreach ( $declared as $output ) {
			$path = Paths::under( $root, (string) $output['file'] );
			clearstatcache( true, $path );

			if ( ! is_file( $path ) || (int) filesize( $path ) !== (int) $output['size'] ) {
				return new WP_Error( 'changed', __( 'The image changed while Iminify was optimizing it, so it\'s sent again.', 'iminify-image-optimizer' ) );
			}
		}

		$writes  = self::writes( $declared, $answer, $root );
		$archive = Zip::open( $zip );

		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$summary = array();
		$made    = array();
		$skipped = 0;
		$no_room = 0;

		foreach ( $declared as $name => $output ) {
			$summary[ $name ] = array(
				'before'  => (int) $output['size'],
				'after'   => (int) $output['size'],
				'width'   => (int) $output['width'],
				'height'  => (int) $output['height'],
				'formats' => array(),
			);
		}

		$own = Copies::own( $copies );

		foreach ( $writes as $write ) {
			$target = Paths::under( $root, $write['file'] );
			$size   = Zip::size( $archive, $write['entry'] );

			/* A WebP or AVIF of that name another tool made beside the file, or rewrote since the plugin made it, stays its own: written over, it would read as the plugin's and go with a restore. */
			if ( 'original' !== $write['format'] && ( file_exists( $target ) || is_link( $target ) ) && ! self::still_own( $target, $own[ $write['file'] ] ?? null ) ) {
				++$skipped;
				continue;
			}

			if ( null === $size ) {
				++$skipped;
				continue;
			}

			/* A replacement holds the old file and the new one at once while it is checked. */
			if ( ! Disk::has_room_for( $size ) ) {
				++$skipped;
				++$no_room;
				continue;
			}

			$bytes = Files::replace(
				$target,
				static function ( string $temporary ) use ( $archive, $write ): ?int {
					return Zip::extract( $archive, $write['entry'], $temporary );
				},
				static function ( string $temporary ) use ( $write ): bool {
					$size = wp_getimagesize( $temporary );

					return is_array( $size )
						&& (int) $size[0] === $write['width']
						&& (int) $size[1] === $write['height']
						&& wp_get_image_mime( $temporary ) === $write['type'];
				}
			);

			if ( null === $bytes ) {
				++$skipped;
				continue;
			}

			if ( 'original' === $write['format'] ) {
				$written[ $write['file'] ]             = $bytes;
				$summary[ $write['output'] ]['after']  = $bytes;
				$summary[ $write['output'] ]['width']  = $write['width'];
				$summary[ $write['output'] ]['height'] = $write['height'];
				continue;
			}

			$copies[ $write['file'] ]                 = array(
				'for'    => (string) $declared[ $write['output'] ]['file'],
				'format' => $write['format'],
				'bytes'  => $bytes,
				'origin' => Records::ORIGIN,
			);
			$made[]                                   = $write['file'];
			$summary[ $write['output'] ]['formats'][] = $write['format'];
		}

		$archive->close();

		/* The file a copy stands beside, as it is once this group's files are written: `<picture>` serves the copy only while it is unchanged. */
		foreach ( $made as $copy ) {
			$beside = Paths::under( $root, (string) $copies[ $copy ]['for'] );
			clearstatcache( true, $beside );

			$copies[ $copy ]['for_bytes'] = is_file( $beside ) ? (int) filesize( $beside ) : -1;
		}

		return array(
			'outputs' => $summary,
			'skipped' => $skipped,
			'no_room' => $no_room,
			'written' => $written,
			'copies'  => $copies,
		);
	}

	/**
	 * Whether a file at a copy's name is the plugin's own copy as it wrote it.
	 *
	 * @param string     $path  The file.
	 * @param mixed|null $about The record's entry for the copy, or null for none.
	 */
	protected static function still_own( string $path, $about ): bool {
		clearstatcache( true, $path );

		return is_array( $about ) && ! is_link( $path ) && (int) filesize( $path ) === (int) ( $about['bytes'] ?? -1 );
	}

	/**
	 * Whether a write's summary changed any file or made any copy.
	 *
	 * @param array<string, array<string, mixed>> $outputs What was written for each output.
	 */
	public static function wrote_any( array $outputs ): bool {
		foreach ( $outputs as $output ) {
			if ( $output['after'] !== $output['before'] || array() !== $output['formats'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Each file to write: an output the service marked `replace` that the
	 * group declared, by the name its answer gives it, in a format the plugin
	 * writes.
	 *
	 * @param array<string, array<string, mixed>> $declared The group's outputs as declared.
	 * @param array<string, mixed>                $answer   The group as the service answered it.
	 * @param string                              $root     The folder the declared files are relative to.
	 * @return list<array{output: string, format: string, entry: string, file: string, type: string, width: int, height: int}>
	 */
	protected static function writes( array $declared, array $answer, string $root ): array {
		$outputs = isset( $answer['outputs'] ) && is_array( $answer['outputs'] ) ? $answer['outputs'] : array();
		$writes  = array();

		foreach ( $outputs as $output ) {
			if ( ! is_array( $output ) || true !== ( $output['replace'] ?? null ) ) {
				continue;
			}

			$name   = isset( $output['name'] ) ? (string) $output['name'] : '';
			$format = isset( $output['format'] ) ? (string) $output['format'] : '';
			$entry  = isset( $output['file'] ) ? (string) $output['file'] : '';

			if ( ! isset( $declared[ $name ] ) || ! preg_match( self::FILE_NAME, $entry ) || ( 'original' !== $format && ! isset( self::COPIES[ $format ] ) ) ) {
				continue;
			}

			$file = (string) $declared[ $name ]['file'];
			$path = Paths::under( $root, $file );

			$writes[] = array(
				'output' => $name,
				'format' => $format,
				'entry'  => $entry,
				'file'   => 'original' === $format ? $file : $file . '.' . $format,
				'type'   => 'original' === $format ? (string) wp_get_image_mime( $path ) : self::COPIES[ $format ],
				'width'  => (int) ( $output['width'] ?? 0 ),
				'height' => (int) ( $output['height'] ?? 0 ),
			);
		}

		return $writes;
	}

	/**
	 * Saves the new file sizes, and the main file's dimensions when they
	 * changed, into the metadata as it stands now.
	 *
	 * @param int                                 $attachment The attachment's id.
	 * @param array<string, array<string, mixed>> $declared   The group's outputs as declared.
	 * @param array<string, array<string, mixed>> $summary    What was written for each: its bytes before and after, its width and height, and the copies made.
	 */
	protected static function save_metadata( int $attachment, array $declared, array $summary ): void {
		$metadata = wp_get_attachment_metadata( $attachment, true );

		if ( ! is_array( $metadata ) ) {
			return;
		}

		$by_file = array();

		foreach ( $summary as $name => $output ) {
			if ( $output['after'] !== $output['before'] ) {
				$by_file[ wp_basename( (string) $declared[ $name ]['file'] ) ] = $output;
			}
		}

		if ( isset( $summary[ Declaration::MAIN ] ) && $summary[ Declaration::MAIN ]['after'] !== $summary[ Declaration::MAIN ]['before'] ) {
			$metadata['filesize'] = $summary[ Declaration::MAIN ]['after'];
			$metadata['width']    = $summary[ Declaration::MAIN ]['width'];
			$metadata['height']   = $summary[ Declaration::MAIN ]['height'];
		}

		if ( isset( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				$file = wp_basename( (string) $size['file'] );

				if ( isset( $by_file[ $file ] ) ) {
					$metadata['sizes'][ $name ]['filesize'] = $by_file[ $file ]['after'];
				}
			}
		}

		self::$writing = $attachment;

		try {
			wp_update_attachment_metadata( $attachment, $metadata );
		} finally {
			self::$writing = null;
		}
	}
}
