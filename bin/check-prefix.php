<?php
/**
 * Prefix guard: fails when any text file still carries the retired global prefix.
 *
 * The plugin's prefix is `sit_wcpg` / `sit-wcpg` / `SIT_WCPG` / `sitWcpg`
 * (coding-plan.md section 10.6). The retired prefix is a substring of the current one,
 * so a plain grep cannot prove the migration finished; this matches it only where it is
 * NOT already preceded by `sit_` or `sit-` (case-insensitively) or by a letter/digit,
 * which is what lets `sitWcpg` through. See prefix-migration-plan.md section 3.3.
 *
 * Usage: `composer lint:prefix` (or `php bin/check-prefix.php`). Exits 1 and prints
 * `file:line` for each hit; exits 0 when clean. PHP rather than grep so it behaves the
 * same on Windows.
 *
 * A line containing `prefix-guard: ignore` is skipped. Use it only for historical notes.
 *
 * @package ProductPublishGuard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$sit_wcpg_root = dirname( __DIR__ );

// The character class keeps this line from matching itself.
$sit_wcpg_pattern = '/(?<![a-z0-9])(?<!sit_)(?<!sit-)wcp[gc]/i';

// Directories never scanned, at any depth.
$sit_wcpg_skip_dirs = array( '.git', 'vendor', 'node_modules', 'build' );

/*
 * Files never scanned, relative to the plugin root. The migration plan is the record of
 * the rename itself, so it has to name the retired prefix.
 */
$sit_wcpg_skip_files = array(
	'.phpcs.cache',
	'.claude/plan/prefix-migration-plan.md',
);

$sit_wcpg_iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $sit_wcpg_root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $sit_wcpg_skip_dirs ): bool {
			return ! ( $file->isDir() && in_array( $file->getFilename(), $sit_wcpg_skip_dirs, true ) );
		}
	)
);

$sit_wcpg_hits = array();

foreach ( $sit_wcpg_iterator as $sit_wcpg_file ) {
	if ( ! $sit_wcpg_file->isFile() ) {
		continue;
	}

	$sit_wcpg_relative = str_replace( '\\', '/', substr( $sit_wcpg_file->getPathname(), strlen( $sit_wcpg_root ) + 1 ) );

	if ( in_array( $sit_wcpg_relative, $sit_wcpg_skip_files, true ) ) {
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, CLI only.
	$sit_wcpg_contents = file_get_contents( $sit_wcpg_file->getPathname() );

	// Unreadable or binary: nothing a prefix could live in.
	if ( false === $sit_wcpg_contents || false !== strpos( $sit_wcpg_contents, "\0" ) ) {
		continue;
	}

	foreach ( preg_split( '/\r\n|\r|\n/', $sit_wcpg_contents ) as $sit_wcpg_index => $sit_wcpg_line ) {
		if ( false !== strpos( $sit_wcpg_line, 'prefix-guard: ignore' ) ) {
			continue;
		}

		if ( preg_match( $sit_wcpg_pattern, $sit_wcpg_line ) ) {
			$sit_wcpg_hits[] = $sit_wcpg_relative . ':' . ( $sit_wcpg_index + 1 ) . ': ' . trim( $sit_wcpg_line );
		}
	}
}

if ( array() === $sit_wcpg_hits ) {
	fwrite( STDOUT, "Prefix guard: no retired-prefix identifiers found.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 0 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
fwrite( STDERR, 'Prefix guard: ' . count( $sit_wcpg_hits ) . " retired-prefix identifier(s) found:\n" . implode( "\n", $sit_wcpg_hits ) . "\n" );
exit( 1 );
