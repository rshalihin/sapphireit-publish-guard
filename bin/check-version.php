#!/usr/bin/env php
<?php
/**
 * Verifies that the plugin version is identical everywhere it is written down.
 *
 * A mismatch between the plugin header and `readme.txt`'s `Stable tag` is the
 * classic WordPress release bug: the update users are offered is not the code
 * that ships. This fails the release build instead.
 *
 * Checked: the main file's `Version:` header and `SIT_WCPG_VERSION`, the test
 * bootstrap's `SIT_WCPG_VERSION`, `package.json`, `package-lock.json` (top level
 * and `packages[""]`), `readme.txt`'s `Stable tag` and the POT's
 * `Project-Id-Version`. The POT is written by `npm run makepot`, so a version bump
 * leaves it behind until the POT is regenerated; `--skip-pot` leaves it out.
 *
 * Usage:
 *   php bin/check-version.php              # every location must agree
 *   php bin/check-version.php 1.1.0        # …and must equal this version
 *   php bin/check-version.php v1.1.0       # a leading "v" is stripped
 *   php bin/check-version.php --skip-pot   # before the POT is regenerated
 *
 * Exit code 0 when everything agrees, 1 otherwise.
 *
 * Development tool: excluded from the release zip. Runs without WordPress.
 *
 * @package ProductPublishGuard
 * @since   1.1.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script: no WordPress, no HTML output, nothing to escape for.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Reads a file, or stops with an error.
 *
 * @since 1.1.0
 *
 * @param string $path Absolute path.
 * @return string
 */
function sit_wcpg_version_read( string $path ): string {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;

	if ( false === $contents ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}

	return $contents;
}

/**
 * Extracts the first capture group of a pattern.
 *
 * @since 1.1.0
 *
 * @param string $pattern Regular expression with one capture group.
 * @param string $subject Text to search.
 * @return string|null Trimmed match, or null when the pattern does not match.
 */
function sit_wcpg_version_match( string $pattern, string $subject ): ?string {
	return preg_match( $pattern, $subject, $matches ) ? trim( $matches[1] ) : null;
}

/**
 * Reads a JSON file and follows a key path into it.
 *
 * @since 1.1.0
 *
 * @param string   $path Absolute path.
 * @param string[] $keys Key path, outermost first.
 * @return string|null The value as a string, or null when the path is missing.
 */
function sit_wcpg_version_json( string $path, array $keys ): ?string {
	$value = json_decode( sit_wcpg_version_read( $path ), true );

	foreach ( $keys as $key ) {
		if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
			return null;
		}

		$value = $value[ $key ];
	}

	return is_string( $value ) ? $value : null;
}

/**
 * The version declared in each location.
 *
 * @since 1.1.0
 *
 * @param string $root     Plugin root directory.
 * @param bool   $with_pot Whether to include the POT.
 * @return array<string, string|null> Location description => version or null.
 */
function sit_wcpg_version_locations( string $root, bool $with_pot ): array {
	$slug      = 'sapphireit-publish-guard';
	$constant  = "/define\(\s*'SIT_WCPG_VERSION'\s*,\s*'([^']+)'/";
	$plugin    = sit_wcpg_version_read( $root . '/' . $slug . '.php' );
	$bootstrap = sit_wcpg_version_read( $root . '/tests/bootstrap.php' );
	$readme    = sit_wcpg_version_read( $root . '/readme.txt' );

	$locations = array(
		$slug . '.php (plugin header)'             => sit_wcpg_version_match( '/^\s*\*\s*Version:\s*(.+)$/m', $plugin ),
		$slug . '.php (SIT_WCPG_VERSION)'          => sit_wcpg_version_match( $constant, $plugin ),
		'tests/bootstrap.php (SIT_WCPG_VERSION)'   => sit_wcpg_version_match( $constant, $bootstrap ),
		'package.json (version)'                   => sit_wcpg_version_json( $root . '/package.json', array( 'version' ) ),
		'package-lock.json (version)'              => sit_wcpg_version_json( $root . '/package-lock.json', array( 'version' ) ),
		'package-lock.json (packages[""].version)' => sit_wcpg_version_json( $root . '/package-lock.json', array( 'packages', '', 'version' ) ),
		'readme.txt (Stable tag)'                  => sit_wcpg_version_match( '/^Stable tag:\s*(.+)$/m', $readme ),
	);

	if ( $with_pot ) {
		$pot = sit_wcpg_version_read( $root . '/languages/' . $slug . '.pot' );

		$locations[ 'languages/' . $slug . '.pot (Project-Id-Version)' ] = sit_wcpg_version_match( '/^"Project-Id-Version:.*\s(\S+)\\\\n"$/m', $pot );
	}

	return $locations;
}

/**
 * Reports every location and returns whether they all agree.
 *
 * @since 1.1.0
 *
 * @param array<string, string|null> $locations Location description => version.
 * @param string|null                $expected  Version they must equal; null to
 *                                              take the first one found.
 * @return bool True when every location agrees.
 */
function sit_wcpg_version_report( array $locations, ?string $expected ): bool {
	$failed = false;

	foreach ( $locations as $where => $version ) {
		if ( null === $version ) {
			fwrite( STDERR, sprintf( "MISSING  %s\n", $where ) );
			$failed = true;
			continue;
		}

		if ( null === $expected ) {
			$expected = $version;
		}

		$matches = $version === $expected;
		$failed  = $failed || ! $matches;

		printf( "%-8s %-58s %s\n", $matches ? 'ok' : 'MISMATCH', $where, $version );
	}

	if ( $failed ) {
		fwrite( STDERR, sprintf( "\nVersion mismatch. Expected %s in all %d locations.\n", (string) $expected, count( $locations ) ) );

		return false;
	}

	printf( "\nVersion %s is consistent in all %d locations.\n", (string) $expected, count( $locations ) );

	return true;
}

$sit_wcpg_args     = array_slice( $argv, 1 );
$sit_wcpg_with_pot = ! in_array( '--skip-pot', $sit_wcpg_args, true );
$sit_wcpg_args     = array_values( array_diff( $sit_wcpg_args, array( '--skip-pot' ) ) );

/*
 * An empty argument counts as "not given", so a release script can pass a tag
 * name unconditionally and have it ignored when there is none.
 */
$sit_wcpg_expected = isset( $sit_wcpg_args[0] ) ? ltrim( trim( $sit_wcpg_args[0] ), 'vV' ) : '';
$sit_wcpg_expected = '' === $sit_wcpg_expected ? null : $sit_wcpg_expected;

exit( sit_wcpg_version_report( sit_wcpg_version_locations( dirname( __DIR__ ), $sit_wcpg_with_pot ), $sit_wcpg_expected ) ? 0 : 1 );
