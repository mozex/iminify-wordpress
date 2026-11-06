<?php
/**
 * The server's rules that send a WebP or AVIF copy in place of the image.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\State;
use Iminify\WordPress\Compat\Caches;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Picture;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Tests\Support\Copies_Fixture;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Copies_Fixture.php';

/**
 * Covers where the rules go in the site's `.htaccess` and what they cover, what
 * the settings show where they can't go in, when they come out and go back,
 * the copies taken off the disk once their file changed while the rules serve
 * them, and the look at whether the server applies them.
 */
class RulesTest extends WP_UnitTestCase {

	use Copies_Fixture;

	const FOLDER = 'iminify-rules-test';

	/**
	 * Another plugin's block, which stays first.
	 */
	const HOTLINK = "# BEGIN Hotlink\nRewriteEngine on\nRewriteCond %{HTTP_REFERER} ^https://thief\\.example/ [NC]\nRewriteRule \\.(?:jpe?g|png)$ - [F]\n# END Hotlink";

	/**
	 * WordPress's own block, as it writes it.
	 */
	const WORDPRESS = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress";

	/**
	 * The site's `.htaccess` as it was before the test.
	 *
	 * @var string|null
	 */
	protected $kept = null;

	/**
	 * What the test's requests to the site itself were asked with.
	 *
	 * @var list<array<string, mixed>>
	 */
	protected $asked = array();

	public function set_up(): void {
		parent::set_up();
		$file = (string) Rules::file();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test site's own file, put back afterwards.
		$this->kept = is_file( $file ) ? (string) file_get_contents( $file ) : null;
		$this->htaccess( self::HOTLINK . "\n\n" . self::WORDPRESS . "\n" );

		delete_option( Folders::OPTION );
		delete_option( Rules::CHECK_OPTION );
		Table::drop();
		$this->remove( WP_CONTENT_DIR . '/' . self::FOLDER );
		Picture::flush();
		/* A purge another test asked for, which shutdown never pays in a test run. */
		Caches::at_shutdown();
		$GLOBALS['is_nginx'] = false; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own server flag, put back as WordPress sets it here.
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		$this->delete_copies();
		$this->remove( WP_CONTENT_DIR . '/' . self::FOLDER );

		$file = (string) Rules::file();

		if ( is_file( $file ) ) {
			chmod( $file, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a test that took the write away gives it back.
		}

		if ( null === $this->kept ) {
			wp_delete_file( $file );
		} else {
			$this->htaccess( $this->kept );
		}

		$GLOBALS['is_nginx'] = false; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own server flag, put back as WordPress sets it here.
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		Caches::at_shutdown();
		parent::tear_down();
	}

	public function test_the_rules_go_in_after_the_file_s_own_or_above_a_network_s_and_cover_the_uploads_folder_and_the_chosen_folders(): void {
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );

		$this->assertSame( $this->with_block( $this->block( array( $this->uploads_pattern() ) ) ), $this->htaccess(), 'After every rule on a single site, above WordPress\'s on a network.' );
		$this->assertSame( 'written', Rules::where() );
		$this->assertNotFalse( has_action( 'shutdown', array( Caches::class, 'at_shutdown' ) ), 'A new way of serving empties the caches.' );

		/* A chosen folder joins them, and leaves them when it's off the list. */
		wp_mkdir_p( WP_CONTENT_DIR . '/' . self::FOLDER );
		Folders::add( self::FOLDER );
		$folder = $this->from_home( WP_CONTENT_DIR . '/' . self::FOLDER );

		$this->assertSame( $this->with_block( $this->block( array( $this->uploads_pattern(), $this->escaped( $folder ) . '/' ) ) ), $this->htaccess() );

		Folders::remove( self::FOLDER );
		$written = $this->htaccess();
		$this->assertStringNotContainsString( self::FOLDER, $written );

		/* A BEGIN line with no END line after it, the block's own or a second one, leaves the file alone, rather than take the rest of it with it. */
		foreach ( array( str_replace( '# END Iminify', '', $written ), $written . "# BEGIN Iminify\nstray\n" ) as $damaged ) {
			$this->htaccess( $damaged );
			$this->assertFalse( Rules::write() );
			$this->assertFalse( Rules::remove() );
			$this->assertSame( 'damaged', Rules::where() );
			$this->assertSame( $damaged, $this->htaccess() );
		}

		$this->htaccess( $written );

		/* Written again, nothing changes; a block found elsewhere moves where it belongs. */
		$this->assertTrue( Rules::write() );
		$this->assertSame( $written, $this->htaccess() );

		$new = $this->block( array( $this->uploads_pattern() ) );

		if ( is_multisite() ) {
			$this->htaccess( self::HOTLINK . "\n" . self::WORDPRESS . "\n\n" . $this->block( array( 'old/' ) ) . "\n" );
			$this->assertTrue( Rules::sync() );
			$this->assertSame( self::HOTLINK . "\n" . $new . "\n\n" . self::WORDPRESS . "\n", $this->htaccess() );
		} else {
			$this->htaccess( self::HOTLINK . "\n" . $this->block( array( 'old/' ) ) . "\n\n" . self::WORDPRESS . "\n" );
			$this->assertTrue( Rules::sync() );
			$this->assertSame( self::HOTLINK . "\n" . self::WORDPRESS . "\n" . $new . "\n\n", $this->htaccess(), 'Another plugin\'s block added after it is moved above it.' );
		}

		/* Serving another way takes it out, and only it. */
		$this->htaccess( $written );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		$this->assertSame( self::HOTLINK . "\n\n" . self::WORDPRESS . "\n", $this->htaccess() );
	}

	public function test_where_the_file_can_t_take_them_the_settings_show_the_lines_to_paste_and_nginx_gets_a_location(): void {
		$this->htaccess( "Options -Indexes\n" );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		$this->stub_answers( 'png' );

		$this->assertSame( "Options -Indexes\n", $this->htaccess(), 'A file that doesn\'t turn rewriting on is left alone.' );

		$state = State::build()['rules'];

		$this->assertSame( 'apache', $state['server'] );
		$this->assertSame( 'no_rewrite', $state['where'] );
		$this->assertSame( Rules::file(), $state['file'] );
		$this->assertSame( $this->block( array( $this->uploads_pattern() ) ), $state['lines'] );
		$this->assertFalse( $state['serves'], 'The server sent the original.' );
		$this->assertFalse( $state['cloudflare'] );

		/* Pasted by hand there, they read as written; a folder added since leaves the file as it is, and it reads as not turning rewriting on. */
		$this->htaccess( $state['lines'] . "\nOptions -Indexes\n" );
		$this->assertSame( 'written', Rules::where() );
		wp_mkdir_p( WP_CONTENT_DIR . '/' . self::FOLDER );
		Folders::add( self::FOLDER );
		$this->assertSame( $state['lines'] . "\nOptions -Indexes\n", $this->htaccess() );
		$this->assertSame( 'no_rewrite', Rules::where() );
		Folders::remove( self::FOLDER );

		$this->htaccess( self::WORDPRESS );
		chmod( (string) Rules::file(), 0444 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a file the site can't write.
		clearstatcache();

		if ( ! wp_is_writable( (string) Rules::file() ) ) {
			$this->assertFalse( Rules::sync() );
			$this->assertSame( 'unwritable', Rules::where() );

			/* One that couldn't take them anyway says why first. */
			chmod( (string) Rules::file(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- written, then taken away again.
			$this->htaccess( "Options -Indexes\n" );
			chmod( (string) Rules::file(), 0444 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a file the site can't write.
			clearstatcache();
			$this->assertSame( 'no_rewrite', Rules::where() );
		}

		chmod( (string) Rules::file(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- given back.
		wp_delete_file( (string) Rules::file() );
		$this->assertSame( 'missing', Rules::where() );

		/* Nginx reads no .htaccess: nothing is written, and the location covers the same places by their addresses. */
		$this->htaccess( self::WORDPRESS );
		$GLOBALS['is_nginx'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own server flag, as it sets it on Nginx.

		$this->assertFalse( Rules::sync() );
		$this->assertSame( self::WORDPRESS, $this->htaccess() );
		$this->assertSame( 'server', Rules::where() );
		$nginx    = (string) Rules::nginx();
		$location = (string) substr( $nginx, (int) strpos( $nginx, "\nlocation" ) );

		/* The formats are read at the server's level: an if inside the location would skip try_files. */
		$this->assertStringStartsWith( "set \$iminify_avif \".iminify-none\";\nif (\$http_accept ~* \"image/avif\") {\n\tset \$iminify_avif \".avif\";\n}\n", $nginx );
		$this->assertStringStartsWith( "\nlocation ~* ^/(?:" . $this->uploads_pattern() . ').+\.(?:jpe?g|jpe|png|gif|webp)$ {', $location );
		$this->assertStringNotContainsString( 'if (', $location );
		$this->assertStringEndsWith( "\ttry_files \$uri\$iminify_avif \$uri\$iminify_webp \$uri =404;\n}", $location );
		$this->assertSame( 'nginx', State::build()['rules']['server'] );
		$this->assertSame( Rules::nginx(), State::build()['rules']['nginx'] );

		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		$this->assertNull( State::build()['rules'] );
	}

	public function test_turning_the_plugin_off_takes_the_rules_out_and_turning_it_on_puts_them_back(): void {
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		$written = $this->htaccess();

		Lifecycle::deactivate();
		$this->assertSame( self::HOTLINK . "\n\n" . self::WORDPRESS . "\n", $this->htaccess() );

		/* An image changed while the plugin was off, which it never saw. */
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['medium'], 'webp' ) ) );
		$this->append( $files['medium'] );

		Lifecycle::activate();
		Lifecycle::resume();
		$this->assertSame( $written, $this->htaccess() );
		$this->assertFileDoesNotExist( $files['medium'] . '.webp', 'Images changed while it was off lose their copies.' );

		Lifecycle::uninstall();
		$this->assertSame( self::HOTLINK . "\n\n" . self::WORDPRESS . "\n", $this->htaccess() );
	}

	public function test_with_the_rules_on_a_changed_file_loses_the_copies_made_for_its_old_bytes_and_keeps_every_other(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'avif' ), array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ), array( $files['large'], 'webp' ), array( $files['thumbnail'], 'webp' ) ) );

		/* Turned on before anything changes, so what follows is the change itself at work, not the pass. */
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		$this->assertFileExists( $files['full'] . '.avif' );

		/* Another tool's copy beside a size, which no record names. */
		$theirs = $files['medium_large'] . '.webp';
		file_put_contents( $theirs, 'theirs' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		$this->files[] = $theirs;

		/* A size whose file changed while another tool wrote over its copy, and one whose file is only in a bucket now. */
		$this->append( $files['thumbnail'] );
		$this->append( $files['medium_large'] );
		file_put_contents( $files['thumbnail'] . '.webp', 'their bytes now' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		wp_delete_file( $files['large'] );

		$this->replace_in_place( $id, $files['full'] );

		$copies = array_keys( Records::get( $id )['copies'] );
		sort( $copies );

		$this->assertFileDoesNotExist( $files['full'] . '.avif' );
		$this->assertFileDoesNotExist( $files['full'] . '.webp' );
		$this->assertFileExists( $files['medium'] . '.webp' );
		$this->assertFileExists( $files['large'] . '.webp', "Its file isn't on the disk to say it changed." );
		$this->assertFileExists( $files['thumbnail'] . '.webp', "Another tool's bytes stay." );
		$this->assertFileExists( $theirs );
		$this->assertSame( array( (string) Paths::relative( $files['large'] . '.webp' ), (string) Paths::relative( $files['medium'] . '.webp' ) ), $copies, "The thumbnail's copy is forgotten." );

		/* Picture tags never serve a copy whose file changed, so with them a change takes no copy off. */
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_PICTURE ) );
		$this->append( $files['medium'] );
		$this->replace_in_place( $id, $files['full'] );
		$this->assertFileExists( $files['medium'] . '.webp' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_look_from_the_settings_page_carries_the_site_s_password_to_the_site_alone(): void {
		define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own flag for a REST request, as it sets it.
		$_SERVER['PHP_AUTH_USER'] = 'editor';
		$_SERVER['PHP_AUTH_PW']   = 'secret';
		$this->stub_answers( 'png' );

		Rules::check();

		$this->assertSame( 'Basic ' . base64_encode( 'editor:secret' ), $this->asked[0]['headers']['Authorization'] ?? null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- what HTTP basic auth sends.
	}

	public function test_the_pass_takes_off_the_copies_already_stale_and_a_scan_those_of_a_file_it_finds_changed(): void {
		$id    = $this->attachment();
		$files = $this->files_of( $id );
		$this->copies( $id, array( array( $files['full'], 'webp' ), array( $files['medium'], 'webp' ) ) );

		$this->append( $files['medium'] );
		$this->put( 'stale.jpg' );
		$this->put( 'fresh.jpg' );
		$this->put( 'scanned.jpg' );
		Folders::add( self::FOLDER );
		$stale   = $this->row( 'stale.jpg' );
		$fresh   = $this->row( 'fresh.jpg' );
		$scanned = $this->row( 'scanned.jpg' );
		$this->append( WP_CONTENT_DIR . '/' . self::FOLDER . '/stale.jpg' );

		/* Neither the pass nor a scan takes a copy off while the site serves another way. */
		Rules::sweep( 'library', 0 );
		Rules::sweep( 'folders', 0 );
		$this->scan();
		$this->assertFileExists( $files['medium'] . '.webp' );
		$this->assertFileExists( WP_CONTENT_DIR . '/' . self::FOLDER . '/stale.jpg.webp' );
		$this->assertCount( 1, Table::get( $stale )['copies'] );

		/* Turned on, the first page of each runs at once, so a site whose background never runs is dealt with too. */
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );

		$this->assertFileDoesNotExist( $files['medium'] . '.webp' );
		$this->assertFileExists( $files['full'] . '.webp' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/' . self::FOLDER . '/stale.jpg.webp' );
		$this->assertSame( array(), Table::get( $stale )['copies'] );
		$this->assertFileExists( WP_CONTENT_DIR . '/' . self::FOLDER . '/fresh.jpg.webp' );
		$this->assertCount( 1, Table::get( $fresh )['copies'] );

		/* A scan finding a file changed takes its copy off too. */
		$this->append( WP_CONTENT_DIR . '/' . self::FOLDER . '/scanned.jpg' );
		$this->scan();

		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/' . self::FOLDER . '/scanned.jpg.webp' );
		$this->assertSame( array(), Table::get( $scanned )['copies'] );
		$this->assertFileExists( WP_CONTENT_DIR . '/' . self::FOLDER . '/fresh.jpg.webp' );
	}

	public function test_the_look_at_the_site_s_answers_tells_a_copy_from_the_original_and_takes_the_rules_out_on_an_error(): void {
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		$probe = Paths::uploads() . '/' . Rules::PROBE;

		$this->stub_answers( 'avif', array( 'cf-ray' => '8f1-IST' ) );
		$check = Rules::check();

		$this->assertTrue( $check['serves'] );
		$this->assertTrue( $check['cloudflare'] );
		$this->assertFalse( $check['refused'] );
		$this->assertSame( array( '*/*', 'image/avif,image/webp,*/*' ), array_column( array_column( $this->asked, 'headers' ), 'Accept' ) );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ) . '/' . Rules::PROBE, '#' ) . '\?iminify-check=\d+-plain$#', (string) $this->asked[0]['url'], 'Each request has an address of its own, so a cache answers neither from the other.' );
		$this->assertStringEndsWith( '-avif', (string) $this->asked[1]['url'] );
		$this->assertSame( array( 0, 0 ), array_column( $this->asked, 'redirection' ) );
		$this->assertFileDoesNotExist( $probe );
		$this->assertFileDoesNotExist( $probe . '.avif' );

		/* Kept until it is old, or until the rules stand elsewhere. */
		$this->stub_answers( 'png' );
		$this->assertTrue( Rules::last_check()['serves'] );
		$this->assertSame( array(), $this->asked );

		Rules::remove();
		$this->assertFalse( Rules::last_check()['serves'] );
		$this->assertCount( 2, $this->asked );
		Rules::write();

		$this->assertFalse( Rules::check()['serves'] );

		$this->stub_answers( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		$this->assertNull( Rules::check()['serves'] );

		/* The site's password goes to the site itself, never to a CDN the uploads folder's address names. */
		$_SERVER['PHP_AUTH_USER'] = 'editor';
		$_SERVER['PHP_AUTH_PW']   = 'secret';
		$this->stub_answers( 'png' );
		Rules::check();
		$this->assertArrayHasKey( 'Authorization', $this->asked[0]['headers'] );

		$cdn = static function ( array $uploads ): array {
			$uploads['baseurl'] = 'https://cdn.example.net/uploads';

			return $uploads;
		};
		add_filter( 'upload_dir', $cdn );
		$this->stub_answers( 'png' );
		Rules::check();
		remove_filter( 'upload_dir', $cdn );
		$this->assertStringStartsWith( 'https://cdn.example.net/', (string) $this->asked[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->asked[0]['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->asked[1]['headers'] );

		/* A server error with no rules in is the server's own. */
		Rules::remove();
		$this->stub_answers( 'error' );
		$this->assertFalse( Rules::check()['refused'] );
		Rules::write();

		/* A server that fails once the rules are in refuses them. */
		$this->stub_answers( 'error' );
		$check = Rules::check();

		$this->assertTrue( $check['refused'] );
		$this->assertFalse( $check['serves'] );
		$this->assertStringNotContainsString( '# BEGIN ' . Rules::MARKER, $this->htaccess() );

		/* A refusal holds: the settings page neither writes them again nor looks again, until someone saves. */
		$this->stub_answers( 'avif' );
		$state = Rules::state();

		$this->assertTrue( $state['refused'] );
		$this->assertSame( array(), $this->asked );
		$this->assertStringNotContainsString( '# BEGIN ' . Rules::MARKER, $this->htaccess() );
		$this->assertFalse( Rules::sync() );

		/* Saving the settings asks for them again. */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/iminify/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'local' => array( 'delivery' => Local_Settings::DELIVERY_RULES ) ) ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertStringContainsString( '# BEGIN ' . Rules::MARKER, $this->htaccess() );
		$this->assertTrue( Rules::last_check()['serves'] );
		$this->assertTrue( Rules::sync() );
	}

	public function test_the_file_is_beside_the_home_address_for_wordpress_in_a_folder_of_its_own(): void {
		$abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$folder  = wp_basename( untrailingslashit( $abspath ) );

		$this->assertSame( $abspath . '.htaccess', Rules::file() );

		/* The test site sets its address in wp-config.php, which the option filter would put back. */
		add_filter(
			'pre_option_siteurl',
			static function () use ( $home, $folder ): string {
				return $home . '/' . $folder;
			}
		);
		$this->assertSame( dirname( untrailingslashit( $abspath ) ) . '/.htaccess', Rules::file(), 'The folder above WordPress\'s own.' );

		remove_all_filters( 'pre_option_siteurl' );
		add_filter(
			'pre_option_siteurl',
			static function () use ( $home ): string {
				return $home . '/elsewhere';
			}
		);
		$this->assertNull( Rules::file(), 'A folder WordPress isn\'t in can\'t be told.' );

		remove_all_filters( 'pre_option_siteurl' );
		add_filter(
			'pre_option_siteurl',
			static function () use ( $home, $folder ): string {
				return $home . '/' . substr( $folder, 1 );
			}
		);
		$this->assertNull( Rules::file(), 'The end of a folder\'s name is no folder of its own.' );
		remove_all_filters( 'pre_option_siteurl' );
	}

	public function test_each_site_of_a_network_keeps_a_block_for_its_own_uploads_folder(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network only.' );
		}

		/* The pass would need the other site's Action Scheduler tables, which this test doesn't make. */
		remove_all_actions( 'add_option_' . Local_Settings::OPTION );
		remove_all_actions( 'update_option_' . Local_Settings::OPTION );

		$site = (int) self::factory()->blog->create( array( 'path' => '/rules-' . wp_generate_password( 8, false ) . '/' ) );
		Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
		$this->assertTrue( Rules::write() );

		switch_to_blog( $site );

		try {
			Local_Settings::update( array( 'delivery' => Local_Settings::DELIVERY_RULES ) );
			$this->assertTrue( Rules::write() );
			$theirs = $this->from_home( Paths::uploads() );
			$this->assertSame( 'Iminify site ' . $site, Rules::marker() );
		} finally {
			restore_current_blog();
		}

		$file = $this->htaccess();

		$this->assertStringContainsString( '# BEGIN ' . Rules::MARKER . "\n", $file );
		$this->assertStringContainsString( 'RewriteRule ^(?:' . $this->escaped( $this->from_home( Paths::uploads() ) ) . '/(?!sites/[0-9]+/)).+', $file, 'The main site leaves the other sites\' folders out.' );
		$this->assertStringContainsString( '# BEGIN Iminify site ' . $site . "\n", $file );
		$this->assertStringContainsString( 'RewriteRule ^(?:' . $this->escaped( $theirs ) . '/).+', $file );
		$this->assertStringEndsWith( self::WORDPRESS . "\n", $file );

		/* Taking the main site's out leaves the other's. */
		Rules::remove();
		$this->assertStringNotContainsString( '# BEGIN ' . Rules::MARKER . "\n", $this->htaccess() );
		$this->assertStringContainsString( '# BEGIN Iminify site ' . $site . "\n", $this->htaccess() );
	}

	/**
	 * The block the plugin writes for these places, as `Rules::lines()` builds it.
	 *
	 * @param list<string> $places Each place's pattern.
	 */
	protected function block( array $places ): string {
		$under = '^(?:' . implode( '|', $places ) . ')';

		return implode(
			"\n",
			array(
				'# BEGIN Iminify',
				'# The directives (lines) between "BEGIN Iminify" and "END Iminify" are written by',
				'# the Iminify Image Optimizer plugin while it serves WebP and AVIF through these',
				'# rules. Any changes to them will be overwritten.',
				'<IfModule mod_mime.c>',
				'AddType image/avif .avif',
				'AddType image/webp .webp',
				'</IfModule>',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteCond %{HTTP_ACCEPT} ^',
				'RewriteRule ' . $under . '.+\.(?:jpe?g|jpe|png|gif|webp)$ - [NC]',
				'RewriteCond %{HTTP_ACCEPT} image/avif [NC]',
				'RewriteCond %{REQUEST_FILENAME} -f',
				'RewriteCond %{REQUEST_FILENAME}.avif -f',
				'RewriteRule ' . $under . '.+\.(?:jpe?g|jpe|png|gif|webp)$ %{REQUEST_URI}.avif [NC,T=image/avif,L]',
				'RewriteCond %{HTTP_ACCEPT} image/webp [NC]',
				'RewriteCond %{REQUEST_FILENAME} -f',
				'RewriteCond %{REQUEST_FILENAME}.webp -f',
				'RewriteRule ' . $under . '.+\.(?:jpe?g|jpe|png|gif)$ %{REQUEST_URI}.webp [NC,T=image/webp,L]',
				'</IfModule>',
				'# END Iminify',
			)
		);
	}

	/**
	 * Reads the site's `.htaccess`, or writes it.
	 *
	 * @param string|null $text What to write.
	 */
	protected function htaccess( ?string $text = null ): string {
		$file = (string) Rules::file();

		if ( null !== $text ) {
			file_put_contents( $file, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the test site's own file, put back afterwards.
		}

		clearstatcache( true, $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test site's own file.
		return is_file( $file ) ? (string) file_get_contents( $file ) : '';
	}

	/**
	 * A path from the folder the `.htaccess` is in.
	 *
	 * @param string $absolute The path.
	 */
	protected function from_home( string $absolute ): string {
		return trim( substr( wp_normalize_path( $absolute ), strlen( dirname( (string) Rules::file() ) . '/' ) ), '/' );
	}

	/**
	 * The test's file with a block where the plugin puts it: after every rule on a
	 * single site, above WordPress's on a network.
	 *
	 * @param string $block The block.
	 */
	protected function with_block( string $block ): string {
		return is_multisite()
			? self::HOTLINK . "\n\n" . $block . "\n\n" . self::WORDPRESS . "\n"
			: self::HOTLINK . "\n\n" . self::WORDPRESS . "\n" . $block . "\n\n";
	}

	/**
	 * The uploads folder's pattern, as the rules write it for this site.
	 */
	protected function uploads_pattern(): string {
		return $this->escaped( $this->from_home( Paths::uploads() ) ) . '/' . ( is_multisite() && is_main_site() ? '(?!sites/[0-9]+/)' : '' );
	}

	/**
	 * A path with its dots taken literally, as a rule writes it.
	 *
	 * @param string $path The path.
	 */
	protected function escaped( string $path ): string {
		return str_replace( '.', '\.', $path );
	}

	/**
	 * A file written over in place, as Enable Media Replace or the image editor
	 * does, its new size saved in the metadata.
	 *
	 * @param int    $id   The attachment's id.
	 * @param string $file The file.
	 */
	protected function replace_in_place( int $id, string $file ): void {
		$this->append( $file );

		$metadata             = wp_get_attachment_metadata( $id );
		$metadata['filesize'] = (int) filesize( $file );
		wp_update_attachment_metadata( $id, $metadata );
	}

	/**
	 * Scans the chosen folders to the end.
	 */
	protected function scan(): void {
		Scanner::start();

		while ( Scanner::running() ) {
			Scanner::step( 0.0 );
		}
	}

	/**
	 * Changes a file's bytes.
	 *
	 * @param string $file The file.
	 */
	protected function append( string $file ): void {
		file_put_contents( $file, 'changed', FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		clearstatcache( true, $file );
	}

	/**
	 * Writes a test image into the test folder.
	 *
	 * @param string $name Its name.
	 */
	protected function put( string $name ): void {
		wp_mkdir_p( WP_CONTENT_DIR . '/' . self::FOLDER );
		copy( DIR_TESTDATA . '/images/canola.jpg', WP_CONTENT_DIR . '/' . self::FOLDER . '/' . $name );
	}

	/**
	 * A file of the test folder with its row and a WebP copy recorded as the plugin's own.
	 *
	 * @param string $name The file's name.
	 * @return int The row's id.
	 */
	protected function row( string $name ): int {
		$path = self::FOLDER . '/' . $name;
		$file = WP_CONTENT_DIR . '/' . $path;

		Table::insert( array( $path => (int) filesize( $file ) ), 1 );
		$id = (int) Table::by_paths( array( $path ) )[ $path ]['id'];

		file_put_contents( $file . '.webp', 'webp bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
		clearstatcache();

		Table::update(
			$id,
			array(
				'status' => Records::OPTIMIZED,
				'copies' => array(
					$path . '.webp' => array(
						'for'       => $path,
						'format'    => 'webp',
						'bytes'     => (int) filesize( $file . '.webp' ),
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( $file ),
					),
				),
			)
		);

		return $id;
	}

	/**
	 * Answers the requests to the site itself: the AVIF copy, the original, a
	 * server error for both, or a failed request.
	 *
	 * @param string|WP_Error       $answer  `avif`, `png`, `error`, or the failure.
	 * @param array<string, string> $headers Headers every answer carries.
	 */
	protected function stub_answers( $answer, array $headers = array() ): void {
		remove_all_filters( 'pre_http_request' );
		$this->asked = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $answer, $headers ) {
				$this->asked[] = array_merge( $args, array( 'url' => $url ) );

				if ( $answer instanceof WP_Error ) {
					return $answer;
				}

				$avif = false !== strpos( (string) $args['headers']['Accept'], 'image/avif' );

				return array(
					'headers'  => $headers,
					'body'     => 'error' === $answer ? 'Internal Server Error' : ( 'avif' === $answer && $avif ? Rules::PROBE_BYTES['avif'] : Rules::PROBE_BYTES['png'] ),
					'response' => array(
						'code'    => 'error' === $answer ? 500 : 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Deletes a folder the test made.
	 *
	 * @param string $folder The folder.
	 */
	protected function remove( string $folder ): void {
		if ( ! is_dir( $folder ) ) {
			return;
		}

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a test's own folder.
		}

		rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a test's own folder.
	}
}
