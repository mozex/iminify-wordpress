<?php
/**
 * Loads the translations that ship with the plugin.
 *
 * @package Iminify\WordPress
 */

namespace Iminify\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin carries its own translations in `languages/`, so a site in one of
 * those languages reads it in its own from the first install. A language pack
 * from translate.wordpress.org wins over them: WordPress looks for one in the
 * site's languages folder first, and only when it finds none for the locale does
 * this hand it the plugin's folder. The scripts follow the same choice, since
 * WordPress asks the same lookup for a script's translations when the script
 * names no folder of its own, so `wp_set_script_translations()` is called
 * without one.
 */
class Translations {

	const DOMAIN = 'iminify-image-optimizer';

	/**
	 * Hooks the lookup in. It runs before any of the plugin's strings is
	 * translated, since WordPress loads a domain the first time one is asked for.
	 */
	public static function register(): void {
		add_filter( 'lang_dir_for_domain', array( self::class, 'folder' ), 10, 3 );
	}

	/**
	 * The folder WordPress loads the plugin's translations from: the one it found
	 * itself (a language pack), else the plugin's own when it has the locale.
	 *
	 * @param string|false $path   The folder WordPress found, or false.
	 * @param string       $domain The text domain asked for.
	 * @param string       $locale The locale asked for.
	 * @return string|false
	 */
	public static function folder( $path, $domain, $locale ) {
		if ( self::DOMAIN !== $domain || false !== $path ) {
			return $path;
		}

		$folder = IMINIFY_DIR . '/languages/';
		$base   = $folder . self::DOMAIN . '-' . $locale;

		return is_readable( $base . '.l10n.php' ) || is_readable( $base . '.mo' ) ? $folder : $path;
	}
}
