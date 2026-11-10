<?php
/**
 * The translations the plugin ships, and how WordPress picks them up.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Integration;

use Iminify\WordPress\Admin\Media_View;
use Iminify\WordPress\Media\Optimizer;
use Iminify\WordPress\Media\Records;
use Iminify\WordPress\Messages;
use Iminify\WordPress\Plugin;
use Iminify\WordPress\Reasons;
use Iminify\WordPress\Site_Record;
use Iminify\WordPress\Tests\Support\Api_Stub;
use Iminify\WordPress\Tests\Support\Translation_Rules;
use Iminify\WordPress\Translations;
use PO;
use Translation_Entry;
use WP_Textdomain_Registry;
use WP_Translation_Controller;
use WP_Translation_File;
use WP_UnitTestCase;

/**
 * Covers that every locale in languages/ translates every string of the template
 * and keeps its placeholders, tags, names and code; that the files WordPress loads
 * say what each .po says; and that WordPress loads them for PHP and the scripts
 * until a language pack from translate.wordpress.org has the locale, which then
 * wins. That the template holds every string the code does is `bin/i18n.php
 * check`'s, run in CI after the scripts are built.
 */
class TranslationsTest extends WP_UnitTestCase {

	/**
	 * Each locale the plugin ships, with the plural forms WordPress's own
	 * translation of it uses. A .po of a locale missing here fails, so a new
	 * locale comes with its line.
	 */
	const PLURAL_FORMS = array(
		'ar'    => 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));',
		'de_DE' => 'nplurals=2; plural=n != 1;',
		'es_ES' => 'nplurals=2; plural=n != 1;',
		'ja'    => 'nplurals=1; plural=0;',
		'ru_RU' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	);

	/**
	 * The registry WordPress had before a test swapped in a fresh one.
	 *
	 * @var WP_Textdomain_Registry
	 */
	protected $registry;

	/**
	 * Files a test wrote into the site's languages folder.
	 *
	 * @var list<string>
	 */
	protected $written = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$this->registry = $GLOBALS['wp_textdomain_registry'];
	}

	public function tear_down(): void {
		foreach ( $this->written as $file ) {
			wp_delete_file( $file );
		}

		$GLOBALS['wp_textdomain_registry'] = $this->registry; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own global, put back.
		WP_Translation_Controller::get_instance()->unload_textdomain( Translations::DOMAIN );
		WP_Translation_Controller::get_instance()->set_locale( 'en_US' );
		unload_textdomain( Translations::DOMAIN, true );
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_each_locale_translates_every_string_and_keeps_what_it_must(): void {
		$template = $this->po( $this->folder() . '/' . Translations::DOMAIN . '.pot' );
		$locales  = $this->locales();
		$problems = array();

		$this->assertSame( array_keys( self::PLURAL_FORMS ), $locales, 'Every locale in languages/ has its plural forms here, and every one here has a .po.' );
		$this->assertGreaterThan( 700, count( $template->entries ) );

		foreach ( $locales as $locale ) {
			$po    = $this->po( $this->file( $locale, 'po' ) );
			$forms = (int) preg_replace( '/^nplurals=(\d+);.*$/', '$1', self::PLURAL_FORMS[ $locale ] );

			$this->assertSame( self::PLURAL_FORMS[ $locale ], $po->headers['Plural-Forms'] ?? null, $locale . '\'s plural forms' );
			$this->assertSame( $locale, $po->headers['Language'] ?? null );

			foreach ( $template->entries as $key => $source ) {
				$entry = $po->entries[ $key ] ?? null;
				$label = $locale . ' "' . $source->singular . '"';

				if ( ! $entry instanceof Translation_Entry ) {
					$problems[] = $label . ': missing';
					continue;
				}

				if ( in_array( 'fuzzy', $entry->flags, true ) ) {
					$problems[] = $label . ': fuzzy';
				}

				if ( count( $entry->translations ) !== ( $source->is_plural ? $forms : 1 ) ) {
					$problems[] = $label . ': ' . count( $entry->translations ) . ' forms';
					continue;
				}

				foreach ( $entry->translations as $form => $translation ) {
					foreach ( Translation_Rules::problems( $source->is_plural ? (string) $source->plural : $source->singular, $translation ) as $problem ) {
						$problems[] = $label . ( $source->is_plural ? ' form ' . $form : '' ) . ': ' . $problem;
					}
				}
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	public function test_the_files_wordpress_loads_say_what_each_po_says(): void {
		foreach ( $this->locales() as $locale ) {
			$po      = $this->po( $this->file( $locale, 'po' ) );
			$scripts = array();
			$all     = array();

			foreach ( $po->entries as $entry ) {
				$key         = ( null === $entry->context ? '' : $entry->context . "\4" ) . $entry->singular;
				$all[ $key ] = implode( "\0", $entry->translations );

				foreach ( $entry->references as $reference ) {
					$path = (string) preg_replace( '/:\d+$/', '', $reference );

					if ( 0 === strpos( $path, 'assets/build/' ) ) {
						$scripts[ $path ][ $key ] = $entry->translations;
					}
				}
			}

			ksort( $all );

			foreach ( array( 'mo', 'l10n.php' ) as $type ) {
				$made = WP_Translation_File::create( $this->file( $locale, $type ) );

				$this->assertInstanceOf( WP_Translation_File::class, $made, $locale . '.' . $type );

				$entries = $made->entries();
				ksort( $entries );

				$this->assertSame( $all, $entries, $locale . '.' . $type . ' says what its .po says.' );
			}

			$this->assertNotEmpty( $scripts );

			foreach ( $scripts as $script => $messages ) {
				$json = $this->folder() . '/' . Translations::DOMAIN . '-' . $locale . '-' . md5( $script ) . '.json';

				$this->assertFileExists( $json, $locale . ' for ' . $script );

				$data = json_decode( (string) file_get_contents( $json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file in the plugin's folder.
				$read = $data['locale_data']['messages'];
				unset( $read[''] );
				ksort( $read );
				ksort( $messages );

				$this->assertSame( $script, $data['source'] );
				$this->assertSame( $messages, $read, $locale . ' for ' . $script . ' says what its .po says.' );
			}

			$this->assertCount( count( $scripts ), (array) glob( $this->folder() . '/' . Translations::DOMAIN . '-' . $locale . '-*.json' ), $locale . ' has a file for each built script and none left over.' );
		}
	}

	public function test_wordpress_loads_the_shipped_files_until_a_language_pack_has_the_locale(): void {
		$locale = 'de_DE';
		$po     = $this->po( $this->file( $locale, 'po' ) );
		$php    = $po->entries['Settings']->translations[0];
		$script = $this->script_string( $po, 'assets/build/settings.js' );

		add_filter(
			'determine_locale',
			static function () use ( $locale ): string {
				return $locale;
			}
		);
		wp_register_script( 'iminify-translations-test', plugins_url( 'assets/build/settings.js', IMINIFY_FILE ), array(), '1', true );

		$this->fresh();
		$this->assertNotSame( 'Settings', $php );
		$this->assertSame( $php, __( 'Settings', 'iminify-image-optimizer' ), 'PHP reads the shipped translation.' );
		$this->assertSame( array( $script['translation'] ), $this->script_messages()[ $script['key'] ] ?? null, 'A script reads the shipped translation.' );
		$this->assertSame( $po->entries["a language alt text is written in\4French"]->translations[0], Messages::language( 'fr', 'French' ), 'A language the service names reads in the admin\'s language.' );
		$this->assertNotSame( 'French', Messages::language( 'fr', 'French' ) );
		$this->assertSame( 'Klingon', Messages::language( 'tlh', 'Klingon' ), 'A language the plugin doesn\'t know keeps the service\'s name.' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->fresh();
		$this->assertSame( $po->entries['Compress, convert and resize the images in your media library with Iminify, and serve them as WebP and AVIF.']->translations[0], get_plugin_data( IMINIFY_FILE, false, true )['Description'], 'The Plugins screen reads the plugin\'s description in the site\'s language.' );

		$packs = WP_LANG_DIR . '/plugins';
		wp_mkdir_p( $packs );
		$this->write( $packs . '/' . Translations::DOMAIN . '-' . $locale . '.l10n.php', "<?php return array( 'messages' => array( 'Settings' => 'From the pack' ) );" );
		$this->write(
			$packs . '/' . Translations::DOMAIN . '-' . $locale . '-' . md5( 'assets/build/settings.js' ) . '.json',
			(string) wp_json_encode(
				array(
					'locale_data' => array(
						'messages' => array(
							''             => array( 'domain' => 'messages' ),
							$script['key'] => array( 'From the pack' ),
						),
					),
				)
			)
		);

		$this->fresh();
		$this->assertSame( 'From the pack', __( 'Settings', 'iminify-image-optimizer' ), 'A language pack wins for PHP.' );
		$this->assertSame( array( 'From the pack' ), $this->script_messages()[ $script['key'] ] ?? null, 'A language pack wins for the scripts.' );

		$this->fresh();
		add_filter(
			'determine_locale',
			static function (): string {
				return 'fr_FR';
			},
			20
		);
		$this->assertSame( 'Settings', __( 'Settings', 'iminify-image-optimizer' ), 'A locale the plugin doesn\'t ship stays in English.' );
		$this->assertFalse( $GLOBALS['wp_textdomain_registry']->get( Translations::DOMAIN, 'fr_FR' ), 'No folder is handed over for a locale the plugin doesn\'t ship.' );
		$this->assertSame( IMINIFY_DIR . '/languages/', Translations::folder( false, Translations::DOMAIN, $locale ) );
		$this->assertFalse( Translations::folder( false, 'another-plugin', $locale ), 'Another text domain is left alone.' );
		$this->assertSame( '/packs/', Translations::folder( '/packs/', Translations::DOMAIN, $locale ), 'A folder WordPress found is kept.' );
	}

	public function test_a_reason_kept_on_a_record_reads_in_the_language_of_whoever_reads_it(): void {
		$api = ( new Api_Stub() )->start();
		Plugin::keys()->save( '41|Zx9vQe3LwR7tYp2mKd8sNf5hJc1bGa6uVo4iEy0r' );
		Site_Record::forget();
		$api->on( 'GET', 'site', 200, Api_Stub::fixture( 'site' ) );
		$api->on( 'POST', 'groups', 503, '' );

		$english = 'Iminify answered with an error (HTTP 503).';
		$german  = 'Iminify hat mit einem Fehler geantwortet (HTTP 503).';
		$locale  = 'de_DE';

		add_filter(
			'determine_locale',
			static function () use ( &$locale ): string {
				return $locale;
			}
		);

		try {
			$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
			as_unschedule_all_actions( Optimizer::OPTIMIZE_HOOK );
			Records::update(
				$id,
				array(
					'status'   => null,
					'attempts' => Optimizer::MAX_ATTEMPTS,
				)
			);

			/* A background run on a site in German, past its last try. */
			$this->fresh();
			Optimizer::optimize( $id, Optimizer::AUTOMATIC );
			$error = Records::get( $id )['error'];

			$this->assertSame( array( Records::ERROR, 'http_503', $german ), array( Records::get( $id )['status'], $error['code'] ?? null, $error['message'] ?? null ), 'The run wrote its reason in the site\'s language.' );

			$locale = 'en_US';
			$this->fresh();
			$this->assertSame( $english, Reasons::text( $error ) );
			$this->assertStringContainsString( esc_html( $english ), Media_View::cell( $id ), 'An admin reading in English reads it in English.' );

			$locale = 'de_DE';
			$this->fresh();
			$this->assertStringContainsString( esc_html( $german ), Media_View::cell( $id ), 'An admin reading in German reads it in German.' );

			/* A record kept before reasons kept their key reads as it was written. */
			Records::update(
				$id,
				array(
					'error' => array(
						'code'    => 'reopened',
						'message' => 'Written by an older version.',
					),
				)
			);
			$this->assertStringContainsString( 'Written by an older version.', Media_View::cell( $id ) );
		} finally {
			$api->stop();
			Plugin::keys()->forget();
			Site_Record::forget();
		}
	}

	/**
	 * Reads a .po or .pot file.
	 *
	 * @param string $file The file.
	 */
	protected function po( string $file ): PO {
		$po = new PO();

		$this->assertTrue( $po->import_from_file( $file ), 'Can\'t read ' . $file );

		return $po;
	}

	/**
	 * The plugin's languages folder.
	 */
	protected function folder(): string {
		return IMINIFY_DIR . '/languages';
	}

	/**
	 * One of a locale's files.
	 *
	 * @param string $locale The locale.
	 * @param string $type   Its extension: po, mo or l10n.php.
	 */
	protected function file( string $locale, string $type ): string {
		return $this->folder() . '/' . Translations::DOMAIN . '-' . $locale . '.' . $type;
	}

	/**
	 * The locales with a .po in languages/, sorted.
	 *
	 * @return list<string>
	 */
	protected function locales(): array {
		$locales = array();

		foreach ( (array) glob( $this->folder() . '/' . Translations::DOMAIN . '-*.po' ) as $file ) {
			$locales[] = substr( basename( (string) $file, '.po' ), strlen( Translations::DOMAIN ) + 1 );
		}

		sort( $locales );

		return $locales;
	}

	/**
	 * A string only a script uses, with its translation in a .po.
	 *
	 * @param PO     $po     The locale's translations.
	 * @param string $script The built script, from the plugin's folder.
	 * @return array{key: string, translation: string}
	 */
	protected function script_string( PO $po, string $script ): array {
		foreach ( $po->entries as $entry ) {
			$files = array_unique(
				array_map(
					static function ( string $reference ): string {
						return (string) preg_replace( '/:\d+$/', '', $reference );
					},
					$entry->references
				)
			);

			if ( array( $script ) === array_values( $files ) && ! $entry->is_plural && null === $entry->context ) {
				return array(
					'key'         => $entry->singular,
					'translation' => $entry->translations[0],
				);
			}
		}

		$this->fail( 'No string only ' . $script . ' uses.' );
	}

	/**
	 * What WordPress hands the test script as its translations.
	 *
	 * @return array<string, mixed>
	 */
	protected function script_messages(): array {
		$json = load_script_textdomain( 'iminify-translations-test', Translations::DOMAIN );

		$this->assertIsString( $json, 'WordPress found no translations for the script.' );

		return json_decode( $json, true )['locale_data']['messages'];
	}

	/**
	 * Starts WordPress's lookup over, as a new request would. A reloadable unload
	 * alone keeps the loaded file in the translation controller, where it would
	 * answer before a file loaded later.
	 */
	protected function fresh(): void {
		WP_Translation_Controller::get_instance()->unload_textdomain( Translations::DOMAIN );
		unload_textdomain( Translations::DOMAIN, true );
		wp_cache_flush();
		$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own global, started over.
	}

	/**
	 * Writes a file the test deletes afterwards.
	 *
	 * @param string $file     Where.
	 * @param string $contents What.
	 */
	protected function write( string $file, string $contents ): void {
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test's own fixture file.
		$this->written[] = $file;
	}
}
