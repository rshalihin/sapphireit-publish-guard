<?php
/**
 * Release packager: builds dist/sapphireit-publish-guard.zip from the working tree.
 *
 * What is left out is decided by `.distignore`, one entry per line, relative to the
 * plugin root: an entry excludes the file or directory at that path, and may use `*`
 * and `?` wildcards. On top of that, every dotfile and dot-directory is excluded at any
 * depth, so a new tooling cache can never leak into a release by being forgotten here.
 *
 * Every file sits under a top-level `sapphireit-publish-guard/` folder, which is what
 * "Upload Plugin" and `wp plugin install <zip>` expect.
 *
 * Usage: `npm run package` (builds assets and the POT first), or
 * `php bin/build-zip.php` on its own. PHP rather than a shell zip so it behaves the same
 * on Windows. Needs the `zip` extension.
 *
 * @package ProductPublishGuard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "build-zip: the PHP zip extension is not loaded.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 1 );
}

$sit_wcpg_root   = dirname( __DIR__ );
$sit_wcpg_slug   = 'sapphireit-publish-guard';
$sit_wcpg_target = $sit_wcpg_root . '/dist/' . $sit_wcpg_slug . '.zip';

// Files a release cannot work without. Missing any of them is a broken build, not a warning.
$sit_wcpg_required = array(
	$sit_wcpg_slug . '.php',
	'readme.txt',
	'build/editor.js',
	'build/editor.asset.php',
	'languages/' . $sit_wcpg_slug . '.pot',
);

foreach ( $sit_wcpg_required as $sit_wcpg_path ) {
	if ( ! is_file( $sit_wcpg_root . '/' . $sit_wcpg_path ) ) {
		fwrite( STDERR, "build-zip: {$sit_wcpg_path} is missing. Run `npm run build` and `npm run makepot` first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, CLI only.
$sit_wcpg_ignore_raw = (string) file_get_contents( $sit_wcpg_root . '/.distignore' );
$sit_wcpg_ignore     = array();

foreach ( preg_split( '/\r\n|\r|\n/', $sit_wcpg_ignore_raw ) as $sit_wcpg_line ) {
	$sit_wcpg_line = trim( $sit_wcpg_line );

	if ( '' !== $sit_wcpg_line && '#' !== $sit_wcpg_line[0] ) {
		$sit_wcpg_ignore[] = trim( str_replace( '\\', '/', $sit_wcpg_line ), '/' );
	}
}

// The output directory is never part of its own archive.
$sit_wcpg_ignore[] = 'dist';

/*
 * Whether a forward-slashed path relative to the plugin root is excluded. A closure, not
 * a function: sit_wcpg_bootstrap() stays the only global function the project defines.
 */
$sit_wcpg_is_excluded = static function ( string $relative ) use ( $sit_wcpg_ignore ): bool {
	foreach ( explode( '/', $relative ) as $segment ) {
		if ( '.' === $segment[0] ) {
			return true;
		}
	}

	foreach ( $sit_wcpg_ignore as $pattern ) {
		if ( fnmatch( $pattern, $relative ) ) {
			return true;
		}
	}

	return false;
};

$sit_wcpg_iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $sit_wcpg_root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $sit_wcpg_root, $sit_wcpg_is_excluded ): bool {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $sit_wcpg_root ) + 1 ) );

			return ! $sit_wcpg_is_excluded( $relative );
		}
	)
);

$sit_wcpg_files = array();

foreach ( $sit_wcpg_iterator as $sit_wcpg_file ) {
	if ( $sit_wcpg_file->isFile() ) {
		$sit_wcpg_files[] = str_replace( '\\', '/', substr( $sit_wcpg_file->getPathname(), strlen( $sit_wcpg_root ) + 1 ) );
	}
}

sort( $sit_wcpg_files );

if ( ! is_dir( dirname( $sit_wcpg_target ) ) ) {
	mkdir( dirname( $sit_wcpg_target ), 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- CLI only, no WP_Filesystem here.
}

if ( is_file( $sit_wcpg_target ) ) {
	unlink( $sit_wcpg_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI only, replaces the previous build.
}

$sit_wcpg_zip = new ZipArchive();

if ( true !== $sit_wcpg_zip->open( $sit_wcpg_target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "build-zip: cannot create {$sit_wcpg_target}.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 1 );
}

foreach ( $sit_wcpg_files as $sit_wcpg_path ) {
	$sit_wcpg_zip->addFile( $sit_wcpg_root . '/' . $sit_wcpg_path, $sit_wcpg_slug . '/' . $sit_wcpg_path );
}

$sit_wcpg_zip->close();

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
fwrite( STDOUT, sprintf( "build-zip: %d files, %.1f KB -> dist/%s.zip\n", count( $sit_wcpg_files ), filesize( $sit_wcpg_target ) / 1024, $sit_wcpg_slug ) );
exit( 0 );
