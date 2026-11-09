<?php
/**
 * Filling in what an image's markup lacks, from the media library.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Delivery\Buffer;
use Iminify\WordPress\Delivery\Complete;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Shared;
use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

/**
 * Tests `Delivery\Complete` through core's content filter and the page's buffer.
 */
class CompleteTest extends WP_UnitTestCase {

	/**
	 * An image of the library, with its alt text.
	 *
	 * @var int
	 */
	protected $image;

	public function set_up(): void {
		parent::set_up();
		$this->image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $this->image, '_wp_attachment_image_alt', 'Tom\'s "yellow" <b>field</b>' );
		Local_Settings::update( array( 'complete_images' => true ) );
	}

	public function tear_down(): void {
		delete_option( Local_Settings::OPTION );
		remove_all_filters( 'wp_template_enhancement_output_buffer' );
		parent::tear_down();
	}

	public function test_an_image_in_content_gets_the_alt_text_of_the_attachment_its_class_names_only_where_it_has_none(): void {
		$medium = (string) wp_get_attachment_image_url( $this->image, 'medium' );
		$class  = 'wp-image-' . $this->image;

		foreach ( array( Local_Settings::DELIVERY_PICTURE, Local_Settings::DELIVERY_OFF ) as $delivery ) {
			Local_Settings::update( array( 'delivery' => $delivery ) );

			$filled = $this->attributes( wp_filter_content_tags( '<img class="' . $class . '" src="' . $medium . '">' ) );

			$this->assertSame( 'Tom\'s "yellow" field', $filled['alt'], $delivery );
			$this->assertSame( array( '300', '225' ), array( $filled['width'], $filled['height'] ), $delivery );
		}

		/* An empty alt says the image is decorative, and one someone wrote is theirs. */
		$this->assertSame( '', $this->attributes( wp_filter_content_tags( '<img class="' . $class . '" src="' . $medium . '" alt="">' ) )['alt'] );
		$this->assertSame( 'Mine', $this->attributes( wp_filter_content_tags( '<img class="' . $class . '" src="' . $medium . '" alt="Mine">' ) )['alt'] );

		/* Off, nothing is added. */
		Local_Settings::update( array( 'complete_images' => false ) );
		$this->assertNull( $this->attributes( wp_filter_content_tags( '<img class="' . $class . '" src="' . $medium . '">' ) )['alt'] );
	}

	public function test_the_page_s_buffer_fills_in_an_image_named_only_by_its_address(): void {
		$medium = (string) wp_get_attachment_image_url( $this->image, 'medium' );

		Buffer::start();
		$this->assertSame( Buffer::PRIORITY, has_filter( 'wp_template_enhancement_output_buffer', array( Buffer::class, 'rewrite' ) ) );

		$filled = $this->attributes( (string) Buffer::rewrite( '<p>Pasted</p><img src="' . $medium . '">' ) );
		$this->assertSame( array( '300', '225', 'Tom\'s "yellow" field' ), array( $filled['width'], $filled['height'], $filled['alt'] ) );

		/* The attachment is the one the `src` names, even where a srcset lists another's file first. */
		$other = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/2004-07-22-DSC_0008.jpg' );
		update_post_meta( $other, '_wp_attachment_image_alt', 'Another picture' );
		$both = $this->attributes( (string) Buffer::rewrite( '<img srcset="' . wp_get_attachment_url( $other ) . ' 640w, ' . $medium . ' 300w" src="' . $medium . '">' ) );
		$this->assertSame( array( '300', '225', 'Tom\'s "yellow" field' ), array( $both['width'], $both['height'], $both['alt'] ) );

		/* One of the two sizes given, the other follows the file's shape; an empty alt stays. */
		$this->assertSame( array( '150', '113', '' ), array_values( $this->attributes( (string) Buffer::rewrite( '<img width="150" alt="" src="' . $medium . '">' ) ) ) );
		$this->assertSame( array( '400', '300' ), array_slice( array_values( $this->attributes( (string) Buffer::rewrite( '<img height="300" src="' . $medium . '">' ) ) ), 0, 2 ) );

		/* An image inside a picture is the content filter's, and one outside the library has no attachment. */
		$page = '<picture><img src="' . $medium . '"></picture><img src="https://cdn.example.com/photo.jpg">';
		$this->assertSame( $page, Buffer::rewrite( $page ) );
	}

	public function test_a_file_translations_share_gets_its_width_and_height_by_address_and_never_one_of_their_alt_texts(): void {
		$translation = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $translation, '_wp_attached_file', get_post_meta( $this->image, '_wp_attached_file', true ) );
		update_post_meta( $translation, '_wp_attachment_image_alt', 'Champ jaune' );
		Shared::forget();

		$medium = (string) wp_get_attachment_image_url( $this->image, 'medium' );
		$filled = $this->attributes( (string) Buffer::rewrite( '<img src="' . $medium . '">' ) );

		$this->assertSame( array( '300', '225', null ), array( $filled['width'], $filled['height'], $filled['alt'] ) );

		/* Its class names the one the page shows, which has an alt in its own language. */
		wp_update_attachment_metadata( $translation, wp_get_attachment_metadata( $this->image ) );
		$this->assertSame( 'Champ jaune', $this->attributes( (string) Buffer::rewrite( '<img class="wp-image-' . $translation . '" src="' . $medium . '">' ) )['alt'] );
	}

	public function test_a_class_left_from_another_image_gives_no_alt_text(): void {
		$other = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/2004-07-22-DSC_0008.jpg' );
		$stale = '<img class="wp-image-' . $this->image . '" src="' . wp_get_attachment_url( $other ) . '">';

		$this->assertNull( $this->attributes( Complete::tag( $stale, $this->image ) )['alt'] );
		$this->assertNull( $this->attributes( wp_filter_content_tags( $stale ) )['alt'] );
	}

	public function test_the_page_is_read_only_while_something_is_filled_in_or_served_and_never_on_a_wp_admin_screen(): void {
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_OFF ) );
		$this->assertTrue( Buffer::reads() );

		$medium = (string) wp_get_attachment_image_url( $this->image, 'medium' );
		$this->assertStringNotContainsString( '<picture', (string) Buffer::rewrite( '<img src="' . $medium . '">' ) );

		set_current_screen( 'upload' );
		$this->assertFalse( Buffer::reads() );
		$this->assertFalse( Complete::on() );
		set_current_screen( 'front' );

		Local_Settings::update( array( 'complete_images' => false ) );
		$this->assertFalse( Buffer::reads() );
		$this->assertSame( '<img src="' . $medium . '">', Buffer::rewrite( '<img src="' . $medium . '">' ) );
	}

	public function test_turning_it_on_or_off_empties_the_page_caches_since_they_kept_the_old_markup(): void {
		$purged = array();

		Local_Settings::update( array( 'complete_images' => false ) );
		Caches::at_shutdown();
		add_filter(
			'iminify_purge_caches',
			static function ( bool $purge, string $why ) use ( &$purged ): bool {
				$purged[] = $why;

				return false;
			},
			10,
			2
		);

		Local_Settings::update( array( 'complete_images' => true ) );
		Caches::at_shutdown();
		Local_Settings::update( array( 'complete_images' => false ) );
		Caches::at_shutdown();
		Local_Settings::update( array( 'backups' => false ) );
		Caches::at_shutdown();

		$this->assertSame( array( 'delivery', 'delivery' ), $purged );
	}

	/**
	 * The first image's width, height and alt in some markup, null for one it lacks.
	 *
	 * @param string $html The markup.
	 * @return array{width: string|null, height: string|null, alt: string|null}
	 */
	protected function attributes( string $html ): array {
		$markup = new WP_HTML_Tag_Processor( $html );
		$markup->next_tag( array( 'tag_name' => 'img' ) );

		$value = static function ( $attribute ): ?string {
			return is_string( $attribute ) ? $attribute : null;
		};

		return array(
			'width'  => $value( $markup->get_attribute( 'width' ) ),
			'height' => $value( $markup->get_attribute( 'height' ) ),
			'alt'    => $value( $markup->get_attribute( 'alt' ) ),
		);
	}
}
