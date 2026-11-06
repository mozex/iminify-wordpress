<?php
/**
 * The folders outside the media library whose images the plugin optimizes.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress\Folders;

use Iminify\WordPress\Media\Paths;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The folders someone chose under `wp-content`: the active theme, its parent, or
 * any folder named by its path there. Every path is kept relative to
 * `wp-content`, so a moved site still finds its files.
 *
 * A folder is refused when it is, or lies inside, the uploads folder (the media
 * library, with the plugin's backups), the plugin's own folder (every update
 * replaces it), a folder other tools fill with copies, caches or backups, or a
 * link to somewhere else; a walk never enters such a folder below a chosen one,
 * nor a dependency or hidden folder, nor follows a link. A file is read or
 * written only through `guard()`, which asks the same again of its real path,
 * since a path in the table is data anyone with the database could have written.
 *
 * On a network, `wp-content` is every site's, so only the main site lists
 * folders (`available()`): each file is optimized once, for the whole network.
 */
class Folders {

	const OPTION = 'iminify_folders';

	/**
	 * The most folders the list keeps.
	 */
	const MAX_FOLDERS = 50;

	/**
	 * Folders directly under `wp-content` that other tools fill with copies,
	 * caches, backups or logs: an image there is never the site's own.
	 */
	const TOOL_FOLDERS = array(
		'ai1wm-backups',
		'backup',
		'backups',
		'backups-dup-lite',
		'backups-dup-pro',
		'cache',
		'et-cache',
		'ewww',
		'languages',
		'litespeed',
		'smush-webp',
		'updraft',
		'upgrade',
		'upgrade-temp-backup',
		'uploads-webpc',
		'w3tc-config',
		'webp-express',
		'wflogs',
		'wpvividbackups',
	);

	/**
	 * Folders a walk never enters, wherever they are: a project's dependencies and build tools.
	 */
	const SKIPPED = array( 'bower_components', 'node_modules', 'vendor' );

	/**
	 * The file names a walk takes, by extension; what the file really is, is read from its bytes before it is sent.
	 */
	const EXTENSIONS = array( 'gif', 'jpe', 'jpeg', 'jpg', 'png', 'webp' );

	/**
	 * A copy someone made beside an image, `photo.jpg.webp`, which is never an original.
	 */
	const COPY_NAME = '/\.(?:jpe?g|jpe|png|gif|webp)\.(?:webp|avif)$/i';

	/**
	 * Whether this site lists folders: any single site, and only the main site of a network.
	 */
	public static function available(): bool {
		return ! is_multisite() || is_main_site();
	}

	/**
	 * `wp-content`, without a trailing slash.
	 */
	public static function root(): string {
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	}

	/**
	 * A path's place under `wp-content`, or null for one outside it.
	 *
	 * @param string $path An absolute path.
	 */
	public static function relative( string $path ): ?string {
		$base = self::root() . '/';
		$path = wp_normalize_path( $path );

		if ( 0 !== strpos( $path, $base ) ) {
			return null;
		}

		return self::clean( (string) substr( $path, strlen( $base ) ) );
	}

	/**
	 * The absolute path of a path kept relative to `wp-content`.
	 *
	 * @param string $relative A path relative to `wp-content`.
	 */
	public static function absolute( string $relative ): string {
		return Paths::under( self::root(), $relative );
	}

	/**
	 * The folders chosen, relative to `wp-content`.
	 *
	 * @return list<string>
	 */
	public static function listed(): array {
		if ( ! self::available() ) {
			return array();
		}

		$folders = get_option( self::OPTION );
		$listed  = array();

		foreach ( is_array( $folders ) ? $folders : array() as $folder ) {
			$folder = is_string( $folder ) ? self::clean( $folder ) : null;

			if ( null !== $folder && ! in_array( $folder, $listed, true ) ) {
				$listed[] = $folder;
			}
		}

		return $listed;
	}

	/**
	 * Whether a file lies inside a chosen folder.
	 *
	 * @param string $relative The file's path relative to `wp-content`.
	 */
	public static function contains( string $relative ): bool {
		foreach ( self::listed() as $folder ) {
			if ( 0 === strpos( $relative, $folder . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A folder someone named, checked: its path relative to `wp-content`, or why
	 * it can't be chosen. It may be named relative to `wp-content` or by its
	 * absolute path inside it.
	 *
	 * @param string $named The folder as someone named it.
	 * @return string|WP_Error
	 */
	public static function check( string $named ) {
		$named    = trim( wp_normalize_path( trim( $named ) ) );
		$relative = self::relative( $named ) ?? self::clean( $named );

		if ( null === $relative ) {
			return new WP_Error( 'folder_invalid', __( 'Name a folder inside wp-content, such as themes/your-theme.', 'iminify-image-optimizer' ), array( 'status' => 422 ) );
		}

		$absolute = self::absolute( $relative );

		if ( ! is_dir( $absolute ) ) {
			return new WP_Error( 'folder_missing', __( 'There is no folder at that path inside wp-content.', 'iminify-image-optimizer' ), array( 'status' => 422 ) );
		}

		if ( ! self::is_real( $absolute, $relative ) ) {
			return new WP_Error( 'folder_link', __( 'That folder is a link to another place, so Iminify leaves it out.', 'iminify-image-optimizer' ), array( 'status' => 422 ) );
		}

		$why = self::forbidden( $relative );

		if ( null !== $why ) {
			return new WP_Error( 'folder_forbidden', $why, array( 'status' => 422 ) );
		}

		return $relative;
	}

	/**
	 * Why a folder can never be chosen, or null when it can.
	 *
	 * @param string $relative The folder's path relative to `wp-content`.
	 */
	public static function forbidden( string $relative ): ?string {
		/* Compared whatever the case, since a file system may not tell `Uploads` from `uploads`. */
		$absolute = strtolower( self::absolute( $relative ) );
		$uploads  = strtolower( Paths::uploads() );

		if ( $absolute === $uploads || 0 === strpos( $absolute . '/', $uploads . '/' ) ) {
			return __( 'That\'s the media library\'s folder: its images are optimized from the media library.', 'iminify-image-optimizer' );
		}

		$own = strtolower( untrailingslashit( wp_normalize_path( IMINIFY_DIR ) ) );

		if ( $absolute === $own || 0 === strpos( $absolute . '/', $own . '/' ) ) {
			return __( 'That\'s the Iminify plugin\'s own folder, which every update replaces.', 'iminify-image-optimizer' );
		}

		$parts = explode( '/', $relative );

		if ( in_array( strtolower( $parts[0] ), self::TOOL_FOLDERS, true ) ) {
			return __( 'Other tools keep copies, caches or backups in that folder, so its images aren\'t the site\'s own.', 'iminify-image-optimizer' );
		}

		foreach ( $parts as $part ) {
			if ( in_array( strtolower( $part ), array_merge( Paths::BACKUP_FOLDERS, array( 'blogs.dir' ) ), true ) ) {
				return __( 'That folder holds the media library\'s files or their backups, which Iminify handles from the media library.', 'iminify-image-optimizer' );
			}

			if ( '.' === substr( $part, 0, 1 ) || in_array( strtolower( $part ), self::SKIPPED, true ) ) {
				return __( 'That folder holds hidden files or a project\'s dependencies, which Iminify leaves alone.', 'iminify-image-optimizer' );
			}
		}

		return null;
	}

	/**
	 * Whether a walk enters a folder below a chosen one.
	 *
	 * @param string $absolute The folder.
	 */
	public static function enters( string $absolute ): bool {
		$relative = self::relative( $absolute );

		return null !== $relative && ! is_link( $absolute ) && null === self::forbidden( $relative );
	}

	/**
	 * Whether a walk takes a file: an image by its name, never a hidden file or a copy someone made beside another image.
	 *
	 * @param string $name The file's name.
	 */
	public static function takes( string $name ): bool {
		if ( '.' === substr( $name, 0, 1 ) || 1 === preg_match( self::COPY_NAME, $name ) ) {
			return false;
		}

		return in_array( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * Adds a folder. A chosen folder inside it gives way to it, and one it lies inside makes it needless.
	 *
	 * @param string $named The folder as someone named it.
	 * @return string|WP_Error The folder, relative to `wp-content`.
	 */
	public static function add( string $named ) {
		if ( ! self::available() ) {
			return new WP_Error( 'folders_unavailable', __( 'On a network, other folders are chosen on the main site.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		$folder = self::check( $named );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$kept = array();

		foreach ( self::listed() as $listed ) {
			if ( $listed === $folder || 0 === strpos( $folder . '/', $listed . '/' ) ) {
				return new WP_Error(
					'folder_listed',
					sprintf(
						/* translators: %s: a folder's path inside wp-content. */
						__( 'That folder is already in the list, inside %s.', 'iminify-image-optimizer' ),
						$listed
					),
					array( 'status' => 409 )
				);
			}

			if ( 0 !== strpos( $listed . '/', $folder . '/' ) ) {
				$kept[] = $listed;
			}
		}

		if ( count( $kept ) >= self::MAX_FOLDERS ) {
			return new WP_Error( 'folders_full', __( 'The list holds as many folders as it can. Remove one, or choose the folder that holds several of them.', 'iminify-image-optimizer' ), array( 'status' => 409 ) );
		}

		Table::create();
		update_option( self::OPTION, array_merge( $kept, array( $folder ) ), false );

		return $folder;
	}

	/**
	 * Takes a folder off the list. Its files keep their records, the proof of what
	 * the plugin wrote, so choosing it again never sends its own output twice.
	 *
	 * @param string $folder The folder, relative to `wp-content`.
	 */
	public static function remove( string $folder ): bool {
		$listed = self::listed();

		if ( ! in_array( $folder, $listed, true ) ) {
			return false;
		}

		update_option( self::OPTION, array_values( array_diff( $listed, array( $folder ) ) ), false );

		return true;
	}

	/**
	 * The folders offered with one press: the active theme and its parent.
	 *
	 * @return list<array{path: string, name: string, kind: string, listed: bool}>
	 */
	public static function offered(): array {
		$offered = array();
		$theme   = wp_get_theme();
		$themes  = array(
			'theme'  => array( get_stylesheet_directory(), (string) $theme->get( 'Name' ) ),
			'parent' => array( get_template_directory(), $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '' ),
		);

		foreach ( $themes as $kind => $about ) {
			$folder = self::relative( (string) $about[0] );

			if ( null === $folder || ( 'parent' === $kind && get_template_directory() === get_stylesheet_directory() ) || is_wp_error( self::check( $folder ) ) ) {
				continue;
			}

			$offered[] = array(
				'path'   => $folder,
				'name'   => '' === $about[1] ? $folder : $about[1],
				'kind'   => $kind,
				'listed' => self::contains( $folder . '/' ) || in_array( $folder, self::listed(), true ),
			);
		}

		return $offered;
	}

	/**
	 * A file's absolute path, when the plugin may read and write it: inside a
	 * chosen folder, in no folder the walk leaves out, and no link, with its real
	 * path where its kept path says. Null otherwise.
	 *
	 * @param string $relative The file's path relative to `wp-content`.
	 */
	public static function guard( string $relative ): ?string {
		$relative = self::clean( $relative );

		if ( null === $relative || ! self::contains( $relative ) || null !== self::forbidden( dirname( $relative ) ) ) {
			return null;
		}

		$absolute = self::absolute( $relative );

		if ( is_link( $absolute ) || ! is_file( $absolute ) || ! self::is_real( $absolute, $relative ) ) {
			return null;
		}

		return $absolute;
	}

	/**
	 * Whether a path inside `wp-content` really is there, with no link on the way.
	 *
	 * @param string $absolute The path.
	 * @param string $relative Its path relative to `wp-content`.
	 */
	protected static function is_real( string $absolute, string $relative ): bool {
		$real = realpath( $absolute );
		$root = realpath( self::root() );

		return false !== $real && false !== $root && wp_normalize_path( $real ) === untrailingslashit( wp_normalize_path( $root ) ) . '/' . $relative;
	}

	/**
	 * A relative path with its slashes tidied, or null for an empty one or one that climbs out (`..`).
	 *
	 * @param string $relative A path relative to `wp-content`.
	 */
	protected static function clean( string $relative ): ?string {
		$relative = trim( wp_normalize_path( $relative ), '/' );

		if ( '' === $relative || false !== strpos( '/' . $relative . '/', '/../' ) || false !== strpos( '/' . $relative . '/', '/./' ) || false !== strpos( $relative, "\0" ) ) {
			return null;
		}

		return $relative;
	}
}
