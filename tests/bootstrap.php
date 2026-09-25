<?php
/**
 * PHPUnit bootstrap.
 *
 * Two modes. When a WordPress test suite is available (the `WP_TESTS_DIR` or
 * `WP_PHPUNIT__DIR` environment variable, which `wp-env` sets), the plugin is loaded
 * into a real WordPress install for the integration and security suites. Otherwise the
 * unit suite runs against test doubles, with no WordPress at all — which is possible
 * only because the rule engine reads its data through `Product_Context`.
 *
 * @package ProductPublishGuard
 */

$sit_wcpg_root = dirname( __DIR__ );

require_once $sit_wcpg_root . '/vendor/autoload.php';

$sit_wcpg_wp_tests = getenv( 'WP_TESTS_DIR' );

if ( ! $sit_wcpg_wp_tests ) {
	$sit_wcpg_wp_tests = getenv( 'WP_PHPUNIT__DIR' );
}

if ( $sit_wcpg_wp_tests && file_exists( $sit_wcpg_wp_tests . '/includes/functions.php' ) ) {
	require_once $sit_wcpg_wp_tests . '/includes/functions.php';

	/*
	 * The test install activates no plugins, so WooCommerce is loaded by hand, ahead of
	 * this plugin: the requirements check at `plugins_loaded` refuses to boot without it.
	 * `wp-env` mounts it beside this plugin, so it is looked for in the plugins directory.
	 */
	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $sit_wcpg_root ) {
			require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			require $sit_wcpg_root . '/product-publish-guard.php';
		}
	);

	/*
	 * The test database starts empty, so WooCommerce's tables, options and roles (the
	 * shop manager the capability tests act as) are installed, then the role cache is
	 * reloaded so the new roles are visible.
	 */
	tests_add_filter(
		'setup_theme',
		static function () {
			\WC_Install::install();

			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces core to rebuild the role list WooCommerce just extended.
			wp_roles();
		}
	);

	require $sit_wcpg_wp_tests . '/includes/bootstrap.php';

	/*
	 * After the suite has booted, the plugin's autoloader is registered, so the rule
	 * double can be declared. The integration tests use it to build a registry whose
	 * rules are known, rather than asserting against the eleven shipped ones.
	 */
	require_once __DIR__ . '/stubs/class-fake-rule.php';
	require_once __DIR__ . '/Integration/Publish_Guard_Test_Case.php';

	return;
}

/*
 * ABSPATH is WordPress's own constant, and the unit suite has to stand in for it because
 * every plugin file guards on it.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
defined( 'ABSPATH' ) || define( 'ABSPATH', $sit_wcpg_root . '/' );
defined( 'SIT_WCPG_PATH' ) || define( 'SIT_WCPG_PATH', $sit_wcpg_root . '/' );
defined( 'SIT_WCPG_VERSION' ) || define( 'SIT_WCPG_VERSION', '1.0.0' );

require_once __DIR__ . '/stubs/wordpress-functions.php';

if ( ! class_exists( 'WC_Product' ) ) {
	require_once __DIR__ . '/stubs/class-wc-product.php';
}

require_once $sit_wcpg_root . '/src/Autoloader.php';

ProductPublishGuard\Autoloader::register();

require_once __DIR__ . '/stubs/class-fake-rule.php';
