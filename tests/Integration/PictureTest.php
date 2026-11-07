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
use Iminify\WordPress\Tests\Support\Copies_Fixture;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Copies_Fixture.php';

/**
 * Covers the markup on a post and from a theme's call, the copies it may serve,
 * the widths a missing copy keeps, markup filtered twice or written by hand, a
 * translation's page serving its holder's copies, and where it never runs.
 */
class PictureTest extends WP_UnitTestCase {

	use Copies_Fixture;

	public function set_up(): void {
		parent::set_up();
		Picture::flush();
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
	}

	public function tear_down(): void {
		$this->delete_copies();
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

		$this->assertSame( 1, substr_count( $html, '<picture' ) );
		$this->assertMatchesRegularExpression( '#<picture style="display: contents;"><source type="image/avif" srcset="([^"]+)" sizes="[^"]+"><source type="image/webp" srcset="([^"]+)" sizes="[^"]+"><img [^>]*data-iminify-picture[^>]*></picture>#', $html );

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

	public function test_only_copies_the_record_proves_unchanged_are_served(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ), array( $files['large'], 'webp' ), array( $files['medium_large'], 'webp' ) ) );

		$record = Records::get( $id );
		$copies = $record['copies'];

		$full         = (string) Paths::relative( $files['full'] ) . '.webp';
		$medium       = (string) Paths::relative( $files['medium'] ) . '.webp';
		$large        = (string) Paths::relative( $files['large'] ) . '.webp';
		$medium_large = (string) Paths::relative( $files['medium_large'] ) . '.webp';

		/* A copy whose record names no one as its maker, one that is gone, one written over since, and one whose image was replaced since. */
		unset( $copies[ $full ]['origin'] );
		wp_delete_file( Paths::absolute( $medium ) );
		file_put_contents( Paths::absolute( $large ), 'changed since' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$copies[ $medium_large ]['for_bytes'] = 1;
		Records::update( $id, array( 'copies' => $copies ) );

		$html = $this->render( $id );

		$this->assertStringNotContainsString( '<picture', $html );
		$this->assertStringNotContainsString( '.webp', $html );
	}

	public function test_markup_filtered_twice_or_written_inside_a_picture_is_wrapped_once(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );

		/* A block theme's template runs the content filter over a post the content filter already ran on. */
		$twice = wp_filter_content_tags( $this->render( $id ), 'template' );

		$this->assertSame( 1, substr_count( $twice, '<picture' ) );

		$written = '<picture><source media="(max-width: 600px)" srcset="https://example.org/narrow.jpg"><img class="wp-image-' . $id . '" src="' . wp_get_attachment_url( $id ) . '" alt=""></picture>';
		$html    = apply_filters( 'the_content', $written ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

		$this->assertSame( 1, substr_count( $html, '<picture' ) );
		$this->assertStringContainsString( 'narrow.jpg', $html );
		$this->assertStringNotContainsString( '.webp', $html );

		/* A theme's own call, then the template's content filter over it. */
		$theme = wp_get_attachment_image( $id, 'full' );

		$this->assertStringStartsWith( '<picture style="display: contents;"><source type="image/webp" srcset="', $theme );
		$this->assertSame( 1, substr_count( wp_filter_content_tags( '<div>' . $theme . '</div>', 'template' ), '<picture' ) );
	}

	public function test_nothing_changes_without_copies_with_delivery_off_in_wp_admin_or_in_a_feed(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		$this->assertStringNotContainsString( '<picture', $this->render( $id ), 'No copies yet.' );

		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );
		$this->assertStringContainsString( '<picture', $this->render( $id ) );

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_OFF ) );
		$this->assertStringNotContainsString( '<picture', $this->render( $id ) );
		$this->assertStringNotContainsString( '<picture', wp_get_attachment_image( $id, 'full' ) );

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		set_current_screen( 'upload' );
		$this->assertStringNotContainsString( '<picture', wp_get_attachment_image( $id, 'full' ) );

		set_current_screen( 'front' );
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assertStringNotContainsString( '<picture', wp_get_attachment_image( $id, 'full' ) );
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

	public function test_only_an_address_under_the_uploads_folder_is_matched_to_a_copy(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ) ) );

		$url    = (string) wp_get_attachment_url( $id );
		$medium = (string) wp_get_attachment_image_url( $id, 'medium' );
		$bucket = 'https://bucket.example.com/' . wp_basename( $medium );
		$moved  = str_replace( '/' . wp_basename( $medium ), '/1999/01/' . wp_basename( $medium ), $medium );
		$comma  = 'https://images.example.com/c_scale,w_768/' . wp_basename( $url );
		$tag    = '<img src="' . $url . '" srcset="' . $bucket . ' 300w, ' . $moved . ' 301w, ' . $comma . ' 768w, ' . $url . ' 1920w" sizes="100vw" class="wp-image-' . $id . '" alt="">';
		$html   = apply_filters( 'wp_content_img_tag', $tag, 'the_content', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

		preg_match( '#type="image/webp" srcset="([^"]+)"#', $html, $webp );

		$this->assertSame( $bucket . ' 300w, ' . $moved . ' 301w, ' . $comma . ' 768w, ' . $url . '.webp 1920w', $webp[1] );
	}

	public function test_an_address_wp_offload_media_serves_takes_a_copy_only_once_it_went_to_the_bucket(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ) ) );

		/* The main file's copy went to the bucket with the bytes it has; the medium size's never did. */
		$record = Records::get( $id );
		$full   = (string) Paths::relative( $files['full'] ) . '.webp';

		$record['copies'][ $full ]['offloaded'] = $record['copies'][ $full ]['bytes'];
		Records::update( $id, array( 'copies' => $record['copies'] ) );

		$uploads = (string) wp_get_upload_dir()['baseurl'];
		$bucket  = static function ( $url ) use ( $uploads ) {
			return str_replace( $uploads, 'https://bucket.example.com/wp-content/uploads', (string) $url );
		};

		$local  = (string) wp_get_attachment_image_url( $id, 'medium' );
		$url    = $bucket( wp_get_attachment_url( $id ) );
		$medium = $bucket( $local );
		$signed = $url . '?X-Amz-Signature=abc';
		$moved  = str_replace( '/' . wp_basename( $url ), '/1999/' . wp_basename( $url ), $url );
		$tag    = '<img src="' . $url . '" srcset="' . $medium . ' 300w, ' . $local . ' 301w, ' . $url . ' 1920w, ' . $signed . ' 1921w, ' . $moved . ' 1922w" sizes="100vw" class="wp-image-' . $id . '" alt="">';

		add_filter( 'wp_get_attachment_url', $bucket );
		Picture::flush();

		try {
			/* Without WP Offload Media running, an attachment's address elsewhere is any other plugin's, and keeps its own, while one under the uploads folder takes its copy as ever. */
			preg_match( '#type="image/webp" srcset="([^"]+)"#', (string) apply_filters( 'wp_content_img_tag', $tag, 'the_content', $id ), $without ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

			$GLOBALS['as3cf'] = new \stdClass(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP Offload Media's own global.
			Picture::flush();

			$html = (string) apply_filters( 'wp_content_img_tag', $tag, 'the_content', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.
		} finally {
			remove_filter( 'wp_get_attachment_url', $bucket );
			unset( $GLOBALS['as3cf'] );
		}

		preg_match( '#type="image/webp" srcset="([^"]+)"#', $html, $webp );

		$this->assertSame( $medium . ' 300w, ' . $local . '.webp 301w, ' . $url . ' 1920w, ' . $signed . ' 1921w, ' . $moved . ' 1922w', $without[1] ?? null );
		/* With it, the medium size's copy, never in the bucket, is served from neither address, since WP Offload Media rewrites a local one later. */
		$this->assertSame( $medium . ' 300w, ' . $local . ' 301w, ' . $url . '.webp 1920w, ' . $signed . ' 1921w, ' . $moved . ' 1922w', $webp[1] ?? null );
	}

	public function test_a_translation_serves_its_holders_copies_one_step_away_while_both_name_its_file(): void {
		$holder = $this->attachment();
		$files  = $this->files_of( $holder );
		$this->copies( $holder, array( array( $files['full'], 'webp' ), array( $files['large'], 'webp' ) ) );

		/* Made once the holder's main file has its copy, so the translation is pointed at it as it is made. */
		$translation = $this->translation( $holder );
		$large       = wp_basename( $files['large'] ) . '.webp';

		$this->assertSame( $holder, Records::get( $translation )['holder'] );
		$this->assertStringContainsString( $large, $this->render( $translation ) );
		$this->assertStringContainsString( '<source type="image/webp"', (string) wp_get_attachment_image( $translation, 'large' ) );

		/* One step only: pointed at the translation, whose own pointer names the holder, another serves nothing. */
		$second = $this->translation( $holder );
		Records::update( $second, array( 'holder' => $translation ) );

		$this->assertStringNotContainsString( '<picture', $this->render( $second ) );

		/* A pointer kept after the attachment came to name another file serves nothing, even for markup naming the holder's files. */
		Records::update( $second, array( 'holder' => $holder ) );
		$this->assertStringContainsString( $large, $this->render( $second ), 'The fixture: pointed at the holder, it serves.' );

		update_post_meta( $second, '_wp_attached_file', 'elsewhere/photo.jpg' );
		Picture::flush();
		$markup = (string) apply_filters( 'the_content', '<img src="' . wp_get_attachment_image_url( $holder, 'large' ) . '" alt="" class="wp-image-' . $second . '">' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

		$this->assertStringNotContainsString( '<picture', $markup );
	}

	public function test_a_pages_translations_and_their_holders_are_read_in_one_go(): void {
		$pairs = array();

		for ( $made = 0; $made < 3; $made++ ) {
			$holder = $this->attachment();
			$files  = $this->files_of( $holder );
			$this->copies( $holder, array( array( $files['full'], 'webp' ), array( $files['large'], 'webp' ) ) );
			$pairs[] = array( $holder, $this->translation( $holder ) );
		}

		$read = static function ( array $pairs ): array {
			$content = '';

			foreach ( $pairs as $pair ) {
				$content .= '<p><img src="' . wp_get_attachment_image_url( $pair[0], 'large' ) . '" alt="" class="wp-image-' . $pair[1] . '"></p>';
				clean_post_cache( $pair[0] );
				clean_post_cache( $pair[1] );
			}

			Picture::flush();
			$queries = $GLOBALS['wpdb']->num_queries;
			$html    = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, run as a page runs it.

			return array( $GLOBALS['wpdb']->num_queries - $queries, substr_count( $html, '<picture' ) );
		};

		/* Once first, so what any first page reads (options, the theme's settings) is behind it. */
		$read( array_slice( $pairs, 0, 1 ) );

		$one   = $read( array_slice( $pairs, 0, 1 ) );
		$three = $read( $pairs );

		$this->assertSame( array( 1, 3 ), array( $one[1], $three[1] ) );
		$this->assertSame( $one[0], $three[0], 'The translations and their holders are read in one go, whatever their number.' );
	}

	public function test_a_long_unclosed_picture_never_blanks_the_markup(): void {
		$id      = $this->attachment();
		$content = '<p>Before</p><picture><source srcset="https://example.org/a.webp"><img class="wp-image-' . $id . '" src="https://example.org/a.jpg" alt=""><script>' . str_repeat( 'x', 200000 ) . '</script><p>After</p>';
		$limit   = ini_get( 'pcre.backtrack_limit' );

		ini_set( 'pcre.backtrack_limit', '1000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the limit a long page reaches in production, made small.
		$marked = Picture::mark_pictures( $content );
		ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- puts it back.

		$this->assertIsString( $marked );
		$this->assertStringContainsString( '<p>After</p>', $marked );
		$this->assertStringContainsString( '<img data-iminify-picture class="wp-image-' . $id . '"', $marked );
		$this->assertSame( strlen( $content ) + strlen( ' data-iminify-picture' ), strlen( $marked ) );
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
}
