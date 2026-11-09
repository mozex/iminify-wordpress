<?php
/**
 * The settings page's calls to its own site.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Connection;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Network;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Leftovers;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Settings_Copy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes under `iminify/v1` the settings page calls with the REST nonce,
 * each for someone who can manage the site's options, and the main site's
 * connection on a network for someone who can manage the network. Every call to Iminify
 * is made from here, on the server, so the site key and the pairing's secret
 * never reach a browser; every answer is the page's whole state (State),
 * which holds neither.
 */
class Rest_Controller {

	const NAMESPACE = 'iminify/v1';

	/**
	 * The site settings the page may change on www.iminify.com, which checks each value.
	 */
	const SITE_SETTINGS = array( 'optimize_on_upload', 'level', 'webp', 'avif', 'max_width', 'size_limit', 'keep_metadata', 'sizes', 'alt_text', 'alt_text_language' );

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$this->route( '/state', WP_REST_Server::READABLE, 'state' );
		$this->route( '/connect', WP_REST_Server::CREATABLE, 'connect', array(), 'can_connect' );
		$this->route( '/connect', WP_REST_Server::DELETABLE, 'cancel', array(), 'can_connect' );
		$this->route( '/connect/claim', WP_REST_Server::CREATABLE, 'claim', array(), 'can_connect' );
		$this->route(
			'/key',
			WP_REST_Server::CREATABLE,
			'paste',
			array(
				'key' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			'can_connect'
		);
		$this->route( '/key', WP_REST_Server::DELETABLE, 'disconnect', array(), 'can_connect' );
		$this->route( '/move', WP_REST_Server::CREATABLE, 'move', array(), 'can_connect' );
		$this->route( '/copy', WP_REST_Server::CREATABLE, 'copy', array(), 'can_connect' );
		$this->route( '/join', WP_REST_Server::CREATABLE, 'join' );
		$this->route(
			'/settings',
			WP_REST_Server::CREATABLE,
			'settings',
			array(
				'site'  => array( 'type' => 'object' ),
				'local' => array( 'type' => 'object' ),
			)
		);
		$this->route( '/queue/start', WP_REST_Server::CREATABLE, 'start_queue' );

		$plugin = array(
			'plugin' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		$this->route( '/import', WP_REST_Server::CREATABLE, 'preview_import', $plugin );
		$this->route( '/import/use', WP_REST_Server::CREATABLE, 'use_import', $plugin );
		$this->route( '/import', WP_REST_Server::DELETABLE, 'decline_import', $plugin );
		$this->route(
			'/leftovers',
			WP_REST_Server::DELETABLE,
			'remove_leftover',
			array(
				'id' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
		$this->route(
			'/notice',
			WP_REST_Server::CREATABLE,
			'dismiss',
			array(
				'notice' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
	}

	/**
	 * Only someone who can manage the site's options reaches these routes.
	 */
	public function can_manage(): bool {
		return Capabilities::can( Capabilities::SETTINGS );
	}

	/**
	 * Connecting and disconnecting ask what the settings ask, except on the main
	 * site of a network the plugin is active for, whose connection is the whole
	 * network's: there only someone who can manage the network does it.
	 */
	public function can_connect(): bool {
		return Capabilities::can( Network::is_main() ? Capabilities::NETWORK : Capabilities::SETTINGS );
	}

	/**
	 * The page's state.
	 */
	public function state(): WP_REST_Response {
		/* The page opening is when the plugin looks at another optimizer's settings; its other answers read the copy. */
		Settings_Copy::take_all();

		return $this->answer();
	}

	/**
	 * Opens a pairing; the page sends the person to its address.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect() {
		$pairing = ( new Connection() )->start();

		return is_wp_error( $pairing ) ? $this->refusal( $pairing ) : $this->answer();
	}

	/**
	 * Drops the pairing waiting for approval.
	 */
	public function cancel(): WP_REST_Response {
		( new Connection() )->cancel();

		return $this->answer();
	}

	/**
	 * Asks Iminify once for the pairing's key.
	 */
	public function claim(): WP_REST_Response {
		$result = ( new Connection() )->claim();
		$error  = $result['error'] ?? null;

		return $this->answer(
			array(
				'claim' => array(
					'status'      => $result['status'],
					'retry_after' => $result['retry_after'] ?? null,
					'message'     => $error instanceof WP_Error ? Messages::for_error( $error ) : null,
				),
			)
		);
	}

	/**
	 * Connects the site with a pasted key.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function paste( WP_REST_Request $request ) {
		$site = ( new Connection() )->connect_with_key( (string) $request->get_param( 'key' ) );

		/* A key made for another address is kept, and the page offers its two ways on. */
		if ( is_wp_error( $site ) && 'site_moved' === $site->get_error_code() ) {
			return $this->answer();
		}

		return is_wp_error( $site ) ? $this->refusal( $site ) : $this->answer();
	}

	/**
	 * Disconnects the site, answering in `key_stopped` whether Iminify stopped the
	 * key too (the state's own `revoked` says Iminify disconnected the site).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect() {
		$result = ( new Connection() )->disconnect();

		return is_wp_error( $result ) ? $this->refusal( $result ) : $this->answer( array( 'key_stopped' => $result['revoked'] ) );
	}

	/**
	 * Moves the connection to this copy's address.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function move() {
		$site = ( new Connection() )->move();

		if ( is_wp_error( $site ) && 'unauthenticated' === $site->get_error_code() ) {
			( new Connection() )->revoked_by_service( $site );

			return $this->answer();
		}

		return is_wp_error( $site ) ? $this->refusal( $site ) : $this->answer();
	}

	/**
	 * Connects this copy of the site as a site of its own.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function copy() {
		$pairing = ( new Connection() )->connect_copy();

		return is_wp_error( $pairing ) ? $this->refusal( $pairing ) : $this->answer();
	}

	/**
	 * "Join the network": a site of a connected network asks it for a key of its
	 * own, whatever an earlier refusal or Disconnect left.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function join() {
		if ( ! Network::is_member() ) {
			return $this->refusal( new WP_Error( 'not_a_network_site', __( 'Only a site of a network the plugin is active for can join it.', 'iminify-image-optimizer' ), array( 'status' => 409 ) ) );
		}

		if ( null !== Plugin::keys()->get() ) {
			return $this->answer();
		}

		$joined = Network::join( true );

		return is_wp_error( $joined ) ? $this->refusal( $joined ) : $this->answer();
	}

	/**
	 * Saves the settings: the local ones here first, since they never wait on the
	 * service, then the site's on www.iminify.com. `saved` says whether all of
	 * them were kept: a key the service no longer knows disconnects the site.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function settings( WP_REST_Request $request ) {
		$site  = $request->get_param( 'site' );
		$local = $request->get_param( 'local' );

		if ( is_array( $local ) ) {
			Local_Settings::update( $local );

			/* Saving again is how someone asks for the rules to be written and looked at once more, once their file can take them. */
			if ( Rules::active() ) {
				Rules::sync( true );
				Rules::check();
			}
		}

		if ( is_array( $site ) && array() !== array_intersect_key( $site, array_flip( self::SITE_SETTINGS ) ) ) {
			$client = Plugin::client();

			if ( null === $client ) {
				return $this->refusal( new WP_Error( 'not_connected', __( 'This site isn\'t connected to Iminify.', 'iminify-image-optimizer' ), array( 'status' => 409 ) ) );
			}

			$record = $client->update_site( array_intersect_key( $site, array_flip( self::SITE_SETTINGS ) ) );

			if ( is_wp_error( $record ) && 'unauthenticated' === $record->get_error_code() ) {
				( new Connection() )->revoked_by_service( $record );

				return $this->answer( array( 'saved' => false ) );
			}

			if ( is_wp_error( $record ) ) {
				return $this->refusal( $record );
			}

			Site_Record::remember( $record );
		}

		return $this->answer( array( 'saved' => true ) );
	}

	/**
	 * "Start now": sends the images waiting for a batch, answering in `started`
	 * whether it could: false while the account's day is still spent.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_queue() {
		$started = Queue::start_now();

		return is_wp_error( $started ) ? $this->refusal( $started ) : $this->answer( array( 'started' => $started ) );
	}

	/**
	 * "Use <plugin>'s settings": what Iminify proposes for another optimizer's
	 * settings, answered in `import` beside the values now. Nothing changes yet.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_import( WP_REST_Request $request ) {
		$slug     = (string) $request->get_param( 'plugin' );
		$proposal = Settings_Import::preview( $slug );

		if ( is_wp_error( $proposal ) ) {
			return $this->revoked_or_refused( $proposal );
		}

		$state = State::build();

		return new WP_REST_Response( array_merge( $state, array( 'import' => Settings_Import::view( $slug, $proposal, $state ) ) ) );
	}

	/**
	 * Uses the settings Iminify proposed, answering in `used` the plugin's name.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function use_import( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'plugin' );
		$used = Settings_Import::apply( $slug );

		return is_wp_error( $used ) ? $this->revoked_or_refused( $used ) : $this->answer( array( 'used' => Others::name( $slug ) ) );
	}

	/**
	 * Turns the offer down.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function decline_import( WP_REST_Request $request ): WP_REST_Response {
		Settings_Import::decline( (string) $request->get_param( 'plugin' ) );

		return $this->answer();
	}

	/**
	 * A key Iminify no longer knows disconnects the site, which the page's state
	 * then shows; any other refusal is shown as it is.
	 *
	 * @param WP_Error $error The refusal.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function revoked_or_refused( WP_Error $error ) {
		if ( 'unauthenticated' === $error->get_error_code() ) {
			( new Connection() )->revoked_by_service( $error );

			return $this->answer();
		}

		return $this->refusal( $error );
	}

	/**
	 * Takes out the rules another optimizer left that the page lists, by the id
	 * the page was given, never a path: the server finds them again.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_leftover( WP_REST_Request $request ) {
		$removed = Leftovers::remove( (string) $request->get_param( 'id' ) );

		return is_wp_error( $removed ) ? $removed : $this->answer( array( 'removed' => true ) );
	}

	/**
	 * Dismisses the notice shown outside the plugin's page, for the person who closed it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		Notice::dismiss( (string) $request->get_param( 'notice' ) );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Registers one route for people who can manage options.
	 *
	 * @param non-falsy-string                    $path       The route's path.
	 * @param string                              $methods    Its methods.
	 * @param string                              $callback   The method that answers it.
	 * @param array<string, array<string, mixed>> $args       Its arguments.
	 * @param string                              $permission The method that says who may call it.
	 */
	protected function route( string $path, string $methods, string $callback, array $args = array(), string $permission = 'can_manage' ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
				'args'                => $args,
			)
		);
	}

	/**
	 * The page's state, with anything the call adds to it.
	 *
	 * @param array<string, mixed> $extra What the call adds.
	 */
	protected function answer( array $extra = array() ): WP_REST_Response {
		return new WP_REST_Response( array_merge( State::build(), $extra ) );
	}

	/**
	 * A refusal the page shows: the plugin's words for a code it knows, with the
	 * service's messages for the fields it refused.
	 *
	 * @param WP_Error $error The refusal.
	 */
	protected function refusal( WP_Error $error ): WP_Error {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );

		if ( 'site_moved' === $error->get_error_code() ) {
			Site_Record::forget();
		}

		/* A key Iminify doesn't know is a wrong value sent to this site, not a failed sign-in to it. */
		if ( 401 === $status ) {
			$status = 422;
		}

		return new WP_Error(
			(string) $error->get_error_code(),
			Messages::for_error( $error ),
			array(
				'status' => $status >= 400 && $status < 500 ? $status : 502,
				'errors' => isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : array(),
			)
		);
	}
}
