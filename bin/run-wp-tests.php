<?php
/**
 * Runs the integration and security suites against a target set up by
 * `bin/install-wp-tests.php`.
 *
 * It points `WP_PHPUNIT__DIR` and `WP_PHPUNIT__TESTS_CONFIG` at the target and runs
 * PHPUnit in a child process. A composer script can't do that portably: it can't choose
 * the PHP binary, read an environment variable the same way under cmd and sh, or add
 * `-d extension=mysqli` only when the binary lacks it.
 *
 * Usage, from the plugin root:
 *
 *     composer test:integration         # php bin/run-wp-tests.php --target=current
 *     composer test:integration:floor   # php bin/run-wp-tests.php --target=floor
 *     composer test:integration -- --filter=Rest_Validate_Test
 *
 * PHP binary:
 *   current  SIT_WCPG_CURRENT_PHP, else the PHP running this script (composer's).
 *   floor    SIT_WCPG_FLOOR_PHP, else `php` on PATH (the PHP 8.0 floor).
 *
 * Options: --target=current|floor, --dir=PATH (the installer's --dir). Every other
 * argument is passed on to PHPUnit.
 *
 * @package ProductPublishGuard
 * @since   1.1.0
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/wp-test-env.php';

$sit_wcpg_root = dirname( __DIR__ );

$sit_wcpg_opts = sit_wcpg_cli_options(
	$argv,
	array(
		'target' => 'current',
		'dir'    => '',
	),
	true
);

$sit_wcpg_target = sit_wcpg_test_target( $sit_wcpg_opts['target'] );
$sit_wcpg_paths  = sit_wcpg_test_paths( sit_wcpg_test_cache_dir( $sit_wcpg_opts['dir'] ), $sit_wcpg_opts['target'], $sit_wcpg_target );
$sit_wcpg_setup  = 'composer test:setup' . ( 'floor' === $sit_wcpg_opts['target'] ? ':floor' : '' );

if ( ! is_file( $sit_wcpg_paths['config'] ) || ! is_file( $sit_wcpg_paths['library'] . '/includes/bootstrap.php' ) ) {
	sit_wcpg_fail( 'The ' . $sit_wcpg_opts['target'] . ' test environment is not installed. Run `' . $sit_wcpg_setup . '` first.' );
}

if ( sit_wcpg_woocommerce_version( $sit_wcpg_paths['woocommerce'] ) !== $sit_wcpg_target['wc'] ) {
	sit_wcpg_fail( 'WooCommerce ' . $sit_wcpg_target['wc'] . ' is not installed in ' . $sit_wcpg_paths['core'] . '. Run `' . $sit_wcpg_setup . '`.' );
}

if ( 'floor' === $sit_wcpg_opts['target'] ) {
	$sit_wcpg_binary = (string) getenv( 'SIT_WCPG_FLOOR_PHP' );
	$sit_wcpg_binary = '' !== $sit_wcpg_binary ? $sit_wcpg_binary : 'php';
} else {
	$sit_wcpg_binary = (string) getenv( 'SIT_WCPG_CURRENT_PHP' );
	$sit_wcpg_binary = '' !== $sit_wcpg_binary ? $sit_wcpg_binary : PHP_BINARY;
}

$sit_wcpg_php = sit_wcpg_php_command( $sit_wcpg_binary );

sit_wcpg_say(
	'Target: ' . $sit_wcpg_opts['target'] . ' (WordPress ' . $sit_wcpg_target['wp'] . ', WooCommerce ' . $sit_wcpg_target['wc']
	. ', PHP ' . $sit_wcpg_php['version'] . ': ' . implode( ' ', $sit_wcpg_php['command'] ) . ')'
);

exit(
	(int) sit_wcpg_run(
		array_merge(
			$sit_wcpg_php['command'],
			array( $sit_wcpg_root . '/vendor/phpunit/phpunit/phpunit', '--testsuite', 'integration,security' ),
			$sit_wcpg_opts['_rest']
		),
		array(
			'WP_PHPUNIT__DIR'          => $sit_wcpg_paths['library'],
			'WP_PHPUNIT__TESTS_CONFIG' => $sit_wcpg_paths['config'],
			'SIT_WCPG_TESTS_PHP'       => sit_wcpg_command_line( $sit_wcpg_php['command'] ),
			// tests/bootstrap.php prefers WP_TESTS_DIR; a stale one in the shell would win.
			'WP_TESTS_DIR'             => null,
		)
	)
);
