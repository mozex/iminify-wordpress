<?php
/**
 * The delivery rules another image optimizer left behind.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Switching;

use Iminify\WordPress\Capabilities;
use Iminify\WordPress\Delivery\Config_File;
use Iminify\WordPress\Delivery\Rules;
use Iminify\WordPress\Media\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Several optimizers leave their rules for serving WebP and AVIF behind once they
 * are switched off: EWWW keeps its `.htaccess` block until it is deleted,
 * TinyPNG has no deactivation at all, ShortPixel keeps its blocks on a server it
 * took for Nginx, and Imagify and Converter for Media keep theirs wherever they
 * couldn't write. Each still answers a browser's request for an image with a copy
 * that plugin made, however old, ahead of whatever Iminify serves; and Smush's
 * rule for the PNG images it turned into JPEG stays among WordPress's rewrite
 * rules, since nothing flushes them when it goes.
 *
 * They are looked for in a fixed list of files (`files()`), by each plugin's own
 * markers matched as whole lines, and only while that plugin is active nowhere
 * that reads the file, since otherwise they are its live rules. Only a block that
 * rewrites is listed: one that only names file types (Imagify's, Converter for
 * Media's in its own folder, which serves the copies the plugin took over from it)
 * or an empty one `insert_with_markers()` leaves when a plugin takes its rules out
 * changes nothing. A block goes only on the person's press (`remove()`), under the
 * lock WordPress takes when it writes the file, and where the file can't be
 * written the settings show the lines to remove by hand. Nothing else in another
 * plugin's files is ever touched, and no file is deleted.
 *
 * On a network the site's `.htaccess`, `wp-content/.htaccess`, the main site's
 * uploads folder and Imagify's Nginx file serve every site: the main site lists
 * them as shared, removing them asks what managing the network asks, and a plugin
 * counts as switched off only once no site has it on. Another site lists its own
 * uploads folder alone.
 *
 * @phpstan-type Leftover array{id: string, plugin: string, name: string, kind: string, file: string, lines: string, shared: bool, why: string, can: bool, what: string}
 */
class Leftovers {

	/**
	 * Each plugin's markers, as it writes them after `# BEGIN ` and `# END `.
	 */
	const MARKERS = array(
		'ewww'       => array( 'EWWWIO' ),
		'shortpixel' => array( 'ShortPixelWebp' ),
		'tinypng'    => array( 'tiny-compress-images' ),
		'imagify'    => array( 'Imagify: rewrite rules for webp', 'Imagify: rewrite rules for avif' ),
		'converter'  => array( 'Converter for Media', 'WebP Converter' ),
	);

	/**
	 * The plugins whose rules serve copies from a folder of their own rather than beside the image.
	 */
	const OWN_FOLDER = array( 'converter' );

	/**
	 * Smush's rule among WordPress's rewrite rules, found by what it asks for.
	 */
	const SMUSH = 'smush';

	const SMUSH_QUERY = 'smush_load_fallback_png=';

	/**
	 * The key of WordPress's rewrite rules in a leftover's id.
	 */
	const RULES = 'rules';

	/**
	 * The key of Imagify's file for Nginx, which only its markers are looked for in.
	 */
	const NGINX = 'nginx';

	/**
	 * How many sites of a network one query asks whether a plugin is on.
	 */
	const SITES_A_QUERY = 100;

	/**
	 * The rules another plugin left that this site's page lists.
	 *
	 * @return list<Leftover>
	 */
	public static function found(): array {
		$found = array();
		$on    = array();

		foreach ( self::files() as $key => $file ) {
			if ( ! is_file( $file['path'] ) || ! is_readable( $file['path'] ) ) {
				continue;
			}

			$lines = Config_File::lines( $file['path'] );

			foreach ( self::MARKERS as $slug => $markers ) {
				if ( self::NGINX === $key && 'imagify' !== $slug ) {
					continue;
				}

				$blocks = self::blocks( $lines, $markers );

				if ( ! $blocks['damaged'] && array() === $blocks['rewriting'] ) {
					continue;
				}

				$reader = Others::reader( $slug );

				if ( null === $reader ) {
					continue;
				}

				/* A network's every site is asked once a look, however many shared files hold that plugin's blocks. */
				if ( $file['shared'] && ! isset( $on[ $slug ] ) ) {
					$on[ $slug ] = self::active_anywhere( $reader );
				}

				if ( $file['shared'] ? $on[ $slug ] : $reader->active() ) {
					continue;
				}

				$found[] = self::leftover( $reader, $key, $file, $lines, $blocks );
			}
		}

		$smush = self::smush();

		if ( null !== $smush ) {
			$found[] = $smush;
		}

		return $found;
	}

	/**
	 * Takes one leftover out, on the person's press: the blocks it lists from its
	 * file, found again under the file's lock, or Smush's rules from WordPress's.
	 *
	 * @param string $id The leftover's id, as `found()` gave it.
	 * @return true|\WP_Error
	 */
	public static function remove( string $id ) {
		$leftover = null;

		foreach ( self::found() as $found ) {
			if ( $found['id'] === $id ) {
				$leftover = $found;
			}
		}

		if ( null === $leftover ) {
			return new \WP_Error( 'leftover_gone', __( 'Those lines are gone already, or their plugin is on again.', 'iminify-image-optimizer' ), array( 'status' => 404 ) );
		}

		if ( ! $leftover['can'] ) {
			return new \WP_Error( 'rest_forbidden', __( 'Only someone who can manage the network removes lines every site shares.', 'iminify-image-optimizer' ), array( 'status' => 403 ) );
		}

		if ( self::RULES === $leftover['kind'] ) {
			self::remove_smush();

			return true;
		}

		$markers = self::MARKERS[ $leftover['plugin'] ];
		$removed = Config_File::edit(
			$leftover['file'],
			static function ( array $lines ) use ( $markers ): ?array {
				return self::without( $lines, $markers );
			}
		);

		/* A file it can't write, or a block without its END line, which `without()` leaves. */
		if ( ! $removed ) {
			return new \WP_Error( 'leftover_by_hand', __( 'The plugin can’t take these lines out. Remove them by hand.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		/* A block in the uploads folder ran before the plugin's own rules, so the last look at them is out of date. */
		if ( Rules::active() ) {
			Rules::check();
		}

		return true;
	}

	/**
	 * The files another optimizer writes its rules into, by a key of their own,
	 * each found once: the site's `.htaccess` and WordPress's own when WordPress is
	 * in a folder of its own (EWWW writes there then), `wp-content`'s, the uploads
	 * folder's, and Imagify's file for Nginx beside the site's `.htaccess`. Another
	 * site of a network has only its own uploads folder, and none when a shared
	 * media library points it at the main site's, whose file every site reads.
	 *
	 * @return array<string, array{path: string, shared: bool}>
	 */
	public static function files(): array {
		$uploads = Paths::uploads() . '/.htaccess';

		if ( is_multisite() && ! is_main_site() ) {
			switch_to_blog( get_main_site_id() );
			$main = Paths::uploads() . '/.htaccess';
			restore_current_blog();

			return self::same( $uploads, $main ) ? array() : array(
				'uploads' => array(
					'path'   => $uploads,
					'shared' => false,
				),
			);
		}

		$root  = Rules::file();
		$paths = array(
			'root'      => $root,
			'abspath'   => trailingslashit( wp_normalize_path( ABSPATH ) ) . '.htaccess',
			'content'   => wp_normalize_path( WP_CONTENT_DIR ) . '/.htaccess',
			'uploads'   => $uploads,
			self::NGINX => null === $root ? null : dirname( $root ) . '/conf/imagify.conf',
		);
		$files = array();

		foreach ( $paths as $key => $path ) {
			if ( null === $path ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( self::same( $file['path'], $path ) ) {
					continue 2;
				}
			}

			$files[ $key ] = array(
				'path'   => $path,
				'shared' => is_multisite(),
			);
		}

		return $files;
	}

	/**
	 * Whether two paths name one file, links followed.
	 *
	 * @param string $one   A path.
	 * @param string $other Another.
	 */
	protected static function same( string $one, string $other ): bool {
		$paths = array();

		foreach ( array( $one, $other ) as $path ) {
			$real    = realpath( $path );
			$paths[] = wp_normalize_path( false === $real ? $path : $real );
		}

		return $paths[0] === $paths[1];
	}

	/**
	 * A plugin's blocks in a file's lines: those that rewrite a request, and
	 * whether one of its BEGIN lines has no END line after it, or holds another
	 * block's BEGIN or END line before it, which leaves the file to be changed by
	 * hand. Each marker is matched as a whole line.
	 *
	 * @param list<string> $lines   The file's lines.
	 * @param list<string> $markers The plugin's markers.
	 * @return array{rewriting: list<array{0: int, 1: int}>, damaged: bool, begin: string}
	 */
	public static function blocks( array $lines, array $markers ): array {
		$trimmed = array_map( 'trim', $lines );
		$found   = array(
			'rewriting' => array(),
			'damaged'   => false,
			'begin'     => '',
		);
		$count   = count( $trimmed );

		for ( $at = 0; $at < $count; $at++ ) {
			foreach ( $markers as $marker ) {
				if ( '# BEGIN ' . $marker !== $trimmed[ $at ] ) {
					continue;
				}

				$end = array_search( '# END ' . $marker, array_slice( $trimmed, $at + 1, null, true ), true );

				$inside = false === $end ? array() : array_slice( $trimmed, $at + 1, (int) $end - $at - 1 );

				/* Without its END line, or around another block's BEGIN or END line (WordPress's), taking it out would take more than its own lines. */
				if ( false === $end || array() !== preg_grep( '/^# (?:BEGIN|END)\s/', $inside ) ) {
					$found['damaged'] = true;
					$found['begin']   = $trimmed[ $at ];

					return $found;
				}

				if ( self::rewrites( $inside ) ) {
					$found['rewriting'][] = array( $at, (int) $end );
				}

				$at = (int) $end;
				break;
			}
		}

		return $found;
	}

	/**
	 * A file's lines without a plugin's rewriting blocks and the blank line after
	 * each, or null when one of its BEGIN lines has no END line, which leaves the
	 * file alone.
	 *
	 * @param list<string> $lines   The file's lines.
	 * @param list<string> $markers The plugin's markers.
	 * @return list<string>|null
	 */
	public static function without( array $lines, array $markers ): ?array {
		$blocks = self::blocks( $lines, $markers );

		if ( $blocks['damaged'] ) {
			return null;
		}

		$drop = array();

		foreach ( $blocks['rewriting'] as $block ) {
			for ( $at = $block[0]; $at <= $block[1]; $at++ ) {
				$drop[ $at ] = true;
			}

			if ( isset( $lines[ $block[1] + 1 ] ) && '' === trim( $lines[ $block[1] + 1 ] ) ) {
				$drop[ $block[1] + 1 ] = true;
			}
		}

		return array_values( array_diff_key( $lines, $drop ) );
	}

	/**
	 * Whether a block's lines rewrite a request: Apache's `RewriteRule`, or Nginx's
	 * `rewrite` and `try_files`. A line that is a comment never counts.
	 *
	 * @param list<string> $lines The block's lines, trimmed.
	 */
	protected static function rewrites( array $lines ): bool {
		foreach ( $lines as $line ) {
			if ( preg_match( '/^(?:RewriteRule|rewrite|try_files)\s/i', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One leftover as the settings show it.
	 *
	 * @param Reader                                                            $reader The plugin's reader.
	 * @param string                                                            $key    The file's key.
	 * @param array{path: string, shared: bool}                                 $file   The file.
	 * @param list<string>                                                      $lines  The file's lines.
	 * @param array{rewriting: list<array{0: int, 1: int}>, damaged: bool, begin: string} $blocks The plugin's blocks in it.
	 * @return Leftover
	 */
	protected static function leftover( Reader $reader, string $key, array $file, array $lines, array $blocks ): array {
		$shown = array();

		foreach ( $blocks['rewriting'] as $block ) {
			$shown[] = str_replace( "\r", '', implode( "\n", array_slice( $lines, $block[0], $block[1] - $block[0] + 1 ) ) );
		}

		$why = '';

		if ( $blocks['damaged'] ) {
			$why   = 'damaged';
			$shown = array( $blocks['begin'] );
		} elseif ( ! wp_is_writable( $file['path'] ) ) {
			$why = 'unwritable';
		}

		return array(
			'id'     => $reader->slug() . ':' . $key,
			'plugin' => $reader->slug(),
			'name'   => $reader->name(),
			'kind'   => self::NGINX === $key ? 'nginx' : 'htaccess',
			'file'   => $file['path'],
			'lines'  => implode( "\n\n", $shown ),
			'shared' => $file['shared'],
			'why'    => $why,
			'can'    => Capabilities::can( $file['shared'] ? Capabilities::NETWORK : Capabilities::SETTINGS ),
			'what'   => self::what( $reader, self::NGINX === $key ),
		);
	}

	/**
	 * What a plugin's rules still do, in a sentence.
	 *
	 * @param Reader $reader The plugin's reader.
	 * @param bool   $nginx  Whether they are Nginx's.
	 */
	protected static function what( Reader $reader, bool $nginx ): string {
		$what = in_array( $reader->slug(), self::OWN_FOLDER, true )
			/* translators: %s: another image optimizer's name. */
			? __( '%s no longer uses them, yet they still answer a browser that reads WebP or AVIF with the copy it made of an image in its own folder, in place of the image, so a visitor can get a copy made before Iminify optimized it.', 'iminify-image-optimizer' )
			/* translators: %s: another image optimizer's name. */
			: __( '%s no longer uses them, yet they still answer a browser that reads WebP or AVIF with whatever WebP or AVIF file is named after an image, however old, in place of the image, so a visitor can get a copy made before Iminify optimized it.', 'iminify-image-optimizer' );

		$what = sprintf( $what, $reader->name() );

		return $nginx ? $what . ' ' . __( 'Nginx keeps them until it is reloaded, so reload it once they are gone.', 'iminify-image-optimizer' ) : $what;
	}

	/**
	 * Smush's rule among this site's rewrite rules, while Smush is off.
	 *
	 * @return Leftover|null
	 */
	protected static function smush(): ?array {
		$reader = Others::reader( self::SMUSH );
		$rules  = self::smush_rules();

		if ( null === $reader || array() === $rules || $reader->active() ) {
			return null;
		}

		$lines = array();

		foreach ( $rules as $pattern => $target ) {
			$lines[] = $pattern . ' => ' . $target;
		}

		return array(
			'id'     => self::SMUSH . ':' . self::RULES,
			'plugin' => self::SMUSH,
			'name'   => $reader->name(),
			'kind'   => self::RULES,
			'file'   => '',
			'lines'  => implode( "\n", $lines ),
			'shared' => false,
			'why'    => '',
			'can'    => Capabilities::can( Capabilities::SETTINGS ),
			'what'   => __( 'Smush added this rule for the PNG images it turned into JPEG, and it is still among WordPress’s rewrite rules: a request for such a PNG, no longer there, ends on your home page in place of a “not found” answer.', 'iminify-image-optimizer' ),
		);
	}

	/**
	 * Smush's entries in WordPress's rewrite rules.
	 *
	 * @return array<string, string>
	 */
	protected static function smush_rules(): array {
		$rules = get_option( 'rewrite_rules' );
		$found = array();

		foreach ( is_array( $rules ) ? $rules : array() as $pattern => $target ) {
			if ( is_string( $target ) && false !== strpos( $target, self::SMUSH_QUERY ) ) {
				$found[ (string) $pattern ] = $target;
			}
		}

		return $found;
	}

	/**
	 * Takes Smush's entries out of WordPress's rewrite rules and leaves every other
	 * as it is; never a flush, which would also write WordPress's own block.
	 */
	protected static function remove_smush(): void {
		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		update_option( 'rewrite_rules', array_diff_key( $rules, self::smush_rules() ) );
	}

	/**
	 * Whether a plugin is on anywhere a shared file serves: on this site or the
	 * network, or on any other site of the network, read from each site's list of
	 * active plugins a hundred sites a query.
	 *
	 * @param Reader $reader The plugin's reader.
	 */
	protected static function active_anywhere( Reader $reader ): bool {
		global $wpdb;

		if ( $reader->active() || ! is_multisite() ) {
			return $reader->active();
		}

		$sites = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);

		foreach ( array_chunk( $sites, self::SITES_A_QUERY ) as $chunk ) {
			$asks = array();

			foreach ( $chunk as $site ) {
				$asks[] = "SELECT option_value FROM `{$wpdb->get_blog_prefix( $site )}options` WHERE option_name = 'active_plugins'";
			}

			$quiet = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- each site's own options table, by the site's prefix; nothing in the query comes from a request.
			$values = (array) $wpdb->get_col( implode( ' UNION ALL ', $asks ) );
			$failed = '' !== $wpdb->last_error;
			$wpdb->suppress_errors( $quiet );

			/* A site whose tables are missing fails the whole query, which can't then say the plugin is off anywhere. */
			if ( $failed ) {
				return true;
			}

			foreach ( $values as $value ) {
				foreach ( (array) maybe_unserialize( (string) $value ) as $basename ) {
					if ( is_string( $basename ) && $reader->owns( $basename ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
