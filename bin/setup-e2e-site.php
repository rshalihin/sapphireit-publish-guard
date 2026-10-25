<?php
/**
 * Prepares the dedicated Laragon site the Playwright suite runs against, so
 * `npm run test:e2e:local` needs neither wp-env nor Docker.
 *
 * It loads the site through `wp-load.php` and then: activates WooCommerce and
 * this plugin, switches to post-name permalinks, and makes sure the
 * administrator the specs log in as exists with a known password.
 *
 * With --install it first provisions the site: it downloads WordPress core into
 * --path, creates the database, writes `wp-config.php`, installs WordPress,
 * installs the pinned WooCommerce and links this plugin into the site with a
 * directory junction, so edits are picked up without copying.
 *
 * Usage, from the plugin root:
 *
 *     php bin/setup-e2e-site.php --install
 *     php bin/setup-e2e-site.php
 *
 * Options:
 *   --path=PATH          WordPress root. Default: G:/laragon/www/sit-wcpg-e2e.
 *   --url=URL            Site address. Default: http://sit-wcpg-e2e.test.
 *   --install            Provision the site at --path first.
 *   --wp=X.Y.Z           Core version for --install. Default: the current test target.
 *   --wc=X.Y.Z           WooCommerce version. Default: the current test target.
 *   --db, --db-user, --db-pass, --db-host, --prefix
 *                        Database settings. --db defaults to sit_wcpg_e2e.
 *   --admin-user=NAME    Administrator the specs log in as. Default: admin.
 *   --admin-pass=PASS    Its password. Default: password.
 *   --admin-email=MAIL   Email used when the account has to be created.
 *   --reset-password     Reset an existing account's password to --admin-pass.
 *   --with-plugin-check  Also install and activate the Plugin Check plugin.
 *
 * Laragon creates the `sit-wcpg-e2e.test` virtual host and its hosts-file entry
 * the next time it is reloaded (Menu → Apache → Reload, or Stop + Start All).
 *
 * WARNING: the end-to-end specs create and delete products and change the
 * plugin's settings. The script refuses the `flow_manager` database and the
 * flow-manager site, and any site this plugin's own folder lives in.
 *
 * Development tool: excluded from the release zip.
 *
 * @package ProductPublishGuard
 * @since   1.1.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI tool: runs before WordPress is loaded, and writes wp-config.php and .htaccess directly.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/wp-test-env.php';

/**
 * A path in forward-slash form, without a trailing slash, for comparisons.
 *
 * @since 1.1.0
 *
 * @param string $path Path.
 * @return string
 */
function sit_wcpg_e2e_normalize( string $path ): string {
	$real = realpath( $path );

	return strtolower( rtrim( str_replace( '\\', '/', false !== $real ? $real : $path ), '/' ) );
}

/**
 * Exits unless the database and the site path are safe to hand to the specs.
 *
 * The specs create and delete products and rewrite the plugin's settings. The
 * flow-manager development site is refused by database name and by path, and
 * so is any WordPress root this plugin's real folder sits inside: that is a site
 * whose content somebody cares about.
 *
 * @since 1.1.0
 *
 * @param string $path     WordPress root.
 * @param string $database Database name.
 * @param string $root     This plugin's root directory.
 * @return void
 */
function sit_wcpg_e2e_assert_safe( string $path, string $database, string $root ): void {
	$warning = ' The end-to-end specs create and delete products. Use the dedicated site (G:/laragon/www/sit-wcpg-e2e, database sit_wcpg_e2e).';

	if ( 'flow_manager' === strtolower( $database ) ) {
		sit_wcpg_fail( 'Refusing to use database "' . $database . '": it is the flow-manager site database.' . $warning );
	}

	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $database ) ) {
		sit_wcpg_fail( 'Refusing database name "' . $database . '": use letters, digits and underscores only.' );
	}

	$site = sit_wcpg_e2e_normalize( $path );

	if ( 'flow-manager' === basename( $site ) || false !== strpos( $site . '/', '/flow-manager/' ) ) {
		sit_wcpg_fail( 'Refusing path "' . $path . '": it is the flow-manager site.' . $warning );
	}

	if ( 0 === strpos( sit_wcpg_e2e_normalize( $root ) . '/', $site . '/' ) ) {
		sit_wcpg_fail( 'Refusing path "' . $path . '": this plugin\'s own folder lives in that site.' . $warning );
	}

	// An existing site is judged by the database its wp-config.php really uses.
	$config = $path . '/wp-config.php';

	if ( is_readable( $config ) ) {
		$matches = array();

		if ( preg_match( "/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]*)['\"]/", (string) file_get_contents( $config ), $matches ) && strtolower( $matches[1] ) !== strtolower( $database ) ) {
			sit_wcpg_fail( 'Refusing path "' . $path . '": its wp-config.php uses database "' . $matches[1] . '", not "' . $database . '".' . $warning );
		}
	}
}

/**
 * The eight authentication keys and salts, as `define()` lines.
 *
 * Generated locally: the script makes no request to the secret-key API.
 *
 * @since 1.1.0
 *
 * @return string[]
 */
function sit_wcpg_e2e_salts(): array {
	$lines = array();

	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
		$lines[] = "define( '" . $key . "', '" . bin2hex( random_bytes( 32 ) ) . "' );";
	}

	return $lines;
}

/**
 * Writes the scratch site's `wp-config.php`.
 *
 * WP_HOME and WP_SITEURL are pinned so the site answers on the URL Playwright
 * targets, whatever the options table holds.
 *
 * @since 1.1.0
 *
 * @param string $path WordPress root.
 * @param array  $opts Keys: db, db-user, db-pass, db-host, prefix, url.
 * @return void
 */
function sit_wcpg_e2e_write_wp_config( string $path, array $opts ): void {
	$lines = array_merge(
		array(
			'<?php',
			'/**',
			' * Generated by bin/setup-e2e-site.php (SapphireIT Publish Guard) for the Playwright suite.',
			' *',
			' * Scratch site: the end-to-end specs create and delete products in it.',
			' */',
			'',
			'define( \'DB_NAME\', ' . sit_wcpg_php_string( $opts['db'] ) . ' );',
			'define( \'DB_USER\', ' . sit_wcpg_php_string( $opts['db-user'] ) . ' );',
			'define( \'DB_PASSWORD\', ' . sit_wcpg_php_string( $opts['db-pass'] ) . ' );',
			'define( \'DB_HOST\', ' . sit_wcpg_php_string( $opts['db-host'] ) . ' );',
			"define( 'DB_CHARSET', 'utf8mb4' );",
			"define( 'DB_COLLATE', '' );",
			'',
		),
		sit_wcpg_e2e_salts(),
		array(
			'',
			'$table_prefix = ' . sit_wcpg_php_string( $opts['prefix'] ) . ';',
			'',
			'define( \'WP_HOME\', ' . sit_wcpg_php_string( $opts['url'] ) . ' );',
			'define( \'WP_SITEURL\', ' . sit_wcpg_php_string( $opts['url'] ) . ' );',
			"define( 'WP_DEBUG', true );",
			"define( 'WP_DEBUG_DISPLAY', false );",
			"define( 'WP_DEBUG_LOG', true );",
			"define( 'DISABLE_WP_CRON', true );",
			"define( 'AUTOMATIC_UPDATER_DISABLED', true );",
			"define( 'WP_ENVIRONMENT_TYPE', 'local' );",
			'',
			'// Update checks and WooCommerce.com calls stall admin requests until they',
			'// time out when PHP cannot reach the internet. The suite needs no outbound HTTP.',
			"define( 'WP_HTTP_BLOCK_EXTERNAL', true );",
			'',
			"if ( ! defined( 'ABSPATH' ) ) {",
			"\tdefine( 'ABSPATH', __DIR__ . '/' );",
			'}',
			'',
			"require_once ABSPATH . 'wp-settings.php';",
			'',
		)
	);

	if ( false === file_put_contents( $path . '/wp-config.php', implode( "\n", $lines ) ) ) {
		sit_wcpg_fail( 'Cannot write ' . $path . '/wp-config.php.' );
	}
}

/**
 * Copies a directory tree.
 *
 * @since 1.1.0
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory.
 * @return void
 */
function sit_wcpg_e2e_copy_tree( string $from, string $to ): void {
	sit_wcpg_mkdir( $to );

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $items as $item ) {
		$target = $to . '/' . $items->getSubPathname();

		if ( $item->isDir() ) {
			sit_wcpg_mkdir( $target );
		} elseif ( ! copy( $item->getPathname(), $target ) ) {
			sit_wcpg_fail( 'Cannot copy ' . $item->getPathname() . '.' );
		}
	}
}

/**
 * Downloads an archive into the cache once, then copies its folder to `$target`.
 *
 * The cache sits on another drive than the Laragon sites on most machines, and
 * Windows cannot rename a directory across drives, so the folder is copied.
 * The test suites' core checkouts are not reused: WooCommerce is installed
 * inside them.
 *
 * @since 1.1.0
 *
 * @param string $url    Zip URL.
 * @param string $cache  Cache directory.
 * @param string $folder The archive's top-level folder.
 * @param string $name   Cache folder name, unique per version.
 * @param string $target Destination.
 * @return void
 */
function sit_wcpg_e2e_install( string $url, string $cache, string $folder, string $name, string $target ): void {
	$cached = $cache . '/e2e/' . $name;

	if ( ! is_dir( $cached ) ) {
		sit_wcpg_install_archive( $url, $cache . '/e2e', $folder, $cached );
	}

	sit_wcpg_rmdir( $target );
	sit_wcpg_say( 'Copying ' . $name . ' to ' . $target . ' ...' );
	sit_wcpg_e2e_copy_tree( $cached, $target );
}

/**
 * Links this plugin into the site with a directory junction.
 *
 * A junction needs no elevation and no Developer Mode, unlike a symlink, and the
 * site then runs the working tree, so a rebuild needs no copy.
 *
 * @since 1.1.0
 *
 * @param string $link   Junction to create.
 * @param string $target Plugin root.
 * @return void
 */
function sit_wcpg_e2e_link_plugin( string $link, string $target ): void {
	if ( is_dir( $link ) ) {
		sit_wcpg_say( 'Plugin already linked at ' . $link . '.' );

		return;
	}

	if ( 'Windows' === PHP_OS_FAMILY ) {
		// mklink is a cmd.exe builtin, so it goes through the shell; exec() on Windows is `cmd /c`.
		$output = array();
		$code   = 1;

		exec( 'mklink /J ' . escapeshellarg( str_replace( '/', '\\', $link ) ) . ' ' . escapeshellarg( str_replace( '/', '\\', $target ) ), $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- CLI dev tool; mklink exists only inside cmd.exe.
	} else {
		$code = symlink( $target, $link ) ? 0 : 1;
	}

	if ( 0 !== $code || ! is_dir( $link ) ) {
		sit_wcpg_fail( 'Cannot link the plugin into ' . $link . '.' );
	}

	sit_wcpg_say( 'Linked the plugin into ' . $link . '.' );
}

$sit_wcpg_root    = dirname( __DIR__ );
$sit_wcpg_current = sit_wcpg_test_target( 'current' );

$sit_wcpg_opts = sit_wcpg_cli_options(
	$argv,
	array(
		'path'              => 'G:/laragon/www/sit-wcpg-e2e',
		'url'               => 'http://sit-wcpg-e2e.test',
		'install'           => false,
		'wp'                => $sit_wcpg_current['wp'],
		'wc'                => $sit_wcpg_current['wc'],
		'db'                => 'sit_wcpg_e2e',
		'db-user'           => 'root',
		'db-pass'           => '',
		'db-host'           => '127.0.0.1',
		'prefix'            => 'wp_',
		'admin-user'        => 'admin',
		'admin-pass'        => 'password',
		'admin-email'       => 'admin@example.org',
		'reset-password'    => false,
		'with-plugin-check' => false,
	)
);

$sit_wcpg_path        = rtrim( str_replace( '\\', '/', $sit_wcpg_opts['path'] ), '/' );
$sit_wcpg_opts['url'] = rtrim( (string) $sit_wcpg_opts['url'], '/' );

// Refused by name and path before anything is downloaded, created or loaded.
sit_wcpg_e2e_assert_safe( $sit_wcpg_path, $sit_wcpg_opts['db'], $sit_wcpg_root );

// The system PHP may lack ext/mysqli: run again with it loaded.
if ( ! extension_loaded( 'mysqli' ) && false === getenv( 'SIT_WCPG_RELAUNCHED' ) ) {
	$sit_wcpg_php = sit_wcpg_php_command( PHP_BINARY );

	exit( (int) sit_wcpg_run( array_merge( $sit_wcpg_php['command'], array( __FILE__ ), array_slice( $argv, 1 ) ), array( 'SIT_WCPG_RELAUNCHED' => '1' ) ) );
}

sit_wcpg_require_extensions( array( 'mysqli' ) );

$sit_wcpg_plugins = $sit_wcpg_path . '/wp-content/plugins';

if ( $sit_wcpg_opts['install'] ) {
	sit_wcpg_require_extensions( array( 'curl', 'zip' ) );

	$sit_wcpg_cache = sit_wcpg_test_cache_dir();

	// 0a. Core files, only into a folder that is empty or not there yet.
	if ( is_file( $sit_wcpg_path . '/wp-load.php' ) ) {
		sit_wcpg_say( 'WordPress already present in ' . $sit_wcpg_path . ', keeping it.' );
	} elseif ( is_dir( $sit_wcpg_path ) && array() !== array_diff( (array) scandir( $sit_wcpg_path ), array( '.', '..' ) ) ) {
		sit_wcpg_fail( $sit_wcpg_path . ' is not empty and holds no WordPress. Pick another --path.' );
	} else {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The zip's top-level folder is literally "wordpress".
		sit_wcpg_e2e_install( 'https://wordpress.org/wordpress-' . $sit_wcpg_opts['wp'] . '.zip', $sit_wcpg_cache, 'wordpress', 'wordpress-' . $sit_wcpg_opts['wp'], $sit_wcpg_path );
	}

	// 0b. Database and configuration.
	$sit_wcpg_connection = sit_wcpg_mysql( $sit_wcpg_opts['db-host'], $sit_wcpg_opts['db-user'], $sit_wcpg_opts['db-pass'] );
	sit_wcpg_create_database( $sit_wcpg_connection, $sit_wcpg_opts['db'] );
	$sit_wcpg_connection->close();

	if ( is_file( $sit_wcpg_path . '/wp-config.php' ) ) {
		sit_wcpg_say( 'wp-config.php already exists, keeping it.' );
	} else {
		sit_wcpg_e2e_write_wp_config( $sit_wcpg_path, $sit_wcpg_opts );
		sit_wcpg_say( 'Wrote ' . $sit_wcpg_path . '/wp-config.php.' );
	}

	// 0c. WooCommerce at the pinned version.
	if ( sit_wcpg_woocommerce_version( $sit_wcpg_plugins . '/woocommerce' ) === $sit_wcpg_opts['wc'] ) {
		sit_wcpg_say( 'WooCommerce ' . $sit_wcpg_opts['wc'] . ' already installed.' );
	} else {
		sit_wcpg_e2e_install( 'https://downloads.wordpress.org/plugin/woocommerce.' . $sit_wcpg_opts['wc'] . '.zip', $sit_wcpg_cache, 'woocommerce', 'woocommerce-' . $sit_wcpg_opts['wc'], $sit_wcpg_plugins . '/woocommerce' );
	}

	if ( $sit_wcpg_opts['with-plugin-check'] && ! is_dir( $sit_wcpg_plugins . '/plugin-check' ) ) {
		sit_wcpg_e2e_install( 'https://downloads.wordpress.org/plugin/plugin-check.latest-stable.zip', $sit_wcpg_cache, 'plugin-check', 'plugin-check', $sit_wcpg_plugins . '/plugin-check' );
	}

	// 0d. This plugin, linked rather than copied.
	sit_wcpg_e2e_link_plugin( $sit_wcpg_plugins . '/' . basename( $sit_wcpg_root ), $sit_wcpg_root );

	// Lets wp-settings.php boot against a database that has no tables yet.
	define( 'WP_INSTALLING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.
}

if ( ! is_file( $sit_wcpg_path . '/wp-load.php' ) ) {
	sit_wcpg_fail( 'No wp-load.php in ' . $sit_wcpg_path . '. Run with --install to provision the site.' );
}

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.


require_once $sit_wcpg_path . '/wp-load.php';

if ( ! is_blog_installed() ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sit_wcpg_installed = wp_install(
		'Publish Guard end-to-end',
		(string) $sit_wcpg_opts['admin-user'],
		(string) $sit_wcpg_opts['admin-email'],
		false,
		'',
		(string) $sit_wcpg_opts['admin-pass']
	);

	if ( empty( $sit_wcpg_installed['user_id'] ) ) {
		sit_wcpg_fail( 'WordPress could not be installed.' );
	}

	// Back to a normal request now that the tables exist.
	wp_installing( false );

	sit_wcpg_say( 'Installed WordPress at ' . $sit_wcpg_opts['url'] . '.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

sit_wcpg_say( 'Site      : ' . site_url() );
sit_wcpg_say( 'WordPress : ' . get_bloginfo( 'version' ) );
sit_wcpg_say( '' );

// 1. WooCommerce first, then this plugin, which requires it. The plugin folder
// may be a junction, so its path is built from the site's plugin directory.
$sit_wcpg_activate = array( 'woocommerce/woocommerce.php', basename( $sit_wcpg_root ) . '/sapphireit-publish-guard.php' );

if ( $sit_wcpg_opts['with-plugin-check'] ) {
	$sit_wcpg_activate[] = 'plugin-check/plugin.php';
}

foreach ( $sit_wcpg_activate as $sit_wcpg_plugin ) {
	if ( ! is_file( WP_PLUGIN_DIR . '/' . $sit_wcpg_plugin ) ) {
		sit_wcpg_fail( $sit_wcpg_plugin . ' is not in ' . WP_PLUGIN_DIR . '. Run with --install first.' );
	}

	if ( is_plugin_active( $sit_wcpg_plugin ) ) {
		sit_wcpg_say( 'Already active: ' . $sit_wcpg_plugin . '.' );
		continue;
	}

	$sit_wcpg_activated = activate_plugin( $sit_wcpg_plugin );

	if ( is_wp_error( $sit_wcpg_activated ) ) {
		sit_wcpg_fail( 'Cannot activate ' . $sit_wcpg_plugin . ': ' . $sit_wcpg_activated->get_error_message() );
	}

	sit_wcpg_say( 'Activated ' . $sit_wcpg_plugin . '.' );
}

// 2. No onboarding wizard, task list or marketplace prompts between the specs and the screens.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_allow_tracking', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );

// 3. Post-name permalinks, so /wp-json/ routes resolve the way the specs expect.
global $wp_rewrite;

if ( '/%postname%/' !== (string) get_option( 'permalink_structure' ) ) {
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	sit_wcpg_say( 'Permalinks set to /%postname%/.' );
} else {
	sit_wcpg_say( 'Permalinks already /%postname%/.' );
}

$wp_rewrite->flush_rules( false );

/*
 * WordPress writes .htaccess only when it can see mod_rewrite, which it never
 * can from the CLI, so the rules are written here. Apache (Laragon's default)
 * only; on nginx, add the usual try_files rule to the server block.
 */
$sit_wcpg_htaccess = $sit_wcpg_path . '/.htaccess';

if ( false === strpos( is_file( $sit_wcpg_htaccess ) ? (string) file_get_contents( $sit_wcpg_htaccess ) : '', '# BEGIN WordPress' ) ) {
	insert_with_markers( $sit_wcpg_htaccess, 'WordPress', explode( "\n", $wp_rewrite->mod_rewrite_rules() ) );
	sit_wcpg_say( 'Wrote the WordPress rewrite rules to .htaccess.' );
}

// 4. The administrator the specs authenticate as.
$sit_wcpg_admin = get_user_by( 'login', $sit_wcpg_opts['admin-user'] );

if ( ! $sit_wcpg_admin ) {
	$sit_wcpg_id = wp_insert_user(
		array(
			'user_login' => $sit_wcpg_opts['admin-user'],
			'user_pass'  => $sit_wcpg_opts['admin-pass'],
			'user_email' => $sit_wcpg_opts['admin-email'],
			'role'       => 'administrator',
		)
	);

	if ( is_wp_error( $sit_wcpg_id ) ) {
		sit_wcpg_fail( 'Cannot create the administrator: ' . $sit_wcpg_id->get_error_message() );
	}

	sit_wcpg_say( 'Created administrator "' . $sit_wcpg_opts['admin-user'] . '".' );
} elseif ( $sit_wcpg_opts['reset-password'] ) {
	wp_set_password( $sit_wcpg_opts['admin-pass'], $sit_wcpg_admin->ID );
	sit_wcpg_say( 'Reset the password of "' . $sit_wcpg_opts['admin-user'] . '".' );
} else {
	sit_wcpg_say( 'Administrator "' . $sit_wcpg_opts['admin-user'] . '" exists (password unchanged; --reset-password resets it).' );
}

// 5. What to run next.
$sit_wcpg_host = (string) wp_parse_url( $sit_wcpg_opts['url'], PHP_URL_HOST );

sit_wcpg_say( '' );
sit_wcpg_say( 'Ready. If ' . $sit_wcpg_host . ' does not resolve yet, reload Laragon (Menu > Apache > Reload)' );
sit_wcpg_say( 'so it creates the virtual host and the hosts-file entry. Then run:' );
sit_wcpg_say( '' );
sit_wcpg_say( '    npm run test:e2e:local' );
sit_wcpg_say( '' );
sit_wcpg_say( 'or, for another site or account:' );
sit_wcpg_say( '' );
sit_wcpg_say( '    npm run test:e2e:local -- --base-url=' . $sit_wcpg_opts['url'] . '/ --user=' . $sit_wcpg_opts['admin-user'] . ' --pass=<password>' );
