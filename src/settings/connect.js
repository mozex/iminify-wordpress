/**
 * Connecting the site: Connect, waiting for the approval, and a pasted key.
 * On a network's page they connect the network; on another site of a connected
 * network, the site on its own.
 */
import { TextControl } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button, ExternalLink, Icon, Panel, PanelTitle } from '../ui';

/**
 * The words of the Connect panel for what it connects.
 *
 * @param {string} variant `site`, `network`, or `own` for a site of a connected network.
 * @return {Object} Its title, lead, text and button.
 */
function connectWords( variant ) {
	if ( 'network' === variant ) {
		return {
			title: __( 'Connect your network', 'iminify-image-optimizer' ),
			lead: __(
				'Iminify compresses the images uploaded to every site of this network and makes WebP and AVIF copies of them. Connect the network to your Iminify account once, or create a free account on the way, and each site joins by itself the first time it needs Iminify.',
				'iminify-image-optimizer'
			),
			text: __(
				'Nothing is sent to Iminify until you connect. Connect opens iminify.com in a new tab, where you approve the network.',
				'iminify-image-optimizer'
			),
			button: __( 'Connect the network', 'iminify-image-optimizer' ),
		};
	}

	if ( 'own' === variant ) {
		return {
			title: __(
				'Connect this site on its own',
				'iminify-image-optimizer'
			),
			lead: __(
				"This site can connect to an Iminify account of its own instead, and then never uses the network's connection.",
				'iminify-image-optimizer'
			),
			text: __(
				'Connect opens iminify.com in a new tab, where you approve this site.',
				'iminify-image-optimizer'
			),
			button: __( 'Connect', 'iminify-image-optimizer' ),
		};
	}

	return {
		title: __( 'Connect your site', 'iminify-image-optimizer' ),
		lead: __(
			'Iminify compresses the images you upload and makes WebP and AVIF copies of them. Connect this site to your Iminify account, or create a free account on the way, to start.',
			'iminify-image-optimizer'
		),
		text: __(
			'Nothing is sent to Iminify until you connect. Connect opens iminify.com in a new tab, where you approve this site.',
			'iminify-image-optimizer'
		),
		button: __( 'Connect', 'iminify-image-optimizer' ),
	};
}

export function Connect( { state, busy, onConnect, variant = 'site' } ) {
	const words = connectWords( variant );
	const networkHint =
		'site' === variant && state.network && ! state.network.main;

	return (
		<Panel>
			<PanelTitle icon="plug">{ words.title }</PanelTitle>
			<p className="iminify-lead">{ words.lead }</p>
			<p className="iminify-text">{ words.text }</p>
			{ networkHint && (
				<p className="iminify-text">
					{ __(
						'A super admin can connect the whole network from the network admin instead, and every site joins it by itself.',
						'iminify-image-optimizer'
					) }
				</p>
			) }
			<div className="iminify-actions">
				{ /* Beside Join the network, connecting on its own is the other way, so it isn't drawn as the way on. */ }
				<Button
					variant={ 'own' === variant ? 'secondary' : 'primary' }
					size={ 'own' === variant ? '' : 'big' }
					iconEnd="arrow"
					busy={ busy }
					disabled={ busy }
					onClick={ onConnect }
				>
					{ words.button }
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

/**
 * How an agency gives a single site its key, linking to Settings, Sites unless the account is kept out of sight.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.links The page's links.
 */
function SiteKeyLead( { links } ) {
	return links.sites
		? createInterpolateElement(
				__(
					'An agency can add the site under <a>Settings, Sites</a> on iminify.com and paste its key here, or set it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
					'iminify-image-optimizer'
				),
				{
					a: <ExternalLink href={ links.sites } />,
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
			);
}

/**
 * How an agency gives a network its key, in the same shape as a site's.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.links The page's links.
 */
function NetworkKeyLead( { links } ) {
	return links.sites
		? createInterpolateElement(
				__(
					'An agency adds the network under <a>Settings, Sites</a> on iminify.com, with This is a multisite network ticked, and pastes its key here, or sets it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
					'iminify-image-optimizer'
				),
				{
					a: <ExternalLink href={ links.sites } />,
					code: <code />,
				}
			)
		: createInterpolateElement(
				__(
					'An agency adds the network under Settings, Sites on iminify.com, with This is a multisite network ticked, and pastes its key here, or sets it in wp-config.php as <code>IMINIFY_API_KEY</code>.',
					'iminify-image-optimizer'
				),
				{
					code: <code />,
				}
			);
}

/**
 * What connecting does, beside Connect while the site isn't connected yet:
 * what happens to uploads, how an upload counts, and the originals kept here.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
export function Welcome( { state } ) {
	const points = [
		{
			icon: 'image',
			title: __(
				'Each upload is optimized in the background',
				'iminify-image-optimizer'
			),
			text: __(
				'WordPress finishes the upload first, then Iminify compresses the image and every size WordPress made of it.',
				'iminify-image-optimizer'
			),
		},
		{
			icon: 'check',
			title: __(
				'An upload counts as one image',
				'iminify-image-optimizer'
			),
			text: __(
				'The sizes WordPress made of it and its WebP and AVIF copies come with it as files, never as more images.',
				'iminify-image-optimizer'
			),
		},
		state.local?.backups && {
			icon: 'restore',
			title: __(
				'Your originals stay on this site',
				'iminify-image-optimizer'
			),
			text: __(
				'A backup of each one is kept in the uploads folder, so any image can be put back as it was.',
				'iminify-image-optimizer'
			),
		},
		{
			icon: 'folder',
			title: __(
				'Your library goes when you say so',
				'iminify-image-optimizer'
			),
			text: __(
				'Images already in the media library wait until you start them under Media, Bulk Optimization.',
				'iminify-image-optimizer'
			),
		},
	].filter( Boolean );

	return (
		<Panel>
			<PanelTitle icon="send">
				{ __( 'Once you connect', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<ul className="iminify-welcome">
				{ points.map( ( point ) => (
					<li key={ point.icon }>
						<span className="iminify-mode__ico">
							<Icon name={ point.icon } />
						</span>
						<b>{ point.title }</b>
						<span>{ point.text }</span>
					</li>
				) ) }
			</ul>
		</Panel>
	);
}

export function PasteKey( { state, busy, onPaste, network = false } ) {
	const [ key, setKey ] = useState( '' );

	return (
		<Panel>
			<PanelTitle icon="key">
				{ network
					? __(
							"Connect with the network's key",
							'iminify-image-optimizer'
						)
					: __(
							'Connect with a site key',
							'iminify-image-optimizer'
						) }
			</PanelTitle>
			<p className="iminify-lead">
				{ network ? (
					<NetworkKeyLead links={ state.links } />
				) : (
					<SiteKeyLead links={ state.links } />
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
					label={
						network
							? __( 'Network key', 'iminify-image-optimizer' )
							: __( 'Site key', 'iminify-image-optimizer' )
					}
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
