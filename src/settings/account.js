/**
 * The connected site and its account: the plan, the day's counts and the
 * next batch, all as Iminify answered them.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

function Usage( { row } ) {
	const text =
		null === row.remaining
			? sprintf(
					/* translators: %s: how many were used today. */
					__( '%s used, no daily limit', 'iminify-image-optimizer' ),
					row.used
				)
			: sprintf(
					/* translators: 1: how many were used today, 2: how many are left. */
					__( '%1$s used, %2$s left', 'iminify-image-optimizer' ),
					row.used,
					row.remaining
				);

	return (
		<tr>
			<th scope="row">{ row.label }</th>
			<td>{ text }</td>
		</tr>
	);
}

export default function Account( { state, busy, onDisconnect } ) {
	const { site, links } = state;

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>{ __( 'Connected', 'iminify-image-optimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
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
				<table className="iminify-account">
					<tbody>
						{ site.plan && (
							<tr>
								<th scope="row">
									{ __( 'Plan', 'iminify-image-optimizer' ) }
								</th>
								<td>
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
								</td>
							</tr>
						) }
						{ ( site.usage ?? [] ).map( ( row ) => (
							<Usage key={ row.key } row={ row } />
						) ) }
						{ site.next_batch_at && (
							<tr>
								<th scope="row">
									{ __(
										'Next batch',
										'iminify-image-optimizer'
									) }
								</th>
								<td>{ site.next_batch_at }</td>
							</tr>
						) }
					</tbody>
				</table>
				{ state.hidden ? (
					<p>
						{ __(
							"This site's Iminify account is set in wp-config.php.",
							'iminify-image-optimizer'
						) }
					</p>
				) : (
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
				{ state.key_in_config ? (
					<p>
						{ __(
							"The site's key is set in wp-config.php as IMINIFY_API_KEY. To disconnect, remove it there, and disconnect the site under Settings, Sites on iminify.com.",
							'iminify-image-optimizer'
						) }
					</p>
				) : (
					<Button
						variant="secondary"
						isDestructive
						__next40pxDefaultSize
						disabled={ busy }
						onClick={ onDisconnect }
					>
						{ __( 'Disconnect', 'iminify-image-optimizer' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
