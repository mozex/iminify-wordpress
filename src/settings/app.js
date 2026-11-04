/**
 * The page's states: connecting, connected, a copy at another address, and
 * Iminify out of reach. The local settings show in every one of them, since
 * they work without a connection.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { call, openTab } from './api';
import Account from './account';
import { Connect, Pairing, PasteKey } from './connect';
import SettingsForm from './settings-form';

/**
 * How long the page waits between claims while a pairing waits for its approval.
 */
const CLAIM_EVERY_MS = 3000;

export default function App() {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( null );
	const claimTimer = useRef( null );

	const run = useCallback( ( path, method, data ) => {
		setBusy( true );
		setError( null );
		setSuccess( null );

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
							setSuccess(
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
	}, [ pairing ] );

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

	const disconnect = () => {
		if (
			// eslint-disable-next-line no-alert -- a one-line yes or no before the key stops.
			! window.confirm(
				__(
					'Disconnect this site from Iminify? New uploads stop being optimized. Images already optimized stay as they are.',
					'iminify-image-optimizer'
				)
			)
		) {
			return;
		}

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
	};

	if ( null === state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error.message }
				<Button
					variant="link"
					onClick={ () => run( '/state' ).catch( () => {} ) }
				>
					{ __( 'Try again', 'iminify-image-optimizer' ) }
				</Button>
			</Notice>
		) : (
			<Spinner />
		);
	}

	const connected = 'connected' === state.connection;

	return (
		<div className="iminify-settings">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error.message }
				</Notice>
			) }
			{ success && (
				<Notice status="success" onRemove={ () => setSuccess( null ) }>
					{ success }
				</Notice>
			) }
			{ state.site?.message && (
				<Notice
					status={
						'update_required' === state.site.message.code
							? 'error'
							: 'warning'
					}
					isDismissible={ false }
				>
					{ state.site.message.text }
				</Notice>
			) }
			{ state.revoked && ! connected && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"Iminify disconnected this site, so new uploads aren't optimized. Connect it again to carry on.",
						'iminify-image-optimizer'
					) }
				</Notice>
			) }

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
									setSuccess(
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
				<Notice status="warning" isDismissible={ false }>
					<p>{ state.error.message }</p>
					<p>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								run( '/move', 'POST' ).catch( () => {} )
							}
						>
							{ __(
								'Move the connection here',
								'iminify-image-optimizer'
							) }
						</Button>{ ' ' }
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => connect( '/copy' ) }
						>
							{ __(
								'Connect this copy as its own site',
								'iminify-image-optimizer'
							) }
						</Button>
					</p>
				</Notice>
			) }

			{ 'error' === state.connection && (
				<Notice status="error" isDismissible={ false }>
					<p>{ state.error.message }</p>
					<p>
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => run( '/state' ).catch( () => {} ) }
						>
							{ __( 'Try again', 'iminify-image-optimizer' ) }
						</Button>{ ' ' }
						{ ! state.key_in_config && (
							<Button
								variant="secondary"
								isDestructive
								disabled={ busy }
								onClick={ disconnect }
							>
								{ __(
									'Disconnect',
									'iminify-image-optimizer'
								) }
							</Button>
						) }
					</p>
				</Notice>
			) }

			{ connected && (
				<Account
					state={ state }
					busy={ busy }
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
								setSuccess(
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
	);
}
