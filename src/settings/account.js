/**
 * The connected site and its account: the plan, the day's counts as tiles
 * with a meter each, and the next batch, all as Iminify answered them.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	Button,
	Confirm,
	ExternalLink,
	Panel,
	PanelTitle,
	useAsk,
} from '../ui';

/**
 * The yes or no before the site's key stops.
 *
 * @param {Object}     props       The component's props.
 * @param {boolean}    props.busy  Whether a press is on its way.
 * @param {() => void} props.onYes Disconnects the site.
 * @param {() => void} props.onNo  Keeps it connected.
 */
export function DisconnectConfirm( { busy, onYes, onNo } ) {
	return (
		<Confirm
			question={ __(
				'Disconnect this site from Iminify? New uploads stop being optimized. Images already optimized stay as they are.',
				'iminify-image-optimizer'
			) }
			yes={ __( 'Disconnect', 'iminify-image-optimizer' ) }
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

export default function Account( { state, busy, onDisconnect } ) {
	const { site, links } = state;
	const { asking, open, close, opener } = useAsk();

	return (
		<Panel>
			<PanelTitle icon="image">
				{ __( 'Your account', 'iminify-image-optimizer' ) }
			</PanelTitle>
			<p className="iminify-text">
				{ sprintf(
					/* translators: 1: the site's name, 2: its address. */
					__(
						'%1$s, at %2$s, is connected to Iminify.',
						'iminify-image-optimizer'
					),
					site.name || site.url,
					site.url
				) }
			</p>
			{ ( site.plan || site.next_batch_at ) && (
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
				</dl>
			) }
			{ site.usage?.length > 0 && (
				<div className="iminify-tiles">
					{ site.usage.map( ( row ) => (
						<Usage key={ row.key } row={ row } />
					) ) }
				</div>
			) }
			{ state.hidden ? (
				<p className="iminify-text">
					{ __(
						"This site's Iminify account is set in wp-config.php.",
						'iminify-image-optimizer'
					) }
				</p>
			) : (
				( links.plan || links.plans ) && (
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
				)
			) }
			{ state.key_in_config && (
				<p className="iminify-text">
					{ __(
						"The site's key is set in wp-config.php as IMINIFY_API_KEY. To disconnect, remove it there, and disconnect the site under Settings, Sites on iminify.com.",
						'iminify-image-optimizer'
					) }
				</p>
			) }
			{ ! state.key_in_config &&
				( asking ? (
					<DisconnectConfirm
						busy={ busy }
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
