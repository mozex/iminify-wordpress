<?php
/**
 * The page audit's REST routes.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes under `iminify/v1/audit`, for someone who may audit the site's
 * pages (`Capabilities::AUDIT`), since each audit spends one of the account's
 * page scans. Iminify holds every audit; these only ask it and add what only
 * WordPress knows (`Audit_State`). A refusal keeps the service's code, and a
 * wait its `retry_after`, so the screen can say when to scan again.
 */
class Audit_Controller {

	/**
	 * An audit's id, as Iminify writes it.
	 */
	const ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$this->route(
			'',
			WP_REST_Server::READABLE,
			'state',
			array(
				'audit' => array(
					'type'    => 'string',
					'pattern' => '^' . self::ID . '$',
				),
			)
		);
		$this->route(
			'',
			WP_REST_Server::CREATABLE,
			'start',
			array(
				'url' => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 2048,
				),
			)
		);
		$this->route( '/(?P<id>' . self::ID . ')', WP_REST_Server::READABLE, 'audit' );
	}

	/**
	 * Only someone who may audit the site's pages reaches these routes.
	 */
	public function can_audit(): bool {
		return Capabilities::can( Capabilities::AUDIT );
	}

	/**
	 * The screen's state, with the audit `audit` names open, else the site's newest.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response {
		$audit = $request->get_param( 'audit' );

		return new WP_REST_Response( Audit_State::build( is_string( $audit ) && '' !== $audit ? $audit : null ) );
	}

	/**
	 * Asks Iminify to audit a page of the site, and answers the state with
	 * that audit open. A site no longer connected (its key forgotten by
	 * another request, or one Iminify stopped) answers the state that says
	 * so, which the screen draws in place of the audit.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$client = Plugin::client();

		if ( null === $client ) {
			return new WP_REST_Response( Audit_State::build() );
		}

		$audit = $client->start_audit( (string) $request->get_param( 'url' ) );

		if ( is_wp_error( $audit ) ) {
			return $this->refusal( $audit );
		}

		/* The scan it spent, for the count the screen shows. */
		Site_Record::fetch();

		return new WP_REST_Response( Audit_State::build( isset( $audit['id'] ) && is_string( $audit['id'] ) ? $audit['id'] : null ) );
	}

	/**
	 * One audit, for the screen to poll while it runs, or the screen's whole
	 * state once the site is no longer connected, as for a start.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function audit( WP_REST_Request $request ) {
		$client = Plugin::client();

		if ( null === $client ) {
			return new WP_REST_Response( Audit_State::build() );
		}

		$audit = Audit_State::audit( $client, (string) $request->get_param( 'id' ) );

		return is_wp_error( $audit ) ? $this->refusal( $audit ) : new WP_REST_Response( array( 'audit' => $audit ) );
	}

	/**
	 * A refusal in the person's language, keeping how long to wait. A key
	 * Iminify stopped disconnects the site and answers the screen's state; one
	 * set in wp-config.php stays, and its refusal is a 422, since it is no
	 * failed sign-in to this site; an outage or a server error of Iminify's is
	 * a bad gateway here, never this site's own error.
	 *
	 * @param WP_Error $error The refusal.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function refusal( WP_Error $error ) {
		if ( Audit_State::revoked( $error ) ) {
			return new WP_REST_Response( Audit_State::build() );
		}

		$data   = (array) $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		$status = 401 === $status ? 422 : ( $status < 400 || $status >= 500 ? 502 : $status );

		return new WP_Error(
			(string) $error->get_error_code(),
			Messages::for_error( $error ),
			array(
				'status'      => $status,
				'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : null,
			)
		);
	}

	/**
	 * Registers one route.
	 *
	 * @param string                              $path     The route's path under `/audit`.
	 * @param string                              $methods  Its methods.
	 * @param string                              $callback The method that answers it.
	 * @param array<string, array<string, mixed>> $args     Its arguments.
	 */
	protected function route( string $path, string $methods, string $callback, array $args = array() ): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/audit' . $path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_audit' ),
				'args'                => $args,
			)
		);
	}
}
