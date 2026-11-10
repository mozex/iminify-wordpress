<?php
/**
 * Makes and checks the plugin's own translations in languages/. Run it with
 * WP-CLI from the plugin's folder in a wp-env site, after a production build
 * (`NODE_ENV=production npm run build`, which is what ships):
 *
 *   wp eval-file bin/i18n.php make
 *       The template from the code, each locale's .po brought up to it, and
 *       from each .po the files WordPress loads (.mo, .l10n.php and one JSON
 *       file per built script).
 *   wp eval-file bin/i18n.php check
 *       Fails when the code holds a string the template lacks, the template
 *       holds one the code no longer has, or a script's strings moved to
 *       another built script, so CI catches a template nobody remade.
 *
 * Strings come from the PHP and the built scripts in assets/build, never
 * src/, since WordPress names a script's translation file after the file it
 * loads. That the translations themselves are whole is TranslationsTest's.
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line tool, run through WP-CLI.

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . WPINC . '/pomo/po.php';

$iminify_i18n_domain   = 'iminify-image-optimizer';
$iminify_i18n_root     = dirname( __DIR__ );
$iminify_i18n_folder   = $iminify_i18n_root . '/languages';
$iminify_i18n_template = $iminify_i18n_folder . '/' . $iminify_i18n_domain . '.pot';

/**
 * Writes the template of every translatable string the shipped code holds.
 *
 * @param string $root   The plugin's folder.
 * @param string $target Where to write it.
 * @param string $domain The text domain.
 */
function iminify_i18n_template( string $root, string $target, string $domain ): void {
	if ( ! is_readable( $root . '/assets/build/settings.js' ) ) {
		WP_CLI::error( 'Run npm run build first: the scripts\' strings are read from assets/build.' );
	}

	/* A development build carries every shared part into every script, so its strings would be listed under scripts the shipped build leaves them out of, and the JSON files would be made for the wrong scripts. */
	if ( false !== strpos( (string) file_get_contents( $root . '/assets/build/settings.js', false, null, 0, 200 ), 'webpackBootstrap' ) ) {
		WP_CLI::error( 'assets/build holds a development build. Run NODE_ENV=production npm run build, which is what ships and what CI checks, then run this again.' );
	}

	WP_CLI::runcommand(
		sprintf(
			'i18n make-pot %s %s --domain=%s --include=iminify-image-optimizer.php,uninstall.php,includes,assets/build --exclude=build/iminify-image-optimizer --skip-audit',
			escapeshellarg( $root ),
			escapeshellarg( $target ),
			escapeshellarg( $domain )
		),
		array(
			'return'     => false,
			'exit_error' => true,
		)
	);
}

/**
 * Each entry of a template, keyed by its context and text, with what decides
 * where it goes: its plural, and the built scripts that use it.
 *
 * @param string $file The template.
 * @return array<string, string>
 */
function iminify_i18n_entries( string $file ): array {
	$po = new PO();

	if ( ! $po->import_from_file( $file ) ) {
		WP_CLI::error( 'Can\'t read ' . $file );
	}

	$entries = array();

	foreach ( $po->entries as $entry ) {
		$scripts = array();

		foreach ( $entry->references as $reference ) {
			$path = preg_replace( '/:\d+$/', '', $reference );

			if ( is_string( $path ) && 0 === strpos( $path, 'assets/build/' ) ) {
				$scripts[ $path ] = true;
			}
		}

		ksort( $scripts );

		$entries[ ( null === $entry->context ? '' : $entry->context . "\4" ) . $entry->singular ] = 'plural: ' . ( $entry->is_plural ? (string) $entry->plural : '-' ) . '; scripts: ' . implode( ', ', array_keys( $scripts ) );
	}

	return $entries;
}

$iminify_i18n_mode = $args[0] ?? '';

if ( 'make' === $iminify_i18n_mode ) {
	iminify_i18n_template( $iminify_i18n_root, $iminify_i18n_template, $iminify_i18n_domain );

	foreach ( array_merge( (array) glob( $iminify_i18n_folder . '/*.json' ), (array) glob( $iminify_i18n_folder . '/*.mo' ), (array) glob( $iminify_i18n_folder . '/*.l10n.php' ) ) as $iminify_i18n_made ) {
		unlink( (string) $iminify_i18n_made );
	}

	foreach ( (array) glob( $iminify_i18n_folder . '/*.po' ) as $iminify_i18n_po ) {
		WP_CLI::runcommand( sprintf( 'i18n update-po %s %s', escapeshellarg( $iminify_i18n_template ), escapeshellarg( (string) $iminify_i18n_po ) ), array( 'exit_error' => true ) );
	}

	foreach ( array( 'make-mo', 'make-php', 'make-json --no-purge' ) as $iminify_i18n_command ) {
		WP_CLI::runcommand( sprintf( 'i18n %s %s', $iminify_i18n_command, escapeshellarg( $iminify_i18n_folder ) ), array( 'exit_error' => true ) );
	}

	WP_CLI::success( 'The template and every locale\'s files are made.' );
} elseif ( 'check' === $iminify_i18n_mode ) {
	$iminify_i18n_fresh = wp_tempnam( 'iminify-i18n' );

	iminify_i18n_template( $iminify_i18n_root, $iminify_i18n_fresh, $iminify_i18n_domain );

	$iminify_i18n_now = iminify_i18n_entries( $iminify_i18n_fresh );
	$iminify_i18n_was = iminify_i18n_entries( $iminify_i18n_template );

	unlink( $iminify_i18n_fresh );

	$iminify_i18n_problems = array();

	foreach ( $iminify_i18n_now as $iminify_i18n_key => $iminify_i18n_where ) {
		if ( ! isset( $iminify_i18n_was[ $iminify_i18n_key ] ) ) {
			$iminify_i18n_problems[] = 'Not in the template: ' . str_replace( "\4", ' | ', $iminify_i18n_key );
		} elseif ( $iminify_i18n_was[ $iminify_i18n_key ] !== $iminify_i18n_where ) {
			$iminify_i18n_problems[] = 'Changed (' . $iminify_i18n_where . ', was ' . $iminify_i18n_was[ $iminify_i18n_key ] . '): ' . str_replace( "\4", ' | ', $iminify_i18n_key );
		}
	}

	foreach ( array_diff_key( $iminify_i18n_was, $iminify_i18n_now ) as $iminify_i18n_key => $iminify_i18n_where ) {
		$iminify_i18n_problems[] = 'No longer in the code: ' . str_replace( "\4", ' | ', $iminify_i18n_key );
	}

	if ( array() !== $iminify_i18n_problems ) {
		WP_CLI::error( implode( "\n", $iminify_i18n_problems ) . "\nRun wp eval-file bin/i18n.php make, then translate what each locale lacks." );
	}

	WP_CLI::success( count( $iminify_i18n_now ) . ' strings, all in the template.' );
} else {
	WP_CLI::error( 'Usage: wp eval-file bin/i18n.php make|check' );
}
