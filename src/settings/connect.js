/**
 * Connecting the site: Connect, waiting for the approval, and a pasted key.
 */
import { TextControl } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button, ExternalLink, Panel, PanelTitle } from '../ui';

export function Connect( { state, busy, onConnect } ) {
	return (
		<Panel>
			<PanelTitle icon="plug">
				{ __( 'Connect your site', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-lead">
				{ __(
					'Iminify compresses the images you upload and makes WebP and AVIF copies of them. Connect this site to your Iminify account, or create a free account on the way, to start.',
					'iminify-image-optimizer'
				) }
			</p>
			<p className="iminify-text">
				{ __(
					'Nothing is sent to Iminify until you connect. Connect opens iminify.com in a new tab, where you approve this site.',
					'iminify-image-optimizer'
				) }
			</p>
			<div className="iminify-actions">
				<Button
					variant="primary"
					size="big"
					iconEnd="arrow"
					busy={ busy }
					disabled={ busy }
					onClick={ onConnect }
				>
					{ __( 'Connect', 'iminify-image-optimizer' ) }
				</Button>
				<span className="iminify-links iminify-links--inline">
					<ExternalLink href={ state.links.guide }>
						{ __(
							'How connecting works',
							'iminify-image-optimizer'
						) }
					</ExternalLink>
				</span>
			</div>
		</Panel>
	);
}

export function Pairing( { state, busy, onCancel } ) {
	return (
		<Panel>
			<PanelTitle icon="plug">
				{ __( 'Waiting for your approval', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-wait" role="status">
				<span className="iminify-spinner" aria-hidden="true" />
				<span>
					{ __(
						'Approve the connection on iminify.com, in the tab that opened. This page connects by itself once you do, and the site carries on connecting if you close it.',
						'iminify-image-optimizer'
					) }
				</span>
			</p>
			<div className="iminify-actions">
				<Button variant="ghost" disabled={ busy } onClick={ onCancel }>
					{ __( 'Cancel', 'iminify-image-optimizer' ) }
				</Button>
				<span className="iminify-links iminify-links--inline">
					<ExternalLink href={ state.pairing.connect_url }>
						{ __(
							'Open the page on iminify.com again',
							'iminify-image-optimizer'
						) }
					</ExternalLink>
				</span>
			</div>
		</Panel>
	);
}

export function PasteKey( { state, busy, onPaste } ) {
	const [ key, setKey ] = useState( '' );

	return (
		<Panel>
			<PanelTitle icon="key">
				{ __( 'Connect with a site key', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-lead">
				{ state.links.sites
					? createInterpolateElement(
							__(
								'An agency can add the site under <a>Settings, Sites</a> on iminify.com and paste its key here, or set it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
								'iminify-image-optimizer'
							),
							{
								a: <ExternalLink href={ state.links.sites } />,
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
				<div className="iminify-actions">
					<Button
						type="submit"
						busy={ busy }
						disabled={ busy || '' === key.trim() }
					>
						{ __(
							'Connect with this key',
							'iminify-image-optimizer'
						) }
					</Button>
				</div>
			</form>
		</Panel>
	);
}
