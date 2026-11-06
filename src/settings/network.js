/**
 * A multisite network the plugin is active for: the network admin's page,
 * where a super admin connects the whole network, the main site's page, which
 * shows the network's connection without changing it, and another site's
 * page, which joins the network or connects on its own.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button, Callout, ExternalLink, Panel, PanelTitle } from '../ui';

/**
 * A link to the network admin's page, for someone who can manage the network.
 *
 * @param {Object} props         The component's props.
 * @param {Object} props.network The state's network.
 */
function NetworkAdminLink( { network } ) {
	if ( ! network.manage ) {
		return null;
	}

	return (
		<div className="iminify-actions">
			<a
				className="iminify-btn iminify-btn--secondary"
				href={ network.admin_url }
			>
				{ __(
					"Open the network's Iminify settings",
					'iminify-image-optimizer'
				) }
			</a>
		</div>
	);
}

/**
 * The main site's page: the network's connection is this site's, changed only
 * in the network admin.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
export function NetworkManaged( { state } ) {
	const { network } = state;
	const connected = 'connected' === state.connection;

	return (
		<Panel>
			<PanelTitle icon="plug">
				{ __( "The network's connection", 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-lead">
				{ connected
					? __(
							"This is the network's main site, so it uses the network's connection to Iminify. A super admin connects and disconnects the network in the network admin.",
							'iminify-image-optimizer'
						)
					: __(
							"This is the network's main site, so it uses the network's connection to Iminify, which isn't made yet. A super admin connects the network in the network admin, and every site joins it by itself.",
							'iminify-image-optimizer'
						) }
			</p>
			{ state.error && ! connected && (
				<Callout tone="danger">
					<p>{ state.error.message }</p>
				</Callout>
			) }
			<NetworkAdminLink network={ network } />
		</Panel>
	);
}

/**
 * The network admin's page: how the network's sites join it, and where each
 * site's own settings are.
 *
 * @param {Object} props       The component's props.
 * @param {Object} props.state The page's state.
 */
export function NetworkSites( { state } ) {
	const { network, site } = state;
	const connected = 'connected' === state.connection;

	return (
		<Panel>
			<PanelTitle icon="image">
				{ __( "The network's sites", 'iminify-image-optimizer' ) }
			</PanelTitle>
			{ connected && ! site?.network && (
				<Callout>
					<p>
						{ __(
							"This key was made for a single site, so the network's sites can't join with it. Disconnect, then connect the network again, or make a key under Settings, Sites on iminify.com with This is a multisite network ticked.",
							'iminify-image-optimizer'
						) }
					</p>
				</Callout>
			) }
			<p className="iminify-text">
				{ __(
					"Each site of the network joins by itself the first time it needs Iminify, with a key of its own, and starts with the main site's settings. Each counts as a site of its own on your account. A site disconnected on its own page, or under Settings, Sites on iminify.com, doesn't join again by itself.",
					'iminify-image-optimizer'
				) }
			</p>
			<p className="iminify-text">
				{ __(
					"Each site's settings are on its own Settings, Iminify page. The images in themes and other folders are the main site's to optimize, once for the whole network.",
					'iminify-image-optimizer'
				) }
			</p>
			<p className="iminify-links">
				<a href={ network.site_url }>
					{ __(
						"The main site's settings",
						'iminify-image-optimizer'
					) }
				</a>
				<a href={ network.folders_url }>
					{ __(
						"The main site's other folders",
						'iminify-image-optimizer'
					) }
				</a>
				<ExternalLink href={ state.links.guide }>
					{ __(
						'How a network connects',
						'iminify-image-optimizer'
					) }
				</ExternalLink>
			</p>
		</Panel>
	);
}

/**
 * Another site's page while it has no key and its network is connected: it
 * joins the network, as it would by itself, or connects on its own below.
 *
 * @param {Object}     props        The component's props.
 * @param {Object}     props.state  The page's state.
 * @param {boolean}    props.busy   Whether a press is on its way.
 * @param {() => void} props.onJoin Asks the network for a key of this site's own.
 */
export function JoinNetwork( { state, busy, onJoin } ) {
	const { network } = state;

	return (
		<Panel>
			<PanelTitle icon="plug">
				{ __( 'Join the network', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-lead">
				{ sprintf(
					/* translators: %s: the network's name. */
					__(
						'%s is connected to Iminify. This site can join it with a key of its own, and counts as a site of its own on the account.',
						'iminify-image-optimizer'
					),
					network.name
				) }
			</p>
			{ network.refusal && (
				<Callout>
					<p>{ network.refusal.message }</p>
				</Callout>
			) }
			<div className="iminify-actions">
				<Button
					variant="primary"
					size="big"
					iconEnd="arrow"
					busy={ busy }
					disabled={ busy }
					onClick={ onJoin }
				>
					{ __( 'Join the network', 'iminify-image-optimizer' ) }
				</Button>
			</div>
		</Panel>
	);
}
