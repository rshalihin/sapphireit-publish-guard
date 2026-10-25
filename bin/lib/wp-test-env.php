<?php
/**
 * Helpers shared by `bin/install-wp-tests.php` and `bin/run-wp-tests.php`, the
 * Docker-free integration-test environment.
 *
 * Standalone CLI code: WordPress is not loaded here, so ext/curl, ext/zip and ext/mysqli
 * are used directly instead of the WP HTTP API, WP_Filesystem and `$wpdb`. None of it
 * ships: `bin/` is in `.distignore`.
 *
 * WARNING: the WordPress test suite DROPS EVERY TABLE in its database on every run.
 * `sit_wcpg_assert_test_database()` is the guard; never weaken it.
 *
 * @package ProductPublishGuard
 * @since   1.1.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.PHP.DiscouragedPHPFunctions -- CLI tool that runs without WordPress: no WP_Filesystem, HTTP API or $wpdb exists here.

/**
 * The two test targets: the current release line and the declared floor.
 *
 * Pinned in verification-notes.md R13. Change a version here and nowhere else.
 *
 * @since 1.1.0
 *
 * @return array<string, array{wp: string, wc: string, db: string}>
 */
function sit_wcpg_test_targets(): array {
	return array(
		'current' => array(
			'wp' => '7.1.3',
			'wc' => '11.2.1',
			'db' => 'sit_wcpg_tests',
		),
		'floor'   => array(
			'wp' => '6.5.11',
			'wc' => '9.0.0',
			'db' => 'sit_wcpg_tests_floor',
		),
	);
}

/**
 * The table prefix every generated config uses.
 *
 * @since 1.1.0
 *
 * @return string
 */
function sit_wcpg_test_table_prefix(): string {
	return 'wptests_';
}

/**
 * Prints a line to stdout.
 *
 * @since 1.1.0
 *
 * @param string $message Message.
 * @return void
 */
function sit_wcpg_say( string $message ): void {
	fwrite( STDOUT, $message . PHP_EOL );
}

/**
 * Prints a message to stderr and exits with a failure code.
 *
 * @since 1.1.0
 *
 * @param string $message Message.
 * @return void
 */
function sit_wcpg_fail( string $message ): void {
	fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Parses `--key=value` / `--flag` arguments against a defaults map.
 *
 * @since 1.1.0
 *
 * @param array $argv        Raw arguments, including the script name.
 * @param array $defaults    Known options and their default values. A bool default makes a flag.
 * @param bool  $passthrough Collect unknown arguments under `_rest` instead of failing.
 * @return array Options.
 */
function sit_wcpg_cli_options( array $argv, array $defaults, bool $passthrough = false ): array {
	$opts          = $defaults;
	$opts['_rest'] = array();

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		$pair = 0 === strpos( $arg, '--' ) ? explode( '=', substr( $arg, 2 ), 2 ) : array( '' );
		$key  = $pair[0];

		if ( '' === $key || ! array_key_exists( $key, $defaults ) ) {
			if ( $passthrough ) {
				$opts['_rest'][] = $arg;
				continue;
			}

			sit_wcpg_fail( 'Unknown argument "' . $arg . '". Known: --' . implode( ', --', array_keys( $defaults ) ) . '.' );
		}

		$opts[ $key ] = is_bool( $defaults[ $key ] ) ? true : ( isset( $pair[1] ) ? $pair[1] : '' );
	}

	return $opts;
}

/**
 * A target's pinned versions, or exit when the name is unknown.
 *
 * @since 1.1.0
 *
 * @param string $name Target name.
 * @return array{wp: string, wc: string, db: string}
 */
function sit_wcpg_test_target( string $name ): array {
	$targets = sit_wcpg_test_targets();

	if ( ! isset( $targets[ $name ] ) ) {
		sit_wcpg_fail( 'Unknown --target "' . $name . '". Use ' . implode( ' or ', array_keys( $targets ) ) . '.' );
	}

	return $targets[ $name ];
}

/**
 * Where downloads, core checkouts and generated configs live: outside the repository.
 *
 * @since 1.1.0
 *
 * @param string $dir `--dir` value; empty for the default.
 * @return string Directory path without a trailing slash.
 */
function sit_wcpg_test_cache_dir( string $dir = '' ): string {
	$base = $dir;

	if ( '' === $base ) {
		$base = (string) getenv( 'SIT_WCPG_WP_TESTS_DIR' );
	}

	if ( '' === $base ) {
		$local = (string) getenv( 'LOCALAPPDATA' );
		$base  = ( '' !== $local ? $local : sys_get_temp_dir() ) . '/sit-wcpg-wp-tests';
	}

	return rtrim( str_replace( '\\', '/', $base ), '/' );
}

/**
 * The paths one target uses inside the cache directory.
 *
 * @since 1.1.0
 *
 * @param string $cache  Cache directory.
 * @param string $name   Target name.
 * @param array  $target Target versions.
 * @return array{core: string, library: string, woocommerce: string, config: string}
 */
function sit_wcpg_test_paths( string $cache, string $name, array $target ): array {
	$core = $cache . '/wordpress-' . $target['wp'];

	return array(
		'core'        => $core,
		'library'     => $cache . '/wp-phpunit-' . $target['wp'],
		'woocommerce' => $core . '/wp-content/plugins/woocommerce',
		'config'      => $cache . '/wp-tests-config-' . $name . '.php',
	);
}

/**
 * Exits unless every required PHP extension is loaded.
 *
 * @since 1.1.0
 *
 * @param string[] $extensions Extension names.
 * @return void
 */
function sit_wcpg_require_extensions( array $extensions ): void {
	$missing = array_values( array_filter( $extensions, static fn ( $extension ) => ! extension_loaded( $extension ) ) );

	if ( array() !== $missing ) {
		sit_wcpg_fail( 'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ') is missing: ' . implode( ', ', $missing ) . '.' );
	}
}

/**
 * Runs a command without a shell, inheriting stdio, and returns its exit code.
 *
 * @since 1.1.0
 *
 * @param string[]                   $command Program and arguments.
 * @param array<string, string|null> $env     Variables to set (or, with a null value, unset) for the child.
 * @return int Exit code.
 */
function sit_wcpg_run( array $command, array $env = array() ): int {
	$environment = getenv();

	foreach ( $env as $name => $value ) {
		if ( null === $value ) {
			unset( $environment[ $name ] );
		} else {
			$environment[ $name ] = $value;
		}
	}

	$process = proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes, null, $environment );

	if ( ! is_resource( $process ) ) {
		sit_wcpg_fail( 'Cannot start ' . $command[0] . '.' );
	}

	return proc_close( $process );
}

/**
 * Runs a PHP binary on a one-line snippet and returns its stdout.
 *
 * @since 1.1.0
 *
 * @param string[] $php  PHP command: binary plus `-d` flags.
 * @param string   $code Code for `-r`.
 * @return string|null Output, or null when the binary did not run cleanly.
 */
function sit_wcpg_php_eval( array $php, string $code ): ?string {
	$process = proc_open(
		array_merge( $php, array( '-r', $code ) ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		return null;
	}

	$output = (string) stream_get_contents( $pipes[1] );
	$errors = (string) stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return 0 === proc_close( $process ) && '' === trim( $errors ) ? trim( $output ) : null;
}

/**
 * The command that runs a PHP binary with ext/mysqli loaded.
 *
 * `-d extension=mysqli` is added only when the binary lacks it: passed to a binary that
 * already loads it, it prints a "Module already loaded" warning on every run.
 *
 * @since 1.1.0
 *
 * @param string $binary PHP binary path, or a name on PATH.
 * @return array{command: string[], version: string}
 */
function sit_wcpg_php_command( string $binary ): array {
	$probe = 'echo PHP_VERSION, " ", (int) extension_loaded("mysqli");';

	foreach ( array( array( $binary ), array( $binary, '-d', 'extension=mysqli' ) ) as $command ) {
		$output = sit_wcpg_php_eval( $command, $probe );

		if ( null === $output ) {
			continue;
		}

		$parts = explode( ' ', $output );

		if ( isset( $parts[1] ) && '1' === $parts[1] ) {
			return array(
				'command' => $command,
				'version' => $parts[0],
			);
		}
	}

	sit_wcpg_fail( 'Cannot run "' . $binary . '" with ext/mysqli loaded, even with -d extension=mysqli.' );

	return array();
}

/**
 * A command as one shell-escaped string, for `WP_PHP_BINARY`.
 *
 * The WordPress test bootstrap passes that constant to `system()` unquoted, so it can
 * carry the `-d` flags along with the binary.
 *
 * @since 1.1.0
 *
 * @param string[] $command Program and arguments.
 * @return string
 */
function sit_wcpg_command_line( array $command ): string {
	return implode( ' ', array_map( 'escapeshellarg', $command ) );
}

/**
 * Performs a GET request and streams the body into a file.
 *
 * @since 1.1.0
 *
 * @param string $url  URL.
 * @param string $sink File to write.
 * @return void
 */
function sit_wcpg_download( string $url, string $sink ): void {
	$file = fopen( $sink, 'wb' );

	if ( false === $file ) {
		sit_wcpg_fail( 'Cannot write to ' . $sink . '.' );
	}

	$handle = curl_init( $url );

	curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 15 );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 900 );
	curl_setopt( $handle, CURLOPT_USERAGENT, 'sit-wcpg-test-env' );
	curl_setopt( $handle, CURLOPT_FILE, $file );

	/*
	 * A Windows PHP with no CA bundle in php.ini fails every HTTPS download. Verification
	 * stays on: curl 7.71+ is pointed at the Windows certificate store instead.
	 */
	if ( 'Windows' === PHP_OS_FAMILY && '' === (string) ini_get( 'curl.cainfo' ) && '' === (string) ini_get( 'openssl.cafile' ) ) {
		curl_setopt( $handle, CURLOPT_SSL_OPTIONS, defined( 'CURLSSLOPT_NATIVE_CA' ) ? CURLSSLOPT_NATIVE_CA : 16 );
	}

	curl_exec( $handle );

	$error  = (string) curl_error( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

	curl_close( $handle );
	fclose( $file );

	if ( '' !== $error || 200 !== $status ) {
		unlink( $sink );
		sit_wcpg_fail( 'Download failed for ' . $url . ': ' . ( '' !== $error ? $error : 'HTTP ' . $status ) );
	}
}

/**
 * Deletes a directory tree.
 *
 * @since 1.1.0
 *
 * @param string $dir Directory.
 * @return void
 */
function sit_wcpg_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $dir );
}

/**
 * Creates a directory and its parents, or exits.
 *
 * @since 1.1.0
 *
 * @param string $dir Directory.
 * @return void
 */
function sit_wcpg_mkdir( string $dir ): void {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		sit_wcpg_fail( 'Cannot create ' . $dir . '.' );
	}
}

/**
 * Downloads a zip and moves one top-level folder of it to `$target`.
 *
 * It extracts into a staging directory first, so an interrupted run never leaves a
 * half-extracted `$target` that a later run would take as installed.
 *
 * @since 1.1.0
 *
 * @param string $url    Zip URL.
 * @param string $cache  Cache directory, for the zip and the staging directory.
 * @param string $folder The archive's top-level folder.
 * @param string $target Final location of that folder.
 * @return void
 */
function sit_wcpg_install_archive( string $url, string $cache, string $folder, string $target ): void {
	sit_wcpg_mkdir( $cache );

	$token   = bin2hex( random_bytes( 4 ) );
	$zip     = $cache . '/download-' . $token . '.zip';
	$staging = $cache . '/staging-' . $token;

	sit_wcpg_say( 'Downloading ' . $url . ' ...' );
	sit_wcpg_download( $url, $zip );

	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		unlink( $zip );
		sit_wcpg_fail( 'Cannot open the archive from ' . $url . '.' );
	}

	$archive->extractTo( $staging );
	$archive->close();
	unlink( $zip );

	if ( ! is_dir( $staging . '/' . $folder ) ) {
		sit_wcpg_rmdir( $staging );
		sit_wcpg_fail( 'The archive from ' . $url . ' has no "' . $folder . '" folder.' );
	}

	sit_wcpg_rmdir( $target );
	sit_wcpg_mkdir( dirname( $target ) );

	if ( ! rename( $staging . '/' . $folder, $target ) ) {
		sit_wcpg_fail( 'Cannot move the extracted files to ' . $target . '.' );
	}

	sit_wcpg_rmdir( $staging );
}

/**
 * The `Version:` header of an installed WooCommerce, or an empty string.
 *
 * @since 1.1.0
 *
 * @param string $dir WooCommerce directory.
 * @return string
 */
function sit_wcpg_woocommerce_version( string $dir ): string {
	$main = $dir . '/woocommerce.php';

	if ( ! is_readable( $main ) ) {
		return '';
	}

	$matches = array();

	return preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', (string) file_get_contents( $main, false, null, 0, 8192 ), $matches ) ? $matches[1] : '';
}

/**
 * Installs one target's core, matching wp-phpunit library and WooCommerce.
 *
 * Each part is skipped when the pinned version is already there, unless `$force`.
 *
 * @since 1.1.0
 *
 * @param array $target Target versions.
 * @param array $paths  Target paths.
 * @param bool  $force  Re-download every part.
 * @return void
 */
function sit_wcpg_install_target( array $target, array $paths, bool $force ): void {
	$cache = dirname( $paths['config'] );

	if ( $force || ! is_file( $paths['core'] . '/wp-includes/version.php' ) ) {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The zip's top-level folder is literally "wordpress".
		sit_wcpg_install_archive( 'https://wordpress.org/wordpress-' . $target['wp'] . '.zip', $cache, 'wordpress', $paths['core'] );
	} else {
		sit_wcpg_say( 'WordPress ' . $target['wp'] . ' already installed.' );
	}

	/*
	 * The test library comes from wp-phpunit's GitHub tag archive, one per target, not
	 * from composer: composer holds a single version, and Packagist lags the tags (R13).
	 */
	if ( $force || ! is_file( $paths['library'] . '/includes/bootstrap.php' ) ) {
		sit_wcpg_install_archive(
			'https://github.com/wp-phpunit/wp-phpunit/archive/refs/tags/' . $target['wp'] . '.zip',
			$cache,
			'wp-phpunit-' . $target['wp'],
			$paths['library']
		);
	} else {
		sit_wcpg_say( 'wp-phpunit ' . $target['wp'] . ' already installed.' );
	}

	if ( $force || sit_wcpg_woocommerce_version( $paths['woocommerce'] ) !== $target['wc'] ) {
		sit_wcpg_install_archive(
			'https://downloads.wordpress.org/plugin/woocommerce.' . $target['wc'] . '.zip',
			$cache,
			'woocommerce',
			$paths['woocommerce']
		);
	} else {
		sit_wcpg_say( 'WooCommerce ' . $target['wc'] . ' already installed.' );
	}
}

/**
 * Connects to MySQL, exiting with a readable message on failure.
 *
 * @since 1.1.0
 *
 * @param string $host     Host, optionally `host:port`.
 * @param string $user     User.
 * @param string $password Password.
 * @return mysqli
 */
function sit_wcpg_mysql( string $host, string $user, string $password ): mysqli {
	$parts = explode( ':', $host, 2 );
	$port  = isset( $parts[1] ) ? (int) $parts[1] : 3306;

	mysqli_report( MYSQLI_REPORT_OFF );

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Connection errors are reported by hand below.
	$connection = @new mysqli( $parts[0], $user, $password, '', $port );

	if ( $connection->connect_errno ) {
		sit_wcpg_fail( 'Cannot connect to MySQL at ' . $host . ' as ' . $user . ': ' . $connection->connect_error . ' Is MySQL running?' );
	}

	return $connection;
}

/**
 * Exits unless a database is safe to hand to the WordPress test suite.
 *
 * The suite drops every table in its database, so it refuses the flow-manager site
 * database by name, and any database holding an options table with a `siteurl` row
 * under a prefix other than the test prefix: that is a WordPress site. Tables under the
 * test prefix (`wptests_`, and `wptests_N_` for multisite blogs) are the suite's own, so
 * a re-run against its own database passes.
 *
 * @since 1.1.0
 *
 * @param mysqli $connection Server connection.
 * @param string $database   Database name.
 * @return void
 */
function sit_wcpg_assert_test_database( mysqli $connection, string $database ): void {
	$warning = ' The WordPress test suite drops every table in its database. Pass --db with a dedicated test database.';

	if ( 'flow_manager' === strtolower( $database ) ) {
		sit_wcpg_fail( 'Refusing to use database "' . $database . '": it is the flow-manager site database.' . $warning );
	}

	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $database ) ) {
		sit_wcpg_fail( 'Refusing database name "' . $database . '": use letters, digits and underscores only.' );
	}

	$statement = $connection->prepare( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%options'" );
	$statement->bind_param( 's', $database );
	$statement->execute();

	$tables = array_column( $statement->get_result()->fetch_all(), 0 );
	$statement->close();

	foreach ( $tables as $table ) {
		$prefix = substr( $table, 0, -strlen( 'options' ) );

		if ( 0 === strpos( $prefix, sit_wcpg_test_table_prefix() ) ) {
			continue;
		}

		// A table without an `option_name` column fails the query: it is not WordPress's.
		$result = $connection->query( 'SELECT 1 FROM `' . $database . '`.`' . str_replace( '`', '``', $table ) . "` WHERE option_name = 'siteurl' LIMIT 1" );

		if ( $result instanceof mysqli_result && $result->num_rows > 0 ) {
			sit_wcpg_fail( 'Refusing to use database "' . $database . '": table ' . $table . ' holds a WordPress site (it has a siteurl row).' . $warning );
		}
	}
}

/**
 * Creates the test database when it does not exist yet.
 *
 * @since 1.1.0
 *
 * @param mysqli $connection Server connection.
 * @param string $database   Database name, already checked by sit_wcpg_assert_test_database().
 * @return void
 */
function sit_wcpg_create_database( mysqli $connection, string $database ): void {
	if ( ! $connection->query( 'CREATE DATABASE IF NOT EXISTS `' . $database . '` DEFAULT CHARACTER SET utf8mb4' ) ) {
		sit_wcpg_fail( 'Cannot create the database: ' . $connection->error );
	}

	sit_wcpg_say( 'Database ' . $database . ' is ready.' );
}

/**
 * Exports a value as a PHP string literal.
 *
 * @since 1.1.0
 *
 * @param string $value Value.
 * @return string
 */
function sit_wcpg_php_string( string $value ): string {
	return "'" . addcslashes( $value, "'\\" ) . "'";
}

/**
 * Writes the `wp-tests-config.php` that `WP_PHPUNIT__TESTS_CONFIG` points at.
 *
 * `WP_PHP_BINARY` comes from `SIT_WCPG_TESTS_PHP`, set by `bin/run-wp-tests.php`, so the
 * test library's `install.php` child runs on the same binary and flags as PHPUnit.
 *
 * @since 1.1.0
 *
 * @param string $path    Destination path.
 * @param string $abspath Core checkout.
 * @param array  $opts    CLI options (db, user, pass, host).
 * @return void
 */
function sit_wcpg_write_tests_config( string $path, string $abspath, array $opts ): void {
	$fallback_php = sit_wcpg_command_line( sit_wcpg_php_command( PHP_BINARY )['command'] );

	$lines = array(
		'<?php',
		'/**',
		' * Generated by bin/install-wp-tests.php. Do not edit: re-run the installer.',
		' *',
		' * WARNING: the WordPress test suite DROPS EVERY TABLE in DB_NAME on every run.',
		' * Never point this at a site database.',
		' */',
		'',
		'define( \'ABSPATH\', ' . sit_wcpg_php_string( rtrim( $abspath, '/' ) . '/' ) . ' );',
		'',
		"define( 'WP_DEFAULT_THEME', 'default' );",
		"define( 'WP_DEBUG', true );",
		'',
		'define( \'DB_NAME\', ' . sit_wcpg_php_string( $opts['db'] ) . ' );',
		'define( \'DB_USER\', ' . sit_wcpg_php_string( $opts['user'] ) . ' );',
		'define( \'DB_PASSWORD\', ' . sit_wcpg_php_string( $opts['pass'] ) . ' );',
		'define( \'DB_HOST\', ' . sit_wcpg_php_string( $opts['host'] ) . ' );',
		"define( 'DB_CHARSET', 'utf8mb4' );",
		"define( 'DB_COLLATE', '' );",
		'',
		'$table_prefix = ' . sit_wcpg_php_string( sit_wcpg_test_table_prefix() ) . ';',
		'',
		"define( 'WP_TESTS_DOMAIN', 'example.org' );",
		"define( 'WP_TESTS_EMAIL', 'admin@example.org' );",
		"define( 'WP_TESTS_TITLE', 'Test Blog' );",
		"define( 'WP_PHP_BINARY', false !== getenv( 'SIT_WCPG_TESTS_PHP' ) ? getenv( 'SIT_WCPG_TESTS_PHP' ) : " . sit_wcpg_php_string( $fallback_php ) . ' );',
		"define( 'WPLANG', '' );",
		'',
		'// The suites need no outbound HTTP, and the plugin makes none.',
		"define( 'WP_HTTP_BLOCK_EXTERNAL', true );",
		'',
	);

	if ( false === file_put_contents( $path, implode( "\n", $lines ) ) ) {
		sit_wcpg_fail( 'Cannot write ' . $path . '.' );
	}
}
