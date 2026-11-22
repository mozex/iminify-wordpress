<?php
/**
 * Checks what ships, as bin/build.php copied it or as its zip unpacks: no
 * address but the ones the plugin may name, nothing that points at a
 * developer's machine, every locale's translation files, and no stylesheet
 * selector that names nothing of the plugin's.
 *
 * Usage: php bin/check-build.php [folder]
 *        (the folder defaults to build/iminify-image-optimizer)
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line check, run outside WordPress.

/**
 * Addresses a shipped file may hold outside Action Scheduler's folder, each
 * matched as the start of an address: the service itself, the licence, the
 * directory's support forum (the translations' header names it), and the
 * namespaces an SVG declares, which name no request.
 */
const IMINIFY_BUILD_ALLOWED = array(
	'https://www.iminify.com/',
	'https://www.gnu.org/licenses/',
	'https://fsf.org/',
	'https://wordpress.org/support/plugin/iminify-image-optimizer',
	'http://www.w3.org/2000/svg',
	'http://www.w3.org/1999/xlink',
);

/**
 * Action Scheduler's folder, whose own documentation links are its own.
 */
const IMINIFY_BUILD_SCHEDULER = 'vendor/woocommerce/action-scheduler/';

/**
 * What points at a development copy of the service or at a developer's
 * machine, never allowed anywhere in what ships, Action Scheduler included:
 * a loopback or container host; a host under a name reserved for testing and
 * examples (RFC 2606 and RFC 6761, and `.internal`), after `//` or standing
 * alone before a port, a path or a quote, so minified code (`e.test(a)`)
 * never matches; a drive's path with either separator, escaped or not; a
 * WSL, Git Bash, macOS or Linux home path. A backslash is written `\x5c`.
 */
const IMINIFY_BUILD_LOCAL = array(
	'~localhost~i',
	'~127\.0\.0\.1~',
	'~host\.docker\.internal~i',
	'~//[^/\s"\'<>()\x5c]*\.(?:test|example|invalid|localhost|internal)(?![a-z0-9.-])~i',
	'~(?<![\w$.\x5c/@-])(?:[a-z0-9-]+\.)+(?:test|example|invalid|localhost|internal)(?=:\d|/|["\'\s]|$)~im',
	'~(?<![\w$])[A-Z]:(?:\x5c\x5c?|/)[\w.-]+(?:\x5c|/)~',
	'~\x5c\x5c(?:wsl\$|wsl\.localhost)~i',
	'~/mnt/[a-z]/~i',
	'~(?<![\w.$/:-])/(?:[a-z]|Users)/[\w.-]+/~',
	'~/home/[a-z0-9_-]+/~i',
);

/**
 * File types whose bytes hold no text worth reading.
 */
const IMINIFY_BUILD_BINARY = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'eot' );

/**
 * Every file under a folder, as paths relative to it with forward slashes.
 *
 * @param string $dir The folder.
 * @return string[]
 */
function iminify_check_files( string $dir ): array {
	$files = array();
	$root  = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
		$files[] = substr( str_replace( '\\', '/', $entry->getPathname() ), strlen( $root ) );
	}

	sort( $files );

	return $files;
}

/**
 * The addresses and local names a shipped folder holds that it may not.
 *
 * @param string $dir The folder that ships.
 * @return string[] One line per problem: the file, then what it holds.
 */
function iminify_check_addresses( string $dir ): array {
	$problems = array();

	foreach ( iminify_check_files( $dir ) as $file ) {
		if ( in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), IMINIFY_BUILD_BINARY, true ) ) {
			continue;
		}

		$text = (string) file_get_contents( $dir . '/' . $file );

		foreach ( IMINIFY_BUILD_LOCAL as $local ) {
			if ( preg_match( $local, $text, $found ) ) {
				$problems[] = $file . ': ' . $found[0];
			}
		}

		if ( 0 === strpos( $file, IMINIFY_BUILD_SCHEDULER ) ) {
			continue;
		}

		/*
		 * A JSON file writes a slash as \/, and code or a stylesheet can name an
		 * address without its scheme (url(//host/...), src="//host/..."), which
		 * loads over the page's own. A .mo file keeps its strings apart with NUL
		 * bytes, so control characters end an address too.
		 */
		$text = str_replace( '\\/', '/', $text );
		preg_match_all( '~https?://[^\s\x00-\x1f"\'<>()\\\\`]*~i', $text, $matches );
		preg_match_all( '~url\(\s*["\']?\K//[^\s\x00-\x1f"\'<>()\\\\`]*~i', $text, $relative );
		preg_match_all( '~["\'=]\s*\K//[a-z0-9-]+(?:\.[a-z0-9-]+)+[^\s\x00-\x1f"\'<>()\x5c`]*~i', $text, $quoted );

		foreach ( array_unique( array_merge( $matches[0], $relative[0], $quoted[0] ) ) as $address ) {
			if ( ! iminify_check_allowed( $address ) ) {
				$problems[] = $file . ': ' . $address;
			}
		}
	}

	return $problems;
}

/**
 * Whether a shipped file may hold an address: one of the allowed ones, or a
 * scheme with no host, as code that builds an address writes it.
 *
 * @param string $address The address as the file holds it.
 */
function iminify_check_allowed( string $address ): bool {
	if ( 0 === strpos( $address, '//' ) ) {
		$address = 'https:' . $address;
	}

	if ( '' === (string) preg_replace( '~^https?://~i', '', $address ) ) {
		return true;
	}

	foreach ( IMINIFY_BUILD_ALLOWED as $allowed ) {
		if ( 0 === strpos( $address, $allowed ) || rtrim( $allowed, '/' ) === $address ) {
			return true;
		}
	}

	return false;
}

/**
 * The selectors in the built stylesheets that don't name one of the plugin's
 * own classes or ids. Each rule's selector is split at every comma, inside
 * parentheses too, because that is how a reader may split it: a piece that
 * names nothing of the plugin's reads as styling WordPress's own screens,
 * however the whole list is scoped. The classes WordPress gives the media
 * list's column and the attachment panel's field, named after the plugin's key
 * (`column-iminify`, `compat-field-iminify`), are the plugin's too. Rules inside
 * `@media`, `@supports`, `@container` and `@layer` are read; `@keyframes` and
 * `@font-face` hold no selectors.
 *
 * @param string $dir The folder that ships.
 * @return string[] One line per selector: the file, then the piece.
 */
function iminify_check_selectors( string $dir ): array {
	$problems = array();

	foreach ( iminify_check_files( $dir ) as $file ) {
		if ( ! preg_match( '~^assets/build/[^/]+\.css$~', $file ) ) {
			continue;
		}

		$css = (string) preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( $dir . '/' . $file ) );

		foreach ( iminify_check_rules( $css ) as $selector ) {
			foreach ( explode( ',', $selector ) as $piece ) {
				if ( ! preg_match( '~[.#](?:iminify-|(?:column|compat-field)-iminify(?![\w-]))~', $piece ) ) {
					$problems[] = $file . ': ' . trim( $piece );
				}
			}
		}
	}

	return array_values( array_unique( $problems ) );
}

/**
 * The selectors of a stylesheet's style rules, those inside grouping at-rules
 * included.
 *
 * @param string $css The stylesheet, without comments.
 * @return string[]
 */
function iminify_check_rules( string $css ): array {
	$selectors = array();
	$length    = strlen( $css );
	$start     = 0;
	$i         = 0;

	while ( $i < $length ) {
		$char = $css[ $i ];

		if ( '"' === $char || "'" === $char ) {
			$i = iminify_check_after_string( $css, $i );
			continue;
		}

		if ( ';' === $char ) {
			$start = ++$i;
			continue;
		}

		if ( '{' !== $char ) {
			++$i;
			continue;
		}

		$prelude = trim( substr( $css, $start, $i - $start ) );
		$end     = iminify_check_block_end( $css, $i );

		if ( preg_match( '~^@(?:media|supports|container|layer)\b~i', $prelude ) ) {
			$selectors = array_merge( $selectors, iminify_check_rules( substr( $css, $i + 1, $end - $i - 1 ) ) );
		} elseif ( '' !== $prelude && '@' !== $prelude[0] ) {
			$selectors[] = $prelude;
		}

		$i     = $end + 1;
		$start = $i;
	}

	return $selectors;
}

/**
 * Where the block opened at an offset ends: its closing brace, or the end.
 *
 * @param string $css  The stylesheet.
 * @param int    $open The offset of the block's opening brace.
 */
function iminify_check_block_end( string $css, int $open ): int {
	$depth  = 0;
	$length = strlen( $css );
	$i      = $open;

	while ( $i < $length ) {
		$char = $css[ $i ];

		if ( '"' === $char || "'" === $char ) {
			$i = iminify_check_after_string( $css, $i );
			continue;
		}

		if ( '{' === $char ) {
			++$depth;
		} elseif ( '}' === $char && 0 === --$depth ) {
			return $i;
		}

		++$i;
	}

	return $length;
}

/**
 * The offset right after the quoted string that starts at an offset.
 *
 * @param string $css   The stylesheet.
 * @param int    $quote The offset of the string's opening quote.
 */
function iminify_check_after_string( string $css, int $quote ): int {
	$length = strlen( $css );
	$i      = $quote + 1;

	while ( $i < $length && $css[ $i ] !== $css[ $quote ] ) {
		$i += '\\' === $css[ $i ] ? 2 : 1;
	}

	return $i + 1;
}

/**
 * The translation files a shipped folder lacks: every file of the source's
 * languages folder but its glossary, byte for byte, and for each locale its
 * .po, .mo and .l10n.php with one JSON file per built script the template
 * names, which WordPress finds by the md5 of the script's path.
 *
 * @param string $dir    The folder that ships.
 * @param string $source The plugin's checkout.
 * @return string[] One line per missing or different file.
 */
function iminify_check_locales( string $dir, string $source ): array {
	$domain   = 'iminify-image-optimizer';
	$problems = array();
	$locales  = array();

	foreach ( iminify_check_files( $source . '/languages' ) as $file ) {
		if ( 'GLOSSARY.md' === $file ) {
			continue;
		}

		$shipped = $dir . '/languages/' . $file;

		if ( ! is_file( $shipped ) ) {
			$problems[] = 'languages/' . $file . ': missing';
		} elseif ( sha1_file( $shipped ) !== sha1_file( $source . '/languages/' . $file ) ) {
			$problems[] = 'languages/' . $file . ': not the checkout\'s';
		}

		if ( preg_match( '~^' . preg_quote( $domain, '~' ) . '-([A-Za-z_]+)\.po$~', $file, $locale ) ) {
			$locales[] = $locale[1];
		}
	}

	$template = $dir . '/languages/' . $domain . '.pot';

	if ( ! is_file( $template ) ) {
		return array_merge( $problems, array( 'languages/' . $domain . '.pot: missing' ) );
	}

	preg_match_all( '~assets/build/([a-z0-9-]+\.js)~', (string) file_get_contents( $template ), $scripts );
	$scripts = array_unique( $scripts[1] );

	if ( array() === $locales ) {
		$problems[] = 'languages: no locale';
	}

	if ( array() === $scripts ) {
		$problems[] = 'languages/' . $domain . '.pot: names no script';
	}

	foreach ( $scripts as $script ) {
		if ( ! is_file( $dir . '/assets/build/' . $script ) ) {
			$problems[] = 'assets/build/' . $script . ': missing';
		}
	}

	foreach ( $locales as $locale ) {
		$files = array( $domain . '-' . $locale . '.po', $domain . '-' . $locale . '.mo', $domain . '-' . $locale . '.l10n.php' );

		foreach ( $scripts as $script ) {
			$files[] = $domain . '-' . $locale . '-' . md5( 'assets/build/' . $script ) . '.json';
		}

		foreach ( $files as $file ) {
			if ( ! is_file( $dir . '/languages/' . $file ) ) {
				$problems[] = 'languages/' . $file . ': missing';
			}
		}
	}

	return array_values( array_unique( $problems ) );
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$iminify_check_dir = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__ ) . '/build/iminify-image-optimizer';

	if ( ! is_file( $iminify_check_dir . '/iminify-image-optimizer.php' ) ) {
		fwrite( STDERR, $iminify_check_dir . " holds no build: run php bin/build.php first.\n" );
		exit( 1 );
	}

	$iminify_check_problems = array_merge( iminify_check_addresses( $iminify_check_dir ), iminify_check_locales( $iminify_check_dir, dirname( __DIR__ ) ), iminify_check_selectors( $iminify_check_dir ) );

	if ( array() !== $iminify_check_problems ) {
		fwrite( STDERR, implode( "\n", $iminify_check_problems ) . "\n" );
		exit( 1 );
	}

	echo 'The build holds no address but the allowed ones, every locale\'s files, and no selector outside the plugin\'s classes.' . "\n";
}
