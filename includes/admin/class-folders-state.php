<?php
/**
 * What the other folders page shows.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Restorer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page's whole state, built here so its script only draws it: the folders
 * chosen and offered, the scan, the counts and savings, a page of the files with
 * what each can do, and the bulk run when it takes these files.
 */
class Folders_State {

	/**
	 * Files a page of the list shows.
	 */
	const PER_PAGE = 50;

	/**
	 * The filters the list offers, each the statuses it shows.
	 */
	const FILTERS = array(
		'all'           => null,
		'optimized'     => array( Records::OPTIMIZED ),
		'not_optimized' => array( Records::NOT_OPTIMIZED, Records::RESTORED ),
		'waiting'       => array( Records::QUEUED, Records::WAITING, Records::PAUSED, Records::PARKED ),
		'failed'        => array( Records::ERROR, Records::SKIPPED ),
	);

	/**
	 * Builds the state.
	 *
	 * @param string $filter One of FILTERS' keys.
	 * @param int    $page   The list's page, from 1.
	 * @return array<string, mixed>
	 */
	public static function build( string $filter = 'all', int $page = 1 ): array {
		$filter = array_key_exists( $filter, self::FILTERS ) ? $filter : 'all';
		$counts = Table::counts();
		$list   = Table::page( self::FILTERS[ $filter ], max( 1, $page ), self::PER_PAGE );
		$scan   = Scanner::state();
		$run    = Bulk::run();

		return array(
			'connected' => null !== Plugin::keys()->get(),
			'listed'    => Folders::listed(),
			'offered'   => Folders::offered(),
			'scan'      => null === $scan ? null : array(
				'running' => null === $scan['finished'],
				'found'   => number_format_i18n( $scan['found'] ),
				'added'   => $scan['added'],
				'changed' => $scan['changed'],
				'at'      => null === $scan['finished'] ? null : State::date( gmdate( 'c', $scan['finished'] ) ),
			),
			'counts'    => $counts,
			'numbers'   => array_map( 'number_format_i18n', $counts ),
			'saved'     => self::saved( Table::saved() ),
			'files'     => array(
				'filter' => $filter,
				'page'   => max( 1, $page ),
				'pages'  => max( 1, (int) ceil( $list['total'] / self::PER_PAGE ) ),
				'total'  => $list['total'],
				'rows'   => array_map( array( self::class, 'row' ), $list['rows'] ),
			),
			'run'       => null !== $run && Bulk::FOLDERS === $run['scope'] && in_array( $run['status'], array( Bulk::RUNNING, Bulk::PAUSED ), true ) ? array(
				'action' => $run['action'],
				'status' => $run['status'],
			) : null,
			'busy'      => null !== $run && in_array( $run['status'], array( Bulk::RUNNING, Bulk::PAUSED ), true ),
			'links'     => array(
				'bulk' => Bulk_Page::url(),
			),
		);
	}

	/**
	 * The bytes saved, as the screens show them, or null for none.
	 *
	 * @param array{before: int, after: int} $saved The bytes before and after.
	 * @return array{bytes: string, before: string, percent: string}|null
	 */
	public static function saved( array $saved ): ?array {
		if ( $saved['before'] <= 0 || $saved['after'] >= $saved['before'] ) {
			return null;
		}

		return array(
			'bytes'   => (string) size_format( $saved['before'] - $saved['after'], 1 ),
			'before'  => (string) size_format( $saved['before'], 1 ),
			'percent' => number_format_i18n( ( $saved['before'] - $saved['after'] ) / $saved['before'] * 100, 1 ),
		);
	}

	/**
	 * One file as the list shows it.
	 *
	 * @param array<string, mixed> $row The file's row.
	 * @return array<string, mixed>
	 */
	protected static function row( array $row ): array {
		$outputs = isset( $row['result']['outputs'] ) && is_array( $row['result']['outputs'] ) ? $row['result']['outputs'] : array();
		$before  = (int) array_sum( array_map( 'intval', array_column( $outputs, 'before' ) ) );
		$after   = (int) array_sum( array_map( 'intval', array_column( $outputs, 'after' ) ) );
		$formats = isset( $row['result']['formats'] ) && is_array( $row['result']['formats'] ) ? array_map( 'strtoupper', $row['result']['formats'] ) : array();
		$status  = (string) $row['status'];

		return array(
			'id'          => (int) $row['id'],
			'path'        => (string) $row['path'],
			'size'        => (string) size_format( (int) $row['bytes'], 1 ),
			'status'      => $status,
			'reason'      => isset( $row['error']['message'] ) && is_string( $row['error']['message'] ) ? $row['error']['message'] : null,
			'saved'       => Records::OPTIMIZED === $status && $before > 0 && $after < $before ? number_format_i18n( ( $before - $after ) / $before * 100, 1 ) : null,
			'formats'     => $formats,
			'can_restore' => Records::OPTIMIZED === $status && Restorer::AVAILABLE === Restorer::availability( $row ),
			'on_its_way'  => in_array( $status, Table::ON_ITS_WAY, true ),
		);
	}
}
