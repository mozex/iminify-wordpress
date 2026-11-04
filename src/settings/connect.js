/**
 * Connecting the site: Connect, waiting for the approval, and a pasted key.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function Connect( { state, busy, onConnect } ) {
	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ __( 'Connect your site', 'iminify-image-optimizer' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Iminify compresses the images you upload and makes WebP and AVIF copies of them. Connect this site to your Iminify account, or create a free account on the way, to start.',
						'iminify-image-optimizer'
					) }
				</p>
				<p>
					{ __(
						'Nothing is sent to Iminify until you connect. Connect opens iminify.com in a new tab, where you approve this site.',
						'iminify-image-optimizer'
					) }{ ' ' }
					<ExternalLink href={ state.links.guide }>
						{ __(
							'How connecting works',
							'iminify-image-optimizer'
						) }
					</ExternalLink>
				</p>
				<Button
					variant="primary"
					__next40pxDefaultSize
					isBusy={ busy }
					disabled={ busy }
					onClick={ onConnect }
				>
					{ __( 'Connect', 'iminify-image-optimizer' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

export function Pairing( { state, busy, onCancel } ) {
	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ __(
						'Waiting for your approval',
						'iminify-image-optimizer'
					) }
				</h2>
			</CardHeader>
			<CardBody>
				<p className="iminify-waiting">
					<Spinner />
					{ __(
						'Approve the connection on iminify.com, in the tab that opened. This page connects by itself once you do, and the site carries on connecting if you close it.',
						'iminify-image-optimizer'
					) }
				</p>
				<p>
					<ExternalLink href={ state.pairing.connect_url }>
						{ __(
							'Open the page on iminify.com again',
							'iminify-image-optimizer'
						) }
					</ExternalLink>
				</p>
				<Button
					variant="secondary"
					__next40pxDefaultSize
					disabled={ busy }
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'iminify-image-optimizer' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

export function PasteKey( { state, busy, onPaste } ) {
	const [ key, setKey ] = useState( '' );

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>
					{ __(
						'Connect with a site key',
						'iminify-image-optimizer'
					) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ state.links.sites
						? createInterpolateElement(
								__(
									'An agency can add the site under <a>Settings, Sites</a> on iminify.com and paste its key here, or set it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
									'iminify-image-optimizer'
								),
								{
									a: (
										<ExternalLink
											href={ state.links.sites }
										/>
									),
									code: <code />,
								}
							)
						: createInterpolateElement(
								__(
									'An agency can add the site under Settings, Sites on iminify.com and paste its key here, or set it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
									'iminify-image-optimizer'
								),
								{
									code: <code />,
								}
							) }
				</p>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						onPaste( key );
					} }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="password"
						autoComplete="off"
						label={ __( 'Site key', 'iminify-image-optimizer' ) }
						value={ key }
						onChange={ setKey }
					/>
					<p>
						<Button
							type="submit"
							variant="secondary"
							__next40pxDefaultSize
							isBusy={ busy }
							disabled={ busy || '' === key.trim() }
						>
							{ __(
								'Connect with this key',
								'iminify-image-optimizer'
							) }
						</Button>
					</p>
				</form>
			</CardBody>
		</Card>
	);
}
