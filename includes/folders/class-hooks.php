<?php
/**
 * Hooks the files in other folders into WordPress.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file pipeline's Action Scheduler steps, the background scan, and a scan
 * after WordPress updates a theme or a plugin, whose files may have changed.
 */
class Hooks {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( Optimizer::OPTIMIZE_HOOK, array( Optimizer::class, 'optimize' ), 10, 2 );
		add_action( Optimizer::CHECK_HOOK, array( Optimizer::class, 'check' ) );
		add_action( Optimizer::RESUME_HOOK, array( Optimizer::class, 'resume' ) );
		add_action( Scanner::HOOK, array( Scanner::class, 'background' ) );
		add_action( 'upgrader_process_complete', array( Scanner::class, 'after_update' ), 10, 2 );
		add_action( 'init', array( Table::class, 'upgrade' ) );
	}
}
