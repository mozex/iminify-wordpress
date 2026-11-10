<?php
/**
 * Checks the directory's assets in .wordpress-org, which the deploy sends to
 * the plugin's Subversion `assets/` folder: each file under a name
 * wordpress.org reads, at its size and in the format its name says, with no
 * metadata, and one caption in the readme for every screenshot.
 *
 * Usage: php bin/check-assets.php [folder] [readme]
 *        (defaults: .wordpress-org and readme.txt)
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line check, run outside WordPress.

require_once __DIR__ . '/check-build.php';

/**
 * The names wordpress.org reads, each with the width and height it must have
 * (null for any) and the most bytes it takes. A localized banner or
 * screenshot (`-rtl`, `-de`) is a name wordpress.org reads too, which this
 * list adds when the plugin ships one.
 */
const IMINIFY_ASSETS = array(
	'~^icon-128x128\.(png|jpg)$~'           => array( 128, 128, 1000000 ),
	'~^icon-256x256\.(png|jpg)$~'           => array( 256, 256, 1000000 ),
	'~^icon\.(svg)$~'                       => array( null, null, 1000000 ),
	'~^banner-772x250\.(png|jpg)$~'         => array( 772, 250, 4000000 ),
	'~^banner-1544x500\.(png|jpg)$~'        => array( 1544, 500, 4000000 ),
	'~^screenshot-[1-9][0-9]*\.(png|jpg)$~' => array( null, null, 10000000 ),
);

/**
 * The PNG chunks an image may hold: its pixels and how to show their colours.
 * Anything else (a tool's text, EXIF, a time, a private chunk) is metadata.
 */
const IMINIFY_ASSETS_PNG_CHUNKS = array( 'IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'bKGD' );

/**
 * What the folder holds that isn't the directory's images.
 */
const IMINIFY_ASSETS_FOLDERS = array( 'blueprints' );

/**
 * Lists what is wrong with the assets.
 *
 * @param string $dir    The assets folder.
 * @param string $readme The readme's text.
 * @return string[] One line per problem.
 */
function iminify_check_assets( string $dir, string $readme ): array {
	if ( ! is_dir( $dir ) ) {
		return array( $dir . ': no assets folder' );
	}

	$problems    = array();
	$names       = array();
	$screenshots = array();

	foreach ( (array) scandir( $dir ) as $name ) {
		$name = (string) $name;

		if ( '.' === $name || '..' === $name ) {
			continue;
		}

		if ( is_dir( $dir . '/' . $name ) ) {
			if ( ! in_array( $name, IMINIFY_ASSETS_FOLDERS, true ) ) {
				$problems[] = $name . '/: not a folder wordpress.org reads';
			}

			continue;
		}

		$rule = iminify_assets_rule( $name );

		if ( null === $rule ) {
			$problems[] = $name . ': not a name wordpress.org reads';
			continue;
		}

		$names[] = (string) preg_replace( '~\.[a-z]+$~', '', $name );

		if ( 1 === preg_match( '~^screenshot-([0-9]+)\.~', $name, $number ) ) {
			$screenshots[] = (int) $number[1];
		}

		$problems = array_merge( $problems, iminify_check_asset( $dir . '/' . $name, $name, $rule ) );
	}

	foreach ( array( 'icon-128x128', 'icon-256x256', 'banner-772x250', 'banner-1544x500', 'screenshot-1' ) as $needed ) {
		if ( ! in_array( $needed, $names, true ) ) {
			$problems[] = $needed . ': missing';
		}
	}

	foreach ( array_count_values( $names ) as $name => $count ) {
		if ( $count > 1 ) {
			$problems[] = $name . ': in more than one format';
		}
	}

	sort( $screenshots );

	if ( ( array() === $screenshots ? array() : range( 1, count( $screenshots ) ) ) !== $screenshots ) {
		$problems[] = 'screenshots: numbered ' . implode( ', ', $screenshots ) . ', not 1 to ' . count( $screenshots );
	}

	return array_merge( $problems, iminify_check_captions( $readme, count( $screenshots ) ) );
}

/**
 * The rule a file name falls under.
 *
 * @param string $name A file name.
 * @return array{0: string, 1: int|null, 2: int|null, 3: int}|null The extension, width, height and most bytes, or null.
 */
function iminify_assets_rule( string $name ): ?array {
	foreach ( IMINIFY_ASSETS as $pattern => $rule ) {
		if ( 1 === preg_match( $pattern, $name, $match ) ) {
			return array( $match[1], $rule[0], $rule[1], $rule[2] );
		}
	}

	return null;
}

/**
 * Lists what is wrong with one asset.
 *
 * @param string                                      $file The file.
 * @param string                                      $name Its name.
 * @param array{0: string, 1: int|null, 2: int|null, 3: int} $rule Its rule.
 * @return string[] One line per problem.
 */
function iminify_check_asset( string $file, string $name, array $rule ): array {
	list( $extension, $width, $height, $most ) = $rule;

	$problems = array();
	$bytes    = (string) file_get_contents( $file );

	if ( strlen( $bytes ) > $most ) {
		$problems[] = $name . ': ' . strlen( $bytes ) . ' bytes, over ' . $most;
	}

	if ( 'svg' === $extension ) {
		return array_merge( $problems, iminify_check_svg( $bytes, $name ) );
	}

	$size  = getimagesizefromstring( $bytes );
	$types = array(
		'png' => IMAGETYPE_PNG,
		'jpg' => IMAGETYPE_JPEG,
	);

	if ( false === $size || $types[ $extension ] !== $size[2] ) {
		$problems[] = $name . ': not a ' . strtoupper( $extension ) . ' image';
		return $problems;
	}

	if ( null !== $width && ( $width !== $size[0] || $height !== $size[1] ) ) {
		$problems[] = $name . ': ' . $size[0] . 'x' . $size[1] . ', not ' . $width . 'x' . $height;
	}

	foreach ( iminify_assets_metadata( $bytes, $extension ) as $kind ) {
		$problems[] = $name . ': carries ' . $kind;
	}

	return $problems;
}

/**
 * Lists what is wrong with an SVG icon: it is an SVG, runs nothing, loads
 * nothing, and names nothing on a developer's machine.
 *
 * @param string $svg  The file's text.
 * @param string $name Its name.
 * @return string[] One line per problem.
 */
function iminify_check_svg( string $svg, string $name ): array {
	$problems = array();

	if ( 1 !== preg_match( '~^\s*(?:<\?xml[^>]*>\s*)?<svg[\s>]~', $svg ) ) {
		$problems[] = $name . ': not an SVG image';
	}

	if ( 1 === preg_match( '~<script|<foreignObject|\son[a-z]+\s*=~i', $svg ) ) {
		$problems[] = $name . ': runs a script';
	}

	if ( 1 === preg_match( '~(?:href|src)\s*=\s*["\'](?!#)|url\(\s*["\']?(?!#)|@import~i', $svg ) ) {
		$problems[] = $name . ': loads another file';
	}

	foreach ( IMINIFY_BUILD_LOCAL as $pattern ) {
		if ( 1 === preg_match( $pattern, $svg ) ) {
			$problems[] = $name . ': names a local address or path';
			break;
		}
	}

	return $problems;
}

/**
 * The metadata an image carries, which an editor or a converter leaves in it
 * (a creation date, the path it was made from, a camera's EXIF).
 *
 * @param string $bytes     The file.
 * @param string $extension Its format.
 * @return string[] Each kind found.
 */
function iminify_assets_metadata( string $bytes, string $extension ): array {
	$found = array();
	$end   = strlen( $bytes );

	if ( 'png' === $extension ) {
		$at = 8;

		while ( $at + 8 <= $end ) {
			$length = iminify_assets_number( 'N', substr( $bytes, $at, 4 ) );
			$type   = substr( $bytes, $at + 4, 4 );

			if ( ! in_array( $type, IMINIFY_ASSETS_PNG_CHUNKS, true ) ) {
				$found[] = 'a chunk named ' . $type;
			}

			$at += 12 + $length;

			if ( 'IEND' === $type ) {
				break;
			}
		}

		if ( $at < $end ) {
			$found[] = 'bytes after its end';
		}
	}

	if ( 'jpg' === $extension ) {
		$at = 2;

		while ( $at + 4 <= $end && "\xFF" === $bytes[ $at ] ) {
			$marker = ord( $bytes[ $at + 1 ] );

			if ( 0xDA === $marker ) {
				break;
			}

			$length = iminify_assets_number( 'n', substr( $bytes, $at + 2, 2 ) );

			if ( 0xE1 === $marker || 0xFE === $marker || ( $marker >= 0xE3 && $marker <= 0xEF ) ) {
				$found[] = sprintf( 'an APP or comment segment (0x%X)', $marker );
			}

			$at += 2 + $length;
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * A big-endian length read from a file's bytes, 0 where the file ends first.
 *
 * @param string $format `N` for four bytes, `n` for two.
 * @param string $bytes  The bytes.
 * @return int The number.
 */
function iminify_assets_number( string $format, string $bytes ): int {
	$number = strlen( $bytes ) === ( 'N' === $format ? 4 : 2 ) ? unpack( $format, $bytes ) : false;

	return is_array( $number ) ? (int) $number[1] : 0;
}

/**
 * Lists what is wrong with the readme's screenshot captions: one numbered
 * line for each screenshot, 1 to N in order, each with words after its
 * number.
 *
 * @param string $readme      The readme's text.
 * @param int    $screenshots How many screenshots the folder holds.
 * @return string[] One line per problem.
 */
function iminify_check_captions( string $readme, int $screenshots ): array {
	if ( 1 !== preg_match( '~^== Screenshots ==\s*$(.*?)(?=^== |\z)~ms', $readme, $section ) ) {
		return 0 === $screenshots ? array() : array( 'readme.txt: no Screenshots section' );
	}

	$problems = array();
	$numbers  = array();

	foreach ( (array) preg_split( '~\R~', trim( $section[1] ) ) as $line ) {
		if ( '' === trim( (string) $line ) ) {
			continue;
		}

		if ( 1 !== preg_match( '~^([0-9]+)\.\s+(\S.*)$~', (string) $line, $caption ) ) {
			$problems[] = 'readme.txt: a Screenshots line that is no numbered caption: ' . trim( (string) $line );
			continue;
		}

		$numbers[] = (int) $caption[1];
	}

	if ( ( $screenshots > 0 ? range( 1, $screenshots ) : array() ) !== $numbers ) {
		$problems[] = 'readme.txt: captions numbered ' . implode( ', ', $numbers ) . ' for ' . $screenshots . ' screenshots';
	}

	return $problems;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$iminify_assets_dir    = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__ ) . '/.wordpress-org';
	$iminify_assets_readme = isset( $argv[2] ) ? $argv[2] : dirname( __DIR__ ) . '/readme.txt';

	$iminify_assets_problems = iminify_check_assets( $iminify_assets_dir, (string) file_get_contents( $iminify_assets_readme ) );

	if ( array() !== $iminify_assets_problems ) {
		fwrite( STDERR, implode( "\n", $iminify_assets_problems ) . "\n" );
		exit( 1 );
	}

	echo 'The assets have the names, sizes and formats wordpress.org reads, and every screenshot its caption.' . "\n";
}
