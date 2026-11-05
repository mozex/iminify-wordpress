/**
 * The page's states: connecting, connected, a copy at another address, and
 * Iminify out of reach. The local settings show in every one of them, since
 * they work without a connection. Drawn with the design system's parts
 * (`src/ui`): refusals stay on the page, what went well shows as a toast.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { call, openTab } from './api';
import Account, { DisconnectConfirm } from './account';
import { Connect, Pairing, PasteKey } from './connect';
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

export default function App() {
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

	const [ pillState, pillText ] = pill( state?.connection );

	useHeaderStatus( null === state ? null : pillState, pillText );

	useEffect( () => {
		if ( ! pairing ) {
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
								__(
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
	}, [ pairing, toast ] );

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
						message: __(
							"The site is disconnected here, but its key couldn't be stopped on Iminify. Disconnect it under Settings, Sites on iminify.com too.",
							'iminify-image-optimizer'
						),
					} );
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
			{ state.revoked && ! connected && (
				<Callout icon="plug">
					<p>
						{ __(
							"Iminify disconnected this site, so new uploads aren't optimized. Connect it again to carry on.",
							'iminify-image-optimizer'
						) }
					</p>
				</Callout>
			) }

			<div className="iminify-grid">
				<div className="iminify-col">
					{ 'disconnected' === state.connection && (
						<>
							<Connect
								state={ state }
								busy={ busy }
								onConnect={ () => connect( '/connect' ) }
							/>
							<PasteKey
								state={ state }
								busy={ busy }
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

					{ pairing && (
						<Pairing
							state={ state }
							busy={ busy }
							onCancel={ () =>
								run( '/connect', 'DELETE' ).catch( () => {} )
							}
						/>
					) }

					{ 'moved' === state.connection && (
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

					{ 'error' === state.connection && (
						<Unreachable
							state={ state }
							busy={ busy }
							onRetry={ () => run( '/state' ).catch( () => {} ) }
							onDisconnect={ disconnect }
						/>
					) }

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
				</div>

				<div className="iminify-col">
					{ connected && (
						<Account
							state={ state }
							busy={ busy }
							onDisconnect={ disconnect }
						/>
					) }

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
				</div>
			</div>
		</>
	);
}

/**
 * Iminify out of reach with the site's key kept: try again, or disconnect.
 *
 * @param {Object}     props              The component's props.
 * @param {Object}     props.state        The page's state.
 * @param {boolean}    props.busy         Whether a press is on its way.
 * @param {() => void} props.onRetry      Reads the state again.
 * @param {() => void} props.onDisconnect Disconnects the site.
 */
function Unreachable( { state, busy, onRetry, onDisconnect } ) {
	const { asking, open, close, opener } = useAsk();

	return (
		<Callout tone="danger">
			<p>{ state.error.message }</p>
			{ asking ? (
				<DisconnectConfirm
					busy={ busy }
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
