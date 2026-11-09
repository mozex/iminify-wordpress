<?php
/**
 * The plugin's place in the media library.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Admin;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Compat\Offload_Media;
use Iminify\WordPress\Media\Alt_Text;
use Iminify\WordPress\Media\Copies;
use Iminify\WordPress\Media\Declaration;
use Iminify\WordPress\Media\Missing;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Restorer;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Others;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A column in the list view, a filter in the list view and the grid, row actions,
 * and a panel in the attachment details, which core shows in the grid's modal and
 * on the edit screen. The markup is built here and only reads what the plugin keeps,
 * so no screen calls Iminify. `src/media` only sends the buttons' presses to
 * Media_Controller and puts back the markup it answers.
 */
class Media_View {

	const COLUMN = 'iminify';

	const QUERY = 'iminify_status';

	const HANDLE = 'iminify-media';

	const GRID_HANDLE = 'iminify-media-grid';

	const FILTER_OPTIMIZED = 'optimized';

	const FILTER_NOT_OPTIMIZED = 'not_optimized';

	const FILTER_ERRORS = 'errors';

	const FILTER_MISSING = 'missing_formats';

	const FILTER_OTHER = 'other';

	const IMAGE_TYPES = Declaration::MIME_TYPES;

	/**
	 * The names of the formats a main file can be in, by MIME type, for the compare.
	 */
	const FORMAT_NAMES = array(
		'image/jpeg' => 'JPEG',
		'image/png'  => 'PNG',
		'image/webp' => 'WebP',
		'image/gif'  => 'GIF',
	);

	/**
	 * The names of the copies the plugin makes, by extension, for the compare.
	 */
	const COPY_NAMES = array(
		'webp' => 'WebP',
		'avif' => 'AVIF',
	);

	/**
	 * Hooks the view into WordPress's admin.
	 */
	public static function register(): void {
		add_filter( 'manage_media_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'print_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'print_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_filter' ) );
		add_filter( 'ajax_query_attachments_args', array( self::class, 'apply_grid_filter' ) );
		add_filter( 'media_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_on_screen' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue' ) );
		add_action( 'load-upload.php', array( Others::class, 'look' ), 10, 0 );
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
		return self::wrap( $attachment, 'panel', self::summary( $attachment ) . self::credentials_detail( $attachment ) . self::compare_button( $attachment ) . self::sizes_table( $attachment ) . self::actions( $attachment ) );
	}

	/**
	 * What the panel's compare shows: the original the plugin made the image from
	 * beside the main file while it still has the bytes the plugin wrote, and each
	 * WebP or AVIF copy of it the plugin made while the copy and the main file both
	 * have the bytes recorded for them (a copy of an older picture compares
	 * nothing), each
	 * with its address on the site, its dimensions and its bytes, and every word
	 * the script puts on screen. Only for an optimized image whose original
	 * (`Originals::find()`, never a file the plugin wrote) is on the disk under the
	 * uploads folder: the untouched original WordPress kept beside a `-scaled`
	 * copy, the plugin's backup, an original another optimizer kept, or the main
	 * file itself where only copies were made of it. Null otherwise.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{title: string, close: string, slider: string, formats: string, failed: string, note: string, before: array{label: string, url: string, width: int, height: int, bytes: int}, after: list<array{label: string, url: string, width: int, height: int, bytes: int}>}|null
	 */
	public static function compare( int $attachment ): ?array {
		$record = Records::get( $attachment );

		if ( ! self::comparable( $attachment, $record ) ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment );
		$source   = Originals::find( $attachment, is_array( $metadata ) ? $metadata : array(), null );
		$attached = (string) get_attached_file( $attachment, true );
		$main     = '' === $attached ? null : Paths::relative( $attached );

		if ( null === $source || null === $main || ! is_file( $attached ) ) {
			return null;
		}

		$before = self::compared( $source['path'], __( 'Original', 'iminify-image-optimizer' ), null );

		if ( null === $before ) {
			return null;
		}

		/* A main file the plugin didn't write (one the service's output didn't beat, or another tool wrote since) is no result of its. */
		$after = array( Records::wrote( $attachment, $attached ) ? self::compared( $attached, self::format_name( $attached ), $before ) : null );

		foreach ( Copies::own( $record['copies'] ) as $key => $copy ) {
			$format = strtolower( (string) pathinfo( (string) $key, PATHINFO_EXTENSION ) );
			$file   = Paths::absolute( (string) $key );

			if ( $main . '.' . $format === (string) $key && isset( self::COPY_NAMES[ $format ] ) && is_file( $file ) && (int) filesize( $file ) === (int) ( $copy['bytes'] ?? -1 ) && (int) filesize( $attached ) === (int) ( $copy['for_bytes'] ?? -1 ) ) {
				$after[] = self::compared( $file, self::COPY_NAMES[ $format ], $before );
			}
		}

		$after = array_values( array_filter( $after ) );

		if ( array() === $after ) {
			return null;
		}

		$level = Messages::levels()[ (string) ( $record['result']['level'] ?? '' ) ]['label'] ?? '';

		return array(
			'title'   => __( 'Compare with the original', 'iminify-image-optimizer' ),
			'close'   => __( 'Close', 'iminify-image-optimizer' ),
			'slider'  => __( 'How much of the original shows', 'iminify-image-optimizer' ),
			'formats' => __( 'The optimized file to compare', 'iminify-image-optimizer' ),
			'failed'  => __( 'This image couldn\'t be loaded from the site.', 'iminify-image-optimizer' ),
			'note'    => '' === $level
				? __( 'Drag the line, or use the arrow keys, to see more of the original on one side and of the optimized file on the other.', 'iminify-image-optimizer' )
				: sprintf(
					/* translators: %s: the compression level with what it does, such as "Smart: a balance between quality and size". */
					__( 'Optimized at %s. Drag the line, or use the arrow keys, to see more of the original on one side and of the optimized file on the other.', 'iminify-image-optimizer' ),
					$level
				),
			'before'  => $before,
			'after'   => $after,
		);
	}

	/**
	 * One side of the compare: a file's address under the uploads folder, its
	 * label with its size, its dimensions and its bytes; null for a file outside
	 * the uploads folder or one that isn't an image.
	 *
	 * @param string                    $path   The file.
	 * @param string                    $name   What it is.
	 * @param array{bytes: int}|null    $before The original it is compared with, for the saving.
	 * @return array{label: string, url: string, width: int, height: int, bytes: int}|null
	 */
	protected static function compared( string $path, string $name, ?array $before ): ?array {
		$relative = Paths::relative( $path );
		$size     = wp_getimagesize( $path );

		if ( null === $relative || ! is_array( $size ) ) {
			return null;
		}

		$bytes = (int) filesize( $path );
		$label = sprintf(
			/* translators: 1: what the file is, such as Original or WebP, 2: its size, such as 1.2 MB. */
			__( '%1$s, %2$s', 'iminify-image-optimizer' ),
			$name,
			(string) size_format( $bytes, 1 )
		);

		if ( null !== $before && $bytes < $before['bytes'] ) {
			$label = sprintf(
				/* translators: 1: what the file is and its size, such as "WebP, 340 KB", 2: how much smaller it is than the original, such as 72%. */
				__( '%1$s, %2$s smaller', 'iminify-image-optimizer' ),
				$label,
				(int) round( 100 * ( $before['bytes'] - $bytes ) / max( 1, $before['bytes'] ) ) . '%'
			);
		}

		return array(
			'label'  => $label,
			/* The file's own time keeps a browser from showing a copy it kept of the same address from before the image was optimized again. */
			'url'    => add_query_arg( 'v', (string) filemtime( $path ), wp_get_upload_dir()['baseurl'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) ) ),
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
			'bytes'  => $bytes,
		);
	}

	/**
	 * A main file's format in words, read from its bytes.
	 *
	 * @param string $path The file.
	 */
	protected static function format_name( string $path ): string {
		return self::FORMAT_NAMES[ (string) wp_get_image_mime( $path ) ] ?? __( 'Optimized', 'iminify-image-optimizer' );
	}

	/**
	 * Whether an optimized image's record says the plugin wrote over a file of it
	 * or made a copy of one.
	 *
	 * @param array<string, mixed> $record The record.
	 */
	protected static function made_any( array $record ): bool {
		return Records::OPTIMIZED === $record['status'] && is_array( $record['result'] ) && ( self::saved( $record['result'] )['files'] > 0 || array() !== Copies::own( $record['copies'] ) );
	}

	/**
	 * Whether the record and the metadata say there is something to compare: the
	 * main file on the disk with the plugin's own file or a copy it made of it,
	 * and, where the plugin wrote over the main file, an original kept beside it
	 * (its backup, WordPress's original of a `-scaled` copy, or a converted
	 * image's kept JPEG). Read without opening a file, since the panel draws for
	 * every image a media library page lists; the route reads the files
	 * (`compare()`), so a backup deleted by hand is said there.
	 *
	 * @param int                  $attachment The attachment's id.
	 * @param array<string, mixed> $record     Its record.
	 */
	protected static function comparable( int $attachment, array $record ): bool {
		$attached = (string) get_attached_file( $attachment, true );
		$main     = '' === $attached ? null : Paths::relative( $attached );

		if ( ! self::made_any( $record ) || null === $main || Offload_Media::only_in_bucket( $attachment, $attached ) ) {
			return false;
		}

		$copies = Copies::own( $record['copies'] );

		if ( ! isset( $record['written'][ $main ] ) ) {
			return isset( $copies[ $main . '.webp' ] ) || isset( $copies[ $main . '.avif' ] );
		}

		$metadata = wp_get_attachment_metadata( $attachment );

		return in_array( Paths::relative( Paths::backup( $main ) ), $record['backups'], true ) || ! empty( $metadata['original_image'] ) || null !== $record['converted'];
	}

	/**
	 * The press that opens the compare, for an image that has one.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function compare_button( int $attachment ): string {
		if ( ! self::comparable( $attachment, Records::get( $attachment ) ) ) {
			return '';
		}

		return sprintf(
			'<p class="iminify-actions"><button type="button" class="iminify-btn iminify-btn--secondary iminify-btn--small" data-iminify-compare="%1$d">%2$s</button></p>',
			$attachment,
			esc_html__( 'Compare with the original', 'iminify-image-optimizer' )
		);
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
	 * Whether the script keeps asking after an attachment: it is on its way, or its AI alt text is.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function is_awaited( int $attachment ): bool {
		return self::is_busy( $attachment ) || in_array( Alt_Text::status( Records::get( $attachment )['alt'] ), Alt_Text::ON_ITS_WAY, true );
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

		if ( ! self::handles( $attachment ) || ! Capabilities::can_change( $attachment ) ) {
			return $actions;
		}

		/* An image another plugin optimized is left as it is, unless someone optimizes it again or restores it from the original that plugin kept; one it converted is restored only there. */
		if ( null !== self::other( $attachment ) ) {
			if ( ! self::is_busy( $attachment ) ) {
				if ( Plugin::connected() && Optimizer::redoable( $attachment ) ) {
					$actions['iminify_optimize'] = self::row_action( $attachment, 'optimize', __( 'Optimize again from the original', 'iminify-image-optimizer' ), self::redo_warning( $attachment ) );
				}

				if ( Restorer::AVAILABLE === Restorer::availability( $attachment ) ) {
					$actions['iminify_restore'] = self::row_action( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_theirs_warning( $attachment ) );
				}
			}

			return $actions;
		}

		/* An image another plugin took away is that plugin's until it brings it back. */
		if ( null !== Others::away( $attachment ) ) {
			return $actions;
		}

		$status    = Records::get( $attachment )['status'];
		$optimized = Records::OPTIMIZED === $status;
		$available = Restorer::availability( $attachment );

		/* An image waiting for the batch goes out by itself, and pressing this could only say so. */
		if ( Plugin::connected() && ! self::is_busy( $attachment ) && Records::PAUSED !== $status && Restorer::NO_ORIGINAL !== $available ) {
			$actions['iminify_optimize'] = self::row_action(
				$attachment,
				'optimize',
				$optimized ? __( 'Optimize again', 'iminify-image-optimizer' ) : __( 'Optimize', 'iminify-image-optimizer' ),
				$optimized ? self::again_warning( $attachment ) : ''
			);
		}

		if ( Restorer::AVAILABLE === $available && ! self::is_busy( $attachment ) ) {
			$actions['iminify_restore'] = self::row_action( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_warning() );
		}

		foreach ( self::fills( $attachment ) as $what => $label ) {
			$actions[ 'iminify_missing_' . $what ] = self::row_action( $attachment, 'missing', $label, self::fill_warning(), $what );
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

		if ( self::handles( $attachment ) && Capabilities::can_change( $attachment ) ) {
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
	 * Narrows the grid's request to the filter's choice. Core drops every query
	 * property it doesn't know before this filter, so the choice is read from the
	 * request itself.
	 *
	 * @param array<string, mixed> $query The grid's query.
	 * @return array<string, mixed>
	 */
	public static function apply_grid_filter( array $query ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core's own request for the grid, which it checks; only a known choice narrows the list.
		$asked = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query'][ self::QUERY ] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['query'][ self::QUERY ] ) ) : '';

		return isset( self::filters()[ $asked ] ) ? array_merge( $query, self::query_for( $asked ) ) : $query;
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
			case self::FILTER_OTHER:
				return array( 'meta_query' => array( self::status_clause( array( Records::OTHER ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta key.
			case self::FILTER_NOT_OPTIMIZED:
				return array(
					'post_mime_type' => self::IMAGE_TYPES,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the filter a person picked, on the plugin's own meta key.
						'relation' => 'OR',
						array(
							'key'     => Records::STATUS_META,
							'compare' => 'NOT EXISTS',
						),
						self::status_clause( array( Records::NOT_OPTIMIZED, Records::RESTORED, Records::PAUSED, Records::PARKED, Records::QUEUED, Records::WAITING ) ),
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

		/* Core has queued the grid's script by now, on that view of the library alone. */
		if ( null !== $screen && 'upload' === $screen->id && wp_script_is( 'media-grid', 'enqueued' ) ) {
			Settings_Page::enqueue_entry( self::GRID_HANDLE, 'media-grid', array( 'media-views' ) );
		}
	}

	/**
	 * Loads the script and its styles, here and wherever the media modal can open.
	 */
	public static function enqueue(): void {
		if ( Settings_Page::enqueue_entry( self::HANDLE, 'media' ) && is_readable( IMINIFY_DIR . '/assets/build/media.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/media.css', IMINIFY_FILE ), array(), IMINIFY_VERSION );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			Scheme::attach( self::HANDLE );
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
			self::FILTER_OTHER         => __( 'Optimized by another plugin', 'iminify-image-optimizer' ),
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
	 * as when the site turned AVIF on later, the images a run for missing formats takes
	 * (`Missing::images()`). A copy the service made and left out for coming out heavier
	 * than its size isn't missing: nothing more can be made.
	 *
	 * @return array<string, mixed>
	 */
	protected static function missing_formats_query(): array {
		$formats = Site_Record::formats();

		if ( array() === $formats ) {
			return array( 'post__in' => array( 0 ) );
		}

		$missing = array(
			'relation' => 'OR',
			array(
				'key'     => Records::FORMATS_META,
				'compare' => 'NOT EXISTS',
			),
		);

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
			self::is_awaited( $attachment ) ? ' data-iminify-busy="1"' : '',
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
		$other  = self::other( $attachment );

		if ( null !== $other ) {
			return self::other_summary( $other ) . self::alt_detail( $record ) . self::taken_detail( $record );
		}

		$away = Others::away( $attachment );

		if ( null !== $away ) {
			return sprintf(
				'<p class="iminify-status iminify-status-other"><span class="iminify-chip iminify-chip--neutral"><span class="iminify-chip__dot" aria-hidden="true"></span>%1$s</span></p><p class="iminify-detail">%2$s</p>',
				esc_html(
					sprintf(
						/* translators: %s: another image optimizer's name, such as Optimole. */
						__( 'Offloaded by %s', 'iminify-image-optimizer' ),
						$away->name()
					)
				),
				esc_html( $away->away_message() )
			);
		}

		$html   = sprintf(
			'<p class="iminify-status iminify-status-%1$s"><span class="iminify-chip iminify-chip--%2$s%3$s"><span class="iminify-chip__dot" aria-hidden="true"></span>%4$s</span></p>',
			esc_attr( $record['status'] ?? 'none' ),
			esc_attr( self::status_tone( $record['status'] ) ),
			self::is_busy( $attachment ) ? ' iminify-chip--moving' : '',
			esc_html( self::status_label( $record['status'] ) )
		);
		$detail = self::status_detail( $record );

		if ( '' !== $detail ) {
			$html .= '<p class="iminify-detail">' . esc_html( $detail ) . '</p>';
		}

		if ( Records::OPTIMIZED !== $record['status'] || null === $record['result'] ) {
			return $html . self::alt_detail( $record ) . self::taken_detail( $record );
		}

		$saved = self::saved( $record['result'] );

		if ( $saved['before'] > $saved['after'] ) {
			$percent = (int) round( 100 * ( $saved['before'] - $saved['after'] ) / $saved['before'] );
			$html   .= sprintf(
				'<div class="iminify-saving"><span class="iminify-saving__bar" aria-hidden="true"><i style="width: %1$d%%"></i></span><p class="iminify-detail">%2$s</p></div>',
				max( 2, min( 100, $percent ) ),
				esc_html(
					sprintf(
						/* translators: 1: the percentage saved, such as 34%, 2: the bytes saved, such as 1.2 MB, 3: how many files it covers. */
						_n( 'Saved %1$s (%2$s) across %3$s file.', 'Saved %1$s (%2$s) across %3$s files.', $saved['files'], 'iminify-image-optimizer' ),
						$percent . '%',
						(string) size_format( $saved['before'] - $saved['after'], 1 ),
						number_format_i18n( $saved['files'] )
					)
				)
			);
		} else {
			$html .= '<p class="iminify-detail">' . esc_html__( 'The originals were already smaller, so they were kept.', 'iminify-image-optimizer' ) . '</p>';
		}

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

		$html .= self::alt_detail( $record ) . self::taken_detail( $record );

		$lacks = Missing::of( $attachment, Missing::FORMATS );

		if ( array() !== $lacks ) {
			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: the formats missing, such as "AVIF". */
					__( 'Missing formats: %s', 'iminify-image-optimizer' ),
					strtoupper( implode( ', ', $lacks ) )
				)
			) . '</p>';
		}

		$lacks = Missing::of( $attachment, Missing::SIZES );

		if ( array() !== $lacks ) {
			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: the sizes missing, by their names. */
					__( 'Missing sizes: %s', 'iminify-image-optimizer' ),
					implode( ', ', $lacks )
				)
			) . '</p>';
		}

		if ( ! empty( $record['result']['kept'] ) ) {
			$kept = (int) $record['result']['kept'];

			$html .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: %s: how many copies. */
					_n( '%s WebP or AVIF copy another tool made was kept as it is.', '%s WebP or AVIF copies another tool made were kept as they are.', $kept, 'iminify-image-optimizer' ),
					number_format_i18n( $kept )
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
	 * Another plugin's optimization of an attachment: the plugin's record of it, or,
	 * for an image the plugin hasn't looked at yet, what the readers find now.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>}|null
	 */
	protected static function other( int $attachment ): ?array {
		$record = Records::get( $attachment );

		if ( Records::OTHER === $record['status'] ) {
			return $record['other'];
		}

		$unseen = null === $record['status'] || ( Records::NOT_OPTIMIZED === $record['status'] && Records::RESTORED !== ( $record['error']['code'] ?? null ) );

		return $unseen ? Others::verdict( $attachment ) : null;
	}

	/**
	 * Where an image another plugin optimized stands: whose it is, what that plugin
	 * saved as it recorded it, and whether its original is kept.
	 *
	 * @param array{plugin: string, before: int, after: int, files: int, original: bool, outputs: array<string, int>} $other The plugin's optimization.
	 */
	protected static function other_summary( array $other ): string {
		$html = sprintf(
			'<p class="iminify-status iminify-status-other"><span class="iminify-chip iminify-chip--success"><span class="iminify-chip__dot" aria-hidden="true"></span>%s</span></p>',
			esc_html( Others::label( $other['plugin'] ) )
		);

		if ( $other['before'] > $other['after'] && $other['after'] > 0 ) {
			$percent = (int) round( 100 * ( $other['before'] - $other['after'] ) / $other['before'] );
			$html   .= '<p class="iminify-detail">' . esc_html(
				sprintf(
					/* translators: 1: another image optimizer's name, 2: the percentage saved, such as 34%, 3: the bytes saved, such as 1.2 MB. */
					__( '%1$s saved %2$s (%3$s), as it recorded it.', 'iminify-image-optimizer' ),
					Others::name( $other['plugin'] ),
					$percent . '%',
					(string) size_format( $other['before'] - $other['after'], 1 )
				)
			) . '</p>';
		}

		$html .= '<p class="iminify-detail">' . esc_html(
			$other['original']
				? __( 'Its original is kept. Iminify leaves the image as it is, and it costs nothing.', 'iminify-image-optimizer' )
				: __( 'No original of it was kept, so Iminify leaves it as it is rather than compress it a second time.', 'iminify-image-optimizer' )
		) . '</p>';

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
	 * The colour of a status's chip.
	 *
	 * @param string|null $status The record's status.
	 */
	protected static function status_tone( ?string $status ): string {
		switch ( $status ) {
			case Records::OPTIMIZED:
				return 'success';
			case Records::QUEUED:
			case Records::WAITING:
				return 'accent';
			case Records::PAUSED:
				return 'info';
			case Records::PARKED:
				return 'warning';
			case Records::ERROR:
				return 'danger';
			default:
				return 'neutral';
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

		if ( ! in_array( $record['status'], array( Records::NOT_OPTIMIZED, Records::PARKED, Records::SKIPPED, Records::ERROR, Records::QUEUED, Records::OPTIMIZED ), true ) ) {
			return '';
		}

		/* An optimized image has a reason only when what it lacked couldn't be made. */
		return (string) Others::reason( $record['error'] );
	}

	/**
	 * The line saying where an image's AI alt text stands, empty when none was asked for.
	 *
	 * @param array<string, mixed> $record The attachment's record.
	 */
	protected static function alt_detail( array $record ): string {
		$labels = array(
			Alt_Text::QUEUED  => __( 'Alt text: being written.', 'iminify-image-optimizer' ),
			Alt_Text::WAITING => __( 'Alt text: being written.', 'iminify-image-optimizer' ),
			Alt_Text::WRITTEN => __( 'Alt text: written by Iminify.', 'iminify-image-optimizer' ),
			Alt_Text::KEPT    => __( 'Alt text: yours was kept.', 'iminify-image-optimizer' ),
			Alt_Text::FAILED  => __( 'Alt text: Iminify couldn\'t write one for this image.', 'iminify-image-optimizer' ),
			Alt_Text::SKIPPED => __( 'Alt text: not written, as the day\'s AI alt texts were used up.', 'iminify-image-optimizer' ),
		);
		$status = Alt_Text::status( $record['alt'] );

		return null === $status || ! isset( $labels[ $status ] ) ? '' : '<p class="iminify-detail iminify-alt-state">' . esc_html( $labels[ $status ] ) . '</p>';
	}

	/**
	 * What became of the Content Credentials an optimized image carried, the signed
	 * record of where it came from: a file the plugin wrote over and a copy it made
	 * leave them out, since any new file breaks their signature, while a file it
	 * left and the backup keep them. In the panel only: the column is too narrow.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function credentials_detail( int $attachment ): string {
		$record = Records::get( $attachment );
		$read   = $record['result']['credentials'] ?? null;

		if ( Records::OPTIMIZED !== $record['status'] || ! is_array( $read ) ) {
			return '';
		}

		$wrote = self::made_any( $record );

		if ( ! $wrote ) {
			$text = __( 'This image carries Content Credentials, a signed record of where it came from, and its files keep them.', 'iminify-image-optimizer' );
		} elseif ( array() !== $record['backups'] ) {
			$text = __( 'This image carried Content Credentials, a signed record of where it came from. The files and copies Iminify wrote leave them out, since a new file breaks their signature, and the original kept as a backup still has them.', 'iminify-image-optimizer' );
		} else {
			$text = __( 'This image carried Content Credentials, a signed record of where it came from. The files and copies Iminify wrote leave them out, since a new file breaks their signature.', 'iminify-image-optimizer' );
		}

		if ( ! empty( $read['generative_ai'] ) ) {
			$text .= ' ' . __( 'They say generative AI made it, wholly or in part.', 'iminify-image-optimizer' );
		}

		return '<p class="iminify-detail iminify-credentials">' . esc_html( $text ) . '</p>';
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
	 * Which copies another plugin made the image's pages are served, in a
	 * sentence, or empty when it has none the plugin took over.
	 *
	 * @param array<string, mixed> $record The record.
	 */
	protected static function taken_detail( array $record ): string {
		$names   = array(
			'webp' => __( 'WebP', 'iminify-image-optimizer' ),
			'avif' => __( 'AVIF', 'iminify-image-optimizer' ),
		);
		$formats = array();
		$plugins = array();

		foreach ( Copies::taken( $record['copies'] ) as $copy ) {
			$formats[ (string) ( $copy['format'] ?? '' ) ]       = true;
			$plugins[ Others::name( (string) $copy['origin'] ) ] = true;
		}

		if ( array() === $plugins ) {
			return '';
		}

		return '<p class="iminify-detail">' . esc_html(
			sprintf(
				/* translators: 1: the formats, such as "WebP, AVIF", 2: the image optimizers that made them, such as "EWWW Image Optimizer". */
				__( 'Its pages are served the %1$s copies %2$s made, until Iminify makes its own.', 'iminify-image-optimizer' ),
				implode( ', ', array_intersect_key( $names, $formats ) ),
				implode( ', ', array_keys( $plugins ) )
			)
		) . '</p>';
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
				'<tr><th scope="row">%s<br><small dir="ltr">%s</small></th><td>%s</td><td>%s</td><td>%s</td></tr>',
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
		return self::file_actions( $attachment ) . self::alt_action( $attachment );
	}

	/**
	 * The press that asks for an AI alt text, for an image with none.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function alt_action( int $attachment ): string {
		if ( ! Plugin::connected() || ! Alt_Text::on() || ! Alt_Text::can_ask( $attachment ) ) {
			return '';
		}

		return '<p class="iminify-actions">' . self::button( $attachment, 'alt-text', __( 'Write alt text with AI', 'iminify-image-optimizer' ), __( 'Send a small copy of this image to write its alt text? It counts as one of your AI alt texts for the day.', 'iminify-image-optimizer' ), '', __( 'Write alt text', 'iminify-image-optimizer' ) ) . '</p>';
	}

	/**
	 * The presses that work on an attachment's files.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function file_actions( int $attachment ): string {
		if ( self::is_busy( $attachment ) ) {
			return '';
		}

		/* Another plugin's image is optimized again or restored from the original it kept; one it converted is restored only there, which the panel says. */
		if ( null !== self::other( $attachment ) ) {
			$available = Restorer::availability( $attachment );
			$html      = '';

			if ( Plugin::connected() && Optimizer::redoable( $attachment ) ) {
				$html .= '<p class="iminify-actions-label">' . esc_html__( 'Optimize again from the original at', 'iminify-image-optimizer' ) . '</p><p class="iminify-actions iminify-levels">';

				foreach ( Messages::levels() as $level => $about ) {
					if ( 'none' !== $level ) {
						$html .= self::button( $attachment, 'optimize', $about['name'], self::redo_warning( $attachment ), $level, __( 'Optimize again', 'iminify-image-optimizer' ) );
					}
				}

				$html .= '</p>';
			}

			if ( Restorer::AVAILABLE === $available ) {
				return $html . '<p class="iminify-actions">' . self::button( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_theirs_warning( $attachment ) ) . '</p>';
			}

			return Restorer::THEIRS === $available ? $html . '<p class="iminify-detail">' . esc_html( Others::elsewhere( $attachment ) ) . '</p>' : $html;
		}

		if ( null !== Others::away( $attachment ) ) {
			return '';
		}

		$record    = Records::get( $attachment );
		$available = Restorer::availability( $attachment );
		$optimized = Records::OPTIMIZED === $record['status'];
		$html      = '';

		if ( ! Plugin::connected() ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'Connect the site to Iminify to optimize this image.', 'iminify-image-optimizer' ) . '</p>';
		} elseif ( Restorer::NO_ORIGINAL === $available ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'No original of this image was kept, so it can\'t be optimized again or restored.', 'iminify-image-optimizer' ) . '</p>';
		} else {
			$html .= '<p class="iminify-actions-label">' . esc_html( $optimized ? __( 'Optimize again at', 'iminify-image-optimizer' ) : __( 'Optimize at', 'iminify-image-optimizer' ) ) . '</p><p class="iminify-actions iminify-levels">';

			foreach ( Messages::levels() as $level => $about ) {
				if ( 'none' !== $level ) {
					$html .= self::button( $attachment, 'optimize', $about['name'], $optimized ? self::again_warning( $attachment ) : '', $level, $optimized ? __( 'Optimize again', 'iminify-image-optimizer' ) : '' );
				}
			}

			$html .= '</p>';

			$fills = '';

			foreach ( self::fills( $attachment ) as $what => $label ) {
				$fills .= self::button( $attachment, 'missing', $label, self::fill_warning(), '', '', false, $what );
			}

			if ( '' !== $fills ) {
				$html .= '<p class="iminify-actions-label">' . esc_html__( 'Make what it lacks', 'iminify-image-optimizer' ) . '</p><p class="iminify-actions">' . $fills . '</p>';
			}
		}

		$local = '';

		if ( Restorer::AVAILABLE === $available ) {
			$local .= self::button( $attachment, 'restore', __( 'Restore original', 'iminify-image-optimizer' ), self::restore_warning() );
		} elseif ( Restorer::CHANGED === $available ) {
			$html .= '<p class="iminify-detail">' . esc_html__( 'This image has changed since Iminify optimized it, so there\'s nothing to restore.', 'iminify-image-optimizer' ) . '</p>';
		} elseif ( Restorer::THEIRS === $available ) {
			/* An image another plugin converted, optimized again by Iminify: its original stays that plugin's to put back. */
			$html .= '<p class="iminify-detail">' . esc_html( Others::elsewhere( $attachment ) ) . '</p>';
		}

		if ( array() !== Copies::own( $record['copies'] ) ) {
			$local .= self::button( $attachment, 'copies', __( 'Delete the WebP and AVIF copies', 'iminify-image-optimizer' ), __( 'Delete this image\'s WebP and AVIF copies? Its pages go back to serving the JPEG or PNG.', 'iminify-image-optimizer' ), '', __( 'Delete the copies', 'iminify-image-optimizer' ), true );
		}

		return '' === $local ? $html : $html . '<p class="iminify-actions">' . $local . '</p>';
	}

	/**
	 * A panel button, in the design system's look.
	 *
	 * @param int    $attachment The attachment's id.
	 * @param string $action     What it asks for.
	 * @param string $label      Its words.
	 * @param string $confirm    What to ask first, if anything.
	 * @param string $level      The level it asks for, if any.
	 * @param string $yes        The words of the button that goes ahead once asked, its own words when empty.
	 * @param bool   $danger     Whether it deletes something.
	 * @param string $what       What is missing, for the button that makes it.
	 */
	protected static function button( int $attachment, string $action, string $label, string $confirm = '', string $level = '', string $yes = '', bool $danger = false, string $what = '' ): string {
		return sprintf(
			'<button type="button" class="iminify-btn iminify-btn--small %1$s" data-iminify-action="%2$s" data-iminify-attachment="%3$d" data-iminify-confirm="%4$s" data-iminify-yes="%5$s" data-iminify-level="%6$s" data-iminify-what="%7$s">%8$s</button> ',
			$danger ? 'iminify-btn--ghost iminify-btn--danger' : 'iminify-btn--secondary',
			esc_attr( $action ),
			$attachment,
			esc_attr( $confirm ),
			esc_attr( '' === $yes ? $label : $yes ),
			esc_attr( $level ),
			esc_attr( $what ),
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
	 * @param string $what       What is missing, for the action that makes it.
	 */
	protected static function row_action( int $attachment, string $action, string $label, string $confirm, string $what = '' ): string {
		return sprintf(
			'<button type="button" class="button-link" data-iminify-action="%1$s" data-iminify-attachment="%2$d" data-iminify-confirm="%3$s" data-iminify-yes="%4$s" data-iminify-what="%5$s" data-iminify-reload="1">%6$s</button>',
			esc_attr( $action ),
			$attachment,
			esc_attr( $confirm ),
			esc_attr( $label ),
			esc_attr( $what ),
			esc_html( $label )
		);
	}

	/**
	 * What can be made for an attachment now, as the words of the press that makes it.
	 *
	 * @param int $attachment The attachment's id.
	 * @return array<string, string>
	 */
	protected static function fills( int $attachment ): array {
		if ( ! Plugin::connected() ) {
			return array();
		}

		$fills = array();

		if ( array() !== Missing::of( $attachment, Missing::FORMATS ) ) {
			$fills[ Missing::FORMATS ] = __( 'Make the missing formats', 'iminify-image-optimizer' );
		}

		if ( array() !== Missing::of( $attachment, Missing::SIZES ) ) {
			$fills[ Missing::SIZES ] = __( 'Make the missing sizes', 'iminify-image-optimizer' );
		}

		/* Reads the image's files, so only once something is lacking. */
		return array() === $fills || ! Missing::can_fill( $attachment ) ? array() : $fills;
	}

	/**
	 * What making what's missing does, asked first since it counts.
	 */
	protected static function fill_warning(): string {
		return __( 'Send this image again to make what it lacks? This counts as one more image, and its other files stay as they are unless Iminify makes them smaller.', 'iminify-image-optimizer' );
	}

	/**
	 * What optimizing again does to an image already optimized, asked first since it counts.
	 */
	protected static function again_warning( int $attachment ): string {
		if ( null !== Originals::converted( $attachment ) ) {
			return __( 'Optimize this image again? Iminify makes its files again from its original, keeping their format and names, and this counts as one more image.', 'iminify-image-optimizer' );
		}

		return __( 'Optimize this image again? Its original goes back first, and this counts as one more image.', 'iminify-image-optimizer' );
	}

	/**
	 * What restoring does, asked first.
	 */
	protected static function restore_warning(): string {
		return __( 'Restore the original of this image? WordPress makes its sizes again, and its WebP and AVIF copies are deleted.', 'iminify-image-optimizer' );
	}

	/**
	 * What optimizing an image another plugin optimized again does, asked first since it counts.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function redo_warning( int $attachment ): string {
		$other = self::other( $attachment );

		/* One it converted keeps its format and names: nothing goes back. */
		if ( null !== Originals::converted( $attachment ) ) {
			return sprintf(
				/* translators: %s: another image optimizer's name, such as Image Optimizer by Elementor. */
				__( 'Optimize this image again from the original %s kept? Iminify makes its files again from it, keeping their format and names so your posts still link to them, and keeps its own backup of the original. This counts as one more image.', 'iminify-image-optimizer' ),
				Others::name( null === $other ? '' : $other['plugin'] )
			);
		}

		return sprintf(
			/* translators: %s: another image optimizer's name, such as Imagify. */
			__( 'Optimize this image again from the original %s kept? The original goes back first and WordPress makes its sizes again, then Iminify optimizes it and keeps its own backup of the original. This counts as one more image.', 'iminify-image-optimizer' ),
			Others::name( null === $other ? '' : $other['plugin'] )
		);
	}

	/**
	 * What restoring an image another plugin optimized does, asked first.
	 *
	 * @param int $attachment The attachment's id.
	 */
	protected static function restore_theirs_warning( int $attachment ): string {
		$other = self::other( $attachment );

		return sprintf(
			/* translators: %s: another image optimizer's name, such as Imagify. */
			__( 'Restore the original of this image from what %s kept? WordPress makes its sizes again, and that plugin\'s backups and copies stay where they are.', 'iminify-image-optimizer' ),
			Others::name( null === $other ? '' : $other['plugin'] )
		);
	}
}
