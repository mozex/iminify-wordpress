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
 *   optimized  adds an image the plugin optimized, with WebP copies, and a post showing it; prints their ids and the post's address
 *   paused     marks the image given in the JSON (`{"id": N}`) as waiting for the next batch, with the pause a refusal starts
 *   record     prints the record of the image given in the JSON
 *   fresh      adds images the plugin hasn't handled (`{"count": N}`, 2 by default); prints their ids
 *   folder     makes a folder in wp-content with images the plugin hasn't handled (`{"count": N}`, 2 by default); prints its path there
 *   files      prints the rows of the files in the chosen folders, as JSON
 *
 * @package Iminify\WordPress
 */

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
		Table::drop();

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

		echo wp_json_encode(
			array(
				'id'   => $attachment,
				'post' => get_permalink( $showing ),
			)
		) . "\n";
		break;

	default:
		echo 'Unknown command: ' . esc_html( $command ) . "\n";
}
