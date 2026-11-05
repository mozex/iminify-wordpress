<?php
/**
 * The plugin's place in the media library.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Declaration;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A column and a filter in the list view, row actions, and a panel in the
 * attachment details, which core shows in the grid's modal and on the edit
 * screen. The markup is built here and only reads what the plugin keeps, so no
 * screen calls Iminify. `src/media` only sends the buttons' presses to
 * Media_Controller and puts back the markup it answers.
 */
class Media_View {

	const COLUMN = 'iminify';

	const QUERY = 'iminify_status';

	const HANDLE = 'iminify-media';

	const FILTER_OPTIMIZED = 'optimized';

	const FILTER_NOT_OPTIMIZED = 'not_optimized';

	const FILTER_ERRORS = 'errors';

	const FILTER_MISSING = 'missing_formats';

	/**
	 * The MIME types a group's source may be, with the one WordPress keeps on an iPhone upload it turned into a JPEG.
	 */
	const IMAGE_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic' );

	/**
	 * Hooks the view into WordPress's admin.
	 */
	public static function register(): void {
		add_filter( 'manage_media_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'print_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'print_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_filter' ) );
		add_filter( 'media_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_on_screen' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the column to the list view.
	 *
	 * @param array<string, string> $columns The columns.
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Iminify', 'iminify-image-optimizer' );

		return $columns;
	}

	/**
	 * Prints the column for an attachment.
	 *
	 * @param string $column     The column's name.
	 * @param int    $attachment The attachment's id.
	 */
	public static function print_column( string $column, int $attachment ): void {
		if ( self::COLUMN === $column && self::handles( $attachment ) ) {
			echo self::cell( $attachment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in cell().
		}
	}

	/**
	 * Whether the plugin has anything to say about an attachment: one of its own
	 * records, or an image a group could take. A PDF, a video or an SVG shows nothing.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function handles( int $attachment ): bool {
		return null !== Records::get( $attachment )['status'] || in_array( get_post_mime_type( $attachment ), self::IMAGE_TYPES, true );
	}

	/**
	 * The column's markup: where the attachment stands and what it saved.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function cell( int $attachment ): string {
		return self::wrap( $attachment, 'cell', self::summary( $attachment ) );
	}

	/**
	 * The attachment panel's markup: the same, with each size and the actions.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function panel( int $attachment ): string {
		return self::wrap( $attachment, 'panel', self::summary( $attachment ) . self::sizes_table( $attachment ) . self::actions( $attachment ) );
	}

	/**
	 * Whether an attachment is on its way, so the script asks after it.
	 *
	 * @param int $attachment The attachment's id.
	 */
	public static function is_busy( int $attachment ): bool {
		return in_array( Records::get( $attachment )['status'], array( Records::QUEUED, Records::WAITING ), true );
	}

	/**
	 * Adds the actions an attachment has to its row in the list view.
	 *
	 * @param array<string, string> $actions The row's actions.
	 * @param WP_Post               $post    The attachment.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		$attachment = (int) $post->ID;

		if ( ! self::handles( $attachment ) || ! current_user_can( 'edit_post', $attachment ) ) {
			return $actions;
		}

		$status    = Records::get( $attachment )['status'];
		$optimized = Records::OPTIMIZED === $status;
		$available = Restorer::availability( $attachment );

		/* An image waiting for the batch goes out by itself, and pressing this could only say so. */
		if ( null !== Plugin::keys()->get() && ! self::is_busy( $attachment ) && Records::PAUSED !== $status && Restorer::NO_ORIGINAL !== $available ) {
			$actions['iminify_optimize'] = self::row_action(
				$attachment,
				'optimize',
				$optimized ? __( 'Optimize again', 'iminify-image-optimizer' ) : __( 'Optimize', 'iminify-image-optimizer' ),
				$optimized ? self::again_warning() : ''
			);
		}

		if ( Restorer::AVAILABLE === $available && ! self::is_busy( $attachment ) ) {
			$actions['iminify_restore'] = self::row_action( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_warning() );
		}

		return $actions;
	}

	/**
	 * Adds the panel to the attachment details, in the grid's modal and on the edit screen.
	 *
	 * @param array<string, array<string, mixed>> $fields The fields.
	 * @param WP_Post                             $post   The attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public static function attachment_field( array $fields, WP_Post $post ): array {
		$attachment = (int) $post->ID;

		if ( self::handles( $attachment ) && current_user_can( 'edit_post', $attachment ) ) {
			$fields['iminify'] = array(
				'label' => __( 'Iminify', 'iminify-image-optimizer' ),
				'input' => 'html',
				'html'  => self::panel( $attachment ),
			);
		}

		return $fields;
	}

	/**
	 * Prints the filter in the list view.
	 *
	 * @param string $post_type The screen's post type.
	 */
	public static function print_filter( string $post_type = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'attachment' !== $post_type && ( null === $screen || 'upload' !== $screen->id ) ) {
			return;
		}

		$current = self::current_filter();

		printf(
			'<label for="iminify-filter" class="screen-reader-text">%s</label><select id="iminify-filter" name="%s"><option value="">%s</option>',
			esc_html__( 'Filter by Iminify status', 'iminify-image-optimizer' ),
			esc_attr( self::QUERY ),
			esc_html__( 'All Iminify statuses', 'iminify-image-optimizer' )
		);

		foreach ( self::filters() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Narrows the list view's query to the filter's choice.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function apply_filter( WP_Query $query ): void {
		global $pagenow;

		$filter = self::current_filter();

		if ( 'upload.php' !== $pagenow || ! $query->is_main_query() || '' === $filter ) {
			return;
		}

		foreach ( self::query_for( $filter ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * The query variables for a filter.
	 *
	 * @param string $filter One of the FILTER_ constants.
	 * @return array<string, mixed>
	 */
	public static function query_for( string $filter ): array {
		switch ( $filter ) {
			case self::FILTER_OPTIMIZED:
				return array( 'meta_query' => array( self::status_clause( array( Records::OPTIMIZED ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta key.
			case self::FILTER_ERRORS:
				return array( 'meta_query' => array( self::status_clause( array( Records::ERROR, Records::SKIPPED ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta key.
			case self::FILTER_NOT_OPTIMIZED:
				return array(
					'post_mime_type' => self::IMAGE_TYPES,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta key.
						'relation' => 'OR',
						array(
							'key'     => Records::STATUS_META,
							'compare' => 'NOT EXISTS',
						),
						self::status_clause( array( Records::NOT_OPTIMIZED, Records::PAUSED, Records::PARKED, Records::QUEUED, Records::WAITING ) ),
					),
				);
			default:
				return self::missing_formats_query();
		}
	}

	/**
	 * Loads the script on the list view, the grid and the attachment edit screen.
	 */
	public static function enqueue_on_screen(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && in_array( $screen->id, array( 'upload', 'attachment' ), true ) ) {
			self::enqueue();
		}
	}

	/**
	 * Loads the script and its styles, here and wherever the media modal can open.
	 */
	public static function enqueue(): void {
		if ( Settings_Page::enqueue_entry( self::HANDLE, 'media' ) && is_readable( IMINIFY_DIR . '/assets/build/media.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/media.css', IMINIFY_FILE ), array(), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * The filter's value from the address, read only to narrow a list, or an empty string.
	 */
	protected static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter that changes nothing, like core's own on this screen.
		$filter = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';

		return isset( self::filters()[ $filter ] ) ? $filter : '';
	}

	/**
	 * The filter's choices.
	 *
	 * @return array<string, string>
	 */
	protected static function filters(): array {
		return array(
			self::FILTER_OPTIMIZED     => __( 'Optimized', 'iminify-image-optimizer' ),
			self::FILTER_NOT_OPTIMIZED => __( 'Not optimized', 'iminify-image-optimizer' ),
			self::FILTER_ERRORS        => __( 'Errors', 'iminify-image-optimizer' ),
			self::FILTER_MISSING       => __( 'Missing formats', 'iminify-image-optimizer' ),
		);
	}

	/**
	 * A meta query clause for some statuses.
	 *
	 * @param list<string> $statuses The statuses.
	 * @return array<string, mixed>
	 */
	protected static function status_clause( array $statuses ): array {
		return array(
			'key'     => Records::STATUS_META,
			'value'   => $statuses,
			'compare' => 'IN',
		);
	}

	/**
	 * Optimized images whose group wasn't asked for a format the site asks for now,
	 * as when the site turned AVIF on later. A copy the service made and left out
	 * for coming out heavier than its size isn't missing: nothing more can be made.
	 *
	 * @return array<string, mixed>
	 */
	protected static function missing_formats_query(): array {
		$formats = Site_Record::formats();

		if ( array() === $formats ) {
			return array( 'post__in' => array( 0 ) );
		}

		$missing = array( 'relation' => 'OR' );

		foreach ( $formats as $format ) {
			$missing[] = array(
				'key'     => Records::FORMATS_META,
				'value'   => '|' . $format . '|',
				'compare' => 'NOT LIKE',
			);
		}

		return array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta keys.
				self::status_clause( array( Records::OPTIMIZED ) ),
				$missing,
			),
		);
	}

	/**
	 * Puts a view's markup in the element the script finds it by.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $view       `cell` or `panel`.
	 * @param string $content    The markup, already escaped.
	 */
	protected static function wrap( int $attachment, string $view, string $content ): string {
		return sprintf(
			'<div class="iminify-media" data-iminify-media="%d" data-iminify-view="%s"%s>%s</div>',
			$attachment,
			esc_attr( $view ),
			self::is_busy( $attachment ) ? ' data-iminify-busy="1"' : '',
			$content
		);
	}

	/**
	 * Where the attachment stands, why, and what it saved.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function summary( int $attachment ): string {
		$record = Records::get( $attachment );
		$html   = sprintf( '<p class="iminify-status iminify-status-%s">%s</p>', esc_attr( $record['status'] ?? 'none' ), esc_html( self::status_label( $record['status'] ) ) );
		$detail = self::status_detail( $record );

		if ( '' !== $detail ) {
			$html .= '<p class="iminify-detail">' . esc_html( $detail ) . '</p>';
		}

		if ( Records::OPTIMIZED !== $record['status'] || null === $record['result'] ) {
			return $html;
		}

		$saved = self::saved( $record['result'] );

		$html .= '<p class="iminify-detail">' . esc_html(
			$saved['before'] > $saved['after']
				? sprintf(
					/* translators: 1: the percentage saved, such as 34%, 2: the bytes saved, such as 1.2 MB, 3: how many files it covers. */
					_n( 'Saved %1$s (%2$s) across %3$s file.', 'Saved %1$s (%2$s) across %3$s files.', $saved['files'], 'iminify-image-optimizer' ),
					(string) round( 100 * ( $saved['before'] - $saved['after'] ) / $saved['before'] ) . '%',
					(string) size_format( $saved['before'] - $saved['after'], 1 ),
					number_format_i18n( $saved['files'] )
				)
				: __( 'The originals were already smaller, so they were kept.', 'iminify-image-optimizer' )
		) . '</p>';

		$formats = self::copies_label( $record );

		if ( '' !== $formats ) {
			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: the formats made, such as "WebP, AVIF". */
					__( 'Copies: %s', 'iminify-image-optimizer' ),
					$formats
				)
			) . '</p>';
		}

		if ( ! empty( $record['result']['skipped'] ) ) {
			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: how many files. */
					_n( '%s file couldn\'t be written.', '%s files couldn\'t be written.', (int) $record['result']['skipped'], 'iminify-image-optimizer' ),
					number_format_i18n( (int) $record['result']['skipped'] )
				)
			) . '</p>';
		}

		return $html;
	}

	/**
	 * A status in words.
	 *
	 * @param string|null $status The record's status.
	 */
	protected static function status_label( ?string $status ): string {
		switch ( $status ) {
			case Records::OPTIMIZED:
				return __( 'Optimized', 'iminify-image-optimizer' );
			case Records::QUEUED:
				return __( 'Queued', 'iminify-image-optimizer' );
			case Records::WAITING:
				return __( 'Being optimized', 'iminify-image-optimizer' );
			case Records::PAUSED:
				return __( 'Waiting for the next batch', 'iminify-image-optimizer' );
			case Records::PARKED:
				return __( 'Waiting for the connection', 'iminify-image-optimizer' );
			case Records::SKIPPED:
				return __( 'Not sent', 'iminify-image-optimizer' );
			case Records::ERROR:
				return __( 'Error', 'iminify-image-optimizer' );
			default:
				return __( 'Not optimized', 'iminify-image-optimizer' );
		}
	}

	/**
	 * What to add to a status: when the batch opens, or the record's reason.
	 *
	 * @param array<string, mixed> $record The record.
	 */
	protected static function status_detail( array $record ): string {
		if ( Records::PAUSED === $record['status'] ) {
			$until = Pause::until();

			return null === $until ? __( 'The next batch is open, so it goes out shortly.', 'iminify-image-optimizer' ) : sprintf(
				/* translators: %s: the date and time the next batch opens. */
				__( 'It goes out by itself on %s.', 'iminify-image-optimizer' ),
				(string) State::date( gmdate( 'c', $until ) )
			);
		}

		if ( ! in_array( $record['status'], array( Records::NOT_OPTIMIZED, Records::PARKED, Records::SKIPPED, Records::ERROR, Records::QUEUED ), true ) ) {
			return '';
		}

		return is_array( $record['error'] ) && isset( $record['error']['message'] ) ? (string) $record['error']['message'] : '';
	}

	/**
	 * The bytes before and after across the attachment's files.
	 *
	 * @param array<string, mixed> $result The record's result.
	 * @return array{before: int, after: int, files: int}
	 */
	protected static function saved( array $result ): array {
		$saved = array(
			'before' => 0,
			'after'  => 0,
			'files'  => 0,
		);

		foreach ( isset( $result['outputs'] ) && is_array( $result['outputs'] ) ? $result['outputs'] : array() as $output ) {
			$saved['before'] += (int) $output['before'];
			$saved['after']  += (int) $output['after'];
			$saved['files']  += (int) $output['after'] < (int) $output['before'] ? 1 : 0;
		}

		return $saved;
	}

	/**
	 * The formats the plugin has copies in, in words.
	 *
	 * @param array<string, mixed> $record The record.
	 */
	protected static function copies_label( array $record ): string {
		$names = array(
			'webp' => __( 'WebP', 'iminify-image-optimizer' ),
			'avif' => __( 'AVIF', 'iminify-image-optimizer' ),
		);
		$found = array();

		foreach ( Copies::own( $record['copies'] ) as $copy ) {
			$found[ (string) ( $copy['format'] ?? '' ) ] = true;
		}

		return implode( ', ', array_intersect_key( $names, $found ) );
	}

	/**
	 * Each size's bytes before and after, with its copies and the level it was made at.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function sizes_table( int $attachment ): string {
		$result  = Records::get( $attachment )['result'];
		$outputs = null !== $result && isset( $result['outputs'] ) && is_array( $result['outputs'] ) ? $result['outputs'] : array();

		if ( array() === $outputs ) {
			return '';
		}

		$html = '';

		if ( isset( $result['level'], Messages::levels()[ $result['level'] ] ) ) {
			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: the compression level, such as Smart. */
					__( 'Level: %s', 'iminify-image-optimizer' ),
					Messages::levels()[ $result['level'] ]['name']
				)
			) . '</p>';
		}

		$html .= sprintf(
			'<table class="iminify-sizes"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
			esc_html__( 'Size', 'iminify-image-optimizer' ),
			esc_html__( 'Before', 'iminify-image-optimizer' ),
			esc_html__( 'After', 'iminify-image-optimizer' ),
			esc_html__( 'Copies', 'iminify-image-optimizer' )
		);

		foreach ( $outputs as $name => $output ) {
			$html .= sprintf(
				'<tr><th scope="row">%s<br><small>%s</small></th><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( Declaration::MAIN === $name ? __( 'Full size', 'iminify-image-optimizer' ) : (string) $name ),
				esc_html( (int) $output['width'] . ' × ' . (int) $output['height'] ),
				esc_html( (string) size_format( (int) $output['before'], 1 ) ),
				esc_html( (string) size_format( (int) $output['after'], 1 ) ),
				esc_html( strtoupper( implode( ', ', array_map( 'strval', (array) ( $output['formats'] ?? array() ) ) ) ) )
			);
		}

		return $html . '</tbody></table>';
	}

	/**
	 * The panel's buttons, or why one isn't there.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function actions( int $attachment ): string {
		if ( self::is_busy( $attachment ) ) {
			return '';
		}

		$record    = Records::get( $attachment );
		$available = Restorer::availability( $attachment );
		$optimized = Records::OPTIMIZED === $record['status'];
		$html      = '';

		if ( null === Plugin::keys()->get() ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'Connect the site to Iminify to optimize this image.', 'iminify-image-optimizer' ) . '</p>';
		} elseif ( Restorer::NO_ORIGINAL === $available ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'No original of this image was kept, so it can\'t be optimized again or restored.', 'iminify-image-optimizer' ) . '</p>';
		} else {
			$html .= '<p class="iminify-actions-label">' . esc_html( $optimized ? __( 'Optimize again at', 'iminify-image-optimizer' ) : __( 'Optimize at', 'iminify-image-optimizer' ) ) . '</p><p class="iminify-actions">';

			foreach ( Messages::levels() as $level => $about ) {
				if ( 'none' !== $level ) {
					$html .= self::button( $attachment, 'optimize', $about['name'], $optimized ? self::again_warning() : '', $level );
				}
			}

			$html .= '</p>';
		}

		$local = '';

		if ( Restorer::AVAILABLE === $available ) {
			$local .= self::button( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_warning() );
		} elseif ( Restorer::CHANGED === $available ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'This image has changed since Iminify optimized it, so there\'s nothing to restore.', 'iminify-image-optimizer' ) . '</p>';
		}

		if ( array() !== Copies::own( $record['copies'] ) ) {
			$local .= self::button( $attachment, 'copies', __( 'Delete the WebP and AVIF copies', 'iminify-image-optimizer' ), __( 'Delete this image\'s WebP and AVIF copies? Its pages go back to serving the JPEG or PNG.', 'iminify-image-optimizer' ) );
		}

		return '' === $local ? $html : $html . '<p class="iminify-actions">' . $local . '</p>';
	}

	/**
	 * A panel button.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $action     What it asks for.
	 * @param string $label      Its words.
	 * @param string $confirm    What to ask first, if anything.
	 * @param string $level      The level it asks for, if any.
	 */
	protected static function button( int $attachment, string $action, string $label, string $confirm = '', string $level = '' ): string {
		return sprintf(
			'<button type="button" class="button button-small" data-iminify-action="%s" data-iminify-attachment="%d" data-iminify-confirm="%s" data-iminify-level="%s">%s</button> ',
			esc_attr( $action ),
			$attachment,
			esc_attr( $confirm ),
			esc_attr( $level ),
			esc_html( $label )
		);
	}

	/**
	 * A row action, which reloads the list once it's done.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $action     What it asks for.
	 * @param string $label      Its words.
	 * @param string $confirm    What to ask first, if anything.
	 */
	protected static function row_action( int $attachment, string $action, string $label, string $confirm ): string {
		return sprintf(
			'<button type="button" class="button-link" data-iminify-action="%s" data-iminify-attachment="%d" data-iminify-confirm="%s" data-iminify-reload="1">%s</button>',
			esc_attr( $action ),
			$attachment,
			esc_attr( $confirm ),
			esc_html( $label )
		);
	}

	/**
	 * What optimizing again does to an image already optimized, asked first since it counts.
	 */
	protected static function again_warning(): string {
		return __( 'Optimize this image again? Its original goes back first, and this counts as one more image.', 'iminify-image-optimizer' );
	}

	/**
	 * What restoring does, asked first.
	 */
	protected static function restore_warning(): string {
		return __( 'Restore the original of this image? WordPress makes its sizes again, and its WebP and AVIF copies are deleted.', 'iminify-image-optimizer' );
	}
}
