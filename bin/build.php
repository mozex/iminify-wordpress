<?php
/**
 * Copies what ships into build/iminify-image-optimizer: the tracked files
 * .distignore doesn't exclude, the admin scripts `npm run build` made from
 * `src/` (which ships beside them), and Action Scheduler's runtime files;
 * then zips that folder into build/iminify-image-optimizer.zip.
 *
 * Usage: php bin/build.php
 *
 * @package Iminify\WordPress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a command-line build script, run outside WordPress.

$iminify_root   = dirname( __DIR__ );
$iminify_target = $iminify_root . '/build/iminify-image-optimizer';

/**
 * Deletes a directory and everything in it, never following a link out of it.
 *
 * @param string $path The directory.
 */
function iminify_build_remove( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	foreach ( (array) scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			iminify_build_remove( $path . '/' . $entry );
		}
	}

	rmdir( $path );
}

/**
 * Copies one file, making its directory first.
 *
 * @param string $from The source file.
 * @param string $to   The destination file.
 */
function iminify_build_copy( string $from, string $to ): void {
	if ( ! is_dir( dirname( $to ) ) ) {
		mkdir( dirname( $to ), 0755, true );
	}

	copy( $from, $to );
}

$iminify_ignored = array_values(
	array_filter(
		array_map(
			static function ( string $line ): string {
				return trim( trim( $line ), '/' );
			},
			(array) file( $iminify_root . '/.distignore' )
		)
	)
);

exec( 'git -C ' . escapeshellarg( $iminify_root ) . ' ls-files', $iminify_files, $iminify_status );

if ( 0 !== $iminify_status ) {
	fwrite( STDERR, "git ls-files failed.\n" );
	exit( 1 );
}

iminify_build_remove( $iminify_target );

foreach ( $iminify_files as $iminify_file ) {
	foreach ( $iminify_ignored as $iminify_pattern ) {
		if ( $iminify_file === $iminify_pattern || 0 === strpos( $iminify_file, $iminify_pattern . '/' ) ) {
			continue 2;
		}
	}

	iminify_build_copy( $iminify_root . '/' . $iminify_file, $iminify_target . '/' . $iminify_file );
}

$iminify_assets = 'assets/build';

if ( ! is_file( $iminify_root . '/' . $iminify_assets . '/settings.asset.php' ) ) {
	fwrite( STDERR, "The admin scripts aren't built: run npm run build first.\n" );
	exit( 1 );
}

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $iminify_root . '/' . $iminify_assets, FilesystemIterator::SKIP_DOTS ) ) as $iminify_entry ) {
	$iminify_relative = substr( str_replace( '\\', '/', $iminify_entry->getPathname() ), strlen( str_replace( '\\', '/', $iminify_root ) ) + 1 );

	if ( '.map' !== substr( $iminify_relative, -4 ) ) {
		iminify_build_copy( $iminify_entry->getPathname(), $iminify_target . '/' . $iminify_relative );
	}
}

$iminify_scheduler = 'vendor/woocommerce/action-scheduler';

if ( ! is_file( $iminify_root . '/' . $iminify_scheduler . '/action-scheduler.php' ) ) {
	fwrite( STDERR, "Action Scheduler is missing: run composer install first.\n" );
	exit( 1 );
}

$iminify_entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $iminify_root . '/' . $iminify_scheduler, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iminify_entries as $iminify_entry ) {
	$iminify_relative = substr( str_replace( '\\', '/', $iminify_entry->getPathname() ), strlen( str_replace( '\\', '/', $iminify_root ) ) + 1 );

	if ( preg_match( '#/(AGENTS|CLAUDE|README)\.md$#', $iminify_relative ) ) {
		continue;
	}

	iminify_build_copy( $iminify_entry->getPathname(), $iminify_target . '/' . $iminify_relative );
}

/*
 * The zip wordpress.org takes on submission, with the plugin's folder at its
 * top, as the directory's own zips have it.
 */
$iminify_zip_path = $iminify_root . '/build/iminify-image-optimizer.zip';
$iminify_zip      = new ZipArchive();

if ( true !== $iminify_zip->open( $iminify_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Can't write " . $iminify_zip_path . "\n" );
	exit( 1 );
}

$iminify_built = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $iminify_target, FilesystemIterator::SKIP_DOTS ) );
$iminify_names = array();

foreach ( $iminify_built as $iminify_entry ) {
	$iminify_names[ substr( str_replace( '\\', '/', $iminify_entry->getPathname() ), strlen( str_replace( '\\', '/', $iminify_root . '/build/' ) ) ) ] = $iminify_entry->getPathname();
}

ksort( $iminify_names );

foreach ( $iminify_names as $iminify_name => $iminify_path ) {
	$iminify_zip->addFile( $iminify_path, $iminify_name );
}

if ( ! $iminify_zip->close() ) {
	fwrite( STDERR, "Can't write " . $iminify_zip_path . "\n" );
	exit( 1 );
}

echo 'Built ' . $iminify_target . ' and ' . $iminify_zip_path . "\n";
