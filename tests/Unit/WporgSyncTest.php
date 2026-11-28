<?php
/**
 * The sync between the bundled translations and translate.wordpress.org.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Unit;

use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/wporg-sync.php';

/**
 * Covers bin/wporg-sync.php's reading, pull and push on a small bundled file
 * and what translate.wordpress.org would answer for it.
 */
class WporgSyncTest extends TestCase {

	/**
	 * A bundled .po, one string per case (the comment line above each says
	 * which). The last entry ends the file.
	 *
	 * @return list<string>
	 */
	private function bundled(): array {
		return array(
			'msgid ""',
			'msgstr ""',
			'"Language: nl_NL\n"',
			'"Plural-Forms: nplurals=2; plural=n != 1;\n"',
			'',
			'#. An editor replaced this text.',
			'msgid "Folder"',
			'msgstr "Folder"',
			'',
			'#. Revised here after it was approved.',
			'msgid "Turn off \"Auto\"."',
			'msgstr "Zet \"Auto\" uit."',
			'',
			'#. The same as approved.',
			'msgctxt "images today"',
			'msgid "%s used, no daily limit"',
			'msgid_plural "%s used, no daily limit"',
			'msgstr[0] "%s gebruikt"',
			'msgstr[1] "%s gebruikt"',
			'',
			'#. Sent back with changes requested.',
			'msgid "Sent"',
			'msgstr "Gestuurd"',
			'',
			'#. No translation yet.',
			'msgid "Empty"',
			'msgstr ""',
			'',
			'#. Not on translate.wordpress.org.',
			'msgid "Only here"',
			'msgstr "Alleen hier"',
			'',
			'#. Rejected, and an editor approved another.',
			'msgid "Rejected"',
			'msgstr "Afgewezen tekst"',
			'',
			'#. Rejected, nothing approved.',
			'msgid "Turned down"',
			'msgstr "Afgeslagen"',
			'',
			'#. Waiting as a suggestion, another text approved.',
			'msgid "Suggested"',
			'msgstr "Voorgesteld"',
			'',
			'#. The editor replaced it twice; the export would show only one old text.',
			'msgid "Running"',
			'msgstr "Actief"',
			'',
			'#. A singular here, a plural on translate.wordpress.org.',
			'msgid "%s file"',
			'msgstr "%s bestand"',
			'',
			'#. Marked fuzzy by the import.',
			'msgid "Fuzzy"',
			'msgstr "Wazig"',
			'',
			'#. Revised here, never sent: the last entry.',
			'msgctxt "AI alt texts today"',
			'msgid "%s used, no daily limit"',
			'msgid_plural "%s used, no daily limit"',
			'msgstr[0] "%s gebruikt, geen beperking"',
			'msgstr[1] "%s gebruikt, geen beperking"',
		);
	}

	/**
	 * A translation in another state, as the listing gives it.
	 *
	 * @param string       $singular     The source.
	 * @param list<string> $translations The translations.
	 * @return array{context: string, singular: string, plural: ?string, translations: list<string>}
	 */
	private function row( string $singular, array $translations ): array {
		return array(
			'context'      => '',
			'singular'     => $singular,
			'plural'       => null,
			'translations' => $translations,
		);
	}

	/**
	 * What translate.wordpress.org holds for the file above.
	 *
	 * @return array{originals: array<string, true>, states: array<string, array<string, list<list<string>>>>}
	 */
	private function held(): array {
		$all = implode(
			"\n",
			array(
				'# Translation of Plugins - Iminify Image Optimizer - Development (trunk) in Dutch',
				'msgid ""',
				'msgstr ""',
				'"Plural-Forms: nplurals=2; plural=n != 1;\n"',
				'',
				'msgid "Folder"',
				'msgstr "Map"',
				'',
				'msgid "Turn off \"Auto\"."',
				'msgstr "Zet \"Auto\" "',
				'"uit, nu."',
				'',
				'msgctxt "images today"',
				'msgid "%s used, no daily limit"',
				'msgid_plural "%s used, no daily limit"',
				'msgstr[0] "%s gebruikt"',
				'msgstr[1] "%s gebruikt"',
				'',
				'msgid "Sent"',
				'msgstr ""',
				'',
				'msgid "Empty"',
				'msgstr "Leeg"',
				'',
				'msgid "Rejected"',
				'msgstr "Geweigerd"',
				'',
				'msgid "Turned down"',
				'msgstr ""',
				'',
				'msgid "Suggested"',
				'msgstr "Goedgekeurd"',
				'',
				'msgid "Running"',
				'msgstr "Wordt uitgevoerd"',
				'',
				'msgid "%s file"',
				'msgid_plural "%s files"',
				'msgstr[0] "%s bestand"',
				'msgstr[1] "%s bestanden"',
				'',
				'msgid "Fuzzy"',
				'msgstr ""',
				'',
				'msgctxt "AI alt texts today"',
				'msgid "%s used, no daily limit"',
				'msgid_plural "%s used, no daily limit"',
				'msgstr[0] ""',
				'msgstr[1] ""',
				'',
			)
		);

		return iminify_wporg_held(
			$all,
			array(
				'old'              => array(
					$this->row( 'Folder', array( 'Folder' ) ),
					$this->row( 'Running', array( 'Bezig' ) ),
					$this->row( 'Running', array( 'Actief' ) ),
				),
				'changesrequested' => array( $this->row( 'Sent', array( 'Gestuurd' ) ) ),
				'rejected'         => array(
					$this->row( 'Rejected', array( 'Afgewezen tekst' ) ),
					$this->row( 'Turned down', array( 'Afgeslagen' ) ),
				),
				'waiting'          => array( $this->row( 'Suggested', array( 'Voorgesteld' ) ) ),
				'fuzzy'            => array( $this->row( 'Fuzzy', array( 'Wazig' ) ) ),
			)
		);
	}

	public function test_an_entry_is_read_as_written_with_its_continued_lines_joined(): void {
		$entries = iminify_wporg_parse( "msgid \"\"\nmsgstr \"\"\n\nmsgctxt \"a\"\nmsgid \"One \\\"x\\\"\"\nmsgid_plural \"Two\"\nmsgstr[0] \"Een \"\n\"x\"\nmsgstr[1] \"Twee\"" );

		$this->assertSame(
			array(
				array(
					'context'      => 'a',
					'singular'     => 'One \"x\"',
					'plural'       => 'Two',
					'translations' => array( 'Een x', 'Twee' ),
				),
			),
			$entries
		);
	}

	public function test_a_listed_translation_is_written_as_the_export_writes_it(): void {
		$entries = iminify_wporg_rows(
			array(
				array(
					'context'      => null,
					'singular'     => "Say \"hi\"\tto C:\\x\nnow",
					'plural'       => null,
					'translations' => array( "Zeg \"hoi\"\tnu\n", null ),
				),
				array(
					'context'      => 'today',
					'singular'     => '%s file',
					'plural'       => '%s files',
					'translations' => array( '%s bestand', '%s bestanden', null ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'context'      => '',
					'singular'     => 'Say \"hi\"\tto C:\\\\x\nnow',
					'plural'       => null,
					'translations' => array( 'Zeg \"hoi\"\tnu\n' ),
				),
				array(
					'context'      => 'today',
					'singular'     => '%s file',
					'plural'       => '%s files',
					'translations' => array( '%s bestand', '%s bestanden' ),
				),
			),
			$entries
		);
	}

	public function test_an_answer_that_is_no_listing_stops_the_run(): void {
		$this->expectException( RuntimeException::class );
		iminify_wporg_rows( json_decode( '<html>429 Too Many Requests</html>', true ) );
	}

	public function test_a_listing_row_without_its_original_stops_the_run(): void {
		$this->expectException( RuntimeException::class );
		iminify_wporg_rows( array( array( 'translations' => array( 'x' ) ) ) );
	}

	public function test_pull_takes_the_editors_wording_only_where_the_bundled_text_was_retired_or_missing(): void {
		list( $text, $changed, $waiting ) = iminify_wporg_pull( implode( "\n", $this->bundled() ) . "\n", $this->held() );

		$expected     = $this->bundled();
		$expected[7]  = 'msgstr "Map"';
		$expected[26] = 'msgstr "Leeg"';
		$expected[34] = 'msgstr "Geweigerd"';
		$expected[46] = 'msgstr "Wordt uitgevoerd"';
		$this->assertSame( implode( "\n", $expected ) . "\n", $text );
		$this->assertSame( 4, $changed );
		$this->assertSame( array( 'changesrequested: Sent', 'rejected: Turned down', 'fuzzy: Fuzzy' ), $waiting );
	}

	public function test_pull_keeps_the_files_line_endings_and_its_last_entry(): void {
		list( $text ) = iminify_wporg_pull( implode( "\r\n", $this->bundled() ), $this->held() );

		$this->assertStringEndsWith( 'msgstr[1] "%s gebruikt, geen beperking"', $text );
		$this->assertStringContainsString( "msgid \"Folder\"\r\nmsgstr \"Map\"\r\n\r\n", $text );
		$this->assertStringNotContainsString( "\r\r", $text );
	}

	public function test_pull_leaves_a_string_whose_forms_differ_and_lists_it(): void {
		$po   = "msgid \"\"\nmsgstr \"\"\n\nmsgid \"%s file\"\nmsgid_plural \"%s files\"\nmsgstr[0] \"\"\n";
		$held = iminify_wporg_held( "msgid \"\"\nmsgstr \"\"\n\nmsgid \"%s file\"\nmsgid_plural \"%s files\"\nmsgstr[0] \"%s bestand\"\nmsgstr[1] \"%s bestanden\"\n", array() );

		list( $text, $changed, $waiting ) = iminify_wporg_pull( $po, $held );

		$this->assertSame( $po, $text );
		$this->assertSame( 0, $changed );
		$this->assertSame( array( 'forms differ: %s file' ), $waiting );
	}

	public function test_pull_writes_the_last_entry_whole_when_the_file_ends_in_blank_lines(): void {
		$po           = "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Folder\"\nmsgstr \"Folder\"\n\n\n";
		list( $text ) = iminify_wporg_pull( $po, $this->held() );

		$this->assertSame( "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Folder\"\nmsgstr \"Map\"\n\n\n", $text );
	}

	public function test_push_sends_each_bundled_text_translate_wordpress_org_never_held_of_a_string_it_has(): void {
		$sent = iminify_wporg_push( implode( "\n", $this->bundled() ), $this->held() );

		$this->assertSame(
			array( 'Turn off \"Auto\".', '%s used, no daily limit' ),
			array_column( $sent, 'singular' )
		);
		$this->assertSame( 'AI alt texts today', $sent[1]['context'] );
		$this->assertSame( array( '%s gebruikt, geen beperking', '%s gebruikt, geen beperking' ), $sent[1]['translations'] );
	}

	public function test_the_file_push_writes_reads_back_as_the_same_entries(): void {
		$sent = iminify_wporg_push( implode( "\n", $this->bundled() ), $this->held() );
		$po   = iminify_wporg_write( $sent, iminify_wporg_plural_forms( implode( "\r\n", $this->bundled() ) ) );

		$this->assertStringContainsString( '"Plural-Forms: nplurals=2; plural=n != 1;\n"', $po );
		$this->assertSame( $sent, iminify_wporg_parse( $po ) );
	}

	public function test_an_answer_that_is_no_export_stops_the_run(): void {
		$this->assertSame( "# Translation\nmsgid \"\"\nmsgstr \"\"\n\"X: y\\n\"\n", iminify_wporg_export( "# Translation\nmsgid \"\"\nmsgstr \"\"\n\"X: y\\n\"\n", 'u' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '429 Too Many Requests' );
		iminify_wporg_export( '<html><head><title>429 Too Many Requests</title></head><body></body></html>', 'u' );
	}
}
