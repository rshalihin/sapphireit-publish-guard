#!/usr/bin/env php
<?php
/**
 * Writes tests/fixtures/icon-names.json from the PHP icon set.
 *
 * The fixture is the contract between `src/Admin/Icons.php` and
 * `assets/js/shared/icons.js`: `Icons_Test` fails when the fixture is stale, and the
 * Jest suite fails when the JavaScript set differs from it. Run this after changing
 * an icon, then update `icons.js` to match.
 *
 * Usage: `php bin/icon-names.php`
 *
 * Development tool: excluded from the release zip. Runs without WordPress.
 *
 * @package ProductPublishGuard
 * @since   1.2.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script: no WordPress, no HTML output, nothing to escape for.

$sit_wcpg_root = dirname( __DIR__ );

// Every plugin file guards on WordPress's own constant; this script stands in for it.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
defined( 'ABSPATH' ) || define( 'ABSPATH', $sit_wcpg_root . '/' );

require_once $sit_wcpg_root . '/src/Admin/Icons.php';

$sit_wcpg_json = json_encode( ProductPublishGuard\Admin\Icons::definitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress here.

file_put_contents( $sit_wcpg_root . '/tests/fixtures/icon-names.json', str_replace( '    ', "\t", $sit_wcpg_json ) );

fwrite( STDOUT, "icon-names: wrote tests/fixtures/icon-names.json\n" );
