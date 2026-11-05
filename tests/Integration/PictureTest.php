<?php
/**
 * Serving the WebP and AVIF copies through `<picture>` markup.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use WP_UnitTestCase;

/**
 * Covers the markup on a post and from a theme's call, the copies it may serve,
 * the widths a missing copy keeps, markup filtered twice or written by hand, and
 * where it never runs.
 */
class PictureTest extends WP_UnitTestCase {

	/**
	 * The copy files the test wrote.
	 *
	 * @var list<string>
	 */
	protected $files = array();

	public function set_up(): void {
		parent::set_up();
		Picture::flush();
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
	}

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_a_posts_image_gets_a_source_per_format_with_every_width_of_its_img(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		/* AVIF for the main file only, WebP for the main file and the medium size: the other widths keep their own files. */
		$this->copies(
			$id,
			array(
				array( $files['full'], 'avif' ),
				array( $files['full'], 'webp' ),
				array( $files['medium'], 'webp' ),
			)
		);

		$html = $this->render( $id );

		$this->assertSame( 1, substr_count( $html, '<picture>' ) );
		$this->assertMatchesRegularExpression( '#<picture><source type="image/avif" srcset="([^"]+)" sizes="[^"]+"><source type="image/webp" srcset="([^"]+)" sizes="[^"]+"><img [^>]*data-iminify-picture[^>]*></picture>#', $html );

		preg_match( '#<img [^>]*srcset="([^"]+)"#', $html, $image );
		preg_match( '#type="image/avif" srcset="([^"]+)"#', $html, $avif );
		preg_match( '#type="image/webp" srcset="([^"]+)"#', $html, $webp );

		$widths = static function ( string $srcset ): array {
			preg_match_all( '#\s(\d+w)#', $srcset, $found );

			return $found[1];
		};

		$full   = wp_basename( $files['full'] );
		$medium = wp_basename( $files['medium'] );
		$small  = wp_basename( $files['medium_large'] );

		$this->assertSame( $widths( $image[1] ), $widths( $avif[1] ) );
		$this->assertSame( $widths( $image[1] ), $widths( $webp[1] ) );
		$this->assertStringContainsString( '/' . $full . '.avif ', $avif[1] );
		$this->assertStringContainsString( '/' . $medium . '.webp ', $avif[1], 'A width with no AVIF copy takes its WebP copy.' );
		$this->assertStringContainsString( '/' . $full . '.webp ', $webp[1] );
		$this->assertStringContainsString( '/' . $medium . '.webp ', $webp[1] );
		$this->assertStringNotContainsString( '.avif', $webp[1] );
		$this->assertStringContainsString( 'loading=', $html );
		$this->assertStringContainsString( 'width=', $html );
		$this->assertStringNotContainsString( '/' . $small . '.', $avif[1] . $webp[1], 'A width with no copy keeps its own file.' );
		$this->assertStringContainsString( '/' . $small . ' 768w', $webp[1] );
	}

	public function test_only_copies_the_record_names_as_its_own_and_unchanged_are_served(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ), array( $files['large'], 'webp' ), array( $files['medium_large'], 'webp' ) ) );

		$record = Records::get( $id );
		$copies = $record['copies'];

		$full         = (string) Paths::relative( $files['full'] ) . '.webp';
		$medium       = (string) Paths::relative( $files['medium'] ) . '.webp';
		$large        = (string) Paths::relative( $files['large'] ) . '.webp';
		$medium_large = (string) Paths::relative( $files['medium_large'] ) . '.webp';

		/* Another plugin's copy, one that is gone, one written over since, and one whose image was replaced since. */
		$copies[ $full ]['origin'] = 'other-plugin';
		wp_delete_file( Paths::absolute( $medium ) );
		file_put_contents( Paths::absolute( $large ), 'changed since' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$copies[ $medium_large ]['for_bytes'] = 1;
		Records::update( $id, array( 'copies' => $copies ) );

		$html = $this->render( $id );

		$this->assertStringNotContainsString( '<picture>', $html );
		$this->assertStringNotContainsString( '.webp', $html );
	}

	public function test_markup_filtered_twice_or_written_inside_a_picture_is_wrapped_once(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );

		/* A block theme's template runs the content filter over a post the content filter already ran on. */
		$twice = wp_filter_content_tags( $this->render( $id ), 'template' );

		$this->assertSame( 1, substr_count( $twice, '<picture>' ) );

		$written = '<picture><source media="(max-width: 600px)" srcset="https://example.org/narrow.jpg"><img class="wp-image-' . $id . '" src="' . wp_get_attachment_url( $id ) . '" alt=""></picture>';
		$html    = apply_filters( 'the_content', $written ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

		$this->assertSame( 1, substr_count( $html, '<picture>' ) );
		$this->assertStringContainsString( 'narrow.jpg', $html );
		$this->assertStringNotContainsString( '.webp', $html );

		/* A theme's own call, then the template's content filter over it. */
		$theme = wp_get_attachment_image( $id, 'full' );

		$this->assertStringStartsWith( '<picture><source type="image/webp" srcset="', $theme );
		$this->assertSame( 1, substr_count( wp_filter_content_tags( '<div>' . $theme . '</div>', 'template' ), '<picture>' ) );
	}

	public function test_nothing_changes_without_copies_with_delivery_off_in_wp_admin_or_in_a_feed(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		$this->assertStringNotContainsString( '<picture>', $this->render( $id ), 'No copies yet.' );

		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );
		$this->assertStringContainsString( '<picture>', $this->render( $id ) );

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_OFF ) );
		$this->assertStringNotContainsString( '<picture>', $this->render( $id ) );
		$this->assertStringNotContainsString( '<picture>', wp_get_attachment_image( $id, 'full' ) );

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		set_current_screen( 'upload' );
		$this->assertStringNotContainsString( '<picture>', wp_get_attachment_image( $id, 'full' ) );

		set_current_screen( 'front' );
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assertStringNotContainsString( '<picture>', wp_get_attachment_image( $id, 'full' ) );
	}

	public function test_an_address_keeps_its_query_and_a_copy_takes_the_name_the_writer_gave_it(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );

		$url  = (string) wp_get_attachment_url( $id );
		$html = apply_filters( 'wp_content_img_tag', '<img src="' . $url . '?ver=2" class="wp-image-' . $id . '" alt="">', 'the_content', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

		$this->assertStringContainsString( 'srcset="' . $url . '.webp?ver=2"', $html );
		$this->assertStringNotContainsString( 'sizes=', $html, 'No srcset, so no sizes.' );
		$this->assertSame( $html, apply_filters( 'wp_content_img_tag', $html, 'the_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.
	}

	/**
	 * A post holding the attachment's image block, as its page prints it.
	 *
	 * @param int $id The attachment's id.
	 */
	protected function render( int $id ): string {
		Picture::flush();

		$url = (string) wp_get_attachment_image_url( $id, 'large' );

		return (string) apply_filters( 'the_content', '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . $url . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.
	}

	/**
	 * A 1,920 by 1,080 JPEG with its sizes, uploaded to the media library.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/33772.jpg' );
	}

	/**
	 * Each of the attachment's files by its size's name, `full` for the main one.
	 *
	 * @param int $id The attachment's id.
	 * @return array<string, string>
	 */
	protected function files_of( int $id ): array {
		$main     = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$files    = array( 'full' => $main );

		foreach ( $metadata['sizes'] as $size => $about ) {
			$files[ $size ] = dirname( $main ) . '/' . $about['file'];
		}

		return $files;
	}

	/**
	 * Writes copies beside files and records them as the plugin's own, as the writer does.
	 *
	 * @param int                               $id     The attachment's id.
	 * @param list<array{0: string, 1: string}> $copies Each file and the format of its copy.
	 */
	protected function copies( int $id, array $copies ): void {
		$record = Records::get( $id );

		foreach ( $copies as $copy ) {
			list( $file, $format ) = $copy;

			$path = $file . '.' . $format;
			file_put_contents( $path, $format . ' bytes of ' . wp_basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
			$this->files[] = $path;
			clearstatcache( true, $path );

			$record['copies'][ (string) Paths::relative( $path ) ] = array(
				'for'       => (string) Paths::relative( $file ),
				'format'    => $format,
				'bytes'     => (int) filesize( $path ),
				'origin'    => Records::ORIGIN,
				'for_bytes' => (int) filesize( $file ),
			);
		}

		Records::update( $id, array( 'copies' => $record['copies'] ) );
	}
}
