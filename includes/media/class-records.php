<?php
/**
 * What the plugin keeps about each attachment it handles.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post meta row per attachment: where its optimization stands, the group
 * that is making it, every file the plugin wrote over with the bytes it wrote,
 * the WebP and AVIF copies it made beside them, and the backups it keeps. The
 * written bytes are how the plugin tells its own output from a file someone or
 * something else put there since, so it never sends its own output back for a
 * second lossy pass. Every copy carries its origin, so restoring, deleting the
 * copies or uninstalling only ever deletes the plugin's own.
 */
class Records {

	const META = '_iminify';

	const ORIGIN = 'iminify';

	const QUEUED = 'queued';

	const WAITING = 'waiting';

	const OPTIMIZED = 'optimized';

	const NOT_OPTIMIZED = 'not_optimized';

	const SKIPPED = 'skipped';

	const ERROR = 'error';

	/**
	 * An attachment's record, every key present.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{status: string|null, trigger: string|null, generation: int, group: array<string, mixed>|null, written: array<string, int>, copies: array<string, mixed>, backups: list<string>, result: array<string, mixed>|null, error: array<string, mixed>|null, attempts: int, reopens: int, updated: int}
	 */
	public static function get( int $attachment ): array {
		$record = get_post_meta( $attachment, self::META, true );
		$record = is_array( $record ) ? $record : array();

		return array(
			'status'     => isset( $record['status'] ) && is_string( $record['status'] ) ? $record['status'] : null,
			'trigger'    => isset( $record['trigger'] ) && is_string( $record['trigger'] ) ? $record['trigger'] : null,
			'generation' => isset( $record['generation'] ) ? (int) $record['generation'] : 0,
			'group'      => isset( $record['group'] ) && is_array( $record['group'] ) ? $record['group'] : null,
			'written'    => isset( $record['written'] ) && is_array( $record['written'] ) ? array_map( 'intval', $record['written'] ) : array(),
			'copies'     => isset( $record['copies'] ) && is_array( $record['copies'] ) ? $record['copies'] : array(),
			'backups'    => isset( $record['backups'] ) && is_array( $record['backups'] ) ? array_values( array_map( 'strval', $record['backups'] ) ) : array(),
			'result'     => isset( $record['result'] ) && is_array( $record['result'] ) ? $record['result'] : null,
			'error'      => isset( $record['error'] ) && is_array( $record['error'] ) ? $record['error'] : null,
			'attempts'   => isset( $record['attempts'] ) ? (int) $record['attempts'] : 0,
			'reopens'    => isset( $record['reopens'] ) ? (int) $record['reopens'] : 0,
			'updated'    => isset( $record['updated'] ) ? (int) $record['updated'] : 0,
		);
	}

	/**
	 * Changes some of an attachment's record.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $changes    The keys to change.
	 * @return array<string, mixed> The record as stored.
	 */
	public static function update( int $attachment, array $changes ): array {
		$record            = array_merge( self::get( $attachment ), $changes );
		$record['updated'] = time();

		update_post_meta( $attachment, self::META, $record );

		return $record;
	}

	/**
	 * Marks the attachment's files as changed, so a group already open for the
	 * files it had writes nothing over the new ones, and the new files get
	 * their own reopens.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function bump( int $attachment ): int {
		$generation = self::get( $attachment )['generation'] + 1;

		self::update(
			$attachment,
			array(
				'generation' => $generation,
				'reopens'    => 0,
			)
		);

		return $generation;
	}

	/**
	 * Whether the file at a path is one the plugin wrote: its record names the
	 * path, and the file still has the bytes the plugin wrote there.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $path       The file's absolute path.
	 */
	public static function wrote( int $attachment, string $path ): bool {
		$relative = Paths::relative( $path );
		$written  = self::get( $attachment )['written'];

		if ( null === $relative || ! isset( $written[ $relative ] ) ) {
			return false;
		}

		/* PHP 7.4 keeps a file's size from before a write in the same run. */
		clearstatcache( true, $path );

		if ( ! is_file( $path ) ) {
			return false;
		}

		return (int) filesize( $path ) === $written[ $relative ];
	}

	/**
	 * Records a failure to show for the attachment.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $status     Where it stands now.
	 * @param string $code       The reason's code.
	 * @param string $message    The reason, in words.
	 */
	public static function fail( int $attachment, string $status, string $code, string $message ): void {
		self::update(
			$attachment,
			array(
				'status' => $status,
				'group'  => null,
				'error'  => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}
}
