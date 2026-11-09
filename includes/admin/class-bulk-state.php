<?php
/**
 * What the bulk page shows, built on the server.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Api\Client;
use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Disk;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
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
	 * What carries a stalled run on from the shell: the plugin's command, which takes
	 * it from here whatever it does. Never Action Scheduler's `run --group=iminify`:
	 * until its data migration has run, which takes the scheduled tasks a stalled site
	 * lacks, Action Scheduler's hybrid store refuses a claim by group ("The group
	 * "iminify" does not exist"), while the plugin's command claims by hook.
	 */
	const COMMANDS = array( 'wp iminify run' );

	/**
	 * Builds the state.
	 *
	 * @param bool $estimate Whether to ask Iminify for the estimate.
	 * @return array<string, mixed>
	 */
	public static function build( bool $estimate = false ): array {
		$keys = Plugin::keys();

		/* What the optimized images lack depends on what the site asks for, which can change on iminify.com. */
		if ( $estimate && Plugin::connected() && null === Site_Record::cached() ) {
			Site_Record::fetch();
		}

		/* Another plugin's records may have changed since the last look: the page counts what it finds. */
		if ( $estimate ) {
			Others::look();
		}

		$hidden = $keys->hides_account();
		$counts = Bulk::counts();
		$saved  = Records::saved();
		$free   = Disk::free();
		$state  = array(
			'connected'     => Plugin::connected(),
			'hidden'        => $hidden,
			'counts'        => $counts,
			'numbers'       => array_map( 'number_format_i18n', $counts ),
			'saved'         => $saved['before'] > 0 && $saved['after'] < $saved['before'] ? array(
				'bytes'   => size_format( $saved['before'] - $saved['after'], 1 ),
				'before'  => size_format( $saved['before'], 1 ),
				'percent' => number_format_i18n( ( $saved['before'] - $saved['after'] ) / $saved['before'] * 100, 1 ),
			) : null,
			'alt_text'      => self::alt_text(),
			'others'        => self::others(),
			'conflict'      => self::conflict(),
			'putback'       => self::puts_back(),
			'run'           => self::run(),
			'queue'         => Queue::summary(),
			'folders'       => self::folders(),
			'disk'          => array(
				'low'     => ! Disk::has_room_for( 0 ),
				'free'    => null === $free ? null : size_format( (int) $free, 1 ),
				'backups' => null,
			),
			'estimate'      => null,
			'redo_estimate' => null,
			'error'         => null,
			'links'         => array(
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

		if ( ! $state['connected'] ) {
			return $state;
		}

		/* Each choice gets its own estimate, as the service counts it: the images not optimized yet, and those another plugin optimized, again from their originals. */
		foreach ( array(
			'estimate'      => $counts['not_optimized'],
			'redo_estimate' => $counts['redo'],
		) as $key => $images ) {
			if ( 0 === $images || null !== $state['error'] ) {
				continue;
			}

			$site = Site_Record::fetch(
				array(
					'attachments' => $images,
					'files'       => $images * Bulk::files_per_image(),
				)
			);

			if ( $site instanceof WP_Error ) {
				$state['error'] = Messages::for_error( $site );
			} elseif ( is_array( $site ) && ! $hidden ) {
				$state[ $key ] = self::estimate( $site['estimate'] ?? null );
			}
		}

		/* The reads above may have started or ended the pause. */
		$state['queue'] = Queue::summary();

		return $state;
	}

	/**
	 * Whether the site writes alt texts, and how many are left of the day, as the service last said.
	 *
	 * @return array{on: bool, remaining: string|null}
	 */
	protected static function alt_text(): array {
		$site = Site_Record::cached();
		$left = is_array( $site ) ? ( $site['usage']['alt_texts']['remaining'] ?? null ) : null;

		return array(
			'on'        => Alt_Text::on(),
			'remaining' => null === $left ? null : number_format_i18n( (int) $left ),
		);
	}

	/**
	 * Whether LiteSpeed Cache is active, which puts its own old files back over the
	 * plugin's and deletes the WebP and AVIF copies beside an image whenever
	 * WordPress makes that image's sizes again: the page says so, and that its
	 * "Remove Original Image Backups" ends the first.
	 */
	protected static function puts_back(): bool {
		$reader = Others::reader( 'litespeed' );

		return null !== $reader && $reader->active();
	}

	/**
	 * The images other plugins optimized, by plugin: how many, and how many with an original kept.
	 *
	 * @return list<array{name: string, count: int, number: string, with: int, originals: string}>
	 */
	protected static function others(): array {
		$others = array();

		foreach ( Others::counts() as $slug => $count ) {
			$others[] = array(
				'name'      => Others::name( $slug ),
				'count'     => $count['with'] + $count['without'],
				'number'    => number_format_i18n( $count['with'] + $count['without'] ),
				'with'      => $count['with'],
				'originals' => number_format_i18n( $count['with'] ),
			);
		}

		return $others;
	}

	/**
	 * The other optimizer that holds the plugin back, with what to switch off, or null.
	 *
	 * @return array{name: string, message: string}|null
	 */
	public static function conflict(): ?array {
		$acting = Others::acting();

		return null === $acting ? null : array(
			'name'    => $acting->name(),
			'message' => Others::acting_message( $acting ),
		);
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
			'action'      => $run['action'],
			'scope'       => $run['scope'],
			'mode'        => $run['mode'],
			'status'      => $run['status'],
			'reason'      => $run['reason'],
			'hold_reason' => $run['hold_reason'],
			'total'       => $run['total'],
			'sent'        => $run['sent'],
			'failed'      => $run['failed'],
			'passed'      => $run['passed'],
			'held'        => $run['hold'] > time() ? State::date( gmdate( 'c', $run['hold'] ) ) : null,
			'stalled'     => $stalled,
			'loopback'    => $stalled ? self::loopback() : null,
			'commands'    => self::COMMANDS,
		);
	}

	/**
	 * The files in other folders, for the bulk page's panel: null where this site
	 * lists none (a network's other sites) or the person may not choose them.
	 *
	 * @return array{listed: list<string>, counts: array<string, int>, numbers: array<string, string>, saved: array<string, string>|null, scanning: bool, link: string}|null
	 */
	protected static function folders(): ?array {
		if ( ! Folders::available() || ! Capabilities::can( Capabilities::FOLDERS ) ) {
			return null;
		}

		$counts = Table::counts();
		$saved  = Table::saved();

		return array(
			'listed'   => Folders::listed(),
			'counts'   => $counts,
			'numbers'  => array_map( 'number_format_i18n', $counts ),
			'saved'    => Folders_State::saved( $saved ),
			'scanning' => Scanner::running(),
			'link'     => Folders_Page::url(),
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
