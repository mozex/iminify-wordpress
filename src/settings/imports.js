/**
 * "Use <plugin>'s settings" (D11): another image optimizer's settings, which
 * the plugin kept a copy of, offered as the site's own once it is connected.
 * The first press asks Iminify what they match and shows it beside the values
 * now, changing nothing; the second uses them. Drawn from what the server
 * answered (`Admin\Settings_Import`), the settings in the settings form's words.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button, Icon, Panel, PanelTitle } from '../ui';

/**
 * What Iminify proposed, each setting with its value now and the one proposed.
 *
 * @param {Object}   props      The component's props.
 * @param {Object[]} props.rows The settings.
 */
function Proposal( { rows } ) {
	return (
		<dl className="iminify-facts iminify-import">
			{ rows.map( ( row ) => (
				<div key={ row.label }>
					<dt>{ row.label }</dt>
					<dd>
						{ row.changes ? (
							<>
								<span className="iminify-import__now">
									{ row.now }
								</span>
								<span className="iminify-import__arrow">
									<Icon name="arrow" flip />
									<span className="iminify-sr">
										{ __(
											'becomes',
											'iminify-image-optimizer'
										) }
									</span>
								</span>
								{ row.then }
							</>
						) : (
							<>
								{ row.then }
								<span className="iminify-import__same">
									{ __(
										'no change',
										'iminify-image-optimizer'
									) }
								</span>
							</>
						) }
					</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * One plugin's offer, or what Iminify proposed for it.
 *
 * @param {Object}      props           The component's props.
 * @param {Object}      props.offer     The plugin: its short name and name.
 * @param {Object|null} props.preview   What Iminify proposed, once asked.
 * @param {boolean}     props.busy      Whether a press is on its way.
 * @param {() => void}  props.onPreview Asks Iminify.
 * @param {() => void}  props.onUse     Uses the proposal.
 * @param {() => void}  props.onDecline Turns the offer down.
 * @param {() => void}  props.onCancel  Goes back to the offer.
 */
function Offer( {
	offer,
	preview,
	busy,
	onPreview,
	onUse,
	onDecline,
	onCancel,
} ) {
	const title = sprintf(
		/* translators: %s: another image optimizer's name. */
		__( '%s’s settings', 'iminify-image-optimizer' ),
		offer.name
	);

	if ( ! preview ) {
		return (
			<Panel className="iminify-import-offer">
				<PanelTitle icon="sliders">{ title }</PanelTitle>
				<p className="iminify-lead">
					{ sprintf(
						/* translators: %s: another image optimizer's name. */
						__(
							'Iminify found %s’s settings on this site. It can set this site’s settings to match them, as far as Iminify has the same settings: the compression level, the maximum width, metadata, WebP and AVIF copies, the sizes and backups. You see what changes before anything does.',
							'iminify-image-optimizer'
						),
						offer.name
					) }
				</p>
				<div className="iminify-actions">
					<Button
						variant="primary"
						busy={ busy }
						disabled={ busy }
						onClick={ onPreview }
					>
						{ sprintf(
							/* translators: %s: another image optimizer's name. */
							__(
								'Use %s’s settings',
								'iminify-image-optimizer'
							),
							offer.name
						) }
					</Button>
					<Button
						variant="ghost"
						disabled={ busy }
						onClick={ onDecline }
					>
						{ __( 'No thanks', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			</Panel>
		);
	}

	const changes = preview.rows.some( ( row ) => row.changes );

	return (
		<Panel className="iminify-import-offer">
			<PanelTitle icon="sliders">{ title }</PanelTitle>
			{ 0 === preview.rows.length ? (
				<p className="iminify-lead" role="status">
					{ sprintf(
						/* translators: %s: another image optimizer's name. */
						__(
							'None of %s’s settings has a match in Iminify, so this site keeps its own.',
							'iminify-image-optimizer'
						),
						offer.name
					) }
				</p>
			) : (
				<>
					<p className="iminify-lead" role="status">
						{ changes
							? sprintf(
									/* translators: %s: another image optimizer's name. */
									__(
										'From %s’s settings, Iminify would set these. Nothing changes until you use them.',
										'iminify-image-optimizer'
									),
									offer.name
								)
							: sprintf(
									/* translators: %s: another image optimizer's name. */
									__(
										'This site already uses what %s’s settings match.',
										'iminify-image-optimizer'
									),
									offer.name
								) }
					</p>
					<Proposal rows={ preview.rows } />
				</>
			) }
			<div className="iminify-actions">
				{ changes ? (
					<>
						<Button
							variant="primary"
							busy={ busy }
							disabled={ busy }
							onClick={ onUse }
						>
							{ __(
								'Use these settings',
								'iminify-image-optimizer'
							) }
						</Button>
						<Button
							variant="ghost"
							disabled={ busy }
							onClick={ onCancel }
						>
							{ __( 'Cancel', 'iminify-image-optimizer' ) }
						</Button>
					</>
				) : (
					<Button
						disabled={ busy }
						onClick={
							0 === preview.rows.length ? onDecline : onUse
						}
					>
						{ __( 'OK', 'iminify-image-optimizer' ) }
					</Button>
				) }
			</div>
		</Panel>
	);
}

/**
 * Each plugin whose settings the page offers, or nothing.
 *
 * @param {Object}                  props           The component's props.
 * @param {Object[]}                props.offers    The plugins, from the state's `imports`.
 * @param {Object|null}             props.preview   What Iminify proposed for one of them.
 * @param {boolean}                 props.busy      Whether a press is on its way.
 * @param {(offer: Object) => void} props.onPreview Asks Iminify about one.
 * @param {(offer: Object) => void} props.onUse     Uses one's proposal.
 * @param {(offer: Object) => void} props.onDecline Turns one down.
 * @param {() => void}              props.onCancel  Goes back to the offers.
 */
export default function Imports( {
	offers,
	preview,
	busy,
	onPreview,
	onUse,
	onDecline,
	onCancel,
} ) {
	return offers.map( ( offer ) => (
		<Offer
			key={ offer.plugin }
			offer={ offer }
			preview={ preview?.plugin === offer.plugin ? preview : null }
			busy={ busy }
			onPreview={ () => onPreview( offer ) }
			onUse={ () => onUse( offer ) }
			onDecline={ () => onDecline( offer ) }
			onCancel={ onCancel }
		/>
	) );
}
