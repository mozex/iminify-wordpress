<?php
/**
 * The page audit: Iminify's audits read through the site's key, each image
 * matched to its attachment and size here.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Audit_State;
use Iminify\WordPress\Admin\Settings_Page;
use Iminify\WordPress\Audit\Findings;
use Iminify\WordPress\Audit\Fixes;
use Iminify\WordPress\Audit\Resolver;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Offload_Stub;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Tests the audit's state, its resolver and its routes.
 */
class AuditTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const AUDIT = '9c2b3c4d-1111-4a2b-9c3d-000000000001';

	/**
	 * The stubbed API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		Plugin::keys()->forget();
		Site_Record::forget();
		parent::tear_down();
	}

	public function test_every_finding_of_an_audit_shows_beside_its_attachment_and_size(): void {
		$id    = $this->attachment();
		$audit = $this->audit_of_page( $id );

		$this->api->on( 'GET', 'site/audits', 200, Api_Stub::fixture( 'site-audits' ) );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, $audit );

		$state = Audit_State::build();

		$this->assertTrue( $state['connected'] );
		$this->assertNull( $state['error'] );
		$this->assertSame( self::AUDIT, $state['audits'][0]['id'] );
		$this->assertTrue( $state['audits'][0]['done'] );
		$this->assertSame( self::AUDIT, $state['audit']['id'] );
		$this->assertSame( 3, $state['audit']['flagged'] );

		$images = $state['audit']['images'];
		$this->assertCount( 4, $images );

		/* The main file, shown oversized, without dimensions and without alt text. */
		$this->assertSame( $id, $images[0]['attachment']['id'] );
		$this->assertSame( 'full', $images[0]['attachment']['size'] );
		$this->assertSame( 'Full Size', $images[0]['attachment']['size_name'] );
		$this->assertNull( $images[0]['attachment']['copy'] );
		$this->assertSame( 1280, $images[0]['serve_width'] );
		$this->assertTrue( $images[0]['largest_paint'] );
		$this->assertSame(
			array(
				'oversized'           => 'smaller_size',
				'lcp_not_prioritized' => 'prioritize',
				'without_dimensions'  => 'add_dimensions',
				'missing_alt'         => 'write_alt_text',
			),
			array_column( $images[0]['findings'], 'fix', 'code' )
		);
		$this->assertSame( 'No alt text', $images[0]['findings'][3]['label'] );
		$this->assertSame( 'The image has no alt text for screen readers and search engines.', $images[0]['findings'][3]['message'] );
		$this->assertSame(
			array(
				'Show a size about 1,280 pixels wide here, twice the width it takes on the page.',
				'Have the browser fetch the image first.',
				'Give the image its width and height in the page.',
				'Write alt text for the image.',
			),
			array_column( $images[0]['findings'], 'fix_text' )
		);
		$this->assertSame( Settings_Page::url(), $state['links']['settings'] );
		$this->assertSame( 'https://www.iminify.com/scan?utm_source=wordpress&utm_medium=plugin&utm_campaign=audit', $state['links']['weekly'] );

		/* A WebP copy the page loaded through a picture tag stands for the size it was made from. */
		$medium = wp_get_attachment_metadata( $id )['sizes']['medium'];
		$this->assertSame( $id, $images[1]['attachment']['id'] );
		$this->assertSame( 'medium', $images[1]['attachment']['size'] );
		$this->assertSame( 'WEBP', $images[1]['attachment']['copy'] );
		$this->assertSame( $medium['width'], $images[1]['attachment']['width'] );
		$this->assertSame( array( 'eager_below_fold' ), array_column( $images[1]['findings'], 'code' ) );

		$this->assertSame( 'thumbnail', $images[2]['attachment']['size'] );
		$this->assertSame( 'load_at_once', $images[2]['findings'][0]['fix'] );
		$this->assertSame( 'Load the image straight away, not lazily.', $images[2]['findings'][0]['fix_text'] );
		$this->assertSame( 'Load the image lazily, once a visitor scrolls near it.', $images[1]['findings'][0]['fix_text'] );

		/* A theme's image is no attachment. */
		$this->assertNull( $images[3]['attachment'] );
		$this->assertSame( array(), $images[3]['findings'] );
	}

	public function test_each_image_offers_what_the_plugin_can_do_about_it(): void {
		$id    = $this->attachment();
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$audit = $this->audit_of_page( $id );

		$audit['data']['url'] = (string) get_permalink( $page );
		$this->api->on( 'GET', 'site/audits', 200, Api_Stub::fixture( 'site-audits' ) );

		/* Its upload queued it; here it waits for someone to press. */
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, $audit );

		$fixes = static function (): array {
			return array_column( Audit_State::build()['audit']['images'], 'fixes' );
		};

		/* Not optimized, with no alt text while the site's AI alt text is off; the theme's image has nothing to offer. */
		$state = Audit_State::build();
		$this->assertSame(
			array(
				'optimize' => true,
				'alt_text' => 'settings',
				'fill'     => true,
				'smaller'  => null,
			),
			$state['audit']['images'][0]['fixes']
		);
		$this->assertSame(
			array(
				'optimize' => false,
				'alt_text' => null,
				'fill'     => false,
				'smaller'  => null,
			),
			$state['audit']['images'][3]['fixes']
		);
		/* The page shows three sizes of it, and one press optimizes them all, offered on the first with something to fix. */
		$this->assertSame( array( true, false, false, false ), array_column( array_column( $state['audit']['images'], 'fixes' ), 'optimize' ) );
		$clean                                  = $audit;
		$clean['data']['images'][0]['findings'] = array();
		$this->assertSame( array( false, true, false, false ), array_column( array_column( Audit_State::detail( $clean['data'] )['images'], 'fixes' ), 'optimize' ) );
		$this->assertSame( get_edit_post_link( $page, 'raw' ), $state['audit']['edit'] );
		$this->assertSame(
			array(
				'images' => 1,
				'on'     => false,
				'can'    => true,
			),
			$state['audit']['complete']
		);

		/* A type the service writes no alt text for gets nothing, whatever the setting. */
		$avif = self::factory()->attachment->create( array( 'post_mime_type' => 'image/avif' ) );
		$this->assertNull( Fixes::alt_text( $avif ) );

		/* With the site's AI alt text on, it is written, and while its group is on its way, it comes with it. */
		$this->writes_alt_text();
		$this->assertNull( Fixes::alt_text( $avif ) );
		$this->assertSame( 'write', $fixes()[0]['alt_text'] );
		Records::update( $id, array( 'status' => Records::QUEUED ) );
		$this->assertSame( 'waiting', $fixes()[0]['alt_text'] );
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );

		/* A smaller size, shown at 200: where the page's own content holds the image, the editor changes it. */
		$audit['data']['images'][0]['serve_width'] = 400;
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, $audit );
		$this->assertSame( array( 'medium', false ), array( $fixes()[0]['smaller']['size'], $fixes()[0]['smaller']['editor'] ) );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->',
			)
		);
		$this->assertTrue( $fixes()[0]['smaller']['editor'] );
		$this->assertFalse( Fixes::in_content( 'class="wp-image-' . $id . '1"', array( $id ) ), 'another id that starts the same' );

		/* Once the image has an alt, the pages are given it. */
		update_post_meta( $id, '_wp_attachment_image_alt', 'A field of yellow flowers' );
		$this->assertSame( 'fill', $fixes()[0]['alt_text'] );

		/* An image the plugin optimized, or one on its way, isn't offered again. */
		foreach ( array( Records::OPTIMIZED, Records::QUEUED ) as $status ) {
			Records::update( $id, array( 'status' => $status ) );
			$this->assertFalse( $fixes()[0]['optimize'], $status );
		}

		/* A file translations share doesn't say which of them the page showed. */
		$translation = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $translation, '_wp_attached_file', get_post_meta( $id, '_wp_attached_file', true ) );
		Shared::forget();
		$this->assertSame( 'translations', $fixes()[0]['alt_text'] );

		/* A translated page's content names its own translation of the image, which the editor holds too. */
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '<img src="x.jpg" class="wp-image-' . $translation . '"/>',
			)
		);
		$this->assertTrue( $fixes()[0]['smaller']['editor'] );

		/* Someone who may audit but not change the settings is told who can fill them in. */
		add_filter(
			'iminify_capability',
			static function ( string $capability, string $context ): string {
				return 'settings' === $context ? 'do_not_allow' : $capability;
			},
			10,
			2
		);
		$this->assertFalse( Audit_State::build()['audit']['complete']['can'] );
	}

	public function test_the_smaller_size_is_the_largest_of_the_image_s_shape_within_twice_the_width_shown(): void {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 1920,
				'height' => 1280,
				'file'   => '2026/11/photo.jpg',
				'sizes'  => array(
					'thumbnail'    => array(
						'width'  => 150,
						'height' => 150,
					),
					'medium'       => array(
						'width'  => 300,
						'height' => 200,
					),
					'medium_large' => array(
						'width'  => 768,
						'height' => 512,
					),
					'large'        => array(
						'width'  => 1024,
						'height' => 683,
					),
					'square'       => array(
						'width'  => 600,
						'height' => 600,
					),
				),
			)
		);

		$full = array(
			'attachment' => $id,
			'size'       => 'full',
			'width'      => 1920,
			'height'     => 1280,
		);
		$size = static function ( int $serve_width ) use ( $full ): ?string {
			$choice = Fixes::smaller( $full, $serve_width );

			return null === $choice ? null : $choice['size'];
		};

		/* Shown at 500: the editor offers no size between 500 and 1,000, so the narrowest above it. */
		$this->assertSame( 'large', $size( 1000 ) );
		/* A size a theme adds to the editor is offered too: 768 is within 1,000, where 1,024 would still be oversized. */
		add_filter(
			'image_size_names_choose',
			static function ( array $names ): array {
				return $names + array( 'medium_large' => 'Medium Large' );
			}
		);
		$this->assertSame( 'medium_large', $size( 1000 ) );
		remove_all_filters( 'image_size_names_choose' );
		/* Shown at 160: 300 is within 320; the thumbnail, a square, is another picture. */
		$this->assertSame( 'medium', $size( 320 ) );
		/* Shown at 149: nothing fits within 298, so the narrowest above it. */
		$this->assertSame( 'medium', $size( 298 ) );
		/* Shown at 700: 1,024 is within 1,400 and sharp. */
		$this->assertSame( 'large', $size( 1400 ) );
		/* Shown at 1,200: every size narrower than the file is too small to stay sharp. */
		$this->assertNull( $size( 2400 ) );
		/* A size the page already shows is never offered for itself. */
		$this->assertNull(
			Fixes::smaller(
				array(
					'attachment' => $id,
					'size'       => 'medium',
					'width'      => 300,
					'height'     => 200,
				),
				200
			)
		);
	}

	public function test_filling_in_the_pages_images_is_turned_on_from_the_screen_by_someone_who_may_change_the_settings(): void {
		$this->api->on( 'GET', 'site/audits', 200, Api_Stub::fixture( 'site-audits' ) );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, $this->audit_of_page( $this->attachment() ) );

		foreach ( array( 'editor', 'author', '' ) as $role ) {
			wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertContains( $this->call( 'POST', '/complete' )->get_status(), array( 401, 403 ), $role );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings = static function ( string $capability, string $context ): string {
			return 'settings' === $context ? 'do_not_allow' : $capability;
		};
		add_filter( 'iminify_capability', $settings, 10, 2 );
		$this->assertSame( 403, $this->call( 'POST', '/complete' )->get_status(), 'audits, but no settings' );
		remove_filter( 'iminify_capability', $settings, 10 );

		$this->assertFalse( Local_Settings::get()['complete_images'] );

		$response = $this->call( 'POST', '/complete', array( 'audit' => self::AUDIT ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( Local_Settings::get()['complete_images'] );
		$this->assertSame( self::AUDIT, $response->get_data()['audit']['id'] );
		$this->assertTrue( $response->get_data()['audit']['complete']['on'] );
	}

	public function test_a_translation_sharing_the_files_resolves_to_the_attachment_holding_them(): void {
		$id          = $this->attachment();
		$attached    = (string) get_post_meta( $id, '_wp_attached_file', true );
		$translation = self::factory()->attachment->create(
			array(
				'file'           => $attached,
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $translation, '_wp_attached_file', $attached );
		wp_update_attachment_metadata( $translation, wp_get_attachment_metadata( $id ) );

		/* The higher id holds the work, so the first attachment naming the file is not the answer. */
		Records::update( $id, array( 'status' => Records::NOT_OPTIMIZED ) );
		Records::update( $translation, array( 'status' => Records::QUEUED ) );
		Shared::forget();

		$url = (string) wp_get_attachment_url( $id );

		$this->assertSame( $translation, Resolver::resolve( array( $url ) )[ $url ]['attachment'] );
	}

	public function test_each_copy_naming_stands_for_its_file_and_an_upload_beside_a_namesake_is_itself(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'scale_at_300' ) );
		$id = $this->attachment();
		remove_filter( 'big_image_size_threshold', array( $this, 'scale_at_300' ) );

		$metadata = wp_get_attachment_metadata( $id );
		$base     = dirname( (string) wp_get_attachment_url( $id ) );
		$medium   = $metadata['sizes']['medium']['file'];
		$stem     = substr( $medium, 0, (int) strrpos( $medium, '.' ) );

		/* A WebP upload, and a JPEG attachment of the same name beside it. */
		$webp      = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.webp' );
		$webp_file = (string) get_post_meta( $webp, '_wp_attached_file', true );
		$namesake  = dirname( (string) wp_get_attachment_url( $webp ) ) . '/' . wp_basename( $webp_file );
		$twin      = substr( $webp_file, 0, -5 ) . '.jpg';
		$jpeg      = self::factory()->attachment->create(
			array(
				'file'           => $twin,
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $jpeg, '_wp_attached_file', $twin );
		wp_update_attachment_metadata( $jpeg, array( 'file' => $twin ) );

		$urls = array(
			'original'   => $base . '/' . $metadata['original_image'],
			'scaled'     => (string) wp_get_attachment_url( $id ),
			'shortpixel' => $base . '/' . $stem . '.webp',
			'avif'       => (string) wp_get_attachment_url( $id ) . '.avif',
			'modern'     => $base . '/' . $stem . '-jpg.avif',
		);

		$found = Resolver::resolve( array_merge( array_values( $urls ), array( $namesake ) ) );
		$read  = static function ( string $url ) use ( $found ): array {
			return array( $found[ $url ]['attachment'], $found[ $url ]['size'], $found[ $url ]['copy'] );
		};

		$this->assertSame( array( $id, 'original', null ), $read( $urls['original'] ) );
		$this->assertGreaterThan( 300, $found[ $urls['original'] ]['width'] );
		$this->assertSame( array( $id, 'full', null ), $read( $urls['scaled'] ) );
		$this->assertSame( array( $id, 'medium', 'webp' ), $read( $urls['shortpixel'] ) );
		$this->assertSame( array( $id, 'full', 'avif' ), $read( $urls['avif'] ) );
		$this->assertSame( array( $id, 'medium', 'avif' ), $read( $urls['modern'] ) );
		$this->assertSame( array( $webp, 'full', null ), $read( $namesake ) );
	}

	public function test_an_address_wp_offload_media_serves_from_its_bucket_resolves_to_its_attachment(): void {
		$id      = $this->attachment();
		$offload = ( new Offload_Stub() )->start();

		try {
			$offload->offload( $id );

			$medium = (string) wp_get_attachment_image_url( $id, 'medium' );
			$webp   = $medium . '.webp';
			$signed = $medium . '?X-Amz-Signature=0f3a';
			$other  = str_replace( Offload_Stub::HOST, 'https://bucket.example.com/another-site', $medium );
			$found  = Resolver::resolve( array( $medium, $webp, $signed, $other ) );

			$this->assertStringStartsWith( Offload_Stub::HOST, $medium );
			$this->assertSame( array( $id, 'medium', null ), array( $found[ $medium ]['attachment'], $found[ $medium ]['size'], $found[ $medium ]['copy'] ) );
			$this->assertSame( array( $id, 'medium', 'webp' ), array( $found[ $webp ]['attachment'], $found[ $webp ]['size'], $found[ $webp ]['copy'] ) );

			/* Past a version folder WP Offload Media's object versioning adds. */
			$offload->version = '09143012';
			$versioned        = (string) wp_get_attachment_image_url( $id, 'medium' );
			$this->assertStringContainsString( '/09143012/', $versioned );
			$this->assertSame( $id, Resolver::resolve( array( $versioned ) )[ $versioned ]['attachment'] ?? null );
			$offload->version = '';

			/* A signed address is for one file, and the same name in another folder of the bucket is another item. */
			$this->assertNull( $found[ $signed ] );
			$this->assertNull( $found[ $other ] );

			/* The page's buffer finds an image by its address only under the uploads folder, so the audit offers no fill for this one. */
			$audit                          = Api_Stub::fixture( 'site-audit' )['data'];
			$audit['images']                = array( $audit['images'][0] );
			$audit['images'][0]['url']      = $medium;
			$audit['images'][0]['findings'] = array(
				array(
					'code' => 'without_dimensions',
					'fix'  => 'add_dimensions',
				),
			);
			$detail                         = Audit_State::detail( $audit );

			$this->assertSame( $id, $detail['images'][0]['attachment']['id'] );
			$this->assertFalse( $detail['images'][0]['fixes']['fill'] );
			$this->assertSame( 0, $detail['complete']['images'] );
		} finally {
			$offload->stop();
		}

		/* Without WP Offload Media, a bucket's address is no attachment. */
		$this->assertNull( Resolver::resolve( array( $medium ) )[ $medium ] );
	}

	public function test_a_bucket_address_of_a_site_without_year_and_month_folders_resolves_too(): void {
		$flat = static function ( array $dirs ): array {
			return array_merge(
				$dirs,
				array(
					'subdir' => '',
					'path'   => $dirs['basedir'],
					'url'    => $dirs['baseurl'],
				)
			);
		};

		add_filter( 'upload_dir', $flat );
		$id = $this->attachment();
		remove_filter( 'upload_dir', $flat );

		$offload = ( new Offload_Stub() )->start();

		try {
			$offload->offload( $id );

			$medium = (string) wp_get_attachment_image_url( $id, 'medium' );

			$this->assertSame( Offload_Stub::HOST . '/' . wp_basename( $medium ), $medium );
			$this->assertSame( array( $id, 'medium' ), array( Resolver::resolve( array( $medium ) )[ $medium ]['attachment'] ?? null, Resolver::resolve( array( $medium ) )[ $medium ]['size'] ?? null ) );
		} finally {
			$offload->stop();
		}
	}

	public function test_metadata_another_plugin_wrote_wrong_reads_as_no_size(): void {
		$id       = $this->attachment();
		$metadata = wp_get_attachment_metadata( $id );
		$url      = dirname( (string) wp_get_attachment_url( $id ) ) . '/' . $metadata['sizes']['medium']['file'];

		$metadata['sizes'] = array(
			'broken' => 'not an array',
			'medium' => array( 'width' => 300 ),
		);
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );

		$this->assertNull( Resolver::resolve( array( $url ) )[ $url ] );
	}

	/**
	 * Scales an upload wider than 300 pixels, as WordPress scales one past 2,560.
	 */
	public function scale_at_300(): int {
		return 300;
	}

	public function test_an_address_outside_the_uploads_folder_or_naming_no_file_resolves_to_nothing(): void {
		$this->attachment();
		$uploads = (string) wp_get_upload_dir()['baseurl'];
		$urls    = array(
			'https://cdn.example.net/wp-content/uploads/canola.jpg',
			$uploads . '/2001/01/nothing-here.jpg',
			$uploads . '/../wp-config.php',
		);

		$this->assertSame( array_fill_keys( $urls, null ), Resolver::resolve( $urls ) );
	}

	public function test_a_problem_shows_in_the_site_language_with_what_the_page_answered(): void {
		$audit                      = Api_Stub::fixture( 'site-audit' );
		$audit['data']['problem']   = array(
			'code'    => 'blocked',
			'status'  => 403,
			'message' => 'English text.',
		);
		$audit['data']['images']    = array();
		$list                       = Api_Stub::fixture( 'site-audits' );
		$list['data'][0]['problem'] = $audit['data']['problem'];
		$unknown                    = $list['data'][0];
		$unknown['id']              = '9c2b3c4d-1111-4a2b-9c3d-000000000002';
		$unknown['problem']['code'] = 'something_new';
		$list['data'][]             = $unknown;

		$this->api->on( 'GET', 'site/audits', 200, $list );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, $audit );

		$state = Audit_State::build();

		$this->assertSame( 'blocked', $state['audit']['problem']['code'] );
		$this->assertStringContainsString( 'it answered 403', $state['audit']['problem']['message'] );
		$this->assertStringContainsString( 'Bot Fight Mode', $state['audit']['problem']['message'] );
		$this->assertSame( 'English text.', $state['audits'][1]['problem']['message'] );

		$answered = Findings::problem(
			array(
				'code'    => 'unreachable',
				'status'  => 451,
				'message' => 'x',
			)
		);
		$silent   = Findings::problem(
			array(
				'code'    => 'unreachable',
				'message' => 'x',
			)
		);
		$finding  = Findings::finding(
			array(
				'code'    => 'something_new',
				'fix'     => 'later',
				'label'   => 'A label.',
				'message' => 'A message.',
			)
		);

		$this->assertSame( 'Show a smaller size of the image here.', Findings::fix( 'smaller_size' ) );
		$this->assertNull( Findings::fix( 'later' ) );
		$this->assertStringContainsString( 'answered 451', (string) $answered['message'] );
		$this->assertStringContainsString( 'didn\'t answer', (string) $silent['message'] );
		$this->assertSame( array( 'A label.', 'A message.', 'later' ), array( $finding['label'], $finding['message'], $finding['fix'] ) );
	}

	public function test_a_key_iminify_stopped_disconnects_the_site_and_reads_as_such(): void {
		$refusal = array(
			'message' => 'Unauthenticated.',
			'code'    => 'unauthenticated',
		);
		$this->api->on( 'GET', 'site/audits', 401, $refusal );
		$this->api->on( 'POST', 'site/audits', 401, $refusal );

		$state = Audit_State::build();

		$this->assertFalse( $state['connected'] );
		$this->assertTrue( $state['revoked'] );
		$this->assertNull( $state['error'] );
		$this->assertNull( Plugin::keys()->get() );

		Plugin::keys()->save( self::KEY );

		$response = $this->call( 'POST', '', array( 'url' => home_url( '/' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['connected'] );
		$this->assertNull( Plugin::keys()->get() );
	}

	public function test_the_day_s_page_scans_come_from_the_site_s_record(): void {
		$site                   = Api_Stub::fixture( 'site' )['data'];
		$site['usage']['scans'] = array(
			'used'      => 2,
			'remaining' => 1,
			'resets_at' => '2026-11-10T09:00:00+00:00',
		);
		$this->api->on( 'GET', 'site', 200, array( 'data' => $site ) );
		$this->api->on( 'GET', 'site/audits', 200, array( 'data' => array() ) );

		$scans = Audit_State::build()['scans'];

		$this->assertSame( array( 2, 1 ), array( $scans['used'], $scans['remaining'] ) );
		$this->assertNotNull( $scans['resets_at'] );
		$this->assertCount( 1, $this->api->calls( 'GET', 'site' ) );
	}

	public function test_starting_an_audit_asks_iminify_and_opens_it(): void {
		$this->api->on( 'POST', 'site/audits', 202, Api_Stub::fixture( 'site-audit' ) );
		$this->api->on( 'GET', 'site/audits', 200, Api_Stub::fixture( 'site-audits' ) );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, Api_Stub::fixture( 'site-audit' ) );

		$response = $this->call( 'POST', '', array( 'url' => home_url( '/about/' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::AUDIT, $response->get_data()['audit']['id'] );
		$this->assertSame( array( 'url' => home_url( '/about/' ) ), json_decode( (string) $this->api->calls( 'POST', 'site/audits' )[0]['args']['body'], true ) );
	}

	public function test_the_state_opens_the_audit_it_names_rather_than_the_newest(): void {
		$older                   = Api_Stub::fixture( 'site-audit' );
		$older_id                = '9c2b3c4d-1111-4a2b-9c3d-000000000002';
		$list                    = Api_Stub::fixture( 'site-audits' );
		$entry                   = $list['data'][0];
		$entry['id']             = $older_id;
		$list['data'][]          = $entry;
		$older['data']['id']     = $older_id;
		$older['data']['images'] = array();

		$this->api->on( 'GET', 'site/audits', 200, $list );
		$this->api->on( 'GET', 'site/audits/' . self::AUDIT, 200, Api_Stub::fixture( 'site-audit' ) );
		$this->api->on( 'GET', 'site/audits/' . $older_id, 200, $older );

		$named  = $this->call( 'GET', '?audit=' . $older_id );
		$newest = $this->call( 'GET', '' );

		$this->assertSame( $older_id, $named->get_data()['audit']['id'] );
		$this->assertCount( 1, $this->api->calls( 'GET', 'site/audits/' . $older_id ) );
		$this->assertSame( self::AUDIT, $newest->get_data()['audit']['id'] );
		$this->assertSame( 400, $this->call( 'GET', '?audit=../site' )->get_status() );
	}

	public function test_a_refusal_keeps_its_code_and_how_long_to_wait(): void {
		$this->api->on(
			'POST',
			'site/audits',
			422,
			array(
				'message' => 'A site\'s audit scans only the site\'s own pages.',
				'code'    => 'audit_off_site',
			)
		);

		$off = $this->call( 'POST', '', array( 'url' => 'https://elsewhere.example.net/' ) );

		$this->assertSame( 422, $off->get_status() );
		$this->assertSame( 'audit_off_site', $off->get_data()['code'] );
		$this->assertSame( 'The audit scans only this site\'s own pages. Enter the address of a page on this site.', $off->get_data()['message'] );

		$this->api->on(
			'POST',
			'site/audits',
			429,
			array(
				'message' => 'Too many page scans in a minute.',
				'code'    => 'rate_limited',
			),
			array( 'Retry-After' => '42' )
		);

		$wait = $this->call( 'POST', '', array( 'url' => home_url( '/' ) ) );

		$this->assertSame( 429, $wait->get_status() );
		$this->assertSame( 'rate_limited', $wait->get_data()['code'] );
		$this->assertSame( 42, $wait->get_data()['data']['retry_after'] );
	}

	public function test_only_people_who_manage_options_reach_the_routes(): void {
		foreach ( array( 'editor', 'author', '' ) as $role ) {
			wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertContains( $this->call( 'GET', '' )->get_status(), array( 401, 403 ), $role );
			$this->assertContains( $this->call( 'GET', '/' . self::AUDIT )->get_status(), array( 401, 403 ), $role );
			$this->assertContains( $this->call( 'POST', '', array( 'url' => home_url( '/' ) ) )->get_status(), array( 401, 403 ), $role );
		}

		$this->assertSame( array(), $this->api->calls( 'POST', 'site/audits' ) );
	}

	public function test_a_disconnected_site_asks_nothing(): void {
		Plugin::keys()->forget();

		$state = Audit_State::build();

		$this->assertFalse( $state['connected'] );
		$this->assertSame( array(), $state['audits'] );
		$this->assertNull( $state['links']['weekly'] );
		$this->assertSame( array(), $this->api->calls( 'GET', 'site/audits' ) );

		/* A start or a poll answers the state that says so, which the screen draws in place of the audit. */
		$start = $this->call( 'POST', '', array( 'url' => home_url( '/' ) ) );
		$poll  = $this->call( 'GET', '/' . self::AUDIT );

		$this->assertSame( array( 200, false ), array( $start->get_status(), $start->get_data()['connected'] ) );
		$this->assertSame( array( 200, false ), array( $poll->get_status(), $poll->get_data()['connected'] ) );
		$this->assertSame( array(), $this->api->calls( 'POST', 'site/audits' ) );
	}

	/**
	 * The site's record on the service, with its AI alt text on.
	 */
	protected function writes_alt_text(): void {
		$site = Api_Stub::fixture( 'site' );

		$site['data']['settings']['alt_text'] = true;

		$this->api->on( 'GET', 'site', 200, $site );
		Site_Record::forget();
		Site_Record::fetch();
	}

	/**
	 * The fixture's audit with its media library addresses pointed at the attachment's files.
	 *
	 * @param int $id The attachment.
	 * @return array<string, mixed>
	 */
	protected function audit_of_page( int $id ): array {
		$audit = Api_Stub::fixture( 'site-audit' );
		$sizes = wp_get_attachment_metadata( $id )['sizes'];
		$base  = dirname( (string) wp_get_attachment_url( $id ) );

		$audit['data']['images'][0]['url'] = (string) wp_get_attachment_url( $id );
		$audit['data']['images'][1]['url'] = $base . '/' . $sizes['medium']['file'] . '.webp';
		$audit['data']['images'][2]['url'] = $base . '/' . $sizes['thumbnail']['file'];

		return $audit;
	}

	/**
	 * An uploaded image with its sizes.
	 */
	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Sends a request to one of the audit's routes.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The path under `/audit`.
	 * @param array<string, mixed> $body   The JSON body.
	 */
	protected function call( string $method, string $path, array $body = array() ): WP_REST_Response {
		$parts   = explode( '?', $path, 2 );
		$request = new WP_REST_Request( $method, '/iminify/v1/audit' . $parts[0] );

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			$request->set_query_params( $query );
		}

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}
}
