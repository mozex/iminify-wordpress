<?php
/**
 * Puts the end-to-end site in the state a browser test starts from. Run inside
 * the e2e environment: wp eval-file .../tests/e2e/bin/seed.php <command> [<json>]
 *
 * Commands:
 *   reset      forgets the key, the plugin's options, the stub's state and every attachment and post
 *   connect    stores the fixtures' site key
 *   state      sets the stub's state from the JSON given (`approved`, `spent`, `settings`)
 *   calls      prints the calls the stub answered, as JSON
 *   optimized  adds an image the plugin optimized, with WebP copies, a post showing it and one showing it in raw HTML; prints its id and the posts' addresses
 *   paused     marks the image given in the JSON (`{"id": N}`) as waiting for the next batch, with the pause a refusal starts
 *   record     prints the record of the image given in the JSON
 *   audit-images  adds an image the plugin hasn't handled and points the stubbed audit's first three images at its main file, its medium size's WebP copy and its thumbnail; prints its id
 *   fresh      adds images the plugin hasn't handled (`{"count": N}`, 2 by default); prints their ids
 *   folder     makes a folder in wp-content with images the plugin hasn't handled (`{"count": N}`, 2 by default); prints its path there
 *   files      prints the rows of the files in the chosen folders, as JSON
 *   folder-optimized  chooses a folder in wp-content with an image whose WebP copy its row records as the plugin's, and a post showing it in raw HTML; prints the post's address
 *   translated  makes a translation of the image given (`{"id": N}`) as Polylang does, and a post showing it; prints its id, its holder and the post's address
 *   rules-lock  takes the write away from the site's .htaccess, so the settings page can't write the rules; prints the file's path
 *   rules-write  gives the write back and has the plugin sync the rules, as WP-CLI can write the file; prints where they stand and the file's text
 *   leftovers  appends to the site's .htaccess the block EWWW left there, as it wrote it running; prints the file's path
 *   rules-file  prints the site's .htaccess, after Action Scheduler's start (which puts the rules back once the plugin is turned on again)
 *   replace    writes another picture over the main file of the image given (`{"id": N}`) and saves its new size, as Enable Media Replace does; prints the file's bytes
 *   outside    makes a folder in wp-content that isn't chosen, with an image and a WebP copy beside it; prints the image's address
 *   backgrounds  gives the image given (`{"id": N}`) AVIF copies where the server writes AVIF, and adds a post with a background in an inline style and one in a style element; prints the post's address, the image's two addresses and whether AVIF was written
 *
 * @package Iminify\WordPress
 */

use Iminify\WordPress\Delivery\Config_File;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Backups;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Switching\Leftovers;
use Iminify\WordPress\Switching\Others;
use Iminify\WordPress\Switching\Settings_Copy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script's own variables.
$command = isset( $args[0] ) ? (string) $args[0] : '';
$given   = isset( $args[1] ) ? (array) json_decode( (string) $args[1], true ) : array();

switch ( $command ) {
	case 'reset':
		Plugin::keys()->forget();
		Site_Record::forget();
		Pause::lift();
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );
		delete_option( Local_Settings::OPTION );
		delete_option( 'iminify_e2e_state' );
		delete_option( 'iminify_e2e_calls' );
		delete_option( 'iminify_pairing' );
		delete_option( 'iminify_revoked' );
		delete_option( Bulk::OPTION );
		delete_option( Folders::OPTION );
		delete_option( Scanner::OPTION );
		delete_option( Rules::CHECK_OPTION );
		Table::drop();

		if ( is_file( (string) Rules::file() ) ) {
			chmod( (string) Rules::file(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a test that took the write away gives it back.
		}

		Rules::remove();

		/* A test that stopped before its press leaves EWWW's block, which would serve every copy beside an image. */
		if ( is_file( (string) Rules::file() ) ) {
			Config_File::edit(
				(string) Rules::file(),
				static function ( array $lines ): ?array {
					return Leftovers::without( $lines, Leftovers::MARKERS['ewww'] );
				}
			);
		}

		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( 'wp-smushit/wp-smush.php' ) ) ) );
		delete_option( Others::STAMP_OPTION );
		delete_option( Settings_Copy::OPTION );

		foreach ( array( 'ewww_image_optimizer_jpg_level', 'ewww_image_optimizer_maxmediawidth', 'ewww_image_optimizer_metadata_remove', 'ewww_image_optimizer_webp', 'ewww_image_optimizer_disable_resizes_opt', 'ewww_image_optimizer_backup_files' ) as $e2e_option ) {
			delete_option( $e2e_option );
		}

		foreach ( array( 'iminify-e2e-outside/out.jpg', 'iminify-e2e-outside/out.jpg.webp' ) as $e2e_outside ) {
			wp_delete_file( WP_CONTENT_DIR . '/' . $e2e_outside );
		}

		$e2e_folder = WP_CONTENT_DIR . '/iminify-e2e-folder';

		foreach ( is_dir( $e2e_folder ) ? (array) scandir( $e2e_folder ) : array() as $entry ) {
			if ( is_file( $e2e_folder . '/' . $entry ) ) {
				wp_delete_file( $e2e_folder . '/' . $entry );
			}
		}

		foreach ( get_posts(
			array(
				'post_type'   => array( 'attachment', 'post' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $existing ) {
			wp_delete_post( (int) $existing, true );
		}

		echo "reset\n";
		break;

	case 'connect':
		$claim = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/iminify-image-optimizer/tests/fixtures/connect-claim.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a test fixture.
		Plugin::keys()->save( (string) $claim['data']['key'] );
		echo "connected\n";
		break;

	case 'state':
		update_option( 'iminify_e2e_state', array_merge( (array) get_option( 'iminify_e2e_state', array() ), $given ), false );
		Site_Record::forget();
		echo "state\n";
		break;

	case 'smushed':
		/* An image Smush compressed in place and recorded, with Smush itself switched off; with `backup`, its original kept beside it as Smush keeps it. */
		$e2e_file   = (string) get_attached_file( (int) $given['id'] );
		$e2e_before = (int) filesize( $e2e_file );

		if ( ! empty( $given['backup'] ) ) {
			copy( $e2e_file, dirname( $e2e_file ) . '/' . wp_basename( $e2e_file, '.jpg' ) . '.bak.jpg' );
			update_post_meta(
				(int) $given['id'],
				'_wp_attachment_backup_sizes',
				array(
					'smush-full' => array(
						'file'   => wp_basename( $e2e_file, '.jpg' ) . '.bak.jpg',
						'width'  => 1,
						'height' => 1,
					),
				)
			);
		}

		$e2e_editor = wp_get_image_editor( $e2e_file );
		$e2e_editor->set_quality( 20 );
		$e2e_saved = $e2e_editor->save( get_temp_dir() . 'iminify-e2e-smushed.jpg', 'image/jpeg' );
		copy( $e2e_saved['path'], $e2e_file );
		wp_delete_file( $e2e_saved['path'] );
		clearstatcache( true, $e2e_file );
		update_post_meta(
			(int) $given['id'],
			'wp-smpro-smush-data',
			array(
				'stats' => array( 'size_before' => $e2e_before ),
				'sizes' => array(
					'full' => (object) array(
						'size_before' => $e2e_before,
						'size_after'  => (int) filesize( $e2e_file ),
					),
				),
			)
		);
		echo "smushed\n";
		break;

	case 'ewww-settings':
		/* EWWW's settings as it keeps them, one option each, with EWWW itself gone. */
		update_option( 'ewww_image_optimizer_jpg_level', '20' );
		update_option( 'ewww_image_optimizer_maxmediawidth', '1920' );
		update_option( 'ewww_image_optimizer_webp', '1' );
		update_option( 'ewww_image_optimizer_backup_files', '' );
		echo "ewww-settings\n";
		break;

	case 'acting':
		/* Smush listed as active, its automatic compression on by default. */
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array( 'wp-smushit/wp-smush.php' ) ) ) ) );
		echo "acting\n";
		break;

	case 'calls':
		echo wp_json_encode( get_option( 'iminify_e2e_calls', array() ) ) . "\n";
		break;

	case 'record':
		echo wp_json_encode( Records::get( (int) ( $given['id'] ?? 0 ) ) ) . "\n";
		break;

	case 'paused':
		Records::wait( (int) $given['id'], Records::PAUSED, 'daily_limit_reached', 'Waiting for the next batch.' );
		Pause::begin( time() + HOUR_IN_SECONDS, Pause::FROM_REFUSAL, 'free' );
		echo "paused\n";
		break;

	case 'audit-images':
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$canvas = imagecreatetruecolor( 800, 500 );

		for ( $row = 0; $row < 500; $row++ ) {
			imageline( $canvas, 0, $row, 799, $row, (int) imagecolorallocate( $canvas, 60 + (int) ( $row / 4 ), 150, 230 - (int) ( $row / 4 ) ) );
		}

		$source = get_temp_dir() . 'iminify-e2e-audited.jpg';
		imagejpeg( $canvas, $source, 90 );

		$audited = media_handle_sideload(
			array(
				'name'     => 'iminify-e2e-audited.jpg',
				'tmp_name' => $source,
			)
		);

		if ( is_wp_error( $audited ) ) {
			echo 'error: ' . esc_html( $audited->get_error_message() ) . '
';
			break;
		}

		Optimizer::cancel( $audited );

		foreach ( Records::ALL_META as $key ) {
			delete_post_meta( $audited, $key );
		}

		$sizes = wp_get_attachment_metadata( $audited )['sizes'];
		$base  = dirname( (string) wp_get_attachment_url( $audited ) );

		update_option(
			'iminify_e2e_state',
			array_merge(
				(array) get_option( 'iminify_e2e_state', array() ),
				array(
					'audit_images' => array(
						(string) wp_get_attachment_url( $audited ),
						$base . '/' . $sizes['medium']['file'] . '.webp',
						$base . '/' . $sizes['thumbnail']['file'],
						home_url( '/wp-content/themes/twentytwentyfive/assets/logo.png' ),
					),
				)
			),
			false
		);

		echo (int) $audited . '
';
		break;

	case 'fresh':
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$fresh = array();

		for ( $made = 0; $made < (int) ( $given['count'] ?? 2 ); $made++ ) {
			$canvas = imagecreatetruecolor( 800, 500 );
			imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 40 * $made % 255, 140, 200 ) );
			$source = get_temp_dir() . 'iminify-e2e-fresh-' . $made . '.jpg';
			imagejpeg( $canvas, $source, 90 );

			$made_id = media_handle_sideload(
				array(
					'name'     => 'iminify-e2e-fresh-' . $made . '.jpg',
					'tmp_name' => $source,
				)
			);

			if ( is_wp_error( $made_id ) ) {
				echo 'error: ' . esc_html( $made_id->get_error_message() ) . "\n";
				break 2;
			}

			/* The upload queued itself on a connected site; these wait for the bulk run instead. */
			Optimizer::cancel( $made_id );

			foreach ( Records::ALL_META as $key ) {
				delete_post_meta( $made_id, $key );
			}

			$fresh[] = $made_id;
		}

		echo wp_json_encode( $fresh ) . "\n";
		break;

	case 'folder':
		$e2e_folder = WP_CONTENT_DIR . '/iminify-e2e-folder';
		wp_mkdir_p( $e2e_folder );

		for ( $made = 0; $made < (int) ( $given['count'] ?? 2 ); $made++ ) {
			$canvas = imagecreatetruecolor( 640, 400 );
			imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 200, 60 * $made % 255, 90 ) );
			imagejpeg( $canvas, $e2e_folder . '/picture-' . $made . '.jpg', 90 );
		}

		echo 'iminify-e2e-folder
';
		break;

	case 'files':
		echo wp_json_encode( Table::page( null, 1, 100 )['rows'] ) . '
';
		break;

	case 'folder-optimized':
		$e2e_folder = WP_CONTENT_DIR . '/iminify-e2e-folder';
		wp_mkdir_p( $e2e_folder );

		$canvas = imagecreatetruecolor( 640, 400 );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 30, 160, 90 ) );
		imagejpeg( $canvas, $e2e_folder . '/theme.jpg', 90 );
		imagewebp( $canvas, $e2e_folder . '/theme.jpg.webp', 80 );
		clearstatcache();

		Folders::add( 'iminify-e2e-folder' );
		Scanner::start();
		Scanner::finish();

		$e2e_rows = Table::by_paths( array( 'iminify-e2e-folder/theme.jpg' ) );
		Table::update(
			(int) $e2e_rows['iminify-e2e-folder/theme.jpg']['id'],
			array(
				'status' => Records::OPTIMIZED,
				'copies' => array(
					'iminify-e2e-folder/theme.jpg.webp' => array(
						'for'       => 'iminify-e2e-folder/theme.jpg',
						'format'    => 'webp',
						'bytes'     => (int) filesize( $e2e_folder . '/theme.jpg.webp' ),
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( $e2e_folder . '/theme.jpg' ),
					),
				),
			)
		);

		$showing = wp_insert_post(
			array(
				'post_title'   => 'Iminify e2e folder',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:html --><div class="theme-art"><img src="' . esc_url( content_url( 'iminify-e2e-folder/theme.jpg' ) ) . '" alt=""></div><!-- /wp:html -->',
			)
		);

		echo wp_json_encode( array( 'post' => get_permalink( $showing ) ) ) . "\n";
		break;

	case 'optimized':
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		/* A 1,600 by 1,000 photo-like gradient, drawn here so the repository holds no binary fixture. */
		$canvas = imagecreatetruecolor( 1600, 1000 );

		for ( $row = 0; $row < 1000; $row++ ) {
			imageline( $canvas, 0, $row, 1599, $row, (int) imagecolorallocate( $canvas, (int) ( $row / 4 ), 120, 255 - (int) ( $row / 4 ) ) );
		}

		$source = get_temp_dir() . 'iminify-e2e-photo.jpg';
		imagejpeg( $canvas, $source, 90 );

		$file       = array(
			'name'     => 'iminify-e2e-photo.jpg',
			'tmp_name' => $source,
		);
		$attachment = media_handle_sideload( $file );

		if ( is_wp_error( $attachment ) ) {
			echo 'error: ' . esc_html( $attachment->get_error_message() ) . "\n";
			break;
		}

		/* The upload queued itself on a connected site; this image is seeded as already optimized instead. */
		as_unschedule_all_actions( '', array(), Optimizer::GROUP );

		$main     = (string) get_attached_file( $attachment );
		$metadata = wp_get_attachment_metadata( $attachment );
		$files    = array( 'full' => $main );

		foreach ( $metadata['sizes'] as $size => $about ) {
			$files[ $size ] = dirname( $main ) . '/' . $about['file'];
		}

		$copies  = array();
		$outputs = array();

		foreach ( $files as $size => $sized ) {
			$editor = wp_get_image_editor( $sized );

			if ( is_wp_error( $editor ) || is_wp_error( $editor->save( $sized . '.webp', 'image/webp' ) ) ) {
				continue;
			}

			clearstatcache( true, $sized . '.webp' );

			$copies[ (string) Paths::relative( $sized . '.webp' ) ] = array(
				'for'       => (string) Paths::relative( $sized ),
				'format'    => 'webp',
				'bytes'     => (int) filesize( $sized . '.webp' ),
				'origin'    => Records::ORIGIN,
				'for_bytes' => (int) filesize( $sized ),
			);

			$size_of          = (array) getimagesize( $sized );
			$outputs[ $size ] = array(
				'before'  => (int) filesize( $sized ) + 2048,
				'after'   => (int) filesize( $sized ),
				'width'   => (int) ( $size_of[0] ?? 0 ),
				'height'  => (int) ( $size_of[1] ?? 0 ),
				'formats' => array( 'webp' ),
			);
		}

		Records::update(
			$attachment,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( (string) Paths::relative( $main ) => (int) filesize( $main ) ),
				'copies'  => $copies,
				'backups' => (array) Backups::keep( $main, (string) Paths::relative( $main ), array() ),
				'result'  => array(
					'level'   => 'smart',
					'formats' => array( 'webp' ),
					'outputs' => $outputs,
				),
			)
		);

		$url     = (string) wp_get_attachment_image_url( $attachment, 'large' );
		$showing = wp_insert_post(
			array(
				'post_title'   => 'Iminify e2e',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:image {"id":' . $attachment . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="" class="wp-image-' . $attachment . '"/></figure><!-- /wp:image -->',
			)
		);

		/* The same image in raw HTML, as a page builder writes it: no class names its attachment. */
		$raw = wp_insert_post(
			array(
				'post_title'   => 'Iminify e2e raw',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:html --><div class="builder"><img src="' . esc_url( $url ) . '" alt=""></div><!-- /wp:html -->',
			)
		);

		echo wp_json_encode(
			array(
				'id'   => $attachment,
				'post' => get_permalink( $showing ),
				'raw'  => get_permalink( $raw ),
			)
		) . "\n";
		break;

	case 'translated':
		/* As Polylang translates an image: a new attachment given the image's metadata and file as post meta. */
		$e2e_of      = (int) ( $given['id'] ?? 0 );
		$translation = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Iminify e2e translation',
				'post_status'    => 'inherit',
			)
		);

		add_post_meta( $translation, '_wp_attachment_metadata', wp_get_attachment_metadata( $e2e_of, true ) );
		add_post_meta( $translation, '_wp_attached_file', (string) get_post_meta( $e2e_of, '_wp_attached_file', true ) );

		$showing = wp_insert_post(
			array(
				'post_title'   => 'Iminify e2e translated',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:image {"id":' . $translation . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . esc_url( (string) wp_get_attachment_image_url( $translation, 'large' ) ) . '" alt="" class="wp-image-' . $translation . '"/></figure><!-- /wp:image -->',
			)
		);

		echo wp_json_encode(
			array(
				'id'     => $translation,
				'holder' => Records::get( $translation )['holder'],
				'post'   => get_permalink( $showing ),
			)
		) . "\n";
		break;

	case 'rules-lock':
		chmod( (string) Rules::file(), 0444 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a file the web server can't write.
		echo wp_json_encode( array( 'file' => Rules::file() ) ) . "\n";
		break;

	case 'rules-write':
		chmod( (string) Rules::file(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- given back.
		clearstatcache();
		Rules::sync();
		echo wp_json_encode(
			array(
				'where' => Rules::where(),
				'text'  => (string) file_get_contents( (string) Rules::file() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the site's own file.
			)
		) . "\n";
		break;

	case 'leftovers':
		/* EWWW's block as it wrote it running, appended as its insert_with_markers() call does. */
		$e2e_file = (string) Rules::file();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a fixture of the test suite.
		$e2e_ewww = trim( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/others/leftovers/ewww.htaccess' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the site's own file.
		file_put_contents( $e2e_file, rtrim( (string) file_get_contents( $e2e_file ) ) . "\n\n" . $e2e_ewww );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( 'ewww-image-optimizer/ewww-image-optimizer.php' ) ) ) );
		echo wp_json_encode( array( 'file' => $e2e_file ) ) . "\n";
		break;

	case 'rules-file':
		/* Read through core, since the plugin may be off. */
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the site's own file.
		echo wp_json_encode( array( 'text' => (string) file_get_contents( get_home_path() . '.htaccess' ) ) ) . "\n";
		break;

	case 'replace':
		$e2e_of   = (int) ( $given['id'] ?? 0 );
		$e2e_main = (string) get_attached_file( $e2e_of );
		$canvas   = imagecreatetruecolor( 1600, 1000 );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 220, 60, 40 ) );
		imagejpeg( $canvas, $e2e_main, 70 );
		clearstatcache( true, $e2e_main );

		$e2e_metadata             = wp_get_attachment_metadata( $e2e_of );
		$e2e_metadata['filesize'] = (int) filesize( $e2e_main );
		wp_update_attachment_metadata( $e2e_of, $e2e_metadata );

		echo wp_json_encode( array( 'bytes' => (int) filesize( $e2e_main ) ) ) . "\n";
		break;

	case 'outside':
		$e2e_folder = WP_CONTENT_DIR . '/iminify-e2e-outside';
		wp_mkdir_p( $e2e_folder );

		$canvas = imagecreatetruecolor( 320, 200 );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 90, 30, 160 ) );
		imagejpeg( $canvas, $e2e_folder . '/out.jpg', 90 );
		imagewebp( $canvas, $e2e_folder . '/out.jpg.webp', 80 );

		echo wp_json_encode( array( 'url' => content_url( 'iminify-e2e-outside/out.jpg' ) ) ) . "\n";
		break;

	case 'backgrounds':
		/* AVIF copies beside the optimized image's main file and large size, where this server's image editor writes AVIF. */
		$e2e_of     = (int) ( $given['id'] ?? 0 );
		$e2e_record = Records::get( $e2e_of );
		$e2e_main   = (string) get_attached_file( $e2e_of );
		$e2e_large  = dirname( $e2e_main ) . '/' . wp_get_attachment_metadata( $e2e_of )['sizes']['large']['file'];
		$e2e_avif   = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );

		foreach ( $e2e_avif ? array( $e2e_main, $e2e_large ) : array() as $sized ) {
			$editor = wp_get_image_editor( $sized );

			if ( is_wp_error( $editor ) || is_wp_error( $editor->save( $sized . '.avif', 'image/avif' ) ) ) {
				$e2e_avif = false;
				continue;
			}

			clearstatcache( true, $sized . '.avif' );

			$e2e_record['copies'][ (string) Paths::relative( $sized . '.avif' ) ] = array(
				'for'       => (string) Paths::relative( $sized ),
				'format'    => 'avif',
				'bytes'     => (int) filesize( $sized . '.avif' ),
				'origin'    => Records::ORIGIN,
				'for_bytes' => (int) filesize( $sized ),
			);
		}

		Records::update( $e2e_of, array( 'copies' => $e2e_record['copies'] ) );

		/* A builder's hero in an inline style, a gradient over the photo, and a banner a style element sets; written as an editor with unfiltered HTML saves them. */
		kses_remove_filters();
		$showing = wp_insert_post(
			array(
				'post_title'   => 'Iminify e2e backgrounds',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:html --><style>.iminify-banner{min-height:120px;background-image:url(' . esc_url( (string) wp_get_attachment_url( $e2e_of ) ) . ')}</style>'
					. '<div class="iminify-hero" style="min-height:120px;background:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.2)),url(' . esc_url( (string) wp_get_attachment_image_url( $e2e_of, 'large' ) ) . ') center/cover no-repeat"></div>'
					. '<div class="iminify-banner"></div><!-- /wp:html -->',
			)
		);

		echo wp_json_encode(
			array(
				'post'  => get_permalink( $showing ),
				'main'  => (string) wp_get_attachment_url( $e2e_of ),
				'large' => (string) wp_get_attachment_image_url( $e2e_of, 'large' ),
				'avif'  => $e2e_avif,
			)
		) . "\n";
		break;

	default:
		echo 'Unknown command: ' . esc_html( $command ) . "\n";
}
