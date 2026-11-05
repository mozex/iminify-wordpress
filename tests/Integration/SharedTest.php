<?php
/**
 * Attachments that share their files, as WPML and Polylang translate an image.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Media\Hooks;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Originals;
use Iminify\WordPress\Media\Paths;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Media\Shared;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use WP_UnitTestCase;

/**
 * Covers a translation that names the same files: it never sends them, never
 * moves or deletes their backup, sends its changes through the attachment that
 * holds the work, and takes that work over when that one is deleted.
 */
class SharedTest extends WP_UnitTestCase {

	const KEY = '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r';

	/**
	 * The stand-in API.
	 *
	 * @var Api_Stub
	 */
	protected $api;

	/**
	 * The files a translation names, which the translation plugin keeps from deletion.
	 *
	 * @var list<string>
	 */
	protected $named = array();

	public function set_up(): void {
		parent::set_up();
		$this->api = ( new Api_Stub() )->start();
		Plugin::keys()->save( self::KEY );
		Site_Record::forget();
		$this->api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$this->api->on( 'POST', 'groups', 201, Api_Stub::fixture( 'group-open' ) );
		$this->api->on( 'POST', 'groups/' . Api_Stub::fixture( 'group-open' )['data']['id'] . '/source', 202, Api_Stub::fixture( 'group-open' ) );
		add_filter( 'wp_delete_file', array( $this, 'keep_named' ) );
	}

	public function tear_down(): void {
		$this->api->stop();
		Shared::forget();
		Plugin::keys()->forget();
		Site_Record::forget();
		if ( is_dir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER ) ) {
			$this->rmdir( Paths::uploads() . '/' . Paths::BACKUP_FOLDER );
		}
		parent::tear_down();
	}

	public function test_a_translation_never_sends_the_files_or_touches_their_backup_and_its_changes_go_through_the_holder(): void {
		$holder   = $this->attachment();
		$attached = (string) get_attached_file( $holder );
		$relative = (string) Paths::relative( $attached );
		$original = (string) file_get_contents( $attached ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test image.

		Optimizer::optimize( $holder, Optimizer::AUTOMATIC );
		$this->finish( $holder, $attached );

		$translation = $this->translation( $holder );

		$this->assertSame( array( $translation ), Shared::others( $holder ) );
		$this->assertSame( $holder, Shared::holder( $translation ) );
		$this->assertSame( 'shared', Optimizer::why_not( $translation )['code'] ?? null );
		$this->assertNull( Originals::find( $translation, (array) wp_get_attachment_metadata( $translation ), null ) );

		/* A bulk run reaching the translation sets it aside, sends nothing, and the backup stays the original. */
		Optimizer::optimize( $translation, Optimizer::BULK );

		$this->assertSame( Records::SKIPPED, Records::get( $translation )['status'] );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( $original, file_get_contents( Paths::backup( $relative ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the backup.
		$this->assertSame( array( Paths::BACKUP_FOLDER . '/' . $relative ), Records::get( $holder )['backups'] );

		/* Its files regenerated queue the holder, never itself. */
		Optimizer::cancel( $holder );
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $holder, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		Hooks::generated( wp_get_attachment_metadata( $translation ), $translation );

		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $holder, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $translation, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		/* Deleting the translation deletes nothing the files still need, even when a translation plugin copied the holder's record onto it. */
		Records::update(
			$translation,
			array(
				'copies'  => Records::get( $holder )['copies'],
				'backups' => Records::get( $holder )['backups'],
			)
		);
		wp_delete_attachment( $translation, true );

		$this->assertFileExists( Paths::backup( $relative ) );
		$this->assertFileExists( $attached . '.webp' );
		$this->assertSame( array( Paths::BACKUP_FOLDER . '/' . $relative ), Records::get( $holder )['backups'] );
	}

	public function test_a_translation_written_where_no_hook_saw_it_is_still_never_sent(): void {
		global $wpdb;

		$holder   = $this->attachment();
		$attached = (string) get_attached_file( $holder );
		$relative = (string) Paths::relative( $attached );

		Optimizer::optimize( $holder, Optimizer::AUTOMATIC );
		$this->finish( $holder, $attached );
		$backup = (string) md5_file( Paths::backup( $relative ) );

		/* The answer kept for this request says nobody shares the file; then a translation is written straight into the tables. */
		$this->assertSame( $holder, Shared::holder( $holder ) );
		$translation = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Translation',
				'post_status'    => 'inherit',
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writes past every hook, as a database import does.
			$wpdb->postmeta,
			array(
				'post_id'    => $translation,
				'meta_key'   => '_wp_attached_file', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the row a translation plugin writes.
				'meta_value' => $relative, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the row a translation plugin writes.
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writes past every hook, as a database import does.
			$wpdb->postmeta,
			array(
				'post_id'    => $translation,
				'meta_key'   => '_wp_attachment_metadata', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the row a translation plugin writes.
				'meta_value' => maybe_serialize( wp_get_attachment_metadata( $holder, true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the row a translation plugin writes.
			)
		);
		wp_cache_delete( $translation, 'post_meta' );

		/* The run that would write the files asks afresh, so the translation is set aside and the holder's backup stays its original. */
		Optimizer::optimize( $translation, Optimizer::BULK );

		$this->assertSame( 'shared', Records::get( $translation )['error']['code'] ?? null );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( $backup, md5_file( Paths::backup( $relative ) ) );
		$this->assertSame( array( Paths::BACKUP_FOLDER . '/' . $relative ), Records::get( $holder )['backups'] );
		$this->assertSame( array(), Records::get( $translation )['backups'] );

		/* A change no hook saw stays out of the request's answer until the database is asked afresh. */
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => dirname( $relative ) . '/renamed.jpg' ),
			array(
				'post_id'  => $holder,
				'meta_key' => '_wp_attached_file',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- writes past every hook, as a database import does.
		wp_cache_delete( $holder, 'post_meta' );

		$this->assertSame( array( $holder ), Shared::others( $translation ) );
		$this->assertSame( array(), Shared::others( $translation, true ) );
	}

	public function test_a_kept_answer_missing_the_sharer_that_holds_the_work_never_lets_the_run_send(): void {
		global $wpdb;

		$image = $this->attachment();
		Optimizer::cancel( $image );
		Records::update( $image, array( 'status' => Records::NOT_OPTIMIZED ) );
		$relative = (string) get_post_meta( $image, '_wp_attached_file', true );

		/* The request's answer says the image alone names its file; then another attachment holding work on it is written past every hook. */
		$this->assertSame( $image, Shared::holder( $image ) );
		$worker = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Holder',
				'post_status'    => 'inherit',
			)
		);
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $worker,
				'meta_key'   => '_wp_attached_file',
				'meta_value' => $relative,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- writes past every hook, as a database import does.
		Records::update( $worker, array( 'backups' => array( Paths::BACKUP_FOLDER . '/' . $relative ) ) );

		Optimizer::optimize( $image, Optimizer::BULK );

		$this->assertSame( 'shared', Records::get( $image )['error']['code'] ?? null );
		$this->assertCount( 0, $this->api->calls( 'POST', 'groups' ) );
	}

	public function test_a_translation_left_on_its_holders_old_file_never_sends_the_holders_output(): void {
		$holder   = $this->attachment();
		$attached = (string) get_attached_file( $holder );
		$relative = (string) Paths::relative( $attached );

		Optimizer::optimize( $holder, Optimizer::AUTOMATIC );
		$this->finish( $holder, $attached );
		$backup      = (string) md5_file( Paths::backup( $relative ) );
		$translation = $this->translation( $holder );

		/* The holder is edited under a new name, and the translation plugin leaves the translation on the old file. */
		copy( $attached, dirname( $attached ) . '/edited.jpg' );
		update_attached_file( $holder, dirname( $attached ) . '/edited.jpg' );

		$this->assertSame( $translation, Shared::holder( $translation ) );
		$this->assertNull( Originals::find( $translation, (array) wp_get_attachment_metadata( $translation ), null ) );

		Optimizer::optimize( $translation, Optimizer::BULK );

		$this->assertSame( 'no_original', Records::get( $translation )['error']['code'] ?? null );
		$this->assertCount( 1, $this->api->calls( 'POST', 'groups' ) );
		$this->assertSame( $backup, md5_file( Paths::backup( $relative ) ) );
		$this->assertSame( array(), Records::get( $translation )['backups'] );
	}

	public function test_only_the_very_same_file_name_is_shared(): void {
		$holder   = $this->attachment();
		$relative = (string) get_post_meta( $holder, '_wp_attached_file', true );
		$other    = $this->attachment();

		/* Another case is another file on most servers, whatever the database's collation says. */
		update_attached_file( $other, Paths::uploads() . '/' . strtoupper( $relative ) );
		$this->assertSame( array(), Shared::others( $holder ) );

		/* A translation renamed by an edit no longer shares the files. */
		$translation = $this->translation( $holder );
		$this->assertSame( array( $translation ), Shared::others( $holder ) );

		update_attached_file( $translation, Paths::uploads() . '/' . dirname( $relative ) . '/elsewhere.jpg' );
		$this->assertSame( array(), Shared::others( $holder ) );

		/* A page of images is answered in one query, the same answers, and drawing each asks nothing more. */
		$second = $this->translation( $holder );
		Shared::forget();
		Shared::prime( array( $holder, $other, $translation, $second ) );

		global $wpdb;
		$queries = $wpdb->num_queries;

		$this->assertSame( array( $second ), Shared::others( $holder ) );
		$this->assertSame( array(), Shared::others( $other ) );
		$this->assertSame( array(), Shared::others( $translation ) );
		$this->assertSame( array( $holder ), Shared::others( $second ) );
		$this->assertSame( $queries, $wpdb->num_queries );
		$this->assertSame( array( $second ), Shared::others( $holder, true ) );
	}

	public function test_deleting_the_holder_hands_its_work_to_a_translation_and_keeps_the_files(): void {
		$holder   = $this->attachment();
		$attached = (string) get_attached_file( $holder );
		$relative = (string) Paths::relative( $attached );

		Optimizer::optimize( $holder, Optimizer::AUTOMATIC );
		$this->finish( $holder, $attached );
		$record = Records::get( $holder );

		$first  = $this->translation( $holder );
		$second = $this->translation( $holder );

		wp_delete_attachment( $holder, true );

		$taken = Records::get( $first );

		$this->assertFileExists( Paths::backup( $relative ) );
		$this->assertFileExists( $attached . '.webp' );
		$this->assertSame( $record['written'], $taken['written'] );
		$this->assertSame( $record['copies'], $taken['copies'] );
		$this->assertSame( $record['backups'], $taken['backups'] );
		$this->assertSame( Records::OPTIMIZED, $taken['status'] );
		$this->assertSame( Records::OPTIMIZED, get_post_meta( $first, Records::STATUS_META, true ) );
		$this->assertSame( $first, Shared::holder( $second ) );
		$this->assertNull( Optimizer::why_not( $first ) );
		$this->assertSame( 'shared', Optimizer::why_not( $second )['code'] ?? null );
	}

	public function test_a_holder_deleted_on_its_way_hands_the_send_to_the_next(): void {
		$holder      = $this->attachment();
		$translation = $this->translation( $holder );

		/* The upload queued the holder. */
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $holder, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );

		wp_delete_attachment( $holder, true );

		$this->assertSame( Records::QUEUED, Records::get( $translation )['status'] );
		$this->assertNotFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $translation, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
		$this->assertFalse( as_next_scheduled_action( Optimizer::OPTIMIZE_HOOK, array( $holder, Optimizer::AUTOMATIC ), Optimizer::GROUP ) );
	}

	public function test_a_translation_set_aside_for_a_holder_that_is_deleted_with_no_work_is_looked_at_again(): void {
		$holder = $this->attachment();
		Optimizer::cancel( $holder );
		Records::update( $holder, array( 'status' => Records::NOT_OPTIMIZED ) );
		$translation = $this->translation( $holder );

		Optimizer::optimize( $translation, Optimizer::BULK );
		$this->assertSame( 'shared', Records::get( $translation )['error']['code'] ?? null );

		wp_delete_attachment( $holder, true );

		$this->assertSame( Records::NOT_OPTIMIZED, Records::get( $translation )['status'] );
		$this->assertNull( Records::get( $translation )['error'] );
		$this->assertNull( Optimizer::why_not( $translation ) );
	}

	public function test_the_wpml_config_leaves_out_every_meta_the_plugin_keeps(): void {
		$config = simplexml_load_file( dirname( __DIR__, 2 ) . '/wpml-config.xml' );
		$fields = array();

		foreach ( $config->{'custom-fields'}->{'custom-field'} as $field ) {
			$this->assertSame( 'ignore', (string) $field['action'] );
			$fields[] = (string) $field;
		}

		$this->assertSame( Records::ALL_META, $fields );
	}

	/**
	 * Marks the holder's group as written: the main file replaced by another image, its WebP copy beside it.
	 *
	 * @param int    $id       The attachment's id.
	 * @param string $attached Its attached file.
	 */
	protected function finish( int $id, string $attached ): void {
		$relative = (string) Paths::relative( $attached );

		copy( DIR_TESTDATA . '/images/test-image.jpg', $attached );
		file_put_contents( $attached . '.webp', 'webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- stands for its copy.
		clearstatcache( true, $attached );
		Records::update(
			$id,
			array(
				'status'  => Records::OPTIMIZED,
				'group'   => null,
				'written' => array( $relative => (int) filesize( $attached ) ),
				'copies'  => array(
					$relative . '.webp' => array(
						'for'       => $relative,
						'format'    => 'webp',
						'bytes'     => 4,
						'origin'    => Records::ORIGIN,
						'for_bytes' => (int) filesize( $attached ),
					),
				),
			)
		);
	}

	/**
	 * A translation of an attachment as Polylang makes one: a new attachment whose
	 * metadata and file are the original's, saved with no event the plugin acts on.
	 *
	 * @param int $of The attachment translated.
	 */
	protected function translation( int $of ): int {
		$id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Translation',
				'post_status'    => 'inherit',
			)
		);

		$metadata = (array) wp_get_attachment_metadata( $of, true );
		$attached = (string) get_attached_file( $of, true );

		wp_update_attachment_metadata( $id, $metadata );
		update_attached_file( $id, $attached );

		$this->named[] = $attached;

		foreach ( $metadata['sizes'] ?? array() as $size ) {
			$this->named[] = dirname( $attached ) . '/' . $size['file'];
		}

		return $id;
	}

	/**
	 * The `wp_delete_file` filter WPML and Polylang add: a file another attachment
	 * still names is kept.
	 *
	 * @param string $file The file about to be deleted.
	 */
	public function keep_named( $file ): string {
		return in_array( $file, $this->named, true ) ? '' : (string) $file;
	}

	protected function attachment(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}
}
