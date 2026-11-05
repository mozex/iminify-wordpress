/**
 * What is waiting: the images the plugin holds for the next batch or for the
 * connection, with "Start now" once a batch can start. A low disk shows here too.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { Button, Callout, Icon, Panel, PanelTitle } from '../ui';

export default function Queue( { state, busy, onStart } ) {
	const { queue, disk } = state;
	const waiting = queue.paused + queue.parked + queue.working;

	if ( 0 === waiting && ! queue.next_batch && ! disk.low ) {
		return null;
	}

	const lines = [
		queue.working > 0 && [
			'send',
			sprintf(
				/* translators: %d: how many images. */
				_n(
					'%d image is being optimized.',
					'%d images are being optimized.',
					queue.working,
					'iminify-image-optimizer'
				),
				queue.working
			),
		],
		queue.paused > 0 && [
			'clock',
			sprintf(
				/* translators: %d: how many images. */
				_n(
					'%d image is waiting for the next batch.',
					'%d images are waiting for the next batch.',
					queue.paused,
					'iminify-image-optimizer'
				),
				queue.paused
			),
		],
		queue.parked > 0 && [
			'plug',
			sprintf(
				/* translators: %d: how many images. */
				_n(
					'%d image is waiting for the site to be connected. It goes out by itself once it is.',
					'%d images are waiting for the site to be connected. They go out by themselves once it is.',
					queue.parked,
					'iminify-image-optimizer'
				),
				queue.parked
			),
		],
		queue.next_batch && [
			'clock',
			sprintf(
				/* translators: %s: the date and time the next batch opens. */
				__(
					'The next batch opens on %s. New uploads go first, by themselves.',
					'iminify-image-optimizer'
				),
				queue.next_batch
			),
		],
	].filter( Boolean );

	return (
		<Panel>
			<PanelTitle icon="queue">
				{ __( 'Queue', 'iminify-image-optimizer' ) }
			</PanelTitle>
			{ disk.low && (
				<div className="iminify-status-gap">
					<Callout icon="disk">
						<p>
							{ __(
								'The server is low on disk space, so Iminify has stopped optimizing images here. Free some up, then optimize the images marked "Not optimized".',
								'iminify-image-optimizer'
							) }
						</p>
					</Callout>
				</div>
			) }
			{ lines.length > 0 && (
				<ul className="iminify-queue">
					{ lines.map( ( [ icon, text ] ) => (
						<li key={ text }>
							<Icon name={ icon } />
							<span>{ text }</span>
						</li>
					) ) }
				</ul>
			) }
			{ queue.can_start && (
				<div className="iminify-actions">
					<Button
						variant="primary"
						icon="play"
						busy={ busy }
						disabled={ busy }
						onClick={ onStart }
					>
						{ __( 'Start now', 'iminify-image-optimizer' ) }
					</Button>
				</div>
			) }
		</Panel>
	);
}
