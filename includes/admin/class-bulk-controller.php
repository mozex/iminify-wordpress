<?php
/**
 * The bulk page's calls to its own site.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Messages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes under `iminify/v1/bulk`, each for someone who can manage the
 * site's options, since a run spends the account's day for the whole library.
 * Every answer is the page's whole state (Bulk_State).
 */
class Bulk_Controller {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$this->route(
			'',
			WP_REST_Server::READABLE,
			'state',
			array(
				'estimate' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$this->route(
			'/start',
			WP_REST_Server::CREATABLE,
			'start',
			array(
				'action' => array(
					'type'     => 'string',
					'enum'     => array( Bulk::OPTIMIZE, Bulk::RETRY, Bulk::RESTORE ),
					'required' => true,
				),
				'mode'   => array(
					'type'    => 'string',
					'enum'    => array( Bulk::BACKGROUND, Bulk::PAGE ),
					'default' => Bulk::BACKGROUND,
				),
			)
		);
		$this->route( '/pause', WP_REST_Server::CREATABLE, 'pause' );
		$this->route( '/resume', WP_REST_Server::CREATABLE, 'resume' );
		$this->route( '/stop', WP_REST_Server::CREATABLE, 'stop' );
		$this->route(
			'/mode',
			WP_REST_Server::CREATABLE,
			'mode',
			array(
				'mode' => array(
					'type'     => 'string',
					'enum'     => array( Bulk::BACKGROUND, Bulk::PAGE ),
					'required' => true,
				),
			)
		);
		$this->route( '/step', WP_REST_Server::CREATABLE, 'step' );
	}

	/**
	 * Only someone who can manage the site's options reaches these routes.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The page's state, with the service's estimate when asked.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Bulk_State::build( (bool) $request->get_param( 'estimate' ) ) );
	}

	/**
	 * Starts a run.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$run = Bulk::start( (string) $request->get_param( 'action' ), (string) $request->get_param( 'mode' ) );

		if ( is_wp_error( $run ) ) {
			return new WP_Error( (string) $run->get_error_code(), Messages::for_error( $run ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( Bulk_State::build( true ) );
	}

	/**
	 * Holds the run.
	 */
	public function pause(): WP_REST_Response {
		Bulk::pause();

		return new WP_REST_Response( Bulk_State::build() );
	}

	/**
	 * Goes on with the run.
	 */
	public function resume(): WP_REST_Response {
		Bulk::resume();

		return new WP_REST_Response( Bulk_State::build() );
	}

	/**
	 * Ends the run.
	 */
	public function stop(): WP_REST_Response {
		Bulk::stop();

		return new WP_REST_Response( Bulk_State::build( true ) );
	}

	/**
	 * Moves the run to the background, or to this page.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function mode( WP_REST_Request $request ): WP_REST_Response {
		Bulk::switch_mode( (string) $request->get_param( 'mode' ) );

		return new WP_REST_Response( Bulk_State::build() );
	}

	/**
	 * A request of a run that goes on while the page is open, answering in
	 * `next_step` the seconds until the next one has something to do.
	 */
	public function step(): WP_REST_Response {
		$next = Bulk::step();

		return new WP_REST_Response( array_merge( Bulk_State::build(), array( 'next_step' => $next ) ) );
	}

	/**
	 * Registers one route for people who can manage options.
	 *
	 * @param string                              $path     The route's path under `/bulk`.
	 * @param string                              $methods  Its methods.
	 * @param string                              $callback The method that answers it.
	 * @param array<string, array<string, mixed>> $args     Its arguments.
	 */
	protected function route( string $path, string $methods, string $callback, array $args = array() ): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/bulk' . $path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $args,
			)
		);
	}
}
