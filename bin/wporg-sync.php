<?php
/**
 * Keeps the bundled translations in languages/ and the plugin's translations
 * on translate.wordpress.org in step. translate.wordpress.org read the bundled
 * files once, at the plugin's first import, and takes translations since only
 * through its website, so each side is brought to the other by hand:
 *
 *   php bin/wporg-sync.php pull [locale ...]
 *     Writes into each locale's .po the wording its editors approved where the
 *     bundled text is one translate.wordpress.org retired (an editor replaced
 *     or rejected it), and lists the strings sent back or rejected that still
 *     wait for an answer. A bundled text translate.wordpress.org never held is
 *     kept: it is a revision made here, which `push` sends. Run `make` after.
 *
 *   php bin/wporg-sync.php push [locale ...]
 *     Writes build/wporg/<locale>.po with every bundled translation that
 *     translate.wordpress.org has never held, in any state, of a string it
 *     has, for the plugin's account to import on the locale's Development
 *     (trunk) project. A locale with nothing to send gets no file.
 *
 * A locale is named as in languages/ (nl_NL). It reads only public exports and
 * signs in nowhere. Any answer that isn't the export asked for stops the run,
 * since a refusal read as an empty export would undo an editor's work.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a command-line tool, run outside WordPress.

const IMINIFY_WPORG_PROJECT = 'https://translate.wordpress.org/projects/wp-plugins/iminify-image-optimizer/dev/';

const IMINIFY_WPORG_API = 'https://translate.wordpress.org/api/projects/wp-plugins/iminify-image-optimizer/dev/';

/**
 * The states a translation on translate.wordpress.org can be in, each read
 * from its own export. `current` comes from the export without a filter,
 * which also lists every original, translated or not.
 */
const IMINIFY_WPORG_STATES = array( 'waiting', 'changesrequested', 'fuzzy', 'rejected', 'old' );

/**
 * Reads the entries of a .po file as written in it, escapes included, so
 * texts compare and go back byte for byte. The header is left out.
 *
 * @param string $po The file's text.
 * @return list<array{context: string, singular: string, plural: ?string, translations: list<string>}>
 */
function iminify_wporg_parse( string $po ): array {
	$entries = array();

	foreach ( iminify_wporg_split( '/\R\R+/', trim( $po ) ) as $block ) {
		$entry = iminify_wporg_entry( $block );

		if ( null !== $entry && '' !== $entry['singular'] ) {
			$entries[] = $entry;
		}
	}

	return $entries;
}

/**
 * A text split by a pattern, as a list.
 *
 * @param string $pattern The separator's pattern.
 * @param string $text    The text.
 * @return list<string>
 */
function iminify_wporg_split( string $pattern, string $text ): array {
	$parts = preg_split( $pattern, $text );

	return false === $parts ? array() : $parts;
}

/**
 * Reads one block of a .po file: its context, source and translations, each
 * string's continued lines joined.
 *
 * @param string $block The block, its comment lines included.
 * @return array{context: string, singular: string, plural: ?string, translations: list<string>}|null
 */
function iminify_wporg_entry( string $block ): ?array {
	$fields  = array();
	$current = null;

	foreach ( iminify_wporg_split( '/\R/', $block ) as $line ) {
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $match ) ) {
			$current            = $match[1];
			$fields[ $current ] = $match[2];
		} elseif ( null !== $current && preg_match( '/^"(.*)"$/', $line, $match ) ) {
			$fields[ $current ] .= $match[1];
		}
	}

	if ( ! isset( $fields['msgid'] ) ) {
		return null;
	}

	$translations = array();

	if ( isset( $fields['msgstr'] ) ) {
		$translations[] = $fields['msgstr'];
	}

	for ( $form = 0; isset( $fields[ 'msgstr[' . $form . ']' ] ); $form++ ) {
		$translations[] = $fields[ 'msgstr[' . $form . ']' ];
	}

	return array(
		'context'      => $fields['msgctxt'] ?? '',
		'singular'     => $fields['msgid'],
		'plural'       => $fields['msgid_plural'] ?? null,
		'translations' => $translations,
	);
}

/**
 * The key of a string: its context and source, as gettext tells strings apart.
 *
 * @param array{context: string, singular: string} $entry The entry.
 */
function iminify_wporg_key( array $entry ): string {
	return $entry['context'] . "\4" . $entry['singular'];
}

/**
 * Whether an entry holds a translation in every form.
 *
 * @param list<string> $translations The entry's translations.
 */
function iminify_wporg_translated( array $translations ): bool {
	return array() !== $translations && ! in_array( '', $translations, true );
}

/**
 * What translate.wordpress.org holds for a locale: each original it has, and
 * each translation of it by state.
 *
 * @param string                $all     The export without a filter: every original, with its current translation.
 * @param array<string, string> $exports Each state's export, by state.
 * @return array{originals: array<string, true>, states: array<string, array<string, list<list<string>>>>}
 */
function iminify_wporg_held( string $all, array $exports ): array {
	$held = array(
		'originals' => array(),
		'states'    => array(),
	);

	foreach ( iminify_wporg_parse( $all ) as $entry ) {
		$key = iminify_wporg_key( $entry );

		$held['originals'][ $key ] = true;

		if ( iminify_wporg_translated( $entry['translations'] ) ) {
			$held['states'][ $key ]['current'][] = $entry['translations'];
		}
	}

	foreach ( $exports as $state => $po ) {
		foreach ( iminify_wporg_parse( $po ) as $entry ) {
			if ( iminify_wporg_translated( $entry['translations'] ) ) {
				$held['states'][ iminify_wporg_key( $entry ) ][ $state ][] = $entry['translations'];
			}
		}
	}

	return $held;
}

/**
 * The states translate.wordpress.org holds a text in, for one string.
 *
 * @param array<string, list<list<string>>> $states       The string's translations by state.
 * @param list<string>                      $translations The text looked for.
 * @return list<string>
 */
function iminify_wporg_states_of( array $states, array $translations ): array {
	$found = array();

	foreach ( $states as $state => $texts ) {
		if ( in_array( $translations, $texts, true ) ) {
			$found[] = $state;
		}
	}

	return $found;
}

/**
 * Brings the editors' approved wording into a bundled .po. A string takes the
 * current translation where its bundled text is missing or one
 * translate.wordpress.org retired (`old`, `rejected`); a text it never held,
 * or holds as a suggestion, is a revision made here and stays.
 *
 * @param string                                                                                 $po   The bundled file's text.
 * @param array{originals: array<string, true>, states: array<string, array<string, list<list<string>>>>} $held What translate.wordpress.org holds.
 * @return array{0: string, 1: int, 2: list<string>} The new text, how many strings changed, and the strings that wait for an answer.
 */
function iminify_wporg_pull( string $po, array $held ): array {
	$newline = false !== strpos( $po, "\r\n" ) ? "\r\n" : "\n";
	$end     = substr( $po, -strlen( $newline ) ) === $newline ? $newline : '';
	$blocks  = explode( $newline . $newline, '' === $end ? $po : substr( $po, 0, -strlen( $end ) ) );
	$changed = 0;
	$waiting = array();

	foreach ( $blocks as $index => $block ) {
		$entry = iminify_wporg_entry( $block );

		if ( null === $entry || '' === $entry['singular'] ) {
			continue;
		}

		$states  = $held['states'][ iminify_wporg_key( $entry ) ] ?? array();
		$current = $states['current'][0] ?? null;
		$in      = iminify_wporg_states_of( $states, $entry['translations'] );

		if ( in_array( 'changesrequested', $in, true ) || ( in_array( 'rejected', $in, true ) && null === $current ) ) {
			$waiting[] = $entry['singular'];
		}

		if ( null === $current || $current === $entry['translations'] ) {
			continue;
		}

		$retired = array() !== array_intersect( array( 'old', 'rejected' ), $in );

		if ( ! $retired && iminify_wporg_translated( $entry['translations'] ) ) {
			continue;
		}

		$blocks[ $index ] = iminify_wporg_with( $block, $current, null !== $entry['plural'], $newline );
		++$changed;
	}

	return array( implode( $newline . $newline, $blocks ) . $end, $changed, $waiting );
}

/**
 * A block with its translations replaced, every other line kept.
 *
 * @param string       $block        The block.
 * @param list<string> $translations The new translations, one per form.
 * @param bool         $plural       Whether the string has plural forms.
 * @param string       $newline      The file's line ending.
 */
function iminify_wporg_with( string $block, array $translations, bool $plural, string $newline ): string {
	$lines = array();
	$open  = false;

	foreach ( iminify_wporg_split( '/\R/', $block ) as $line ) {
		if ( 0 === strpos( $line, 'msgstr' ) ) {
			$open = true;
			continue;
		}

		if ( $open && 0 === strpos( $line, '"' ) ) {
			continue;
		}

		$open    = false;
		$lines[] = $line;
	}

	foreach ( $translations as $form => $translation ) {
		$lines[] = ( $plural ? 'msgstr[' . $form . ']' : 'msgstr' ) . ' "' . $translation . '"';
	}

	return implode( $newline, $lines );
}

/**
 * The bundled translations translate.wordpress.org has never held, in any
 * state, of the strings it has.
 *
 * @param string                                                                                 $po   The bundled file's text.
 * @param array{originals: array<string, true>, states: array<string, array<string, list<list<string>>>>} $held What translate.wordpress.org holds.
 * @return list<array{context: string, singular: string, plural: ?string, translations: list<string>}>
 */
function iminify_wporg_push( string $po, array $held ): array {
	$send = array();

	foreach ( iminify_wporg_parse( $po ) as $entry ) {
		$key = iminify_wporg_key( $entry );

		if ( ! isset( $held['originals'][ $key ] ) || ! iminify_wporg_translated( $entry['translations'] ) ) {
			continue;
		}

		if ( array() === iminify_wporg_states_of( $held['states'][ $key ] ?? array(), $entry['translations'] ) ) {
			$send[] = $entry;
		}
	}

	return $send;
}

/**
 * A .po file holding the given entries, for translate.wordpress.org's import.
 *
 * @param list<array{context: string, singular: string, plural: ?string, translations: list<string>}> $entries      The entries.
 * @param string                                                                                      $plural_forms The locale's plural forms.
 */
function iminify_wporg_write( array $entries, string $plural_forms ): string {
	$po = "msgid \"\"\nmsgstr \"\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"Plural-Forms: " . $plural_forms . "\\n\"\n";

	foreach ( $entries as $entry ) {
		$po .= "\n" . ( '' !== $entry['context'] ? 'msgctxt "' . $entry['context'] . "\"\n" : '' ) . 'msgid "' . $entry['singular'] . "\"\n";

		if ( null === $entry['plural'] ) {
			$po .= 'msgstr "' . $entry['translations'][0] . "\"\n";
			continue;
		}

		$po .= 'msgid_plural "' . $entry['plural'] . "\"\n";

		foreach ( $entry['translations'] as $form => $translation ) {
			$po .= 'msgstr[' . $form . '] "' . $translation . "\"\n";
		}
	}

	return $po;
}

/**
 * The plural forms a bundled .po declares.
 *
 * @param string $po The file's text.
 */
function iminify_wporg_plural_forms( string $po ): string {
	return preg_match( '/^"Plural-Forms: (.*?)\\\\n"$/m', $po, $match ) ? $match[1] : '';
}

/**
 * An export's text, or an exception when the answer is anything else (a
 * refusal page, a rate limit, an empty body).
 *
 * @param string $body The answer's body.
 * @param string $url  The address asked, for the message.
 * @throws RuntimeException When the body is no .po file.
 */
function iminify_wporg_export( string $body, string $url ): string {
	if ( 1 !== preg_match( '/^msgid ""\R+msgstr ""\R+"/m', substr( $body, 0, 2000 ) ) ) {
		$text = (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/<[^>]*>/', ' ', $body ) );

		throw new RuntimeException( 'translate.wordpress.org answered something other than a .po file for ' . $url . ': ' . substr( trim( $text ), 0, 160 ) );
	}

	return $body;
}

/**
 * Asks translate.wordpress.org for an address, waiting and asking again when
 * it answers that too many requests came, up to four times.
 *
 * @param string $url The address.
 * @throws RuntimeException When it never answers 200.
 */
function iminify_wporg_get( string $url ): string {
	$status = 0;

	for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'header'        => "User-Agent: iminify-image-optimizer translations sync\r\n",
					'ignore_errors' => true,
					'timeout'       => 60,
				),
			)
		);
		$handle  = fopen( $url, 'r', false, $context );
		$body    = false === $handle ? false : stream_get_contents( $handle );
		$meta    = false === $handle ? array() : stream_get_meta_data( $handle );
		$first   = is_array( $meta['wrapper_data'] ?? null ) ? (string) reset( $meta['wrapper_data'] ) : '';
		$status  = preg_match( '/^HTTP\/\S+ (\d+)/', $first, $match ) ? (int) $match[1] : 0;

		if ( false !== $handle ) {
			fclose( $handle );
		}

		if ( 200 === $status && false !== $body ) {
			usleep( 300000 );
			return $body;
		}

		if ( 429 !== $status && $status < 500 ) {
			break;
		}

		sleep( 10 * $attempt );
	}

	throw new RuntimeException( 'translate.wordpress.org did not answer ' . $url . ' (' . $status . ')' );
}

/**
 * The translate.wordpress.org locale of each WordPress locale the plugin's
 * project has (`nl_NL` to `nl`), its default set only.
 *
 * @throws RuntimeException When the answer isn't the project's sets.
 * @return array<string, string>
 */
function iminify_wporg_locales(): array {
	$project = json_decode( iminify_wporg_get( IMINIFY_WPORG_API ), true );

	if ( ! is_array( $project ) || ! isset( $project['translation_sets'] ) || ! is_array( $project['translation_sets'] ) ) {
		throw new RuntimeException( 'translate.wordpress.org answered something other than the project\'s translation sets.' );
	}

	$locales = array();

	foreach ( $project['translation_sets'] as $set ) {
		if ( is_array( $set ) && 'default' === ( $set['slug'] ?? '' ) && is_string( $set['wp_locale'] ?? null ) && is_string( $set['locale'] ?? null ) ) {
			$locales[ $set['wp_locale'] ] = $set['locale'];
		}
	}

	return $locales;
}

/**
 * What translate.wordpress.org holds for one of its locales, from its exports.
 *
 * @param string $locale The translate.wordpress.org locale (`nl`).
 * @return array{originals: array<string, true>, states: array<string, array<string, list<list<string>>>>}
 */
function iminify_wporg_fetch( string $locale ): array {
	$base    = IMINIFY_WPORG_PROJECT . $locale . '/default/export-translations/?format=po';
	$exports = array();

	foreach ( IMINIFY_WPORG_STATES as $state ) {
		$url               = $base . '&filters%5Bstatus%5D=' . $state;
		$exports[ $state ] = iminify_wporg_export( iminify_wporg_get( $url ), $url );
	}

	return iminify_wporg_held( iminify_wporg_export( iminify_wporg_get( $base ), $base ), $exports );
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$iminify_wporg_command = $argv[1] ?? '';
	$iminify_wporg_folder  = dirname( __DIR__ ) . '/languages';

	if ( ! in_array( $iminify_wporg_command, array( 'pull', 'push' ), true ) ) {
		fwrite( STDERR, "Usage: php bin/wporg-sync.php pull|push [locale ...]\n" );
		exit( 1 );
	}

	try {
		$iminify_wporg_map    = iminify_wporg_locales();
		$iminify_wporg_wanted = array_slice( $argv, 2 );
		$iminify_wporg_files  = glob( $iminify_wporg_folder . '/iminify-image-optimizer-*.po' );
		$iminify_wporg_files  = false === $iminify_wporg_files ? array() : $iminify_wporg_files;
		$iminify_wporg_found  = false;

		foreach ( $iminify_wporg_files as $iminify_wporg_file ) {
			$iminify_wporg_locale = substr( basename( $iminify_wporg_file, '.po' ), strlen( 'iminify-image-optimizer-' ) );

			if ( array() !== $iminify_wporg_wanted && ! in_array( $iminify_wporg_locale, $iminify_wporg_wanted, true ) ) {
				continue;
			}

			$iminify_wporg_found = true;

			if ( ! isset( $iminify_wporg_map[ $iminify_wporg_locale ] ) ) {
				throw new RuntimeException( $iminify_wporg_locale . ' has no set on translate.wordpress.org.' );
			}

			$iminify_wporg_slug = $iminify_wporg_map[ $iminify_wporg_locale ];
			$iminify_wporg_held = iminify_wporg_fetch( $iminify_wporg_slug );
			$iminify_wporg_po   = (string) file_get_contents( $iminify_wporg_file );

			if ( 'pull' === $iminify_wporg_command ) {
				list( $iminify_wporg_text, $iminify_wporg_changed, $iminify_wporg_waiting ) = iminify_wporg_pull( $iminify_wporg_po, $iminify_wporg_held );

				if ( $iminify_wporg_changed > 0 ) {
					file_put_contents( $iminify_wporg_file, $iminify_wporg_text );
				}

				echo $iminify_wporg_locale . ': ' . $iminify_wporg_changed . ' strings take the editors\' wording';
				echo array() === $iminify_wporg_waiting ? "\n" : ', ' . count( $iminify_wporg_waiting ) . ' sent back or rejected wait for an answer: ' . IMINIFY_WPORG_PROJECT . $iminify_wporg_slug . "/default/?filters%5Bstatus%5D=changesrequested\n";
				continue;
			}

			$iminify_wporg_send = iminify_wporg_push( $iminify_wporg_po, $iminify_wporg_held );

			if ( array() === $iminify_wporg_send ) {
				echo $iminify_wporg_locale . ": nothing to send\n";
				continue;
			}

			$iminify_wporg_out = dirname( __DIR__ ) . '/build/wporg/' . $iminify_wporg_locale . '.po';

			if ( ! is_dir( dirname( $iminify_wporg_out ) ) ) {
				mkdir( dirname( $iminify_wporg_out ), 0777, true );
			}

			file_put_contents( $iminify_wporg_out, iminify_wporg_write( $iminify_wporg_send, iminify_wporg_plural_forms( $iminify_wporg_po ) ) );
			echo $iminify_wporg_locale . ': ' . count( $iminify_wporg_send ) . ' to import from ' . $iminify_wporg_out . ' at ' . IMINIFY_WPORG_PROJECT . $iminify_wporg_slug . "/default/import-translations/\n";
		}

		if ( ! $iminify_wporg_found ) {
			throw new RuntimeException( 'No locale in languages/ matches ' . implode( ', ', $iminify_wporg_wanted ) . '.' );
		}
	} catch ( RuntimeException $iminify_wporg_error ) {
		fwrite( STDERR, $iminify_wporg_error->getMessage() . "\n" );
		exit( 1 );
	}
}
