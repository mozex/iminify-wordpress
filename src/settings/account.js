/**
 * The connected site and its account: the plan, the day's counts as tiles
 * with a meter each, and the next batch, all as Iminify answered them.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Button,
	Confirm,
	ExternalLink,
	Panel,
	PanelTitle,
	useAsk,
} from '../ui';

/**
 * The question before a key stops, for what it stops.
 *
 * @param {string} what `site`, `network`, or `joined` for a site whose key came from its network.
 * @return {string} The question.
 */
function disconnectQuestion( what ) {
	if ( 'network' === what ) {
		return __(
			'Disconnect the network from Iminify? Every key its sites joined with stops too, and new uploads on those sites stop being optimized. Images already optimized stay as they are.',
			'iminify-image-optimizer'
		);
	}

	if ( 'joined' === what ) {
		return __(
			"Disconnect this site from Iminify? New uploads stop being optimized, and the site doesn't join the network again by itself: press Join the network to bring it back. Images already optimized stay as they are.",
			'iminify-image-optimizer'
		);
	}

	return __(
		'Disconnect this site from Iminify? New uploads stop being optimized. Images already optimized stay as they are.',
		'iminify-image-optimizer'
	);
}

/**
 * What a Disconnect on this page stops: the network's key, a key the site joined its network with, or the site's own.
 *
 * @param {Object}  state       The page's state.
 * @param {boolean} networkPage Whether this is the network admin's page.
 * @return {string} `network`, `joined` or `site`.
 */
export function disconnecting( state, networkPage ) {
	if ( networkPage ) {
		return 'network';
	}

	return state.network?.joined ? 'joined' : 'site';
}

/**
 * The yes or no before the site's key stops.
 *
 * @param {Object}     props       The component's props.
 * @param {boolean}    props.busy  Whether a press is on its way.
 * @param {string}     props.what  `site`, `network` or `joined` (`disconnectQuestion()`).
 * @param {() => void} props.onYes Disconnects the site.
 * @param {() => void} props.onNo  Keeps it connected.
 */
export function DisconnectConfirm( { busy, what = 'site', onYes, onNo } ) {
	return (
		<Confirm
			question={ disconnectQuestion( what ) }
			yes={ __( 'Disconnect', 'iminify-image-optimizer' ) }
			danger
			busy={ busy }
			onYes={ onYes }
			onNo={ onNo }
		/>
	);
}

/**
 * One of the day's counts: what was used, what is left, and a meter of the day.
 *
 * @param {Object} props     The component's props.
 * @param {Object} props.row The count, as the server built it.
 */
function Usage( { row } ) {
	const limited = null !== row.remaining;
	const share = limited ? Math.min( 1, Math.max( 0, row.share ?? 0 ) ) : 0;

	return (
		<div className="iminify-tile">
			<span className="iminify-label">{ row.label }</span>
			<span className="iminify-tile__value iminify-num">
				{ row.used }
			</span>
			{ limited && (
				<span
					className={ `iminify-meter${
						share >= 1 ? ' iminify-meter--full' : ''
					}` }
					aria-hidden="true"
				>
					<i style={ { width: `${ Math.round( share * 100 ) }%` } } />
				</span>
			) }
			<span className="iminify-tile__note">
				{ limited
					? sprintf(
							/* translators: 1: how many were used today, 2: how many are left. */
							__(
								'%1$s used, %2$s left',
								'iminify-image-optimizer'
							),
							row.used,
							row.remaining
						)
					: sprintf(
							/* translators: %s: how many were used today. */
							__(
								'%s used, no daily limit',
								'iminify-image-optimizer'
							),
							row.used
						) }
			</span>
		</div>
	);
}

/**
 * What the account panel says is connected.
 *
 * @param {Object} state The page's state.
 * @return {string} The sentence.
 */
function connectedSentence( state ) {
	const { site, network } = state;

	if ( site.network?.main ) {
		return sprintf(
			/* translators: 1: the network's name, 2: its address. */
			__(
				'%1$s, at %2$s, is connected to Iminify as a multisite network.',
				'iminify-image-optimizer'
			),
			site.name || site.url,
			site.url
		);
	}

	if ( network?.joined ) {
		return sprintf(
			/* translators: 1: the site's name, 2: its address. */
			__(
				'%1$s, at %2$s, is connected to Iminify through its network.',
				'iminify-image-optimizer'
			),
			site.name || site.url,
			site.url
		);
	}

	return sprintf(
		/* translators: 1: the site's name, 2: its address. */
		__(
			'%1$s, at %2$s, is connected to Iminify.',
			'iminify-image-optimizer'
		),
		site.name || site.url,
		site.url
	);
}

/**
 * The connected site and its account.
 *
 * @param {Object}     props              The component's props.
 * @param {Object}     props.state        The page's state.
 * @param {boolean}    props.busy         Whether a press is on its way.
 * @param {() => void} props.onDisconnect Disconnects the site.
 * @param {boolean}    props.readOnly     Whether the connection is changed elsewhere (a network's main site).
 * @param {boolean}    props.networkPage  Whether this is the network admin's page.
 */
export default function Account( {
	state,
	busy,
	onDisconnect,
	readOnly = false,
	networkPage = false,
} ) {
	const { site, links } = state;
	const { asking, open, close, opener } = useAsk();
	const what = disconnecting( state, networkPage );

	return (
		<Panel>
			<PanelTitle icon="user">
				{ __( 'Your account', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-text">{ connectedSentence( state ) }</p>
			{ ( site.plan || site.next_batch_at || site.network ) && (
				<dl className="iminify-facts">
					{ site.plan && (
						<div>
							<dt>{ __( 'Plan', 'iminify-image-optimizer' ) }</dt>
							<dd>
								{ site.plan.ends_at
									? sprintf(
											/* translators: 1: the plan's name, 2: the date it ends or renews. */
											__(
												'%1$s, until %2$s',
												'iminify-image-optimizer'
											),
											site.plan.label,
											site.plan.ends_at
										)
									: site.plan.label }
							</dd>
						</div>
					) }
					{ site.next_batch_at && (
						<div>
							<dt>
								{ __(
									'Next batch',
									'iminify-image-optimizer'
								) }
							</dt>
							<dd>{ site.next_batch_at }</dd>
						</div>
					) }
					{ site.network && (
						<div>
							<dt>
								{ __(
									"Network's sites",
									'iminify-image-optimizer'
								) }
							</dt>
							<dd>
								{ sprintf(
									/* translators: 1: how many sites the network has on Iminify, its main site included, 2: how many it can have. */
									__(
										'%1$s of %2$s',
										'iminify-image-optimizer'
									),
									site.network.sites,
									site.network.max_sites
								) }
							</dd>
						</div>
					) }
				</dl>
			) }
			{ site.usage?.length > 0 && (
				<div className="iminify-tiles">
					{ site.usage.map( ( row ) => (
						<Usage key={ row.key } row={ row } />
					) ) }
				</div>
			) }
			{ site.stats?.length > 0 && (
				<div className="iminify-totals">
					<span className="iminify-label">
						{ __( 'Saved so far', 'iminify-image-optimizer' ) }
					</span>
					<dl className="iminify-facts">
						{ site.stats.map( ( row ) => (
							<div key={ row.key }>
								<dt>
									{ /* The network's key sends the main site's images, the other sites having keys of their own. */ }
									{ networkPage && 'site' === row.key
										? __(
												'The main site',
												'iminify-image-optimizer'
											)
										: row.label }
								</dt>
								<dd className="iminify-num">
									<bdi className="iminify-totals__saved">
										{ sprintf(
											/* translators: %s: the bytes Iminify saved, such as 1.2 GB. */
											__(
												'%s saved',
												'iminify-image-optimizer'
											),
											row.saved
										) }
									</bdi>
									<bdi className="iminify-totals__images">
										{ sprintf(
											/* translators: %s: how many images Iminify optimized. */
											_n(
												'%s image',
												'%s images',
												row.count,
												'iminify-image-optimizer'
											),
											row.images
										) }
									</bdi>
								</dd>
							</div>
						) ) }
					</dl>
				</div>
			) }
			{ /* With the key itself in wp-config.php, the line below says so with how to disconnect. */ }
			{ state.hidden
				? ! state.key_in_config && (
						<p className="iminify-text">
							{ __(
								"This site's Iminify account is set in wp-config.php.",
								'iminify-image-optimizer'
							) }
						</p>
					)
				: ( links.plan || links.plans ) && (
						<p className="iminify-links">
							{ links.plan && (
								<ExternalLink href={ links.plan }>
									{ __(
										'Your plan on iminify.com',
										'iminify-image-optimizer'
									) }
								</ExternalLink>
							) }
							{ links.plans && (
								<ExternalLink href={ links.plans }>
									{ __(
										'Compare the plans',
										'iminify-image-optimizer'
									) }
								</ExternalLink>
							) }
						</p>
					) }
			{ state.key_in_config && ! readOnly && (
				<p className="iminify-text">
					{ createInterpolateElement(
						networkPage
							? __(
									"The network's key is set in wp-config.php as <code>IMINIFY_API_KEY</code>. To disconnect, remove it there, and disconnect the network under Settings, Sites on iminify.com.",
									'iminify-image-optimizer'
								)
							: __(
									"The site's key is set in wp-config.php as <code>IMINIFY_API_KEY</code>. To disconnect, remove it there, and disconnect the site under Settings, Sites on iminify.com.",
									'iminify-image-optimizer'
								),
						{ code: <code /> }
					) }
				</p>
			) }
			{ ! state.key_in_config &&
				! readOnly &&
				( asking ? (
					<DisconnectConfirm
						busy={ busy }
						what={ what }
						onYes={ onDisconnect }
						onNo={ close }
					/>
				) : (
					<div className="iminify-actions">
						<Button
							ref={ opener }
							variant="ghost"
							danger
							icon="plug"
							disabled={ busy }
							onClick={ open }
						>
							{ __( 'Disconnect', 'iminify-image-optimizer' ) }
						</Button>
					</div>
				) ) }
		</Panel>
	);
}
