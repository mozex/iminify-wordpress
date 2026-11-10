<?php
/**
 * What a translation must keep of its source string.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Tests\Support;

/**
 * The rules languages/GLOSSARY.md states, as checks on one string and its
 * translation: the same placeholders, the same tags, every name and piece of
 * code kept, and no em dash. Each answers what is wrong, in words, or nothing.
 */
class Translation_Rules {

	/**
	 * Names and code a translation keeps as written whenever its source holds them.
	 */
	const KEPT = array( 'Iminify', 'WebP', 'AVIF', 'JPEG', 'PNG', 'GIF', 'HEIC', 'WP-CLI', 'WooCommerce', 'Cloudflare', 'Nginx', 'Apache', 'IMINIFY_API_KEY', 'wp-config.php', '.htaccess', 'iminify.com', 'wp iminify', 'wp-content' );

	/**
	 * The plan names, kept as whole words: "Max" is also the start of "Maximum".
	 */
	const KEPT_WORDS = array( 'Pro', 'Max' );

	/**
	 * What is wrong with one translated form against its source, or an empty list.
	 *
	 * @param string $source      The source (the plural source for a plural form).
	 * @param string $translation The translated form.
	 * @return list<string>
	 */
	public static function problems( string $source, string $translation ): array {
		$problems = array();

		if ( '' === trim( $translation ) ) {
			return array( 'empty' );
		}

		if ( self::placeholders( $source ) !== self::placeholders( $translation ) ) {
			$problems[] = 'placeholders ' . implode( ' ', self::placeholders( $translation ) ) . ' where the source has ' . implode( ' ', self::placeholders( $source ) );
		}

		if ( self::tags( $source ) !== self::tags( $translation ) ) {
			$problems[] = 'tags ' . implode( ' ', self::tags( $translation ) ) . ' where the source has ' . implode( ' ', self::tags( $source ) );
		}

		if ( self::stray( $source ) !== self::stray( $translation ) ) {
			$problems[] = self::stray( $translation ) . ' lone % where the source has ' . self::stray( $source );
		}

		foreach ( self::kept( $source ) as $kept ) {
			if ( false === strpos( $translation, $kept ) ) {
				$problems[] = 'lacks "' . $kept . '"';
			}
		}

		foreach ( self::KEPT_WORDS as $word ) {
			if ( self::has_word( $source, $word ) && ! self::has_word( $translation, $word ) ) {
				$problems[] = 'lacks "' . $word . '"';
			}
		}

		if ( false !== strpos( $translation, "\u{2014}" ) && false === strpos( $source, "\u{2014}" ) ) {
			$problems[] = 'an em dash';
		}

		return $problems;
	}

	/**
	 * The string's printf placeholders, each as its argument's position and type,
	 * sorted, so a translation may move numbered ones but never change one.
	 *
	 * @param string $text A string.
	 * @return list<string>
	 */
	public static function placeholders( string $text ): array {
		preg_match_all( '/%(?:(\d+)\$)?[-+0#]?\d*(?:\.\d+)?([bcdeEfFgGosuxX])/', str_replace( '%%', '', $text ), $matches, PREG_SET_ORDER );

		$found = array_fill( 0, substr_count( $text, '%%' ), '%%' );
		$next  = 0;

		foreach ( $matches as $match ) {
			$position = '' !== $match[1] ? (int) $match[1] : ++$next;
			$found[]  = $position . ':' . $match[2];
		}

		sort( $found );

		return $found;
	}

	/**
	 * How many `%` the string holds that start no placeholder: PHP's sprintf()
	 * reads one as the start of a placeholder it has no argument for, and throws.
	 *
	 * @param string $text A string.
	 */
	public static function stray( string $text ): int {
		return substr_count( (string) preg_replace( '/%(?:\d+\$)?[-+0#]?\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', '', str_replace( '%%', '', $text ) ), '%' );
	}

	/**
	 * Whether the string holds the word with no Latin letter touching it.
	 *
	 * @param string $text A string.
	 * @param string $word The word.
	 */
	public static function has_word( string $text, string $word ): bool {
		return 1 === preg_match( '/(?<![A-Za-z])' . preg_quote( $word, '/' ) . '(?![A-Za-z])/', $text );
	}

	/**
	 * The string's tags (`<a>`, `</a>`, `<file />`) whole, attributes included,
	 * sorted: the scripts fill in only a tag written exactly as in the source.
	 *
	 * @param string $text A string.
	 * @return list<string>
	 */
	public static function tags( string $text ): array {
		preg_match_all( '#</?[a-zA-Z][\w-]*\b[^<>]*>#', $text, $matches );

		$found = array();

		foreach ( $matches[0] as $tag ) {
			$found[] = (string) preg_replace( '#\s*/>$#', ' />', (string) preg_replace( '/\s+/', ' ', $tag ) );
		}

		sort( $found );

		return $found;
	}

	/**
	 * The names and code a translation of this string keeps: those in KEPT it
	 * holds, and whatever it puts inside `<code>`.
	 *
	 * @param string $text A string.
	 * @return list<string>
	 */
	public static function kept( string $text ): array {
		$kept = array_values(
			array_filter(
				self::KEPT,
				static function ( string $name ) use ( $text ): bool {
					return false !== strpos( $text, $name );
				}
			)
		);

		preg_match_all( '#<code>(.*?)</code>#s', $text, $code );

		return array_values( array_unique( array_merge( $kept, $code[1] ) ) );
	}
}
