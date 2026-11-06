<?php
/**
 * The background declarations of a piece of CSS, given the copies of their images.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page names its backgrounds in `background-image` and `background`
 * declarations, in an inline `style` attribute or a `<style>` element. Each one
 * whose `url()` names a file with copies keeps its declaration as it is and
 * gains a copy of itself right after it, where each such address becomes an
 * `image-set()` of its copies by `type()`, the original last: a browser that
 * reads `image-set()` with `type()` takes the first format it supports, and one
 * that doesn't drops the copy and keeps the first. The copy is the same
 * declaration but for those addresses, so it sets every other part (a
 * shorthand's position, size, repeat and colour, a gradient, `!important`) as
 * the first one did, and an invalid one stays invalid.
 *
 * The CSS is read in one pass that knows comments, strings, `url()` and
 * parentheses, never with a pattern: a `data:` address can run to megabytes and
 * holds semicolons. Only an address a layer names in its own `url()` is swapped:
 * one inside another function (an author's `image-set()`, a `cross-fade()`) and
 * one it can't read for sure (an escape, a space, more than one string) stay as
 * written in the copy. A declaration with a function the browser fills in only
 * once it computes the value (`var()`, `env()`, `attr()`, `if()` or a custom
 * `--function()`) is left alone: it reads as valid in every browser, so one that
 * can't read `image-set()` would take the copy and show no background at all. A
 * declaration already followed by its copy (a page an editor saved back with
 * it) gains nothing.
 */
class Css {

	/**
	 * The properties whose images get their copies.
	 */
	const PROPERTIES = array( 'background', 'background-image' );

	/**
	 * The functions a browser fills in only once it computes the value, which
	 * leave a declaration alone, as a custom function's name (`--`) does.
	 */
	const LEFT = array( 'var', 'env', 'attr', 'if' );

	/**
	 * The bytes the reading stops at: a comment's, a string's or a `url()`'s first,
	 * an escape's, and the ones that open or end a block or a declaration.
	 */
	const STOPS = "/\"'\\uU(){};";

	/**
	 * The MIME type of an original by its extension, for its `type()`; one with
	 * any other extension is listed without one, which every browser reads as
	 * one it can show.
	 */
	const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Every address the background declarations name in `url()`, once each.
	 *
	 * @param string $css The CSS.
	 * @return list<string>
	 */
	public static function urls( string $css ): array {
		$urls = array();

		foreach ( self::declarations( $css ) as $declaration ) {
			foreach ( self::items( $css, $declaration['value'][0], $declaration['value'][1] ) ?? array() as $item ) {
				if ( 'url' === $item['kind'] && null !== $item['address'] ) {
					$urls[ $item['address'] ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $urls ) );
	}

	/**
	 * The CSS with a copy after each background declaration whose images have
	 * copies, and every other byte as it came.
	 *
	 * @param string                                  $css    The CSS.
	 * @param callable(string): array<string, string> $copies The copies of the file an address names, as addresses by MIME type, in the order to try them; empty for none.
	 */
	public static function rewrite( string $css, callable $copies ): string {
		$declarations = self::declarations( $css );
		$parts        = array();
		$at           = 0;

		foreach ( $declarations as $index => $declaration ) {
			$next = $declarations[ $index + 1 ] ?? null;

			/* The declaration right after it is its copy, as a page saved back with it has. */
			if ( null !== $next && ';' === substr( $css, $declaration['end'], 1 ) && $next['from'] === $declaration['end'] + 1 && $next['name'] === $declaration['name'] && false !== stripos( substr( $css, $next['value'][0], $next['value'][1] - $next['value'][0] ), 'image-set(' ) ) {
				continue;
			}

			$added = self::added( $css, $declaration, $copies );

			if ( null === $added ) {
				continue;
			}

			$parts[] = substr( $css, $at, $added[0] - $at );
			$parts[] = $added[1];
			$at      = $added[0];
		}

		if ( array() === $parts ) {
			return $css;
		}

		$parts[] = substr( $css, $at );

		return implode( '', $parts );
	}

	/**
	 * The copy to add after a background declaration, and where: right after its
	 * value, `!important` included, or null when it gains none.
	 *
	 * @param string                                                                                 $css         The CSS.
	 * @param array{from: int, name: string, written: string, value: array{0: int, 1: int}, end: int} $declaration The declaration.
	 * @param callable(string): array<string, string>                                               $copies      As `rewrite()` takes it.
	 * @return array{0: int, 1: string}|null
	 */
	protected static function added( string $css, array $declaration, callable $copies ): ?array {
		list( $from, $to ) = $declaration['value'];

		$end   = self::trimmed( $css, $from, $to );
		$items = self::left( $css, $from, $end ) ? null : self::items( $css, $from, $end );

		if ( null === $items ) {
			return null;
		}

		$value  = '';
		$at     = $from;
		$served = false;

		foreach ( $items as $item ) {
			if ( 'url' !== $item['kind'] || null === $item['address'] ) {
				continue;
			}

			$set = $copies( $item['address'] );

			if ( array() === $set ) {
				continue;
			}

			$value .= substr( $css, $at, $item['start'] - $at ) . self::image_set( $set, substr( $css, $item['start'], $item['end'] - $item['start'] ), $item['address'] );
			$at     = $item['end'];
			$served = true;
		}

		return $served ? array( $end, '; ' . $declaration['written'] . ':' . $value . substr( $css, $at, $end - $at ) ) : null;
	}

	/**
	 * Whether a value holds a function that leaves its declaration alone (`LEFT`,
	 * or a custom `--function()`), at any depth. A name inside a string counts too,
	 * which only ever leaves a declaration alone.
	 *
	 * @param string $css  The CSS.
	 * @param int    $from The value's first byte.
	 * @param int    $to   The byte after its last.
	 */
	protected static function left( string $css, int $from, int $to ): bool {
		for ( $open = $from + strcspn( $css, '(', $from, $to - $from ); $open < $to; $open += 1 + strcspn( $css, '(', $open + 1, $to - $open - 1 ) ) {
			$start = $open;

			while ( $start > $from && ( ctype_alnum( $css[ $start - 1 ] ) || '-' === $css[ $start - 1 ] || '_' === $css[ $start - 1 ] ) ) {
				--$start;
			}

			$name = strtolower( substr( $css, $start, $open - $start ) );

			if ( in_array( $name, self::LEFT, true ) || 0 === strpos( $name, '--' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An `image-set()` of the copies, then the original as it was written.
	 *
	 * @param array<string, string> $set      The copies' addresses by MIME type.
	 * @param string                $original The original's `url()` as the CSS wrote it.
	 * @param string                $address  The original's address.
	 */
	protected static function image_set( array $set, string $original, string $address ): string {
		$options = array();

		foreach ( $set as $type => $url ) {
			$options[] = 'url("' . strtr(
				$url,
				array(
					'\\' => '\\\\',
					'"'  => '\\"',
					"\n" => '\\a ',
					"\r" => '\\d ',
					"\f" => '\\c ',
				)
			) . '") type("' . $type . '")';
		}

		$path      = wp_parse_url( $address, PHP_URL_PATH );
		$extension = strtolower( pathinfo( is_string( $path ) ? $path : '', PATHINFO_EXTENSION ) );
		$options[] = $original . ( isset( self::TYPES[ $extension ] ) ? ' type("' . self::TYPES[ $extension ] . '")' : '' );

		return 'image-set(' . implode( ', ', $options ) . ')';
	}

	/**
	 * The background declarations, in order: where each began looking (right after
	 * the `;`, `{` or `}` before it), its property in lower case and as written,
	 * its value's span, and where it ends (its `;` or `}`, or the end of the CSS).
	 *
	 * @param string $css The CSS.
	 * @return list<array{from: int, name: string, written: string, value: array{0: int, 1: int}, end: int}>
	 */
	protected static function declarations( string $css ): array {
		$length = strlen( $css );
		$found  = array();
		$start  = true;
		$depth  = 0;
		$i      = 0;

		while ( $i < $length ) {
			if ( $start ) {
				$start       = false;
				$declaration = self::declaration( $css, $i );

				if ( null !== $declaration ) {
					$found[] = $declaration;
					$i       = $declaration['end'];
					continue;
				}
			}

			/* Nothing between here and the next byte it stops at can start or end anything. */
			$i += strcspn( $css, self::STOPS, $i );

			if ( $i >= $length ) {
				break;
			}

			$char = $css[ $i ];
			$next = self::skipped( $css, $i, $length );

			if ( null !== $next ) {
				$i = $next;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( '{' === $char || '}' === $char ) {
				$depth = 0;
				$start = true;
			} elseif ( ';' === $char && 0 === $depth ) {
				$start = true;
			}

			++$i;
		}

		return $found;
	}

	/**
	 * The background declaration starting at a place one may start, or null:
	 * another property, no colon after it, or a nested rule (a `{` before its end).
	 *
	 * @param string $css  The CSS.
	 * @param int    $from Where to look.
	 * @return array{from: int, name: string, written: string, value: array{0: int, 1: int}, end: int}|null
	 */
	protected static function declaration( string $css, int $from ): ?array {
		$length = strlen( $css );
		$i      = self::space( $css, $from, $length );
		$name   = $i;

		while ( $i < $length && ( ctype_alnum( $css[ $i ] ) || '-' === $css[ $i ] || '_' === $css[ $i ] ) ) {
			++$i;
		}

		$written  = substr( $css, $name, $i - $name );
		$property = strtolower( $written );

		if ( ! in_array( $property, self::PROPERTIES, true ) ) {
			return null;
		}

		$i = self::space( $css, $i, $length );

		if ( ':' !== substr( $css, $i, 1 ) ) {
			return null;
		}

		$value = ++$i;
		$depth = 0;

		while ( $i < $length ) {
			$i += strcspn( $css, self::STOPS, $i );

			if ( $i >= $length ) {
				break;
			}

			$char = $css[ $i ];
			$next = self::skipped( $css, $i, $length );

			if ( null !== $next ) {
				$i = $next;
				continue;
			}

			if ( '{' === $char ) {
				return null;
			}

			if ( 0 === $depth && ( ';' === $char || '}' === $char ) ) {
				break;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			}

			++$i;
		}

		return array(
			'from'    => $from,
			'name'    => $property,
			'written' => $written,
			'value'   => array( $value, min( $i, $length ) ),
			'end'     => min( $i, $length ),
		);
	}

	/**
	 * A value's parts outside any parentheses, in order: each comma, `url()`
	 * (with its address, or null for one with an escape or that isn't one
	 * address), function (with its name in lower case) and word, with its span.
	 * Null for a function left open.
	 *
	 * @param string $css  The CSS.
	 * @param int    $from The value's first byte.
	 * @param int    $to   The byte after its last.
	 * @return list<array{kind: string, start: int, end: int, name: string, address: string|null}>|null
	 */
	protected static function items( string $css, int $from, int $to ): ?array {
		$items = array();
		$i     = $from;

		while ( $i < $to ) {
			$char = $css[ $i ];

			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}

			if ( '/' === $char && '*' === substr( $css, $i + 1, 1 ) ) {
				$i = self::comment_end( $css, $i, $to );
				continue;
			}

			$item = array(
				'kind'    => 'word',
				'start'   => $i,
				'end'     => $i + 1,
				'name'    => '',
				'address' => null,
			);

			if ( ',' === $char ) {
				$item['kind'] = 'comma';
			} elseif ( self::is_url( $css, $i ) ) {
				$item['kind'] = 'url';
				$item['end']  = self::url_end( $css, $i, $to, $item['address'] );
			} elseif ( '"' === $char || "'" === $char ) {
				$item['end'] = self::string_end( $css, $i, $to );
			} else {
				$end = $i;

				while ( $end < $to && ! ctype_space( $css[ $end ] ) && false === strpos( ',("\'', $css[ $end ] ) && ! ( '/' === $css[ $end ] && '*' === substr( $css, $end + 1, 1 ) ) ) {
					++$end;
				}

				$item['end'] = max( $end, $i + 1 );

				if ( $end < $to && '(' === $css[ $end ] ) {
					$close = self::block_end( $css, $end, $to );

					if ( null === $close ) {
						return null;
					}

					$item['kind'] = 'function';
					$item['name'] = strtolower( substr( $css, $i, $end - $i ) );
					$item['end']  = $close;
				}
			}

			$items[] = $item;
			$i       = $item['end'];
		}

		return $items;
	}

	/**
	 * Where a comment, a string, a `url()` or an escape starting at a byte ends,
	 * or null when none starts there. An escape (`\'` in a selector, as
	 * Tailwind's `content-['']` class writes it) is its byte and the next.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   The byte.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function skipped( string $css, int $i, int $to ): ?int {
		$char = $css[ $i ];

		if ( '\\' === $char ) {
			return min( $i + 2, $to );
		}

		if ( '/' === $char && '*' === substr( $css, $i + 1, 1 ) ) {
			return self::comment_end( $css, $i, $to );
		}

		if ( '"' === $char || "'" === $char ) {
			return self::string_end( $css, $i, $to );
		}

		if ( self::is_url( $css, $i ) ) {
			$address = null;

			return self::url_end( $css, $i, $to, $address );
		}

		return null;
	}

	/**
	 * Whether a `url(` starts at a byte. A value's parts are read from where each
	 * starts (`items()`), so a function whose name ends in `url` is read as that
	 * function; anywhere else either reading ends at the same `)`.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   The byte.
	 */
	protected static function is_url( string $css, int $i ): bool {
		return ( 'u' === $css[ $i ] || 'U' === $css[ $i ] ) && 'url(' === strtolower( substr( $css, $i, 4 ) );
	}

	/**
	 * Where a `url()` starting at a byte ends, after its `)`, reading its address:
	 * a quoted one as written, an unquoted one trimmed, and null for one holding an
	 * escape, a line break, a quote or a parenthesis, or one with more than its
	 * string inside.
	 *
	 * @param string      $css     The CSS.
	 * @param int         $i       Where its `url(` starts.
	 * @param int         $to      The byte after the last one to read.
	 * @param string|null $address Set to its address.
	 */
	protected static function url_end( string $css, int $i, int $to, ?string &$address ): int {
		$address = null;
		$open    = $i + 3;
		$i       = self::blank( $css, $i + 4, $to );

		if ( $i < $to && ( '"' === $css[ $i ] || "'" === $css[ $i ] ) ) {
			$end   = self::string_end( $css, $i, $to );
			$after = self::blank( $css, $end, $to );

			/* Anything after the string makes it a function of more than one address, which runs to its own `)`. */
			if ( $after >= $to || ')' !== $css[ $after ] ) {
				return self::block_end( $css, $open, $to ) ?? $to;
			}

			$text    = (string) substr( $css, $i + 1, max( 0, $end - $i - 2 ) );
			$closed  = $end - $i >= 2 && $css[ $end - 1 ] === $css[ $i ];
			$address = $closed && '' !== $text && false === strpbrk( $text, "\\\n\r\f" ) ? $text : null;

			return $after + 1;
		}

		$start = $i;
		$odd   = false;

		/* An unquoted address may hold no quote, parenthesis or escape, and white space only at its end. */
		while ( $i < $to ) {
			$i += strcspn( $css, ")\\\"'(", $i );

			if ( $i >= $to || ')' === $css[ $i ] ) {
				break;
			}

			$odd = true;
			$i  += '\\' === $css[ $i ] ? 2 : 1;
		}

		$i       = min( $i, $to );
		$text    = rtrim( substr( $css, $start, max( 0, $i - $start ) ) );
		$address = ! $odd && $i < $to && '' !== $text && false === strpbrk( $text, " \t\n\r\f" ) ? $text : null;

		return min( $to, $i + 1 );
	}

	/**
	 * Where the parentheses opening at a byte close, after the `)`, or null when
	 * they never do.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   The byte of the `(`.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function block_end( string $css, int $i, int $to ): ?int {
		$depth = 0;

		while ( $i < $to ) {
			$i += strcspn( $css, '/"\'\\uU()', $i );

			if ( $i >= $to ) {
				break;
			}

			$char = $css[ $i ];
			$next = self::skipped( $css, $i, $to );

			if ( null !== $next ) {
				$i = $next;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && 0 === --$depth ) {
				return $i + 1;
			}

			++$i;
		}

		return null;
	}

	/**
	 * Where a string starting at a byte ends, after its closing quote, or at the
	 * line break that ends a string left open.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   The byte of its opening quote.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function string_end( string $css, int $i, int $to ): int {
		$quote = $css[ $i ];

		for ( ++$i; $i < $to; ++$i ) {
			$i += strcspn( $css, $quote . "\\\n", $i );

			if ( $i >= $to ) {
				break;
			}

			if ( '\\' === $css[ $i ] ) {
				++$i;
			} elseif ( $quote === $css[ $i ] ) {
				return $i + 1;
			} else {
				return $i;
			}
		}

		return $to;
	}

	/**
	 * Where a comment starting at a byte ends, after its `*` and `/`.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   The byte of its `/`.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function comment_end( string $css, int $i, int $to ): int {
		$end = strpos( $css, '*/', $i + 2 );

		return false === $end ? $to : min( $end + 2, $to );
	}

	/**
	 * The first byte from here that is neither white space nor in a comment.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   Where to start.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function space( string $css, int $i, int $to ): int {
		while ( $i < $to ) {
			if ( ctype_space( $css[ $i ] ) ) {
				++$i;
			} elseif ( '/' === $css[ $i ] && '*' === substr( $css, $i + 1, 1 ) ) {
				$i = self::comment_end( $css, $i, $to );
			} else {
				break;
			}
		}

		return $i;
	}

	/**
	 * The first byte from here that isn't white space.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   Where to start.
	 * @param int    $to  The byte after the last one to read.
	 */
	protected static function blank( string $css, int $i, int $to ): int {
		while ( $i < $to && ctype_space( $css[ $i ] ) ) {
			++$i;
		}

		return $i;
	}

	/**
	 * The byte after a span's last that isn't white space.
	 *
	 * @param string $css  The CSS.
	 * @param int    $from The span's first byte.
	 * @param int    $to   The byte after its last.
	 */
	protected static function trimmed( string $css, int $from, int $to ): int {
		while ( $to > $from && ctype_space( $css[ $to - 1 ] ) ) {
			--$to;
		}

		return $to;
	}
}
