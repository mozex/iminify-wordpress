<?php
/**
 * The media library's calls to its own site.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Messages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes under `iminify/v1/media` the media library's buttons call with the
 * REST nonce, for someone who can upload files and edit that attachment, as
 * core asks of editing an image (Capabilities). Each answers the attachment's markup.
 */
class Media_Controller {

	/**
	 * How many attachments one status read may ask after: a list view's longest page.
	 */
	const MAX_IDS = 100;

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'statuses' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
						'maxItems' => self::MAX_IDS,
					),
				),
			)
		);

		$this->route(
			'/media/(?P<id>\d+)/optimize',
			WP_REST_Server::CREATABLE,
			'optimize',
			$id + array(
				'level' => array(
					'type' => 'string',
					'enum' => array_keys( Messages::levels() ),
				),
			)
		);
		$this->route(
			'/media/(?P<id>\d+)/missing',
			WP_REST_Server::CREATABLE,
			'missing',
			$id + array(
				'what' => array(
					'type'     => 'string',
					'enum'     => array( Missing::FORMATS, Missing::SIZES ),
					'required' => true,
				),
			)
		);
		$this->route( '/media/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore', $id );
		$this->route( '/media/(?P<id>\d+)/copies', WP_REST_Server::DELETABLE, 'delete_copies', $id );
	}

	/**
	 * Whether the person can reach the media library at all.
	 */
	public function can_upload(): bool {
		return Capabilities::can( Capabilities::MEDIA );
	}

	/**
	 * Whether the person can change this attachment.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		$attachment = (int) $request->get_param( 'id' );

		return 'attachment' === get_post_type( $attachment ) && Capabilities::can_change( $attachment );
	}

	/**
	 * Where each of some attachments stands, for the ones the person can edit.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function statuses( WP_REST_Request $request ): WP_REST_Response {
		$answers = array();

		foreach ( array_map( 'intval', (array) $request->get_param( 'ids' ) ) as $attachment ) {
			if ( 'attachment' === get_post_type( $attachment ) && current_user_can( 'edit_post', $attachment ) ) {
				$answers[] = self::view( $attachment );
			}
		}

		return new WP_REST_Response( array( 'items' => $answers ) );
	}

	/**
	 * Optimizes an attachment, at a level if the request names one.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize( WP_REST_Request $request ) {
		$level = $request->get_param( 'level' );

		return $this->answer( (int) $request->get_param( 'id' ), Optimizer::ask( (int) $request->get_param( 'id' ), is_string( $level ) ? $level : null ) );
	}

	/**
	 * Makes the formats or sizes an optimized attachment lacks.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function missing( WP_REST_Request $request ) {
		return $this->answer( (int) $request->get_param( 'id' ), Optimizer::fill( (int) $request->get_param( 'id' ), (string) $request->get_param( 'what' ) ) );
	}

	/**
	 * Restores an attachment's original.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		return $this->answer( (int) $request->get_param( 'id' ), Restorer::restore( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Deletes an attachment's WebP and AVIF copies.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_copies( WP_REST_Request $request ) {
		Copies::delete_own( (int) $request->get_param( 'id' ) );

		return $this->answer( (int) $request->get_param( 'id' ), true );
	}

	/**
	 * An action's answer: the attachment's markup, or its refusal in the plugin's words.
	 *
	 * @param int           $attachment The attachment's id.
	 * @param true|WP_Error $result     What the action did.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function answer( int $attachment, $result ) {
		if ( ! is_wp_error( $result ) ) {
			return new WP_REST_Response( self::view( $attachment ) );
		}

		$data   = (array) $result->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );

		return new WP_Error( (string) $result->get_error_code(), Messages::for_error( $result ), array( 'status' => $status >= 400 && $status < 500 && 401 !== $status ? $status : 502 ) );
	}

	/**
	 * An attachment's markup, for the script to put back.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{id: int, busy: bool, cell: string, panel: string}
	 */
	protected static function view( int $attachment ): array {
		return array(
			'id'    => $attachment,
			'busy'  => Media_View::is_busy( $attachment ),
			'cell'  => Media_View::cell( $attachment ),
			'panel' => Media_View::panel( $attachment ),
		);
	}

	/**
	 * Registers one route on an attachment.
	 *
	 * @param non-falsy-string                    $path     The route's path.
	 * @param string                              $methods  Its methods.
	 * @param string                              $callback The method that answers it.
	 * @param array<string, array<string, mixed>> $args     Its arguments.
	 */
	protected function route( string $path, string $methods, string $callback, array $args ): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $args,
			)
		);
	}
}
