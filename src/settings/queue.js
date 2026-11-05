/**
 * What is waiting: the images the plugin holds for the next batch or for the
 * connection, with "Start now" once a batch can start. A low disk shows here too.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

export default function Queue( { state, busy, onStart } ) {
	const { queue, disk } = state;
	const waiting = queue.paused + queue.parked + queue.working;

	if ( 0 === waiting && ! queue.next_batch && ! disk.low ) {
		return null;
	}

	return (
		<Card className="iminify-card">
			<CardHeader>
				<h2>{ __( 'Queue', 'iminify-image-optimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				{ disk.low && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The server is low on disk space, so Iminify has stopped optimizing images here. Free some up, then optimize the images marked "Not optimized".',
							'iminify-image-optimizer'
						) }
					</Notice>
				) }
				{ queue.working > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: how many images. */
							_n(
								'%d image is being optimized.',
								'%d images are being optimized.',
								queue.working,
								'iminify-image-optimizer'
							),
							queue.working
						) }
					</p>
				) }
				{ queue.paused > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: how many images. */
							_n(
								'%d image is waiting for the next batch.',
								'%d images are waiting for the next batch.',
								queue.paused,
								'iminify-image-optimizer'
							),
							queue.paused
						) }
					</p>
				) }
				{ queue.parked > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: how many images. */
							_n(
								'%d image is waiting for the site to be connected. It goes out by itself once it is.',
								'%d images are waiting for the site to be connected. They go out by themselves once it is.',
								queue.parked,
								'iminify-image-optimizer'
							),
							queue.parked
						) }
					</p>
				) }
				{ queue.next_batch && (
					<p>
						{ sprintf(
							/* translators: %s: the date and time the next batch opens. */
							__(
								'The next batch opens on %s. New uploads go first, by themselves.',
								'iminify-image-optimizer'
							),
							queue.next_batch
						) }
					</p>
				) }
				{ queue.can_start && (
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ busy }
						disabled={ busy }
						onClick={ onStart }
					>
						{ __( 'Start now', 'iminify-image-optimizer' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
