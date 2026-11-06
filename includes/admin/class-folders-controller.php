<?php
/**
 * The other folders page's REST routes.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Optimizer;
use Iminify\WordPress\Folders\Restorer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Messages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes under `iminify/v1/folders`, for someone who may choose the folders
 * (`Capabilities::FOLDERS`), on a site that lists them. Every answer is the page's
 * whole state (`Folders_State`), for the filter and page the request names.
 */
class Folders_Controller {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$list   = array(
			'filter' => array(
				'type'    => 'string',
				'enum'    => array_keys( Folders_State::FILTERS ),
				'default' => 'all',
			),
			'page'   => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);
		$folder = array(
			'folder' => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 1024,
			),
		);

		$this->route( '', WP_REST_Server::READABLE, 'state', $list );
		$this->route( '', WP_REST_Server::CREATABLE, 'add', array_merge( $list, $folder ) );
		$this->route( '', WP_REST_Server::DELETABLE, 'remove', array_merge( $list, $folder ) );
		$this->route(
			'/scan',
			WP_REST_Server::CREATABLE,
			'scan',
			array_merge(
				$list,
				array(
					'continue' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			)
		);
		$this->route( '/files/(?P<id>\d+)/optimize', WP_REST_Server::CREATABLE, 'optimize', $list );
		$this->route( '/files/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore', $list );
	}

	/**
	 * Only someone who may choose the folders reaches these routes, on a site that lists them.
	 */
	public function can_manage(): bool {
		return Folders::available() && Capabilities::can( Capabilities::FOLDERS );
	}

	/**
	 * The page's state.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response {
		return $this->answer( $request );
	}

	/**
	 * Adds a folder and starts looking through it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add( WP_REST_Request $request ) {
		$folder = Folders::add( (string) $request->get_param( 'folder' ) );

		if ( is_wp_error( $folder ) ) {
			return $this->refusal( $folder );
		}

		Scanner::start( array( $folder ) );
		Scanner::step( microtime( true ) + Scanner::BUDGET );

		return $this->answer( $request );
	}

	/**
	 * Takes a folder off the list.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		if ( ! Folders::remove( (string) $request->get_param( 'folder' ) ) ) {
			return new WP_Error( 'folder_not_listed', __( 'That folder isn\'t in the list.', 'iminify-image-optimizer' ), array( 'status' => 404 ) );
		}

		return $this->answer( $request );
	}

	/**
	 * Looks through the folders for new and changed files: starts a scan unless
	 * one is going, then walks it for one request's budget. The pages ask again
	 * while it goes on, with `continue`, which never starts another once it ends.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Scanner::running() && ! $request->get_param( 'continue' ) ) {
			Scanner::start();
		}

		Scanner::step( microtime( true ) + Scanner::BUDGET );

		return $this->answer( $request );
	}

	/**
	 * Optimizes one file now, or again from its original.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize( WP_REST_Request $request ) {
		$id = $this->listed_file( $request );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$asked = Optimizer::ask( $id );

		return is_wp_error( $asked ) ? $this->refusal( $asked ) : $this->answer( $request );
	}

	/**
	 * Restores one file.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		$id = $this->listed_file( $request );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$restored = Restorer::restore( $id );

		return is_wp_error( $restored ) ? $this->refusal( $restored ) : $this->answer( $request );
	}

	/**
	 * The file a request names, when it is in a chosen folder.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return int|WP_Error
	 */
	protected function listed_file( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = Table::get( $id );

		if ( null === $row || ! Folders::contains( $row['path'] ) ) {
			return new WP_Error( 'not_found', __( 'That image isn\'t in the list.', 'iminify-image-optimizer' ), array( 'status' => 404 ) );
		}

		return $id;
	}

	/**
	 * The page's state for the request's filter and page.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	protected function answer( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Folders_State::build( (string) $request->get_param( 'filter' ), (int) $request->get_param( 'page' ) ) );
	}

	/**
	 * A refusal in the person's language, keeping its status.
	 *
	 * @param WP_Error $error The refusal.
	 */
	protected function refusal( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		return new WP_Error( (string) $error->get_error_code(), Messages::for_error( $error ), array( 'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 409 ) );
	}

	/**
	 * Registers one route.
	 *
	 * @param string                              $path     The route's path under `/folders`.
	 * @param string                              $methods  Its methods.
	 * @param string                              $callback The method that answers it.
	 * @param array<string, array<string, mixed>> $args     Its arguments.
	 */
	protected function route( string $path, string $methods, string $callback, array $args = array() ): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/folders' . $path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $args,
			)
		);
	}
}
