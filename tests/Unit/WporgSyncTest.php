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
 * and the exports translate.wordpress.org would answer for it.
 */
class WporgSyncTest extends TestCase {

	/**
	 * A bundled .po: an editor replaced the first string's text, the second
	 * was revised here, the third matches what was approved, the fourth was
	 * sent back, the fifth has no translation yet, the sixth isn't on
	 * translate.wordpress.org, and the two plural strings differ only by
	 * context. The last entry ends the file.
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
			'#: a.php:1',
			'msgid "Folder"',
			'msgstr "Folder"',
			'',
			'#: a.php:2',
			'msgid "Turn off \"Auto\"."',
			'msgstr "Zet \"Auto\" uit."',
			'',
			'#: a.php:3',
			'msgctxt "images today"',
			'msgid "%s used, no daily limit"',
			'msgid_plural "%s used, no daily limit"',
			'msgstr[0] "%s gebruikt"',
			'msgstr[1] "%s gebruikt"',
			'',
			'#: a.php:4',
			'msgid "Sent"',
			'msgstr "Gestuurd"',
			'',
			'#: a.php:5',
			'msgid "Empty"',
			'msgstr ""',
			'',
			'#: a.php:6',
			'msgid "Only here"',
			'msgstr "Alleen hier"',
			'',
			'#: a.php:7',
			'msgctxt "AI alt texts today"',
			'msgid "%s used, no daily limit"',
			'msgid_plural "%s used, no daily limit"',
			'msgstr[0] "%s gebruikt, geen beperking"',
			'msgstr[1] "%s gebruikt, geen beperking"',
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
				'old'              => "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Folder\"\nmsgstr \"Folder\"\n",
				'changesrequested' => "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Sent\"\nmsgstr \"Gestuurd\"\n",
				'waiting'          => "msgid \"\"\nmsgstr \"\"\n",
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

	public function test_pull_takes_the_editors_wording_only_where_the_bundled_text_was_retired_or_missing(): void {
		list( $text, $changed, $waiting ) = iminify_wporg_pull( implode( "\n", $this->bundled() ) . "\n", $this->held() );

		$expected     = $this->bundled();
		$expected[7]  = 'msgstr "Map"';
		$expected[26] = 'msgstr "Leeg"';
		$this->assertSame( implode( "\n", $expected ) . "\n", $text );
		$this->assertSame( 2, $changed );
		$this->assertSame( array( 'Sent' ), $waiting );
	}

	public function test_pull_keeps_the_files_line_endings_and_its_last_entry(): void {
		list( $text ) = iminify_wporg_pull( implode( "\r\n", $this->bundled() ), $this->held() );

		$this->assertStringEndsWith( 'msgstr[1] "%s gebruikt, geen beperking"', $text );
		$this->assertStringContainsString( "msgid \"Folder\"\r\nmsgstr \"Map\"\r\n\r\n", $text );
		$this->assertStringNotContainsString( "\r\r", $text );
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
		$po   = iminify_wporg_write( $sent, iminify_wporg_plural_forms( implode( "\n", $this->bundled() ) ) );

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
