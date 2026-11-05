/**
 * The Iminify filter in the media library's grid, beside core's own filters. It runs
 * before the grid is drawn, so core's browser is extended as this script loads; the
 * choice travels as a query property, which `Media_View::apply_grid_filter()` reads.
 */
import { __ } from '@wordpress/i18n';

const media = window.wp?.media;

if ( media?.view?.AttachmentsBrowser && media?.view?.AttachmentFilters ) {
	const Filter = media.view.AttachmentFilters.extend( {
		id: 'iminify-filter',

		createFilters() {
			const choices = [
				[
					'all',
					__( 'All Iminify statuses', 'iminify-image-optimizer' ),
				],
				[ 'optimized', __( 'Optimized', 'iminify-image-optimizer' ) ],
				[
					'not_optimized',
					__( 'Not optimized', 'iminify-image-optimizer' ),
				],
				[ 'errors', __( 'Errors', 'iminify-image-optimizer' ) ],
				[
					'missing_formats',
					__( 'Missing formats', 'iminify-image-optimizer' ),
				],
			];

			this.filters = {};

			choices.forEach( ( [ value, text ], index ) => {
				this.filters[ value ] = {
					text,
					priority: ( index + 1 ) * 10,
					props: { iminify_status: 'all' === value ? null : value },
				};
			} );
		},
	} );

	const Browser = media.view.AttachmentsBrowser;

	media.view.AttachmentsBrowser = Browser.extend( {
		createToolbar( ...args ) {
			Browser.prototype.createToolbar.apply( this, args );

			this.toolbar.set(
				'iminifyFilterLabel',
				new media.view.Label( {
					value: __(
						'Filter by Iminify status',
						'iminify-image-optimizer'
					),
					attributes: { for: 'iminify-filter' },
					priority: -76,
				} ).render()
			);
			this.toolbar.set(
				'iminifyFilter',
				new Filter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -75,
				} ).render()
			);
		},
	} );
}
