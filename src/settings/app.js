/**
 * The page's states: connecting, connected, a copy at another address, and
 * Iminify out of reach. The local settings show in every one of them, since
 * they work without a connection. Drawn with the design system's parts
 * (`src/ui`): refusals stay on the page, what went well shows as a toast.
 *
 * On a network the plugin is active for, the same page draws three ways
 * (`network.js`): the network admin's (`scope` `network`) connects the network
 * and holds no site settings; the main site's shows the network's connection
 * without changing it; another site's joins the network or connects on its own.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { call, openTab } from './api';
import Account, { DisconnectConfirm, disconnecting } from './account';
import { Connect, Pairing, PasteKey } from './connect';
import { JoinNetwork, NetworkManaged, NetworkSites } from './network';
import Leftovers, { shownLeftovers } from './leftovers';
import Queue from './queue';
import SettingsForm from './settings-form';
import {
	Button,
	Callout,
	ErrorState,
	Loading,
	Toasts,
	useAsk,
	useHeaderStatus,
	useToasts,
} from '../ui';

/**
 * How long the page waits between claims while a pairing waits for its approval.
 */
const CLAIM_EVERY_MS = 3000;

/**
 * The header's connection pill for each of the page's states.
 *
 * @param {string|undefined} connection The page's connection.
 * @return {string[]} The pill's state and its words.
 */
function pill( connection ) {
	switch ( connection ) {
		case 'connected':
			return [ 'on', __( 'Connected', 'iminify-image-optimizer' ) ];
		case 'pairing':
			return [
				'waiting',
				__( 'Waiting for your approval', 'iminify-image-optimizer' ),
			];
		case 'moved':
		case 'error':
			return [
				'warning',
				__( 'Needs attention', 'iminify-image-optimizer' ),
			];
		default:
			return [ 'off', __( 'Not connected', 'iminify-image-optimizer' ) ];
	}
}

/**
 * The settings page.
 *
 * @param {Object} props       The component's props.
 * @param {string} props.scope `site`, or `network` on the network admin's page.
 */
export default function App( { scope } ) {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const { toasts, toast, dismiss } = useToasts();
	const claimTimer = useRef( null );

	const run = useCallback( ( path, method, data ) => {
		setBusy( true );
		setError( null );

		return call( path, method, data )
			.then( ( next ) => {
				setState( next );

				return next;
			} )
			.catch( ( failure ) => {
				setError( failure );

				throw failure;
			} )
			.finally( () => setBusy( false ) );
	}, [] );

	useEffect( () => {
		run( '/state' ).catch( () => {} );
	}, [ run ] );

	const pairing = state?.connection === 'pairing';
	const networkPage = 'network' === scope;
	const mainSite = Boolean( state?.network?.main ) && ! networkPage;

	const [ pillState, pillText ] = pill( state?.connection );

	useHeaderStatus( null === state ? null : pillState, pillText );

	useEffect( () => {
		/* The main site's page shows a network pairing it can't claim: the network admin's page does. */
		if ( ! pairing || mainSite ) {
			return undefined;
		}

		const claim = ( wait ) => {
			claimTimer.current = setTimeout( () => {
				call( '/connect/claim', 'POST' )
					.then( ( next ) => {
						setState( next );

						const status = next.claim?.status;

						if ( 'connected' === status ) {
							toast(
								'network' === scope
									? __(
											'The network is connected.',
											'iminify-image-optimizer'
										)
									: __(
											'The site is connected.',
											'iminify-image-optimizer'
										)
							);
						} else if (
							'closed' === status ||
							'expired' === status
						) {
							setError( {
								message: __(
									"The connection wasn't approved in time, or was turned down. Press Connect to try again.",
									'iminify-image-optimizer'
								),
							} );
						} else if ( 'failed' === status ) {
							setError( { message: next.claim.message } );
						} else if ( 'pairing' === next.connection ) {
							claim(
								Math.max(
									CLAIM_EVERY_MS,
									( next.claim?.retry_after ?? 0 ) * 1000
								)
							);
						}
					} )
					.catch( () => claim( CLAIM_EVERY_MS * 2 ) );
			}, wait );
		};

		claim( CLAIM_EVERY_MS );

		return () => clearTimeout( claimTimer.current );
	}, [ pairing, mainSite, toast, scope ] );

	const connect = ( path ) => {
		const tab = openTab();

		run( path, 'POST' )
			.then( ( next ) => {
				if ( next.pairing ) {
					tab.go( next.pairing.connect_url );
				} else {
					tab.close();
				}
			} )
			.catch( () => tab.close() );
	};

	const disconnect = () =>
		run( '/key', 'DELETE' )
			.then( ( next ) => {
				if ( ! next.key_stopped ) {
					setError( {
						message:
							'network' === scope
								? __(
										"The network is disconnected here, but its key couldn't be stopped on Iminify. Disconnect it under Settings, Sites on iminify.com too.",
										'iminify-image-optimizer'
									)
								: __(
										"The site is disconnected here, but its key couldn't be stopped on Iminify. Disconnect it under Settings, Sites on iminify.com too.",
										'iminify-image-optimizer'
									),
					} );
				}
			} )
			.catch( () => {} );

	const join = () =>
		run( '/join', 'POST' )
			.then( ( next ) => {
				if ( 'connected' === next.connection ) {
					toast(
						__(
							'The site joined the network.',
							'iminify-image-optimizer'
						)
					);
				}
			} )
			.catch( () => {} );

	if ( null === state ) {
		return error ? (
			<ErrorState
				message={ error.message }
				busy={ busy }
				onRetry={ () => run( '/state' ).catch( () => {} ) }
			/>
		) : (
			<Loading />
		);
	}

	const connected = 'connected' === state.connection;
	const joinable =
		Boolean( state.network ) &&
		! state.network.main &&
		state.network.connected &&
		'disconnected' === state.connection;

	return (
		<>
			<Toasts toasts={ toasts } onDismiss={ dismiss } />
			{ error && (
				<Callout tone="danger" role="alert">
					<p>{ error.message }</p>
				</Callout>
			) }
			{ state.site?.message && (
				<Callout
					tone={
						'update_required' === state.site.message.code
							? 'danger'
							: 'warning'
					}
				>
					<p>{ state.site.message.text }</p>
				</Callout>
			) }
			{ state.conflict && (
				<Callout tone="warning" role="status">
					<p>{ state.conflict.message }</p>
				</Callout>
			) }
			{ state.revoked && ! connected && (
				<Callout icon="plug">
					<p>{ revokedText( networkPage, mainSite, joinable ) }</p>
				</Callout>
			) }

			<div className="iminify-grid">
				<div className="iminify-col">
					{ mainSite && <NetworkManaged state={ state } /> }

					{ joinable && (
						<JoinNetwork
							state={ state }
							busy={ busy }
							onJoin={ join }
						/>
					) }

					{ ! mainSite && 'disconnected' === state.connection && (
						<>
							<Connect
								state={ state }
								busy={ busy }
								variant={ connectVariant(
									networkPage,
									joinable
								) }
								onConnect={ () => connect( '/connect' ) }
							/>
							<PasteKey
								state={ state }
								busy={ busy }
								network={ networkPage }
								onPaste={ ( key ) =>
									run( '/key', 'POST', { key } )
										.then( () =>
											toast(
												__(
													'The site is connected.',
													'iminify-image-optimizer'
												)
											)
										)
										.catch( () => {} )
								}
							/>
						</>
					) }

					{ ! mainSite && pairing && (
						<Pairing
							state={ state }
							busy={ busy }
							onCancel={ () =>
								run( '/connect', 'DELETE' ).catch( () => {} )
							}
						/>
					) }

					{ ! mainSite && 'moved' === state.connection && (
						<Callout>
							<p>{ state.error.message }</p>
							<div className="iminify-actions">
								<Button
									variant="primary"
									busy={ busy }
									disabled={ busy }
									onClick={ () =>
										run( '/move', 'POST' ).catch( () => {} )
									}
								>
									{ __(
										'Move the connection here',
										'iminify-image-optimizer'
									) }
								</Button>
								<Button
									disabled={ busy }
									onClick={ () => connect( '/copy' ) }
								>
									{ __(
										'Connect this copy as its own site',
										'iminify-image-optimizer'
									) }
								</Button>
							</div>
						</Callout>
					) }

					{ ! mainSite && 'error' === state.connection && (
						<Unreachable
							state={ state }
							busy={ busy }
							what={ disconnecting( state, networkPage ) }
							onRetry={ () => run( '/state' ).catch( () => {} ) }
							onDisconnect={ disconnect }
						/>
					) }

					{ ! networkPage && (
						<SettingsForm
							key={ connected ? 'connected' : 'local' }
							state={ state }
							busy={ busy }
							errors={ error?.errors ?? {} }
							onSave={ ( changes ) =>
								run( '/settings', 'POST', changes )
									.then( ( next ) => {
										if ( false !== next.saved ) {
											toast(
												__(
													'Settings saved.',
													'iminify-image-optimizer'
												)
											);
										}
									} )
									.catch( () => {} )
							}
						/>
					) }

					{ /* The network's page holds no settings, so its account takes their place. */ }
					{ networkPage && connected && (
						<Account
							state={ state }
							busy={ busy }
							networkPage
							onDisconnect={ disconnect }
						/>
					) }

					<Leftovers
						leftovers={ shownLeftovers( state, networkPage ) }
						network={ Boolean( state.network ) }
						busy={ busy }
						onRemove={ ( leftover ) =>
							run( '/leftovers', 'DELETE', { id: leftover.id } )
								.then( () => {
									toast(
										'nginx' === leftover.kind
											? __(
													'The lines are removed. Reload Nginx so it stops serving them.',
													'iminify-image-optimizer'
												)
											: __(
													'The lines are removed.',
													'iminify-image-optimizer'
												)
									);

									return true;
								} )
								.catch( () => false )
						}
					/>
				</div>

				<div className="iminify-col">
					{ ! networkPage && connected && (
						<Account
							state={ state }
							busy={ busy }
							readOnly={ mainSite }
							onDisconnect={ disconnect }
						/>
					) }

					{ networkPage && <NetworkSites state={ state } /> }

					{ ! networkPage && (
						<Queue
							state={ state }
							busy={ busy }
							onStart={ () =>
								run( '/queue/start', 'POST' )
									.then( ( next ) => {
										if ( next.started ) {
											toast(
												__(
													'The images waiting are on their way.',
													'iminify-image-optimizer'
												)
											);
										} else {
											setError( {
												message: __(
													"Your account's images for the day are still used up.",
													'iminify-image-optimizer'
												),
											} );
										}
									} )
									.catch( () => {} )
							}
						/>
					) }
				</div>
			</div>
		</>
	);
}

/**
 * Which Connect panel the page offers: the network's on its own page, a site's
 * own connection on a site its network could take in, the site's otherwise.
 *
 * @param {boolean} networkPage Whether this is the network admin's page.
 * @param {boolean} joinable    Whether the site can join its connected network.
 * @return {string} The panel's variant.
 */
function connectVariant( networkPage, joinable ) {
	if ( networkPage ) {
		return 'network';
	}

	return joinable ? 'own' : 'site';
}

/**
 * What the page says after Iminify stopped the key.
 *
 * @param {boolean} networkPage Whether this is the network admin's page.
 * @param {boolean} mainSite    Whether this is a network's main site.
 * @param {boolean} joinable    Whether the site can join its connected network.
 * @return {string} The sentence.
 */
function revokedText( networkPage, mainSite, joinable ) {
	if ( networkPage ) {
		return __(
			"Iminify disconnected the network, so new uploads on its sites aren't optimized. Connect it again to carry on.",
			'iminify-image-optimizer'
		);
	}

	if ( mainSite ) {
		return __(
			"Iminify disconnected the network, so new uploads aren't optimized. A super admin connects it again in the network admin.",
			'iminify-image-optimizer'
		);
	}

	if ( joinable ) {
		return __(
			"Iminify disconnected this site, so new uploads aren't optimized. Join the network again, or connect the site on its own, to carry on.",
			'iminify-image-optimizer'
		);
	}

	return __(
		"Iminify disconnected this site, so new uploads aren't optimized. Connect it again to carry on.",
		'iminify-image-optimizer'
	);
}

/**
 * Iminify out of reach with the site's key kept: try again, or disconnect.
 *
 * @param {Object}     props              The component's props.
 * @param {Object}     props.state        The page's state.
 * @param {boolean}    props.busy         Whether a press is on its way.
 * @param {string}     props.what         What a Disconnect stops (`disconnecting()`).
 * @param {() => void} props.onRetry      Reads the state again.
 * @param {() => void} props.onDisconnect Disconnects the site.
 */
function Unreachable( { state, busy, what, onRetry, onDisconnect } ) {
	const { asking, open, close, opener } = useAsk();

	return (
		<Callout tone="danger">
			<p>{ state.error.message }</p>
			{ asking ? (
				<DisconnectConfirm
					busy={ busy }
					what={ what }
					onYes={ onDisconnect }
					onNo={ close }
				/>
			) : (
				<div className="iminify-actions">
					<Button
						icon="restore"
						disabled={ busy }
						onClick={ onRetry }
					>
						{ __( 'Try again', 'iminify-image-optimizer' ) }
					</Button>
					{ ! state.key_in_config && (
						<Button
							ref={ opener }
							variant="ghost"
							danger
							disabled={ busy }
							onClick={ open }
						>
							{ __( 'Disconnect', 'iminify-image-optimizer' ) }
						</Button>
					) }
				</div>
			) }
		</Callout>
	);
}
