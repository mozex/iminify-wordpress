<?php
/**
 * A page audit's findings and problems, in the site's language.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iminify's audit says what it found about each image, and why a page could
 * not be read, as a code with English text: for a code it knows, the plugin
 * shows its own words, which translate with it, and for one it doesn't, the
 * service's text as sent. Which fix suits a finding is the service's to say.
 */
class Findings {

	/**
	 * One finding of an image, with the plugin's words for a code it knows.
	 *
	 * @param mixed $finding A finding from the audit's `images`.
	 * @return array{code: string, fix: string, label: string, message: string}|null
	 */
	public static function finding( $finding ): ?array {
		if ( ! is_array( $finding ) || ! isset( $finding['code'] ) || ! is_string( $finding['code'] ) ) {
			return null;
		}

		$words = self::words( $finding['code'] );

		return array(
			'code'    => $finding['code'],
			'fix'     => isset( $finding['fix'] ) && is_string( $finding['fix'] ) ? $finding['fix'] : '',
			'label'   => null === $words ? (string) ( $finding['label'] ?? $finding['code'] ) : $words['label'],
			'message' => null === $words ? (string) ( $finding['message'] ?? '' ) : $words['message'],
		);
	}

	/**
	 * What to do about a finding, in the plugin's words for the fix Iminify
	 * suggests, or null for a fix it doesn't know.
	 *
	 * @param string   $fix         The fix's code.
	 * @param int|null $serve_width For a smaller size, the width it should be, twice the width it is shown at.
	 */
	public static function fix( string $fix, ?int $serve_width = null ): ?string {
		switch ( $fix ) {
			case 'smaller_size':
				return null === $serve_width
					? __( 'Show a smaller size of the image here.', 'iminify-image-optimizer' )
					/* translators: %s: a width in pixels, such as 1,280. */
					: sprintf( __( 'Show a size about %s pixels wide here, twice the width it takes on the page.', 'iminify-image-optimizer' ), number_format_i18n( $serve_width ) );
			case 'add_dimensions':
				return __( 'Give the image its width and height in the page.', 'iminify-image-optimizer' );
			case 'write_alt_text':
				return __( 'Write alt text for the image.', 'iminify-image-optimizer' );
			case 'load_at_once':
				return __( 'Load the image straight away, not lazily.', 'iminify-image-optimizer' );
			case 'load_lazily':
				return __( 'Load the image lazily, once a visitor scrolls near it.', 'iminify-image-optimizer' );
			case 'prioritize':
				return __( 'Have the browser fetch the image first.', 'iminify-image-optimizer' );
			default:
				return null;
		}
	}

	/**
	 * Why a page could not be read, with the plugin's words for a code it knows.
	 *
	 * @param mixed $problem The audit's `problem`.
	 * @return array{code: string, status: int|null, message: string}|null
	 */
	public static function problem( $problem ): ?array {
		if ( ! is_array( $problem ) || ! isset( $problem['code'] ) || ! is_string( $problem['code'] ) ) {
			return null;
		}

		$status = isset( $problem['status'] ) && is_int( $problem['status'] ) ? $problem['status'] : null;
		$words  = self::problem_words( $problem['code'], $status );

		return array(
			'code'    => $problem['code'],
			'status'  => $status,
			'message' => null === $words ? (string) ( $problem['message'] ?? '' ) : $words,
		);
	}

	/**
	 * The plugin's name and sentence for a finding's code, or null for one it doesn't know.
	 *
	 * @param string $code The finding's code.
	 * @return array{label: string, message: string}|null
	 */
	protected static function words( string $code ): ?array {
		switch ( $code ) {
			case 'oversized':
				return array(
					'label'   => __( 'Oversized', 'iminify-image-optimizer' ),
					'message' => __( 'The file is more than twice as wide and twice as tall as the space it is shown in. A smaller size of the same image would look as sharp.', 'iminify-image-optimizer' ),
				);
			case 'without_dimensions':
				return array(
					'label'   => __( 'No width and height', 'iminify-image-optimizer' ),
					'message' => __( 'The image has no width and height attributes, so the browser can\'t hold its space and the page moves when it arrives.', 'iminify-image-optimizer' ),
				);
			case 'lazy_above_fold':
				return array(
					'label'   => __( 'Lazy in the first screen', 'iminify-image-optimizer' ),
					'message' => __( 'The image is in the first screen but is lazy loaded, which delays it.', 'iminify-image-optimizer' ),
				);
			case 'eager_below_fold':
				return array(
					'label'   => __( 'Loads too early', 'iminify-image-optimizer' ),
					'message' => __( 'The image is below the first screen and loads straight away, competing with what visitors see first.', 'iminify-image-optimizer' ),
				);
			case 'missing_alt':
				return array(
					'label'   => __( 'No alt text', 'iminify-image-optimizer' ),
					'message' => __( 'The image has no alt text for screen readers and search engines.', 'iminify-image-optimizer' ),
				);
			case 'lcp_not_prioritized':
				return array(
					'label'   => __( 'Not prioritized', 'iminify-image-optimizer' ),
					'message' => __( 'This is the largest thing in the first screen, so the page feels loaded when it arrives. Fetching it first would show the page sooner.', 'iminify-image-optimizer' ),
				);
			default:
				return null;
		}
	}

	/**
	 * The plugin's sentence for a problem's code, or null for one it doesn't know.
	 *
	 * @param string   $code   The problem's code.
	 * @param int|null $status What the page answered, when it is known.
	 */
	protected static function problem_words( string $code, ?int $status ): ?string {
		switch ( $code ) {
			case 'blocked':
				return null === $status
					? __( 'Your site turned the scanner away, so the page couldn\'t be read. A firewall or a bot check does this, often Cloudflare\'s. Let the scan through for a moment: in Cloudflare, turn Bot Fight Mode off or set the Security Level to "Essentially off"; in a security plugin, pause its firewall or its bot blocking. Scan again, then turn them back on.', 'iminify-image-optimizer' )
					/* translators: %d: the HTTP status the site answered, such as 403. */
					: sprintf( __( 'Your site turned the scanner away (it answered %d), so the page couldn\'t be read. A firewall or a bot check does this, often Cloudflare\'s. Let the scan through for a moment: in Cloudflare, turn Bot Fight Mode off or set the Security Level to "Essentially off"; in a security plugin, pause its firewall or its bot blocking. Scan again, then turn them back on.', 'iminify-image-optimizer' ), $status );
			case 'not_found':
				return __( 'There is no page at this address. Check the address and scan again.', 'iminify-image-optimizer' );
			case 'unavailable':
				return __( 'The page answered with an error on the site\'s side, so it couldn\'t be read. Scan again in a few minutes.', 'iminify-image-optimizer' );
			case 'redirected':
				return __( 'The page sent the scanner to another site, which an audit doesn\'t follow. Scan the address the page ends up at, if it is on this site.', 'iminify-image-optimizer' );
			case 'unreachable':
				return null === $status
					? __( 'The page couldn\'t be read: the site didn\'t answer the scanner. Check the address and scan again.', 'iminify-image-optimizer' )
					/* translators: %d: the HTTP status the site answered, such as 400. */
					: sprintf( __( 'The page answered %d instead of the page, so it couldn\'t be read. Check the address and scan again.', 'iminify-image-optimizer' ), $status );
			default:
				return null;
		}
	}
}
