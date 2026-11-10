<?php
/**
 * The images in other folders, against the stub of Iminify's API.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Folders_State;
use Iminify\WordPress\Api\Upload;
use Iminify\WordPress\Cli;
use Iminify\WordPress\Folders\Folders;
use Iminify\WordPress\Folders\Optimizer;
use Iminify\WordPress\Folders\Restorer;
use Iminify\WordPress\Folders\Scanner;
use Iminify\WordPress\Folders\Table;
use Iminify\WordPress\Lifecycle;
use Iminify\WordPress\Local_Settings;
use Iminify\WordPress\Media\Bulk;
use Iminify\WordPress\Media\Optimizer as Media_Optimizer;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Pause;
use Iminify\WordPress\Media\Queue;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Writer;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_REST_Request;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/Wp_Cli_Stub.php';

/**
 * Covers which folders can be chosen and which files read and written, the scan
 * with its resumable walk, new, changed, missing and returning files, a file's
 * group from its file or its backup at its own dimensions, restore, the bulk run
 * over the folders with stop, the plugin turned off and on, uninstall, and the
 * routes' gate.
 */
class FoldersTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	const GROUP = '9d3c5c02-4e1f-4a6b-8d2c-3f5a7b9c1e24';

	const FOLDER = 'iminify-test';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	/**
	 * Folders and links the test made under wp-content, removed afterwards.
	 *
	 * @var list<string>
	 */
	protected $made = array();

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		Pause::lift();
		delete_option( Bulk::OPTION );
		delete_option( Folders::OPTION );
		delete_option( Scanner::OPTION );
		/* The test library makes the table temporary, and its rows outlive a test's rollback on the test site's database. */
		Table::drop();

		/* A run cut short leaves its files behind. */
		foreach ( array( self::FOLDER, self::FOLDER . '-link', 'iminify-elsewhere' ) as $left ) {
			$this->remove( WP_CONTENT_DIR . '/' . $left );
		}
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . self::GROUP . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'DELETE', 'groups/' . self::GROUP, 204, '' );
		as_unschedule_all_actions( '', array(), Media_Optimizer::GROUP );
	}

	public function tear_down(): void {
		$this->api->stop();
		Pause::lift();
		delete_option( Bulk::OPTION );
		as_unschedule_all_actions( '', array(), Media_Optimizer::GROUP );
		Plugin::keys()->forget();
		Site_Record::forget();

		foreach ( array_reverse( $this->made ) as $path ) {
			$this->remove( $path );
		}

		foreach ( Paths::BACKUP_FOLDERS as $folder ) {
			$this->remove( Paths::uploads() . '/' . $folder );
		}

		parent::tear_down();
	}

	public function test_a_folder_is_chosen_only_inside_wp_content_away_from_the_library_the_plugin_tools_and_links(): void {
		$this->folder(
			array(
				'a.jpg'     => 'canola.jpg',
				'sub/c.jpg' => 'canola.jpg',
			)
		);
		$this->dir( self::FOLDER . '/node_modules' );
		$this->dir( 'cache' );
		$this->link( self::FOLDER . '-link', WP_CONTENT_DIR . '/' . self::FOLDER );
		$this->link( self::FOLDER . '/outside.jpg', DIR_TESTDATA . '/images/canola.jpg' );
		$this->link( self::FOLDER . '/linked', WP_CONTENT_DIR . '/' . self::FOLDER . '/sub' );

		$uploads = (string) Folders::relative( Paths::uploads() );

		foreach ( array( $uploads, strtoupper( $uploads ), $uploads . '/2026/11', $uploads . '/' . Paths::FOLDERS_BACKUP_FOLDER . '/themes', 'themes/x/iminify-backup', 'blogs.dir/2/files', (string) Folders::relative( IMINIFY_DIR ), (string) Folders::relative( IMINIFY_DIR ) . '/assets', 'cache/pages', 'Cache', 'ewww', 'themes/x/.git', 'themes/x/vendor/y' ) as $never ) {
			$this->assertNotNull( Folders::forbidden( $never ), $never );
		}

		$this->assertNull( Folders::forbidden( 'themes/x/assets/images' ) );
		$this->assertNull( Folders::forbidden( 'plugins/another-plugin' ) );

		$refused = array(
			'the library'          => array( $uploads, 'folder_forbidden' ),
			'a cache'              => array( 'cache', 'folder_forbidden' ),
			'dependencies'         => array( self::FOLDER . '/node_modules', 'folder_forbidden' ),
			'a link'               => array( self::FOLDER . '-link', 'folder_link' ),
			'a climb out'          => array( self::FOLDER . '/../' . self::FOLDER, 'folder_invalid' ),
			'nothing there'        => array( 'iminify-nothing-here', 'folder_missing' ),
			'wp-content itself'    => array( '/', 'folder_invalid' ),
			'an absolute path out' => array( ABSPATH . 'wp-includes', 'folder_missing' ),
		);

		foreach ( $refused as $case => $named ) {
			$checked = Folders::check( $named[0] );

			$this->assertWPError( $checked, $case );
			$this->assertSame( $named[1], $checked->get_error_code(), $case );
		}

		/* By its absolute path inside wp-content, too. */
		$this->assertSame( self::FOLDER, Folders::check( WP_CONTENT_DIR . '/' . self::FOLDER . '/' ) );

		/* A folder inside a chosen one is needless; a chosen one inside a new one gives way to it. */
		$this->assertSame( self::FOLDER . '/sub', Folders::add( self::FOLDER . '/sub' ) );
		$this->assertSame( self::FOLDER, Folders::add( self::FOLDER ) );
		$this->assertSame( array( self::FOLDER ), Folders::listed() );
		$this->assertSame( 'folder_listed', Folders::add( self::FOLDER . '/sub' )->get_error_code() );

		/* A file is read or written only inside a chosen folder, never through a link. */
		$this->assertSame( WP_CONTENT_DIR . '/' . self::FOLDER . '/a.jpg', Folders::guard( self::FOLDER . '/a.jpg' ) );
		$this->assertNull( Folders::guard( self::FOLDER . '/outside.jpg' ) );
		$this->assertNull( Folders::guard( self::FOLDER . '/../' . self::FOLDER . '/a.jpg' ) );
		$this->assertNull( Folders::guard( self::FOLDER . '-link/a.jpg' ) );
		$this->assertSame( WP_CONTENT_DIR . '/' . self::FOLDER . '/sub/c.jpg', Folders::guard( self::FOLDER . '/sub/c.jpg' ) );
		$this->assertNull( Folders::guard( self::FOLDER . '/linked/c.jpg' ), 'The same file, reached through a link inside the folder.' );
		$this->assertTrue( Folders::remove( self::FOLDER ) );
		$this->assertNull( Folders::guard( self::FOLDER . '/a.jpg' ) );
	}

	public function test_a_scan_finds_each_image_once_leaves_out_copies_links_and_dependencies_and_goes_on_where_it_stopped(): void {
		$this->folder(
			array(
				'a.jpg'              => 'canola.jpg',
				'b.jpg'              => 'test-image.jpg',
				'b.jpg.webp'         => 'canola.jpg',
				'.hidden.jpg'        => 'canola.jpg',
				'notes.txt'          => 'canola.jpg',
				'sub/c.jpg'          => 'canola.jpg',
				'sub/deep/d.JPG'     => 'test-image.jpg',
				'node_modules/e.jpg' => 'canola.jpg',
				'vendor/f.jpg'       => 'canola.jpg',
			)
		);
		$this->link( self::FOLDER . '/link.jpg', WP_CONTENT_DIR . '/' . self::FOLDER . '/a.jpg' );
		$this->link( self::FOLDER . '/linked', WP_CONTENT_DIR . '/' . self::FOLDER . '/sub' );
		Folders::add( self::FOLDER );

		/* A step that ran out of time still reads a folder, and the next goes on from what is left. */
		Scanner::start();
		$steps = 0;

		while ( Scanner::running() ) {
			Scanner::step( 0.0 );
			++$steps;
		}

		$this->assertSame( 3, $steps, 'One folder a step: the folder, sub, sub/deep.' );
		$this->assertSame( array( self::FOLDER . '/a.jpg', self::FOLDER . '/b.jpg', self::FOLDER . '/sub/c.jpg', self::FOLDER . '/sub/deep/d.JPG' ), $this->paths() );
		$this->assertSame( 4, Table::counts()['not_optimized'] );

		/* Scanned again: a new file, one gone, one changed since it was optimized, one that changed and wasn't. */
		$ids = $this->ids();
		$a   = $ids[ self::FOLDER . '/a.jpg' ];
		$c   = $ids[ self::FOLDER . '/sub/c.jpg' ];
		$b   = $ids[ self::FOLDER . '/b.jpg' ];

		Table::update(
			$a,
			array(
				'status'  => Records::OPTIMIZED,
				'written' => array( self::FOLDER . '/a.jpg' => (int) filesize( WP_CONTENT_DIR . '/' . self::FOLDER . '/a.jpg' ) ),
				'result'  => array( 'outputs' => array() ),
			)
		);
		$this->put( 'a.jpg', 'test-image.jpg' );
		$this->put( 'sub/c.jpg', 'test-image.jpg' );
		$this->put( 'g.png', 'canola.jpg' );
		$moved = WP_CONTENT_DIR . '/' . self::FOLDER . '/b.jpg';
		rename( $moved, $moved . '.away' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the file goes for a while.

		Scanner::start();
		Scanner::finish();

		$scan = Scanner::state();
		$this->assertSame( array( 1, 2 ), array( $scan['added'], $scan['changed'] ) );
		$this->assertSame( Records::NOT_OPTIMIZED, Table::get( $a )['status'] );
		$this->assertSame( 'changed', Table::get( $a )['error']['code'] );
		$this->assertSame( array(), Table::get( $a )['written'], 'The plugin no longer wrote what is there.' );
		$this->assertSame( (int) filesize( WP_CONTENT_DIR . '/' . self::FOLDER . '/sub/c.jpg' ), Table::get( $c )['bytes'] );
		$this->assertSame( Table::MISSING, Table::get( $b )['status'] );
		$this->assertSame( 4, Table::counts()['files'], 'A missing file isn\'t counted.' );

		/* It comes back, and a rescan finds it where it stood: restored, so no run takes it. */
		$this->assertSame( Records::NOT_OPTIMIZED, Table::get( $b )['was'] );
		Table::update( $b, array( 'status' => Records::RESTORED ) );
		Scanner::start();
		Scanner::finish();
		Scanner::start();
		Scanner::finish();
		rename( $moved . '.away', $moved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the file comes back.

		/* A file on its way is only stamped as found, never its record, which its run may be writing. */
		$g = $this->ids()[ self::FOLDER . '/g.png' ];
		Table::update(
			$g,
			array(
				'status' => Records::WAITING,
				'bytes'  => 1,
				'group'  => array( 'id' => self::GROUP ),
			)
		);
		Scanner::start();
		Scanner::finish();

		$this->assertSame( Records::RESTORED, Table::get( $b )['status'] );
		$this->assertSame( array( Records::WAITING, 1, self::GROUP ), array( Table::get( $g )['status'], Table::get( $g )['bytes'], Table::get( $g )['group']['id'] ) );
		$this->assertSame( Scanner::state()['mark'], Table::get( $g )['seen'] );
		$this->assertSame( 5, Table::counts()['files'] );
	}

	public function test_a_file_goes_whole_at_its_own_dimensions_with_a_backup_and_restores_and_a_changed_one_goes_from_itself(): void {
		$this->folder( array( 'photo.jpg' => 'canola.jpg' ) );
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$id       = $this->ids()[ self::FOLDER . '/photo.jpg' ];
		$file     = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg';
		$original = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test image.
		$backup   = Paths::uploads() . '/' . Paths::FOLDERS_BACKUP_FOLDER . '/' . self::FOLDER . '/photo.jpg';

		$this->optimize( $id );

		$body = json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true );
		$this->assertTrue( $body['keep_dimensions'] );
		$this->assertSame( 'photo', $body['name'] );
		$this->assertSame( array( 'format', 'size', 'width', 'height' ), array_keys( $body['source'] ) );
		$this->assertSame(
			array(
				array(
					'name'   => 'full',
					'width'  => 640,
					'height' => 480,
					'size'   => strlen( $original ),
				),
			),
			$body['outputs']
		);
		$this->assertArrayNotHasKey( 'priority', $body, 'A press takes the faster lane.' );

		$row = Table::get( $id );
		$this->assertSame( Records::OPTIMIZED, $row['status'] );
		$this->assertSame( $original, file_get_contents( $backup ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.
		$this->assertSame( array( Paths::FOLDERS_BACKUP_FOLDER . '/' . self::FOLDER . '/photo.jpg' ), $row['backups'] );
		$this->assertSame( (int) filesize( $file ), $row['written'][ self::FOLDER . '/photo.jpg' ] );
		$this->assertNotSame( $original, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the written file.
		$this->assertFileExists( $file . '.webp' );
		$this->assertSame( (int) filesize( $file ), $row['copies'][ self::FOLDER . '/photo.jpg.webp' ]['for_bytes'] );
		$this->assertLessThan( Table::saved()['before'], Table::saved()['after'] );

		/* The plugin's output is never a source: a second group goes from the backup. */
		$this->assertSame(
			array(
				'path' => $backup,
				'kind' => Optimizer::BACKUP,
			),
			Optimizer::source( $row )
		);

		$this->assertTrue( Restorer::restore( $id ) );
		$this->assertSame( $original, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the restored file.
		$this->assertFileDoesNotExist( $file . '.webp' );
		$this->assertFileExists( $backup );
		$this->assertSame( Records::RESTORED, Table::get( $id )['status'] );
		$this->assertSame( 0, Bulk::count_candidates( Bulk::OPTIMIZE, null, Bulk::FOLDERS ), 'A file someone restored stays as they chose.' );

		/* Optimized again, then a theme update puts another picture there: it is the file's original now, and the old backup moves aside. */
		$this->optimize( $id, 1 );
		$this->put( 'photo.jpg', 'test-image.jpg' );
		$update = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test image.
		Scanner::start();
		Scanner::finish();

		$row = Table::get( $id );
		$this->assertSame( 'changed', $row['error']['code'] );
		$this->assertSame(
			array(
				'path' => $file,
				'kind' => Optimizer::FILE,
			),
			Optimizer::source( $row )
		);

		$this->optimize( $id, 2 );

		$row = Table::get( $id );
		$this->assertSame( $update, file_get_contents( $backup ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the new backup.
		$this->assertCount( 2, $row['backups'] );
		$this->assertSame( $original, file_get_contents( Paths::absolute( $row['backups'][1] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the old backup, moved aside.

		/* A file changed after the plugin wrote it isn't put back: its backup is an older picture's. */
		$this->put( 'photo.jpg', 'canola.jpg' );
		$this->assertSame( Restorer::CHANGED, Restorer::availability( Table::get( $id ) ) );
		$this->assertSame( 'image_changed', Restorer::restore( $id )->get_error_code() );
	}

	public function test_a_bulk_run_over_the_folders_sends_their_files_on_the_slower_lane_restores_them_and_stop_takes_back_what_waits(): void {
		$this->folder(
			array(
				'one.jpg'   => 'canola.jpg',
				'two.jpg'   => 'canola.jpg',
				'three.jpg' => 'test-image.jpg',
			)
		);
		$library = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		as_unschedule_all_actions( '', array(), Media_Optimizer::GROUP );
		Folders::add( self::FOLDER );

		/* Not while a scan is under way: the run would miss what it hasn't found. */
		Scanner::start();
		$this->assertSame( 'scan_running', Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND, null, Bulk::FOLDERS )->get_error_code() );
		Scanner::finish();
		$this->assertSame( 'invalid_action', Bulk::start( Bulk::FORMATS, Bulk::BACKGROUND, null, Bulk::FOLDERS )->get_error_code() );

		Site_Record::fetch();
		$run = Bulk::start( Bulk::OPTIMIZE, Bulk::BACKGROUND, null, Bulk::FOLDERS );

		$this->assertIsArray( $run );
		$this->assertSame( array( Bulk::FOLDERS, 3, 3 ), array( $run['scope'], $run['total'], $run['sent'] ) );

		foreach ( $this->ids() as $id ) {
			$this->assertSame( Records::QUEUED, Table::get( $id )['status'] );
			$this->assertSame( Media_Optimizer::BULK, Table::get( $id )['trigger'] );
			$this->assertTrue( Media_Optimizer::pending( Optimizer::OPTIMIZE_HOOK, array( $id, Media_Optimizer::BULK ) ) );
		}

		$this->assertFalse( Media_Optimizer::has_run( $library ), 'The library is another run\'s.' );
		$this->assertSame( Records::QUEUED, Records::get( $library )['status'] );
		$this->assertSame( 4, Queue::summary()['working'], 'The upload waiting beside them counts too: they share the service\'s queue.' );

		/* One goes, on the slower lane; stop takes back the two still queued, with nothing counted. */
		$ids = array_values( $this->ids() );
		Optimizer::optimize( $ids[0], Media_Optimizer::BULK );
		$this->assertSame( 'background', json_decode( (string) $this->api->calls( 'POST', 'groups' )[0]['args']['body'], true )['priority'] );

		Bulk::stop();

		$this->assertSame( Records::WAITING, Table::get( $ids[0] )['status'], 'A group already open goes on.' );

		foreach ( array( $ids[1], $ids[2] ) as $id ) {
			$this->assertSame( Records::NOT_OPTIMIZED, Table::get( $id )['status'] );
			$this->assertSame( 'stopped', Table::get( $id )['error']['code'] );
			$this->assertFalse( Optimizer::has_run( $id ) );
		}

		/* Turned off with a file on its way, it waits, saying why; turned on again, the queue is asked to go on for the file alone. */
		Records::update( $library, array( 'status' => Records::NOT_OPTIMIZED ) );
		Scanner::start();
		Lifecycle::deactivate();
		$this->assertSame( Records::PARKED, Table::get( $ids[0] )['status'] );
		$this->assertStringContainsString( 'turned off', Table::get( $ids[0] )['error']['message'] );
		update_option( Lifecycle::ACTIVATED_OPTION, 1 );
		Lifecycle::resume();
		$this->assertIsInt( as_next_scheduled_action( Queue::RESUME_HOOK, array( 0 ), Media_Optimizer::GROUP ) );
		$this->assertIsInt( as_next_scheduled_action( Scanner::HOOK, array(), Media_Optimizer::GROUP ), 'A scan cut off goes on.' );
		Scanner::finish();
		Queue::resume();
		$this->assertSame( Records::QUEUED, Table::get( $ids[0] )['status'] );

		/* Restore all puts every optimized file back while the page is open, without the connection. */
		Optimizer::cancel( $ids[0] );

		foreach ( $ids as $id ) {
			$this->optimized( $id );
		}

		Plugin::keys()->forget();
		delete_option( Bulk::OPTION );
		$this->assertIsArray( Bulk::start( Bulk::RESTORE, Bulk::PAGE, null, Bulk::FOLDERS ) );
		Bulk::step();

		$this->assertSame( Bulk::DONE, Bulk::run()['status'] );
		$this->assertSame( 3, Bulk::run()['sent'] );
		$this->assertSame( 3, Table::counts()['restored'] );

		foreach ( $ids as $id ) {
			$file = Folders::absolute( Table::get( $id )['path'] );

			$this->assertSame( (string) file_get_contents( Paths::backup( Table::get( $id )['path'], Paths::FOLDERS_BACKUP_FOLDER ) ), (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- compares the restored file with its backup.
			$this->assertFileDoesNotExist( $file . '.webp' );
		}
		$this->assertSame( 0, Bulk::counts()['restored'], 'The library counts its own images alone.' );
	}

	public function test_a_refused_file_waits_as_an_image_does_and_one_whose_folder_went_during_the_download_writes_nothing(): void {
		$this->folder(
			array(
				'one.jpg' => 'canola.jpg',
				'two.jpg' => 'canola.jpg',
			)
		);
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();
		$ids = array_values( $this->ids() );

		/* The service's queue is full: the file waits as it asks, and a bulk run holds back with it. */
		update_option(
			Bulk::OPTION,
			array(
				'action' => Bulk::OPTIMIZE,
				'scope'  => Bulk::FOLDERS,
				'mode'   => Bulk::BACKGROUND,
				'status' => Bulk::RUNNING,
				'window' => 20,
			)
		);
		$this->api->on(
			'POST',
			'groups',
			429,
			array(
				'code'    => 'rate_limited',
				'message' => 'Too many.',
			),
			array( 'Retry-After' => '30' )
		);
		Optimizer::queue( $ids[0], Media_Optimizer::BULK );
		Optimizer::optimize( $ids[0], Media_Optimizer::BULK );

		$this->assertSame( array( Records::QUEUED, 'rate_limited' ), array( Table::get( $ids[0] )['status'], Table::get( $ids[0] )['error']['code'] ) );
		$this->assertGreaterThan( time(), Bulk::run()['hold'] );
		$this->assertSame( 10, Bulk::run()['window'], 'Halved, down to one folder file\'s files, never held at a library image\'s.' );

		/* The day is spent: it waits for the next batch. */
		$this->api->on(
			'POST',
			'groups',
			429,
			array(
				'code'    => 'daily_limit_reached',
				'message' => 'Spent.',
			),
			array( 'Retry-After' => '3600' )
		);
		Optimizer::optimize( $ids[0], Media_Optimizer::BULK );

		$this->assertSame( Records::PAUSED, Table::get( $ids[0] )['status'] );
		$this->assertNotNull( Pause::until() );
		Pause::lift();

		/* A key the service stopped: it waits for the connection, and the site reads as disconnected. */
		$this->api->on(
			'POST',
			'groups',
			401,
			array(
				'code'    => 'unauthenticated',
				'message' => 'Unknown key.',
			)
		);
		Optimizer::optimize( $ids[0], Media_Optimizer::BULK );

		$this->assertSame( Records::PARKED, Table::get( $ids[0] )['status'] );
		$this->assertNull( Plugin::keys()->get() );

		/* The folder is taken off the list while a finished group comes down: nothing is written. */
		Plugin::keys()->save( self::KEY );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$before = (string) file_get_contents( Folders::absolute( Table::get( $ids[1] )['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test image.
		Optimizer::optimize( $ids[1], Media_Optimizer::MANUAL );
		$this->finished( $ids[1] );
		$removed = static function ( $preempt, $args, $url ) {
			if ( false !== strpos( (string) $url, '/download' ) ) {
				Folders::remove( self::FOLDER );
			}

			return $preempt;
		};
		add_filter( 'pre_http_request', $removed, 1, 3 );
		Optimizer::check( $ids[1] );
		remove_filter( 'pre_http_request', $removed, 1 );

		$this->assertSame( array( Records::ERROR, 'outside_folders' ), array( Table::get( $ids[1] )['status'], Table::get( $ids[1] )['error']['code'] ) );
		$this->assertSame( $before, file_get_contents( Folders::absolute( Table::get( $ids[1] )['path'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file left as it was.
	}

	public function test_a_copy_another_tool_made_beside_a_file_is_never_written_over_nor_deleted_with_a_restore(): void {
		$this->folder(
			array(
				'photo.jpg'      => 'canola.jpg',
				'photo.jpg.webp' => 'test-image.jpg',
			)
		);
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$id      = $this->ids()[ self::FOLDER . '/photo.jpg' ];
		$foreign = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg.webp';
		$theirs  = (string) file_get_contents( $foreign ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the other tool's copy.

		$this->assertSame( array( self::FOLDER . '/photo.jpg' ), array_keys( $this->ids() ), 'A copy is never an original.' );

		$this->optimize( $id );

		$this->assertSame( $theirs, file_get_contents( $foreign ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the other tool's copy.
		$this->assertSame( array(), Table::get( $id )['copies'] );
		$this->assertSame( 0, Table::get( $id )['result']['skipped'], 'A copy another tool made is kept, never a fault.' );
		$this->assertSame( 1, Table::get( $id )['result']['kept'] );

		$this->assertTrue( Restorer::restore( $id ) );
		$this->assertSame( $theirs, file_get_contents( $foreign ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the other tool's copy.
	}

	public function test_a_copy_is_written_over_only_while_it_has_the_bytes_the_plugin_wrote(): void {
		$this->folder(
			array(
				'photo.jpg'      => 'canola.jpg',
				'photo.jpg.webp' => 'test-image.jpg',
			)
		);
		$copy    = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg.webp';
		$file    = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg';
		$size    = (array) getimagesize( $file );
		$theirs  = (string) file_get_contents( $copy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the copy.
		$editor  = wp_get_image_editor( $file );
		$made    = $editor->save( get_temp_dir() . 'iminify-copy-test.webp', 'image/webp' );
		$webp    = (string) file_get_contents( $made['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
		$zip     = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$archive->addFromString( 'full.webp', $webp );
		$archive->close();
		wp_delete_file( $made['path'] );

		$declared = array(
			'full' => array(
				'file'   => self::FOLDER . '/photo.jpg',
				'size'   => (int) filesize( $file ),
				'width'  => (int) $size[0],
				'height' => (int) $size[1],
			),
		);
		$answer   = array(
			'outputs' => array(
				array(
					'name'    => 'full',
					'format'  => 'webp',
					'file'    => 'full.webp',
					'width'   => (int) $size[0],
					'height'  => (int) $size[1],
					'replace' => true,
				),
			),
		);
		$recorded = static function ( int $bytes ): array {
			return array(
				self::FOLDER . '/photo.jpg.webp' => array(
					'for'    => self::FOLDER . '/photo.jpg',
					'format' => 'webp',
					'bytes'  => $bytes,
					'origin' => Records::ORIGIN,
				),
			);
		};

		/* The record names the copy, but something rewrote it since: it is someone else's now. */
		$put = Writer::put( $declared, $answer, $zip, array(), $recorded( strlen( $theirs ) + 1 ), Folders::root() );

		$this->assertSame( array( self::FOLDER . '/photo.jpg.webp' ), $put['kept'] );
		$this->assertSame( 0, $put['skipped'] );
		$this->assertSame( $theirs, file_get_contents( $copy ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the copy left as it was.

		/* Still as the plugin wrote it: the plugin's own, written over. */
		$put = Writer::put( $declared, $answer, $zip, array(), $recorded( strlen( $theirs ) ), Folders::root() );
		wp_delete_file( $zip );

		$this->assertSame( array(), $put['kept'] );
		$this->assertSame( 0, $put['skipped'] );
		$this->assertSame( $webp, file_get_contents( $copy ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the written copy.
	}

	public function test_a_file_written_by_a_request_that_died_before_saving_its_row_is_still_the_plugins_own(): void {
		$this->folder( array( 'photo.jpg' => 'canola.jpg' ) );
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$id       = $this->ids()[ self::FOLDER . '/photo.jpg' ];
		$file     = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg';
		$original = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a test image.

		Optimizer::queue( $id );
		Optimizer::optimize( $id, Media_Optimizer::MANUAL );
		$this->finished( $id );

		/* The request dies with the file and its copy in place, before the row naming them is saved. What they named is saved before the file took its place. */
		$journaled = null;
		$dies      = static function ( string $query ) use ( $file, &$journaled ): string {
			if ( false === strpos( $query, Table::NAME ) || 0 !== strpos( ltrim( $query ), 'UPDATE' ) ) {
				return $query;
			}

			/* The serialized record names a non-empty `pending`, its quotes escaped in the query. */
			if ( 1 === preg_match( '/pending\\\\?";a:/', $query ) ) {
				$journaled = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file as the journal is saved.
			}

			if ( false !== strpos( $query, "`status` = '" . Records::OPTIMIZED . "'" ) ) {
				throw new \RuntimeException( 'died' );
			}

			return $query;
		};
		add_filter( 'query', $dies );

		try {
			Optimizer::check( $id );
			$this->fail( 'The row was saved.' );
		} catch ( \RuntimeException $died ) {
			$this->assertSame( 'died', $died->getMessage() );
		} finally {
			remove_filter( 'query', $dies );
		}

		$this->assertSame( $original, $journaled );
		$this->assertNotSame( $original, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file written.
		$this->assertFileExists( $file . '.webp' );

		/* Its output is never a source, and its WebP is its own. */
		$this->assertSame( Optimizer::BACKUP, Optimizer::source( (array) Table::get( $id ) )['kind'] ?? null );
		$this->assertSame( Records::ORIGIN, Table::get( $id )['copies'][ self::FOLDER . '/photo.jpg.webp' ]['origin'] ?? null );

		/* The next try finds the file changed under its group, and it goes again from its backup. */
		Optimizer::check( $id );
		Optimizer::optimize( $id, Media_Optimizer::MANUAL );
		$sent = $this->api->calls( 'POST', 'groups/' . self::GROUP . '/source' );

		$this->assertCount( 2, $sent );
		$this->assertSame( $original, file_get_contents( $sent[1]['args'][ Upload::ARGUMENT ]['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file streamed.

		/* Its WebP is written over like any of its own, never kept as another tool's. */
		$this->finished( $id );
		Optimizer::check( $id );
		$row = (array) Table::get( $id );

		$this->assertSame( Records::OPTIMIZED, $row['status'] );
		$this->assertSame( 0, $row['result']['kept'] );
		$this->assertNull( $row['pending'] );
	}

	public function test_the_shell_carries_on_a_scan_under_way_and_finishes_one_before_a_folders_run(): void {
		$this->folder( array( 'photo.jpg' => 'canola.jpg' ) );
		Folders::add( self::FOLDER );
		Scanner::start();
		$mark = Scanner::state()['mark'];

		( new Cli() )->folders( array( 'scan' ), array() );

		$this->assertSame( $mark, Scanner::state()['mark'], 'The scan under way, not a new one.' );
		$this->assertFalse( Scanner::running() );

		Scanner::start();
		Site_Record::fetch();
		( new Cli() )->optimize( array(), array( 'folders' => true, 'background' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a test's flags.

		$this->assertFalse( Scanner::running() );
		$this->assertSame( Bulk::FOLDERS, Bulk::run()['scope'] );
		$this->assertSame( 1, Bulk::run()['sent'] );
	}

	public function test_uninstall_deletes_the_folders_copies_and_backups_as_asked_and_their_table_only_with_both(): void {
		$this->folder( array( 'photo.jpg' => 'canola.jpg' ) );
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$id     = $this->ids()[ self::FOLDER . '/photo.jpg' ];
		$file   = WP_CONTENT_DIR . '/' . self::FOLDER . '/photo.jpg';
		$backup = Paths::uploads() . '/' . Paths::FOLDERS_BACKUP_FOLDER . '/' . self::FOLDER . '/photo.jpg';
		$this->optimized( $id );

		Local_Settings::update( array( 'uninstall_copies' => true ) );
		Lifecycle::uninstall();

		$this->assertFileDoesNotExist( $file . '.webp' );
		$this->assertFileExists( $backup );
		$this->assertTrue( Table::exists(), 'The proof of what the plugin wrote stays while a backup does.' );
		$this->assertSame( Records::OPTIMIZED, Table::get( $id )['status'] );

		Local_Settings::update(
			array(
				'uninstall_copies'  => true,
				'uninstall_backups' => true,
			)
		);
		Lifecycle::uninstall();

		$this->assertFileDoesNotExist( $backup );
		$this->assertFalse( Table::exists() );
		$this->assertDirectoryDoesNotExist( Paths::uploads() . '/' . Paths::FOLDERS_BACKUP_FOLDER );
	}

	public function test_the_routes_are_for_someone_who_may_choose_the_folders_and_name_only_their_files(): void {
		$this->folder( array( 'photo.jpg' => 'canola.jpg' ) );
		$this->dir( 'iminify-elsewhere' );
		copy( DIR_TESTDATA . '/images/canola.jpg', WP_CONTENT_DIR . '/iminify-elsewhere/x.jpg' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/iminify/v1/folders', array( 'folder' => self::FOLDER ) )->get_status() );
		$this->assertSame( array(), Folders::listed() );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		/* On a network the folders are every site's, so only someone who manages the network chooses them. */
		if ( is_multisite() ) {
			wp_set_current_user( $admin );
			$this->assertSame( 403, $this->rest( 'POST', '/iminify/v1/folders', array( 'folder' => self::FOLDER ) )->get_status() );
			grant_super_admin( $admin );
		}

		wp_set_current_user( $admin );
		$added = $this->rest( 'POST', '/iminify/v1/folders', array( 'folder' => self::FOLDER ) );
		$this->assertSame( 200, $added->get_status() );
		$this->assertSame( array( self::FOLDER ), $added->get_data()['listed'] );
		$this->assertSame( self::FOLDER . '/photo.jpg', $added->get_data()['files']['rows'][0]['path'] );
		$this->assertSame( 422, $this->rest( 'POST', '/iminify/v1/folders', array( 'folder' => 'cache' ) )->get_status() );

		/* A file left in the table from a folder no longer chosen answers as missing. */
		Folders::add( 'iminify-elsewhere' );
		Scanner::start();
		Scanner::finish();
		$elsewhere = $this->ids()['iminify-elsewhere/x.jpg'];
		Folders::remove( 'iminify-elsewhere' );

		$this->assertSame( 404, $this->rest( 'POST', '/iminify/v1/folders/files/' . $elsewhere . '/optimize' )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', '/iminify/v1/folders/files/' . $elsewhere . '/restore' )->get_status() );
		$this->assertSame( array( self::FOLDER . '/photo.jpg' ), array_column( Folders_State::build()['files']['rows'], 'path' ) );
	}

	public function test_each_file_shows_its_picture_from_its_own_address_and_none_once_it_is_gone(): void {
		$this->folder( array( 'my photo.jpg' => 'canola.jpg' ) );
		Folders::add( self::FOLDER );
		Scanner::start();
		Scanner::finish();

		$file = WP_CONTENT_DIR . '/' . self::FOLDER . '/my photo.jpg';

		$this->assertSame(
			add_query_arg( 'v', (int) filesize( $file ), content_url( self::FOLDER . '/my%20photo.jpg' ) ),
			Folders_State::build()['files']['rows'][0]['thumb']
		);

		/* A row is only data: a link put where the file was is refused as the guard refuses it everywhere, and a file gone has no picture. */
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- what the test made.
		$this->link( self::FOLDER . '/my photo.jpg', DIR_TESTDATA . '/images/canola.jpg' );

		$this->assertTrue( is_file( $file ) );
		$this->assertNull( Folders_State::build()['files']['rows'][0]['thumb'] );

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the link the test made.

		$this->assertNull( Folders_State::build()['files']['rows'][0]['thumb'] );
	}

	/**
	 * Deletes a file, a link or a folder with everything in it, never following a link.
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

	/**
	 * Makes the test folder with files copied from WordPress's test images.
	 *
	 * @param array<string, string> $files Each file's test image, by its path in the folder.
	 */
	protected function folder( array $files ): void {
		$this->dir( self::FOLDER );

		foreach ( $files as $path => $image ) {
			$this->put( $path, $image );
		}
	}

	/**
	 * Writes a test image into the test folder.
	 *
	 * @param string $path  Its path in the folder.
	 * @param string $image The test image.
	 */
	protected function put( string $path, string $image ): void {
		$target = WP_CONTENT_DIR . '/' . self::FOLDER . '/' . $path;

		wp_mkdir_p( dirname( $target ) );
		copy( DIR_TESTDATA . '/images/' . $image, $target );
		clearstatcache();
	}

	/**
	 * Makes a folder under wp-content.
	 *
	 * @param string $relative Its path there.
	 */
	protected function dir( string $relative ): void {
		$path = WP_CONTENT_DIR . '/' . $relative;

		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
			$this->made[] = $path;
		}
	}

	/**
	 * Makes a link under wp-content.
	 *
	 * @param string $relative Its path there.
	 * @param string $target   What it points at.
	 */
	protected function link( string $relative, string $target ): void {
		symlink( $target, WP_CONTENT_DIR . '/' . $relative );
		$this->made[] = WP_CONTENT_DIR . '/' . $relative;
	}

	/**
	 * The files the table holds, by path.
	 *
	 * @return array<string, int>
	 */
	protected function ids(): array {
		$ids = array();

		foreach ( Table::page( null, 1, 100 )['rows'] as $row ) {
			$ids[ $row['path'] ] = $row['id'];
		}

		return $ids;
	}

	/**
	 * The paths the table holds for the chosen folders, sorted.
	 *
	 * @return list<string>
	 */
	protected function paths(): array {
		$paths = array_keys( $this->ids() );
		sort( $paths );

		return $paths;
	}

	/**
	 * Runs a file's group through to the end against the stub: opened, its source
	 * sent, then finished with the file and its WebP copy, made at a low quality so
	 * they are smaller.
	 *
	 * @param int $id    The file's row id.
	 * @param int $calls The groups opened before this one.
	 */
	protected function optimize( int $id, int $calls = 0 ): void {
		Optimizer::queue( $id );
		Optimizer::optimize( $id, Media_Optimizer::MANUAL );

		$this->assertCount( $calls + 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( Records::WAITING, Table::get( $id )['status'] );

		$this->finished( $id );
		Optimizer::check( $id );

		$this->assertSame( Records::OPTIMIZED, Table::get( $id )['status'] );
	}

	/**
	 * Answers the file's open group as finished, with the file and its WebP copy in
	 * its zip, made at a low quality so they are smaller.
	 *
	 * @param int $id The file's row id.
	 */
	protected function finished( int $id ): void {
		$row    = (array) Table::get( $id );
		$output = $row['group']['outputs']['full'];
		$answer = Api_Stub::fixture( 'group' )['data'];
		$file   = Folders::absolute( $row['path'] );

		$answer['outputs'] = array();
		$entries           = array();

		foreach ( array( 'jpg', 'webp' ) as $format ) {
			$editor = wp_get_image_editor( $file );
			$editor->set_quality( 10 );
			$saved = $editor->save( get_temp_dir() . wp_generate_password( 8, false ) . '.' . $format, 'webp' === $format ? 'image/webp' : 'image/jpeg' );

			$entries[ 'full.' . $format ] = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a made test file.
			wp_delete_file( $saved['path'] );

			$answer['outputs'][] = array(
				'name'    => 'full',
				'format'  => 'jpg' === $format ? 'original' : 'webp',
				'status'  => 'completed',
				'file'    => 'full.' . $format,
				'width'   => $output['width'],
				'height'  => $output['height'],
				'size'    => strlen( $entries[ 'full.' . $format ] ),
				'replace' => true,
			);
		}

		$zip     = wp_tempnam( 'group.zip' );
		$archive = new \ZipArchive();
		$archive->open( $zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $bytes ) {
			$archive->addFromString( $name, $bytes );
		}

		$archive->close();

		$this->api->on( 'GET', 'groups/' . self::GROUP, 200, array( 'data' => $answer ) );
		$this->api->on( 'GET', 'groups/' . self::GROUP . '/download', 200, (string) file_get_contents( $zip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test zip.
		wp_delete_file( $zip );
	}

	/**
	 * Gives a file the record of one the plugin optimized, without the service: its
	 * original in the backup, a smaller copy of it in its place, and a WebP beside it.
	 *
	 * @param int $id The file's row id.
	 */
	protected function optimized( int $id ): void {
		$row    = (array) Table::get( $id );
		$file   = Folders::absolute( $row['path'] );
		$backup = Paths::backup( $row['path'], Paths::FOLDERS_BACKUP_FOLDER );

		wp_mkdir_p( dirname( $backup ) );
		copy( $file, $backup );

		$editor = wp_get_image_editor( $file );
		$editor->set_quality( 10 );
		$editor->save( $file, 'image/jpeg' );
		copy( $file, $file . '.webp' );
		clearstatcache();

		Table::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'bytes'   => (int) filesize( $file ),
				'written' => array( $row['path'] => (int) filesize( $file ) ),
				'copies'  => array(
					$row['path'] . '.webp' => array(
						'for'       => $row['path'],
						'format'    => 'webp',
						'bytes'     => (int) filesize( $file . '.webp' ),
						'for_bytes' => (int) filesize( $file ),
						'origin'    => Records::ORIGIN,
					),
				),
				'backups' => array( (string) Paths::relative( $backup ) ),
				'result'  => array(
					'outputs' => array(
						'full' => array(
							'before' => (int) filesize( $backup ),
							'after'  => (int) filesize( $file ),
						),
					),
				),
			)
		);
	}

	/**
	 * Sends a request to the plugin's REST routes.
	 *
	 * @param string               $method The method.
	 * @param string               $route  The route.
	 * @param array<string, mixed> $params Its parameters.
	 */
	protected function rest( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_do_request( $request );
	}
}
