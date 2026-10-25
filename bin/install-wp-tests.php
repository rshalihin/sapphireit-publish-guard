<?php
/**
 * Installs a WordPress + WooCommerce test environment for the integration and security
 * suites, without Docker or wp-env.
 *
 * For one target it downloads WordPress core, the wp-phpunit test library of the same
 * version and WooCommerce into that core's `wp-content/plugins/`, creates the test
 * database and writes `wp-tests-config-<target>.php`. Everything lands in `--dir`,
 * outside the repository. Pinned versions: `sit_wcpg_test_targets()` in
 * `bin/lib/wp-test-env.php`.
 *
 * Usage, from the plugin root:
 *
 *     composer test:setup              # php bin/install-wp-tests.php --target=current
 *     composer test:setup:floor        # php bin/install-wp-tests.php --target=floor
 *
 * Options:
 *   --target=current|floor  current: WP 7.1.3 + WC 11.2.1; floor: WP 6.5.11 + WC 9.0.0.
 *   --dir=PATH              Cache directory. Default: SIT_WCPG_WP_TESTS_DIR, else
 *                           %LOCALAPPDATA% (or the temp dir) + /sit-wcpg-wp-tests.
 *   --db, --user, --pass, --host  Database settings. --db defaults to sit_wcpg_tests
 *                           (current) or sit_wcpg_tests_floor (floor).
 *   --force                 Re-download core, the test library and WooCommerce.
 *
 * WARNING: the WordPress test suite DROPS EVERY TABLE in --db on every run. The script
 * refuses `flow_manager` and any database that holds a WordPress site.
 *
 * @package ProductPublishGuard
 * @since   1.1.0
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/wp-test-env.php';

$sit_wcpg_opts = sit_wcpg_cli_options(
	$argv,
	array(
		'target' => 'current',
		'dir'    => '',
		'db'     => '',
		'user'   => 'root',
		'pass'   => '',
		'host'   => '127.0.0.1',
		'force'  => false,
	)
);

$sit_wcpg_target = sit_wcpg_test_target( $sit_wcpg_opts['target'] );

if ( '' === $sit_wcpg_opts['db'] ) {
	$sit_wcpg_opts['db'] = $sit_wcpg_target['db'];
}

// Refused by name before anything is downloaded or connected to.
if ( 'flow_manager' === strtolower( $sit_wcpg_opts['db'] ) ) {
	sit_wcpg_fail(
		'Refusing to use database "' . $sit_wcpg_opts['db'] . '": it is the flow-manager site database.'
		. ' The WordPress test suite drops every table in its database. Pass --db with a dedicated test database.'
	);
}

sit_wcpg_require_extensions( array( 'curl', 'zip' ) );

// Composer may run on a PHP without ext/mysqli: run again with it loaded.
if ( ! extension_loaded( 'mysqli' ) && false === getenv( 'SIT_WCPG_RELAUNCHED' ) ) {
	$sit_wcpg_php = sit_wcpg_php_command( PHP_BINARY );

	exit( (int) sit_wcpg_run( array_merge( $sit_wcpg_php['command'], array( __FILE__ ), array_slice( $argv, 1 ) ), array( 'SIT_WCPG_RELAUNCHED' => '1' ) ) );
}

sit_wcpg_require_extensions( array( 'mysqli' ) );

$sit_wcpg_cache = sit_wcpg_test_cache_dir( $sit_wcpg_opts['dir'] );
$sit_wcpg_paths = sit_wcpg_test_paths( $sit_wcpg_cache, $sit_wcpg_opts['target'], $sit_wcpg_target );

$sit_wcpg_connection = sit_wcpg_mysql( $sit_wcpg_opts['host'], $sit_wcpg_opts['user'], $sit_wcpg_opts['pass'] );

sit_wcpg_assert_test_database( $sit_wcpg_connection, $sit_wcpg_opts['db'] );

sit_wcpg_say( 'Target      : ' . $sit_wcpg_opts['target'] . ' (WordPress ' . $sit_wcpg_target['wp'] . ', WooCommerce ' . $sit_wcpg_target['wc'] . ')' );
sit_wcpg_say( 'Directory   : ' . $sit_wcpg_cache );
sit_wcpg_say( 'Database    : ' . $sit_wcpg_opts['db'] . ' on ' . $sit_wcpg_opts['host'] );
sit_wcpg_say( '' );

sit_wcpg_install_target( $sit_wcpg_target, $sit_wcpg_paths, (bool) $sit_wcpg_opts['force'] );
sit_wcpg_create_database( $sit_wcpg_connection, $sit_wcpg_opts['db'] );
$sit_wcpg_connection->close();

sit_wcpg_write_tests_config( $sit_wcpg_paths['config'], $sit_wcpg_paths['core'], $sit_wcpg_opts );

sit_wcpg_say( 'Wrote ' . $sit_wcpg_paths['config'] );
sit_wcpg_say( '' );
sit_wcpg_say( 'Run the integration and security suites with:' );
sit_wcpg_say( '    composer test:integration' . ( 'floor' === $sit_wcpg_opts['target'] ? ':floor' : '' ) );
