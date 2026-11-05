<?php
/**
 * What the bulk page shows, built on the server.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;
use WP_Site_Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bulk page's whole state in one answer: the library's counts and savings,
 * the run, the queue, the disk, and, only when asked (the page's first load and
 * after a run starts or stops), the service's estimate for the images not
 * optimized yet, which is the one part that calls Iminify. The page's poll reads
 * only what the site keeps.
 */
class Bulk_State {

	/**
	 * Site Health's loopback check, kept a while since it makes a request of its own.
	 */
	const LOOPBACK_TRANSIENT = 'iminify_loopback';

	/**
	 * Action Scheduler's own command that runs the plugin's queue from the shell.
	 */
	const COMMAND = 'wp action-scheduler run --group=iminify';

	/**
	 * Builds the state.
	 *
	 * @param bool $estimate Whether to ask Iminify for the estimate.
	 * @return array<string, mixed>
	 */
	public static function build( bool $estimate = false ): array {
		$keys   = Plugin::keys();
		$hidden = $keys->hides_account();
		$counts = Bulk::counts();
		$saved  = Records::saved();
		$free   = Disk::free();

		$state = array(
			'connected' => null !== $keys->get(),
			'hidden'    => $hidden,
			'counts'    => $counts,
			'numbers'   => array_map( 'number_format_i18n', $counts ),
			'saved'     => $saved['before'] > 0 && $saved['after'] < $saved['before'] ? array(
				'bytes'   => size_format( $saved['before'] - $saved['after'], 1 ),
				'before'  => size_format( $saved['before'], 1 ),
				'percent' => number_format_i18n( ( $saved['before'] - $saved['after'] ) / $saved['before'] * 100, 1 ),
			) : null,
			'run'       => self::run(),
			'queue'     => Queue::summary(),
			'disk'      => array(
				'low'     => ! Disk::has_room_for( 0 ),
				'free'    => null === $free ? null : size_format( (int) $free, 1 ),
				'backups' => null,
			),
			'estimate'  => null,
			'error'     => null,
			'links'     => array(
				'settings' => Settings_Page::url(),
				'plans'    => $hidden ? null : Client::website( 'pricing', array( 'utm_campaign' => 'bulk' ) ),
			),
		);

		if ( ! $estimate ) {
			return $state;
		}

		if ( Local_Settings::get()['backups'] && $counts['not_optimized'] > 0 ) {
			$state['disk']['backups'] = size_format( Bulk::backup_bytes(), 1 );
		}

		if ( ! $state['connected'] || 0 === $counts['not_optimized'] ) {
			return $state;
		}

		if ( null === Site_Record::cached() ) {
			Site_Record::fetch();
		}

		$site = Site_Record::fetch(
			array(
				'attachments' => $counts['not_optimized'],
				'files'       => $counts['not_optimized'] * Bulk::files_per_image(),
			)
		);

		if ( $site instanceof WP_Error ) {
			$state['error'] = Messages::for_error( $site );
		} elseif ( is_array( $site ) && ! $hidden ) {
			$state['estimate'] = self::estimate( $site['estimate'] ?? null );
		}

		/* The read above may have started or ended the pause. */
		$state['queue'] = Queue::summary();

		return $state;
	}

	/**
	 * The run as the page shows it, or null when there has been none.
	 *
	 * @return array<string, mixed>|null
	 */
	protected static function run(): ?array {
		$run = Bulk::run();

		if ( null === $run ) {
			return null;
		}

		$stalled = Bulk::stalled();

		return array(
			'action'   => $run['action'],
			'mode'     => $run['mode'],
			'status'   => $run['status'],
			'reason'   => $run['reason'],
			'total'    => $run['total'],
			'sent'     => $run['sent'],
			'failed'   => $run['failed'],
			'held'     => $run['hold'] > time() ? State::date( gmdate( 'c', $run['hold'] ) ) : null,
			'stalled'  => $stalled,
			'loopback' => $stalled ? self::loopback() : null,
			'command'  => self::COMMAND,
		);
	}

	/**
	 * The service's estimate, with the plans' names.
	 *
	 * @param mixed $estimate The record's `estimate`.
	 * @return array{days: int, faster: list<array{plan: string, days: int}>}|null
	 */
	protected static function estimate( $estimate ): ?array {
		if ( ! is_array( $estimate ) || ! isset( $estimate['days'] ) ) {
			return null;
		}

		$faster = array();

		foreach ( isset( $estimate['faster'] ) && is_array( $estimate['faster'] ) ? $estimate['faster'] : array() as $plan ) {
			if ( is_array( $plan ) && isset( $plan['plan'], $plan['days'] ) && is_string( $plan['plan'] ) ) {
				$faster[] = array(
					'plan' => State::plan_label( $plan['plan'] ),
					'days' => (int) $plan['days'],
				);
			}
		}

		return array(
			'days'   => (int) $estimate['days'],
			'faster' => $faster,
		);
	}

	/**
	 * Site Health's loopback check, for a background run that stalled.
	 *
	 * @return array{ok: bool, message: string}|null Null when the check can't run here.
	 */
	protected static function loopback(): ?array {
		$kept = get_transient( self::LOOPBACK_TRANSIENT );

		if ( is_array( $kept ) && isset( $kept['ok'], $kept['message'] ) ) {
			return array(
				'ok'      => (bool) $kept['ok'],
				'message' => (string) $kept['message'],
			);
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$health = WP_Site_Health::get_instance();

		if ( null === $health ) {
			return null;
		}

		$result = $health->can_perform_loopback();
		$view   = array(
			'ok'      => 'good' === ( $result->status ?? '' ),
			'message' => wp_strip_all_tags( (string) ( $result->message ?? '' ) ),
		);

		set_transient( self::LOOPBACK_TRANSIENT, $view, 10 * MINUTE_IN_SECONDS );

		return $view;
	}
}
