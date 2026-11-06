<?php
/**
 * Core's tag processor, telling where the tag it stopped on lies.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Delivery;

use WP_HTML_Span;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core's tag processor changes attributes but can't put markup around a tag,
 * which a `<picture>` needs, and a pattern over a whole page runs out of
 * backtracking on a long unclosed tag. So the buffer walks the page with this
 * one, reads where each `<img>` it wraps begins and how long it is from the
 * bookmark the processor keeps (a `WP_HTML_Span`, the same since WordPress
 * 6.5), and builds the page again from the original text around those spans,
 * leaving every other byte as it was. It only reads: a processor that had
 * changed something would answer spans in the changed text.
 */
class Markup extends WP_HTML_Tag_Processor {

	const BOOKMARK = 'iminify-span';

	/**
	 * Where the tag the processor stopped on lies in the markup: its first byte
	 * and its length, or null when it stopped on none.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public function span(): ?array {
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}

		$span = $this->bookmarks[ self::BOOKMARK ] ?? null;

		$this->release_bookmark( self::BOOKMARK );

		return $span instanceof WP_HTML_Span ? array( (int) $span->start, (int) $span->length ) : null;
	}
}
