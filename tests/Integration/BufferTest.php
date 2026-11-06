<?php
/**
 * Picture tags for every image on a page, from the page as it is sent.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Delivery\Buffer;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Tests\Support\Copies_Fixture;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Copies_Fixture.php';

/**
 * Covers the images the buffer finds by their address, all of a page's in one
 * lookup, the class, a translation sharing the file, the files in other folders,
 * the backgrounds in `style` attributes and `<style>` elements, what it leaves
 * (an image already wrapped, an author's `<picture>`, a copy it can't prove, an
 * address elsewhere), and when it reads a page at all, through core's template
 * buffer.
 */
class BufferTest extends WP_UnitTestCase {

	use Copies_Fixture;

	const FOLDER = 'iminify-buffer-test';

	public function set_up(): void {
		parent::set_up();
		Picture::flush();
		Shared::forget();
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PAGE ) );
		remove_all_filters( 'wp_template_enhancement_output_buffer' );
		delete_option( Folders::OPTION );
		/* The test library makes the table temporary, and its rows outlive a test's rollback on the test site's database. */
		Table::drop();
		$this->remove( WP_CONTENT_DIR . '/' . self::FOLDER );
	}

	public function tear_down(): void {
		$this->delete_copies();
		$this->remove( WP_CONTENT_DIR . '/' . self::FOLDER );
		remove_all_filters( 'wp_template_enhancement_output_buffer' );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_images_named_only_by_their_address_get_their_copies_from_one_lookup_and_the_rest_of_the_page_stays(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$other = $this->attachment();

		$this->copies( $id, array( array( $files['full'], 'avif' ), array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ) ) );

		$full   = (string) wp_get_attachment_url( $id );
		$medium = (string) wp_get_attachment_image_url( $id, 'medium' );
		$path   = (string) wp_parse_url( $medium, PHP_URL_PATH );
		$page   = '<!DOCTYPE html><html><head><title>A &amp; B</title></head><body>'
			. '<div class="builder"><IMG SRC="' . $medium . '" alt="a &gt; b" data-x=\'1\'></div>'
			. '<p><img src="' . $full . '" srcset="' . $medium . ' 300w, ' . $full . ' 1920w" sizes="100vw"></p>'
			. '<img src="' . $path . '">'
			. '<img src="' . wp_get_attachment_url( $other ) . '">'
			. '<img src="https://elsewhere.example/' . wp_basename( $full ) . '">'
			. '<script>var html = "<img src=\'' . $full . '\'>";</script>'
			. '</body></html>';

		/* Another image with copies, so the count below holds for any number of them, and nothing read yet, as on a fresh page. */
		$third = $this->attachment();
		$this->copies( $third, array( array( $this->files_of( $third )['full'], 'webp' ) ) );
		$page = str_replace( '</body>', '<img src="' . wp_get_attachment_url( $third ) . '"></body>', $page );

		foreach ( array( $id, $other, $third ) as $attachment ) {
			clean_post_cache( $attachment );
		}

		$queries = $GLOBALS['wpdb']->num_queries;
		$html    = Buffer::rewrite( $page );

		$this->assertSame( 3, $GLOBALS['wpdb']->num_queries - $queries, 'One lookup of the files, one read of the attachments and one of their records, whatever the number of images.' );
		$this->assertSame( 4, substr_count( $html, '<picture style="display: contents;">' ) );
		$this->assertStringContainsString( '<picture style="display: contents;"><source type="image/webp" srcset="' . $medium . '.webp"><IMG data-iminify-picture SRC="' . $medium . '" alt="a &gt; b" data-x=\'1\'></picture>', $html );
		$this->assertStringContainsString( '<source type="image/avif" srcset="' . $medium . '.webp 300w, ' . $full . '.avif 1920w" sizes="100vw"><source type="image/webp" srcset="' . $medium . '.webp 300w, ' . $full . '.webp 1920w" sizes="100vw">', $html );
		$this->assertStringContainsString( '<source type="image/webp" srcset="' . $path . '.webp"><img data-iminify-picture src="' . $path . '"></picture>', $html, 'An address without its host is this site\'s.' );
		$this->assertStringContainsString( '<img src="' . wp_get_attachment_url( $other ) . '">', $html, 'An image with no copies stays.' );
		$this->assertStringContainsString( '<img src="https://elsewhere.example/' . wp_basename( $full ) . '">', $html, 'An address elsewhere keeps its own.' );
		$this->assertStringContainsString( '<script>var html = "<img src=\'' . $full . '\'>";</script>', $html, 'A script\'s text isn\'t markup.' );

		/* Everything but the wrapping is the page as it came. */
		$unwrapped = preg_replace( array( '#<picture style="display: contents;">(?:<source [^>]+>)+#', '#</picture>#', '# data-iminify-picture#' ), '', $html );
		$this->assertSame( $page, $unwrapped );

		/* Read again, as a cache's own buffer or a second pass would, nothing is wrapped twice. */
		$this->assertSame( $html, Buffer::rewrite( $html ) );
	}

	public function test_backgrounds_in_a_style_attribute_and_a_style_element_take_their_copies_from_the_images_lookup(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$other = $this->attachment();
		$third = $this->attachment();

		$this->copies( $id, array( array( $files['full'], 'avif' ), array( $files['full'], 'webp' ), array( $files['large'], 'webp' ) ) );
		$this->copies( $third, array( array( $this->files_of( $third )['full'], 'webp' ) ) );

		$full  = (string) wp_get_attachment_url( $id );
		$large = (string) wp_get_attachment_image_url( $id, 'large' );
		$page  = '<!DOCTYPE html><html><head><style id="hero-css">.hero{background:linear-gradient(#0008,#0008),url(' . $full . ') center/cover}' . "\n" . '.card{color:red}</style><style>.plain{color:blue}</style></head><body>'
			. '<section class="builder" style="min-height:40vh;background-image:url(&quot;' . $large . '&quot;)" data-x=\'1\'></section>'
			. '<div style="background-image:url(' . wp_get_attachment_url( $other ) . ')"></div>'
			. '<div style="--hero:url(' . $full . ')"></div>'
			. '<div style="background:url(' . wp_get_attachment_url( $third ) . ') repeat-x"></div>'
			. '<img src="' . $large . '">'
			. '<script>var css = "background:url(' . $full . ')";</script>'
			. '</body></html>';

		foreach ( array( $id, $other, $third ) as $attachment ) {
			clean_post_cache( $attachment );
		}

		$queries = $GLOBALS['wpdb']->num_queries;
		$html    = Buffer::rewrite( $page );

		$this->assertSame( 3, $GLOBALS['wpdb']->num_queries - $queries, 'The backgrounds\' addresses go into the images\' one lookup and their reads.' );

		$hero = '; background:linear-gradient(#0008,#0008),image-set(url("' . $full . '.avif") type("image/avif"), url("' . $full . '.webp") type("image/webp"), url(' . $full . ') type("image/jpeg")) center/cover';
		$card = '; background-image:image-set(url(&quot;' . $large . '.webp&quot;) type(&quot;image/webp&quot;), url(&quot;' . $large . '&quot;) type(&quot;image/jpeg&quot;))';
		$band = '; background:image-set(url(&quot;' . wp_get_attachment_url( $third ) . '.webp&quot;) type(&quot;image/webp&quot;), url(' . wp_get_attachment_url( $third ) . ') type(&quot;image/jpeg&quot;)) repeat-x';

		$this->assertStringContainsString( '.hero{background:linear-gradient(#0008,#0008),url(' . $full . ') center/cover' . $hero . '}', $html );
		$this->assertStringContainsString( '<section class="builder" style="min-height:40vh;background-image:url(&quot;' . $large . '&quot;)' . $card . '" data-x=\'1\'>', $html );
		$this->assertStringContainsString( ') repeat-x' . $band . '"', $html );
		$this->assertSame( 1, substr_count( $html, '<picture' ), 'The image is wrapped as before.' );

		/* Everything but what it adds is the page as it came: no copies, a custom property and a script's text all stay. */
		$this->assertSame( $page, str_replace( array( $hero, $card, $band ), '', preg_replace( array( '#<picture style="display: contents;">(?:<source [^>]+>)+#', '#</picture>#', '# data-iminify-picture#' ), '', $html ) ) );

		/* Read again, nothing is added twice. */
		$this->assertSame( $html, Buffer::rewrite( $html ) );
	}

	public function test_an_image_wrapped_inside_an_authors_picture_or_without_a_copy_it_can_prove_stays_as_it_was(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ), array( $files['large'], 'webp' ) ) );

		$full   = (string) wp_get_attachment_url( $id );
		$medium = (string) wp_get_attachment_image_url( $id, 'medium' );
		$large  = (string) wp_get_attachment_image_url( $id, 'large' );

		/* The medium size's copy was written over since, the large size was replaced since. */
		file_put_contents( $files['medium'] . '.webp', 'changed since' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$record = Records::get( $id );
		$record['copies'][ (string) Paths::relative( $files['large'] ) . '.webp' ]['for_bytes'] = 1;
		Records::update( $id, array( 'copies' => $record['copies'] ) );
		Picture::flush();

		$page = '<body>'
			. Picture::wrap( '<img src="' . $full . '">', $id )
			. '<picture><source media="(max-width: 600px)" srcset="https://example.org/narrow.jpg"><img src="' . $full . '"></picture>'
			. '<img src="' . $medium . '"><img src="' . $large . '">'
			. '</body>';

		$this->assertSame( 1, substr_count( $page, '<source type="image/webp"' ), 'The fixture: core\'s filter wrapped the first image.' );
		$this->assertSame( $page, Buffer::rewrite( $page ) );
	}

	public function test_an_address_finds_the_attachment_with_copies_among_those_sharing_its_file_and_a_class_left_from_another_image(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );

		/* A translation, made after the image it translates, names the same file; the translation holds the copies here. */
		$translation = self::factory()->attachment->create(
			array(
				'file'           => (string) get_post_meta( $id, '_wp_attached_file', true ),
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $translation, '_wp_attached_file', (string) get_post_meta( $id, '_wp_attached_file', true ) );
		wp_update_attachment_metadata( $translation, wp_get_attachment_metadata( $id ) );
		$this->copies( $translation, array( array( $files['full'], 'webp' ) ) );

		/* A class left from another image whose own copies are served; none of them is this image's. */
		$full  = (string) wp_get_attachment_url( $id );
		$other = $this->attachment();
		$this->copies( $other, array( array( $this->files_of( $other )['full'], 'webp' ) ) );
		$html = Buffer::rewrite( '<img src="' . $full . '"><img class="alignleft wp-image-' . $other . '" src="' . $full . '">' );

		$this->assertSame( 2, substr_count( $html, '<source type="image/webp" srcset="' . $full . '.webp">' ) );
		$this->assertStringNotContainsString( wp_basename( (string) wp_get_attachment_url( $other ) ), $html );
	}

	public function test_an_image_in_a_chosen_folder_takes_the_copies_its_row_proves(): void {
		$this->put( 'photo.jpg' );
		$this->put( 'second.jpg' );
		$this->put( 'sub/third.jpg' );
		Folders::add( self::FOLDER );

		$photo  = self::FOLDER . '/photo.jpg';
		$second = self::FOLDER . '/second.jpg';
		Table::insert(
			array(
				$photo  => (int) filesize( WP_CONTENT_DIR . '/' . $photo ),
				$second => (int) filesize( WP_CONTENT_DIR . '/' . $second ),
			),
			1
		);
		$rows = Table::by_paths( array( $photo, $second ) );

		$this->row_copy( (int) $rows[ $photo ]['id'], $photo, 'avif' );
		$base = content_url( self::FOLDER );
		$page = '<img src="' . $base . '/photo.jpg"><img src="' . $base . '/second.jpg"><img src="' . $base . '/sub/third.jpg">';
		$html = Buffer::rewrite( $page );

		$this->assertSame( '<picture style="display: contents;"><source type="image/avif" srcset="' . $base . '/photo.jpg.avif"><img data-iminify-picture src="' . $base . '/photo.jpg"></picture><img src="' . $base . '/second.jpg"><img src="' . $base . '/sub/third.jpg">', $html, 'A file with no row, or no copies, stays.' );
		$this->assertSame(
			'<div style="background:url(' . $base . '/photo.jpg) center; background:image-set(url(&quot;' . $base . '/photo.jpg.avif&quot;) type(&quot;image/avif&quot;), url(' . $base . '/photo.jpg) type(&quot;image/jpeg&quot;)) center"></div><div style="background:url(' . $base . '/second.jpg)"></div>',
			Buffer::rewrite( '<div style="background:url(' . $base . '/photo.jpg) center"></div><div style="background:url(' . $base . '/second.jpg)"></div>' ),
			'A background takes the copies the row proves.'
		);

		/* A copy beside a file replaced since, one written over since, and one a row names in another folder. */
		$cases = array(
			'replaced'  => array( 'for_bytes' => 1 ),
			'written'   => array( 'bytes' => 1 ),
			'elsewhere' => array( 'copy' => self::FOLDER . '/sub/second.jpg.webp' ),
		);

		foreach ( $cases as $case => $off ) {
			$this->row_copy( (int) $rows[ $second ]['id'], $second, 'webp', $off );
			$this->assertStringNotContainsString( 'second.jpg.webp', Buffer::rewrite( $page ), $case );
		}

		$this->row_copy( (int) $rows[ $second ]['id'], $second, 'webp' );
		$this->assertStringContainsString( '<source type="image/webp" srcset="' . $base . '/second.jpg.webp">', Buffer::rewrite( $page ), 'The same copy, proven.' );

		/* Off the list, the folder's files are no longer the plugin's to serve, and their rows aren't even read. */
		Folders::remove( self::FOLDER );
		$queries = $GLOBALS['wpdb']->num_queries;
		$this->assertStringNotContainsString( '<picture', Buffer::rewrite( '<img src="' . $base . '/photo.jpg">' ) );
		$this->assertSame( 0, $GLOBALS['wpdb']->num_queries - $queries );
	}

	public function test_core_hands_over_an_html_page_alone_and_only_in_the_whole_page_mode_where_picture_tags_are_served(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );

		$page = '<html><body><img src="' . wp_get_attachment_url( $id ) . '"></body></html>';
		$type = (string) ini_get( 'default_mimetype' );

		Buffer::start();
		$this->assertSame( Buffer::PRIORITY, has_filter( 'wp_template_enhancement_output_buffer', array( Buffer::class, 'rewrite' ) ) );

		try {
			ini_set( 'default_mimetype', 'application/json' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- what core reads when no header was sent, as under the command line.
			$json = wp_finalize_template_enhancement_output_buffer( $page, PHP_OUTPUT_HANDLER_FINAL );
			ini_set( 'default_mimetype', 'text/html' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- an HTML answer.
			$html = wp_finalize_template_enhancement_output_buffer( $page, PHP_OUTPUT_HANDLER_FINAL );
		} finally {
			ini_set( 'default_mimetype', $type ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- puts it back.
		}

		$this->assertSame( $page, $json );
		$this->assertStringContainsString( '<picture', $html );

		foreach (
			array(
				'picture tags alone'  => static function (): void {
					Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
				},
				'nothing changed'     => static function (): void {
					Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_OFF ) );
				},
				'a wp-admin screen'   => static function (): void {
					set_current_screen( 'upload' );
				},
				'a developer said no' => static function (): void {
					add_filter( 'iminify_page_buffer', '__return_false' );
				},
			) as $case => $set
		) {
			remove_all_filters( 'wp_template_enhancement_output_buffer' );
			$set();
			Buffer::start();
			$this->assertFalse( has_filter( 'wp_template_enhancement_output_buffer' ), $case );

			Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PAGE ) );
			set_current_screen( 'front' );
			remove_filter( 'iminify_page_buffer', '__return_false' );
		}

		/* Picture tags over the whole page keep core's own filters too. */
		$this->assertStringContainsString( '<picture', wp_get_attachment_image( $id, 'full' ) );
	}

	public function test_the_page_is_read_after_core_has_put_a_classic_themes_late_styles_in_it(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ) ) );

		$full = (string) wp_get_attachment_url( $id );
		$late = '<style id="late-inline-css">.hero{background-image:url(' . $full . ')}</style>';
		$type = (string) ini_get( 'default_mimetype' );

		/* The buffer's filter goes on at `template_redirect`; core adds the one that moves late styles into the head once its buffer starts, after it, at the default priority. */
		Buffer::start();
		$hoist = static function ( $html ) use ( $late ) {
			return str_replace( '</head>', $late . '</head>', (string) $html );
		};
		add_filter( 'wp_template_enhancement_output_buffer', $hoist );

		try {
			ini_set( 'default_mimetype', 'text/html' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- an HTML answer.
			$html = wp_finalize_template_enhancement_output_buffer( '<html><head></head><body></body></html>', PHP_OUTPUT_HANDLER_FINAL );
		} finally {
			ini_set( 'default_mimetype', $type ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- puts it back.
			remove_filter( 'wp_template_enhancement_output_buffer', $hoist );
		}

		$this->assertStringContainsString( '.hero{background-image:url(' . $full . '); background-image:image-set(url("' . $full . '.webp") type("image/webp"), url(' . $full . ') type("image/jpeg"))}', $html );
	}

	public function test_a_main_file_is_found_from_any_of_its_sizes(): void {
		$this->assertSame( array( '2026/11/photo-300x200.jpg', '2026/11/photo.jpg', '2026/11/photo-scaled.jpg', '2026/11/photo-rotated.jpg' ), Buffer::main_files( '2026/11/photo-300x200.jpg' ) );
		$this->assertSame( array( '2026/11/photo-e1700000000000.jpg', '2026/11/photo-e1700000000000-scaled.jpg', '2026/11/photo-e1700000000000-rotated.jpg' ), Buffer::main_files( '2026/11/photo-e1700000000000.jpg' ) );
		$this->assertSame( array( 'a.b/photo' ), Buffer::main_files( 'a.b/photo' ) );
	}

	/**
	 * Writes a copy beside a file in the test folder and records it on the file's
	 * row as the plugin's own, with the record off by what a case says.
	 *
	 * @param int                  $id     The row's id.
	 * @param string               $path   The file's path relative to wp-content.
	 * @param string               $format The copy's format.
	 * @param array<string, mixed> $off    `for_bytes` or `bytes` to add to what is true, or the `copy` the row names instead.
	 */
	protected function row_copy( int $id, string $path, string $format, array $off = array() ): void {
		$name = $off['copy'] ?? $path . '.' . $format;
		$copy = WP_CONTENT_DIR . '/' . $name;
		wp_mkdir_p( dirname( $copy ) );
		file_put_contents( $copy, $format . ' bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		clearstatcache();

		Table::update(
			$id,
			array(
				'copies' => array(
					$name => array(
						'for'       => $path,
						'format'    => $format,
						'bytes'     => (int) filesize( $copy ) + (int) ( $off['bytes'] ?? 0 ),
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( WP_CONTENT_DIR . '/' . $path ) + (int) ( $off['for_bytes'] ?? 0 ),
					),
				),
			)
		);
	}

	/**
	 * Writes a test image into the test folder.
	 *
	 * @param string $path Its path in the folder.
	 */
	protected function put( string $path ): void {
		$target = WP_CONTENT_DIR . '/' . self::FOLDER . '/' . $path;

		wp_mkdir_p( dirname( $target ) );
		copy( DIR_TESTDATA . '/images/canola.jpg', $target );
		clearstatcache();
	}

	/**
	 * Deletes a folder with everything in it, never following a link.
	 *
	 * @param string $path The path.
	 */
	protected function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- what the test made.

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}

		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a folder the test made.
	}
}
