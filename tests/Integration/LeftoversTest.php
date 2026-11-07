<?php
/**
 * The delivery rules another image optimizer left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Switching\Leftovers;
use Iminify\WordPress\Switching\Reader;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers each plugin's rules found while it is off and removed on the press,
 * every other line left as it was (the plugin's own block and WordPress's
 * included); what is never listed (an empty block, a block of file types alone,
 * a plugin that is on); and what the press refuses (a file it can't write, a
 * block without its END line, an id it didn't give, someone who can't manage the
 * site, or the network for a file every site shares).
 *
 * Each block is written as its plugin writes it, from the code each cites: core's
 * `insert_with_markers()` for EWWW (`common.php`, `ewww_image_optimizer_webp_rewrite_verify()`),
 * ShortPixel (`class/Helper/UtilHelper.php`, `alterHtaccess()`) and TinyPNG
 * (`src/class-tiny-apache-rewrite.php`), Imagify's own writer at the file's top
 * (`classes/WriteFile/AbstractApacheDirConfFile.php`, `classes/Webp/RewriteRules/Apache.php`),
 * and Converter for Media's (`src/Loader/HtaccessLoader.php`), whose blocks and EWWW's
 * were also captured from them running.
 */
class LeftoversTest extends WP_UnitTestCase {

	const HOTLINK = "# BEGIN Hotlink\nRewriteEngine on\nRewriteCond %{HTTP_REFERER} ^https://thief\\.example/ [NC]\nRewriteRule \\.(?:jpe?g|png)$ - [F]\n# END Hotlink";

	const WORDPRESS = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress";

	/**
	 * The plugin's own block, as `Rules::block()` writes it, cut short.
	 */
	const IMINIFY = "# BEGIN Iminify\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{HTTP_ACCEPT} image/avif [NC]\nRewriteRule ^(?:wp-content/uploads/).+\\.(?:jpe?g|png)$ %{REQUEST_URI}.avif [NC,T=image/avif,L]\n</IfModule>\n# END Iminify";

	const EWWW = array(
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteCond %{HTTP_ACCEPT} image/webp',
		'RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png|gif)$',
		'RewriteCond %{REQUEST_FILENAME}.webp -f',
		'RewriteCond %{QUERY_STRING} !type=original',
		'RewriteRule (.+)\.(jpe?g|png|gif)$ %{REQUEST_URI}.webp [T=image/webp,L]',
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'<FilesMatch "\.(jpe?g|png|gif)$">',
		'Header append Vary Accept',
		'</FilesMatch>',
		'</IfModule>',
		'AddType image/webp .webp',
	);

	const SHORTPIXEL = "\n           <IfModule mod_rewrite.c>\n             RewriteEngine On\n             RewriteCond %{HTTP_ACCEPT} image/webp\n             RewriteCond %{REQUEST_URI} ^(.+)\\.(?:jpe?g|png|gif)$\n             RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f\n             RewriteRule ^(.+)$ $1.webp [NC,T=image/webp,E=webp,L]\n           </IfModule>\n           <IfModule mod_headers.c>\n             <FilesMatch \"\\.(jpe?g|png|gif|webp|avif)$\">\n               Header append Vary Accept\n             </FilesMatch>\n           </IfModule>\n           ";

	const TINYPNG = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteOptions Inherit\nRewriteCond %{HTTP_ACCEPT} image/avif\nRewriteCond %{REQUEST_URI} ^(.+)\\.(?:jpe?g|png)$\nRewriteCond %{DOCUMENT_ROOT}/%1.avif -f\nRewriteCond %{QUERY_STRING} !type=original\nRewriteRule (.+)\\.(?:jpe?g|png)$ $1.avif [T=image/avif,L]\n</IfModule>\n<IfModule mod_mime.c>\nAddType image/webp .webp\nAddType image/avif .avif\n</IfModule>";

	const IMAGIFY_REWRITE = "# BEGIN Imagify: rewrite rules for webp\n<IfModule mod_rewrite.c>\n\tRewriteEngine On\n\tRewriteBase /\n\n\t# Check if browser supports WebP images.\n\tRewriteCond %{HTTP_ACCEPT} image/webp\n\n\t# Check if WebP replacement image exists.\n\tRewriteCond %{REQUEST_FILENAME}.webp -f\n\n\t# Serve WebP image instead.\n\tRewriteRule (.+)\\.(jpg|jpeg|jpe|png|gif)$ $1.$2.webp [T=image/webp,NC]\n</IfModule>\n# END Imagify: rewrite rules for webp";

	const IMAGIFY_TYPE = "# BEGIN Imagify: webp file type\n<IfModule mod_mime.c>\n\tAddType image/webp .webp\n</IfModule>\n# END Imagify: webp file type";

	const IMAGIFY_NGINX = "# BEGIN Imagify: rewrite rules for webp\nlocation ~* ^(/.+)\\.(jpg|jpeg|jpe|png|gif|webp|avif)$ {\n    add_header Vary Accept;\n\n    set \$canwebp 1;\n\n    if (\$http_accept !~* \"webp\"){\n        set \$canwebp 0;\n    }\n\n    if (!-f \$request_filename.webp) {\n        set \$canwebp 0;\n\n    }\n    if (\$canwebp = 1){\n        rewrite ^(.*) \$1.webp;\n        break;\n    }\n}\n# END Imagify: rewrite rules for webp";

	/**
	 * Each file the test writes, with what it held before (null for none).
	 *
	 * @var array<string, string|null>
	 */
	protected $kept = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		foreach ( $this->paths() as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test site's own file, put back afterwards.
			$this->kept[ $path ] = is_file( $path ) ? (string) file_get_contents( $path ) : null;
			wp_delete_file( $path );
		}

		update_option( 'active_plugins', array() );
		Reader::forget();
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		wp_set_current_user( $admin );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		/* The purge a change of delivery asked for, which shutdown never pays in a test run. */
		Caches::at_shutdown();

		foreach ( $this->kept as $path => $text ) {
			if ( is_file( $path ) ) {
				chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a test that took the write away gives it back.
			}

			if ( null === $text ) {
				wp_delete_file( $path );
			} else {
				$this->put( $path, $text );
			}
		}

		foreach ( array( dirname( (string) Rules::file() ) . '/conf', wp_normalize_path( WP_CONTENT_DIR ) . '/uploads-webpc' ) as $folder ) {
			if ( is_dir( $folder ) && array() === array_diff( (array) scandir( $folder ), array( '.', '..' ) ) ) {
				rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a folder the test made for another plugin's file.
			}
		}

		update_option( 'active_plugins', array() );
		parent::tear_down();
	}

	public function test_each_plugin_s_rules_show_while_it_is_off_and_go_on_the_press_leaving_every_other_line(): void {
		$root    = (string) Rules::file();
		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/.htaccess';
		$uploads = Paths::uploads() . '/.htaccess';
		$nginx   = dirname( $root ) . '/conf/imagify.conf';
		$webpc   = wp_normalize_path( WP_CONTENT_DIR ) . '/uploads-webpc/.htaccess';

		/* The site's own file: the hotlink rule, EWWW's block appended, ShortPixel's emptied on its deactivation, the plugin's own, WordPress's, and Imagify's two at the top. */
		$this->put( $root, self::HOTLINK . "\n" );
		insert_with_markers( $root, 'EWWWIO', self::EWWW );
		insert_with_markers( $root, 'ShortPixelWebp', '' );
		$this->put( $root, $this->read( $root ) . "\n" . self::IMINIFY . "\n\n" . self::WORDPRESS . "\n" );
		$this->put( $root, self::IMAGIFY_REWRITE . "\n\n" . self::IMAGIFY_TYPE . "\n\n" . $this->read( $root ) );
		$before_root = $this->read( $root );

		$this->put( $content, "Options -Indexes\n" );
		insert_with_markers( $content, 'ShortPixelWebp', str_replace( 'RewriteEngine On', 'RewriteEngine On' . PHP_EOL . 'RewriteOptions Inherit', self::SHORTPIXEL ) );
		$this->put( $content, $this->fixture( 'converter-content' ) . "\n" . $this->read( $content ) );

		$this->put( $uploads, $this->fixture( 'converter-uploads' ) );
		insert_with_markers( $uploads, 'tiny-compress-images', self::TINYPNG );
		/* A block under a listed marker that only names types serves nothing in place of an image. */
		insert_with_markers( $uploads, 'EWWWIO', array( '<IfModule mod_mime.c>', 'AddType image/webp .webp', '</IfModule>' ) );
		$types = $this->marked( 'EWWWIO', "<IfModule mod_mime.c>\nAddType image/webp .webp\n</IfModule>" );

		/* Its block in its own folder only names the copies' types, which the copies taken over from it are served with. */
		wp_mkdir_p( dirname( $webpc ) );
		$this->put( $webpc, $this->fixture( 'converter-webpc' ) );

		wp_mkdir_p( dirname( $nginx ) );
		$this->put( $nginx, self::IMAGIFY_NGINX . "\n" );

		/* A plugin that is on keeps its rules out of the list, since they are its live ones. */
		update_option( 'active_plugins', array( 'ewww-image-optimizer/ewww-image-optimizer.php' ) );
		$this->assertNotContains( 'ewww:root', array_column( Leftovers::found(), 'id' ) );
		update_option( 'active_plugins', array() );

		$found = array_column( Leftovers::found(), null, 'id' );

		$this->assertSame( array( 'ewww:root', 'imagify:root', 'shortpixel:content', 'converter:content', 'tinypng:uploads', 'converter:uploads', 'imagify:nginx' ), array_keys( $found ), 'The empty block and the file types alone are never listed.' );
		$this->assertSame( str_replace( "\r", '', $this->marked( 'EWWWIO', implode( "\n", self::EWWW ) ) ), $found['ewww:root']['lines'], 'Shown without the carriage returns a line may end in.' );
		$this->assertSame( self::IMAGIFY_REWRITE, $found['imagify:root']['lines'] );
		$this->assertSame( self::IMAGIFY_NGINX, $found['imagify:nginx']['lines'] );
		$this->assertSame( trim( $this->fixture( 'converter-content' ) ), $found['converter:content']['lines'] );
		$this->assertSame( trim( $this->fixture( 'converter-uploads' ) ), $found['converter:uploads']['lines'] );
		$this->assertSame( trim( $this->fixture( 'ewww' ) ), $found['ewww:root']['lines'], 'As EWWW wrote it, running.' );
		$this->assertSame( 'nginx', $found['imagify:nginx']['kind'] );
		$this->assertSame( $nginx, $found['imagify:nginx']['file'] );
		$this->assertStringContainsString( 'reloaded', $found['imagify:nginx']['what'] );
		$this->assertStringContainsString( 'in its own folder', $found['converter:content']['what'] );
		$this->assertSame( array( '' ), array_unique( array_column( $found, 'why' ) ) );
		$this->assertSame( array( is_multisite() ), array_unique( array_column( $found, 'shared' ) ), 'On a network the main site\'s files serve every site.' );

		/* Each goes on its own press, and nothing else in its file moves. */
		$this->assertSame( 200, $this->remove( 'ewww:root' )->get_status() );
		/* Where core writes carriage returns into its comment lines, as this WordPress does, it drops them again when it reads the file for the next block, as it did EWWW's for ShortPixel's. */
		$this->assertSame( str_replace( "\n" . str_replace( "\r", '', $this->marked( 'EWWWIO', implode( "\n", self::EWWW ) ) ) . "\n", "\n", $before_root ), $this->read( $root ) );

		$answer = $this->remove( 'imagify:root' );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertTrue( $answer->get_data()['removed'] );
		$this->assertNotContains( 'imagify:root', array_column( $answer->get_data()['leftovers'], 'id' ), 'The answer is the page\'s new state.' );
		$this->assertSame( self::IMAGIFY_TYPE . "\n\n" . self::HOTLINK . "\n\n" . $this->marked( 'ShortPixelWebp', '' ) . "\n" . self::IMINIFY . "\n\n" . self::WORDPRESS . "\n", $this->read( $root ) );

		$this->remove( 'converter:content' );
		$this->remove( 'shortpixel:content' );
		$this->assertSame( "\nOptions -Indexes\n", $this->read( $content ), 'Converter for Media\'s first empty line was its own, before its block.' );

		$this->remove( 'tinypng:uploads' );
		$this->remove( 'converter:uploads' );
		$this->assertSame( "\n" . $types, $this->read( $uploads ), 'The block that only names types stays.' );
		$this->put( $uploads, '' );
		$this->assertSame( $this->fixture( 'converter-webpc' ), $this->read( $webpc ) );
		$this->assertFileExists( $uploads );

		$this->remove( 'imagify:nginx' );
		$this->assertSame( '', $this->read( $nginx ), 'An emptied file stays, empty.' );
		$this->assertFileExists( $nginx );

		$this->assertSame( array(), Leftovers::found() );
		$this->assertSame( 404, $this->remove( 'ewww:root' )->get_status(), 'A press on lines already gone changes nothing.' );
	}

	public function test_a_file_with_windows_line_endings_keeps_them_on_every_line_the_press_leaves(): void {
		$root  = (string) Rules::file();
		$block = "# BEGIN EWWWIO\n" . implode( "\n", self::EWWW ) . "\n# END EWWWIO";
		$crlf  = str_replace( "\n", "\r\n", self::HOTLINK . "\n\n" . $block . "\n" . self::WORDPRESS . "\n" );

		$this->put( $root, $crlf );

		$this->assertSame( $block, Leftovers::found()[0]['lines'], 'Shown without the carriage returns.' );
		$this->assertSame( 200, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( str_replace( "\n", "\r\n", self::HOTLINK . "\n\n" . self::WORDPRESS . "\n" ), $this->read( $root ) );
	}

	public function test_the_press_leaves_a_file_it_can_t_write_or_a_block_without_its_end_and_takes_only_ids_it_gave(): void {
		$root = (string) Rules::file();

		$this->put( $root, self::WORDPRESS . "\n" );
		insert_with_markers( $root, 'EWWWIO', self::EWWW );
		$written = $this->read( $root );

		chmod( $root, 0444 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a file the site can't write.
		clearstatcache();

		if ( ! wp_is_writable( $root ) ) {
			$this->assertSame( 'unwritable', Leftovers::found()[0]['why'], 'The settings show the lines to remove by hand.' );
			$this->assertSame( 409, $this->remove( 'ewww:root' )->get_status() );
			$this->assertSame( $written, $this->read( $root ) );
		}

		chmod( $root, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- given back.

		/* A BEGIN line with no END line leaves the file alone, rather than take the rest of it with the block. */
		$damaged = str_replace( '# END EWWWIO', '', $written );
		$this->put( $root, $damaged );
		$found = Leftovers::found();

		$this->assertSame( 'damaged', $found[0]['why'] );
		$this->assertSame( '# BEGIN EWWWIO', $found[0]['lines'] );
		$this->assertSame( 409, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( $damaged, $this->read( $root ) );
		$this->assertNull( Leftovers::without( explode( "\n", $damaged ), array( 'EWWWIO' ) ) );

		/* Nor does one whose END line comes after WordPress's block began, which it would take with it. */
		$around = "# BEGIN EWWWIO\nRewriteRule (.+)\\.jpg$ %{REQUEST_URI}.webp [T=image/webp,L]\n" . self::WORDPRESS . "\n# END EWWWIO\n";
		$this->put( $root, $around );
		$this->assertSame( 'damaged', Leftovers::found()[0]['why'] );
		$this->assertSame( 409, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( $around, $this->read( $root ) );

		/* A marker is a whole line: a block of another name that starts the same way, or a BEGIN line in a comment, is someone else's. */
		$this->put( $root, $this->marked( 'EWWWIO-old', implode( "\n", self::EWWW ) ) . "\n## BEGIN EWWWIO\n" . self::WORDPRESS . "\n" );
		$this->assertSame( array(), Leftovers::found() );

		/* Only an id it gave, never a path; and only for someone who manages the site. */
		$this->put( $root, $written );
		$this->assertSame( 404, $this->remove( '../../wp-config.php' )->get_status() );
		$this->assertSame( 404, $this->remove( 'ewww:abspath' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( $written, $this->read( $root ) );
	}

	public function test_with_the_server_s_rules_on_a_removal_looks_again_at_whether_they_serve(): void {
		$uploads = Paths::uploads() . '/.htaccess';

		$this->put( (string) Rules::file(), self::WORDPRESS . "\n" );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		update_option(
			Rules::CHECK_OPTION,
			array(
				'at'         => time(),
				'where'      => Rules::where(),
				'serves'     => false,
				'cloudflare' => false,
				'refused'    => false,
			),
			false
		);

		$this->put( $uploads, '' );
		insert_with_markers( $uploads, 'tiny-compress-images', self::TINYPNG );

		$asked = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$asked ): array {
				++$asked;

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 404 ),
					'cookies'  => array(),
				);
			}
		);

		$this->remove( 'tinypng:uploads' );

		$this->assertSame( 2, $asked, 'The look asks the site for its test image twice.' );
		$this->assertNull( get_option( Rules::CHECK_OPTION )['serves'] );
	}

	public function test_smush_s_rule_leaves_wordpress_s_rewrite_rules_on_the_press_and_every_other_stays(): void {
		$rules = array(
			'wp-content/uploads/(.*\.(?:png))$' => 'index.php?smush_load_fallback_png=$matches[1]',
			'(.?.+?)(?:/([0-9]+))?/?$'          => 'index.php?pagename=$matches[1]&page=$matches[2]',
		);
		update_option( 'rewrite_rules', $rules );

		update_option( 'active_plugins', array( 'wp-smushit/wp-smush.php' ) );
		$this->assertSame( array(), Leftovers::found(), 'Smush, on, keeps its rule.' );
		update_option( 'active_plugins', array() );

		$found = Leftovers::found();

		$this->assertCount( 1, $found );
		$this->assertSame( 'smush:rules', $found[0]['id'] );
		$this->assertSame( 'rules', $found[0]['kind'] );
		$this->assertSame( 'wp-content/uploads/(.*\.(?:png))$ => index.php?smush_load_fallback_png=$matches[1]', $found[0]['lines'] );

		$this->assertSame( 200, $this->remove( 'smush:rules' )->get_status() );
		$this->assertSame( array( '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]' ), get_option( 'rewrite_rules' ) );
		$this->assertSame( array(), Leftovers::found() );
	}

	public function test_on_a_network_the_files_every_site_shares_need_the_network_and_a_plugin_off_on_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		$root = (string) Rules::file();
		$site = (int) self::factory()->blog->create( array( 'path' => '/leftovers-' . wp_generate_password( 8, false ) . '/' ) );

		$this->put( $root, self::WORDPRESS . "\n" );
		insert_with_markers( $root, 'EWWWIO', self::EWWW );
		$written = $this->read( $root );

		/* On on another site, the shared block is that site's live rules. */
		switch_to_blog( $site );
		update_option( 'active_plugins', array( 'ewww-image-optimizer/ewww-image-optimizer.php' ) );
		$theirs = Paths::uploads() . '/.htaccess';
		restore_current_blog();

		$this->assertSame( array(), Leftovers::found() );

		switch_to_blog( $site );
		update_option( 'active_plugins', array() );
		wp_mkdir_p( dirname( $theirs ) );
		$this->put( $theirs, '' );
		insert_with_markers( $theirs, 'tiny-compress-images', self::TINYPNG );

		try {
			/* Another site lists its own uploads folder alone, which its own admin removes. */
			$this->assertSame( array( 'tinypng:uploads' ), array_column( Leftovers::found(), 'id' ) );
			$this->assertFalse( Leftovers::found()[0]['shared'] );

			/* A shared media library points it at the main site's folder, whose file every site reads: it lists nothing. */
			$main = static function ( array $dirs ): array {
				$dirs['basedir'] = wp_normalize_path( WP_CONTENT_DIR ) . '/uploads';

				return $dirs;
			};
			add_filter( 'upload_dir', $main );
			$this->assertSame( array(), Leftovers::files() );
			remove_filter( 'upload_dir', $main );
		} finally {
			wp_delete_file( $theirs );
			restore_current_blog();
		}

		/* A site whose tables are missing fails the look at every site's plugins, which then can't call the plugin off. */
		global $wpdb;
		$wpdb->insert(
			$wpdb->blogs,
			array(
				'site_id'    => get_current_network_id(),
				'domain'     => get_network()->domain,
				'path'       => '/leftovers-ghost/',
				'registered' => current_time( 'mysql' ),
			)
		);
		$ghost = (int) $wpdb->insert_id;
		wp_cache_delete( 'last_changed', 'sites' );
		$this->assertSame( array(), Leftovers::found() );
		$wpdb->delete( $wpdb->blogs, array( 'blog_id' => $ghost ) );
		wp_cache_delete( 'last_changed', 'sites' );

		$found = Leftovers::found();

		$this->assertSame( array( 'ewww:root' ), array_column( $found, 'id' ) );
		$this->assertTrue( $found[0]['shared'] );

		/* A site's admin who can't manage the network sees them and can't remove them. */
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertFalse( Leftovers::found()[0]['can'] );
		$this->assertSame( 403, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( $written, $this->read( $root ) );

		grant_super_admin( $admin );
		$this->assertSame( 200, $this->remove( 'ewww:root' )->get_status() );
		$this->assertSame( self::WORDPRESS . "\n", $this->read( $root ) );
	}

	/**
	 * The files the test writes.
	 *
	 * @return list<string>
	 */
	protected function paths(): array {
		$root = (string) Rules::file();

		return array(
			$root,
			wp_normalize_path( WP_CONTENT_DIR ) . '/.htaccess',
			Paths::uploads() . '/.htaccess',
			dirname( $root ) . '/conf/imagify.conf',
			wp_normalize_path( WP_CONTENT_DIR ) . '/uploads-webpc/.htaccess',
		);
	}

	/**
	 * A block as core's `insert_with_markers()` writes it.
	 *
	 * @param string $marker The marker.
	 * @param string $lines  Its lines.
	 */
	protected function marked( string $marker, string $lines ): string {
		$file = wp_tempnam( 'iminify-marked' );

		insert_with_markers( $file, $marker, '' === $lines ? '' : explode( "\n", $lines ) );
		/* Core starts a block in an empty file after the empty line it read there. */
		$text = ltrim( $this->read( $file ), "\n" );
		wp_delete_file( $file );

		return $text;
	}

	/**
	 * A block captured from its plugin running.
	 *
	 * @param string $name The fixture's name.
	 */
	protected function fixture( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a fixture of the test suite.
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/others/leftovers/' . $name . '.htaccess' );
	}

	/**
	 * Presses Remove on a leftover through the page's route.
	 *
	 * @param string $id The leftover's id.
	 */
	protected function remove( string $id ): WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/iminify/v1/leftovers' );
		$request->set_param( 'id', $id );

		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * A file's text.
	 *
	 * @param string $path The file.
	 */
	protected function read( string $path ): string {
		clearstatcache( true, $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test site's own file.
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Writes a file.
	 *
	 * @param string $path The file.
	 * @param string $text Its text.
	 */
	protected function put( string $path, string $text ): void {
		file_put_contents( $path, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the test site's own file, put back afterwards.
		clearstatcache( true, $path );
	}
}
