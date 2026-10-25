/**
 * Playwright config: the `@wordpress/scripts` preset, pointed at tests/e2e/.
 *
 * The suite runs against the dedicated Laragon site only, never wp-env:
 * `npm run test:e2e:local` sets WP_BASE_URL, WP_USERNAME and WP_PASSWORD
 * (default http://sit-wcpg-e2e.test/, admin, password). Prepare the site once
 * with `php bin/setup-e2e-site.php --install`.
 *
 * Global setup signs in, puts the plugin's settings back to the baseline the
 * specs assume, and creates every fixture product as a draft through the
 * WooCommerce REST API. Global teardown deletes them.
 */
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

const root = path.resolve( __dirname, '../..' );

process.env.WP_ARTIFACTS_PATH ??= path.join( root, 'artifacts' );

module.exports = {
	...baseConfig,
	testDir: __dirname,
	testMatch: '**/*.spec.js',
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	globalSetup: require.resolve( './global-setup.js' ),
	globalTeardown: require.resolve( './global-teardown.js' ),
	reporter: [
		[ 'list' ],
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join( root, 'playwright-report' ),
			},
		],
	],
	/*
	 * A live re-check is an AJAX save, an 800 ms debounce, a 1500 ms floor, then
	 * the request; on a local site that alone can pass the 5 s default.
	 */
	expect: { ...baseConfig.expect, timeout: 15_000 },
	use: {
		...baseConfig.use,
		// The classic product editor needs room for its two columns.
		viewport: { width: 1280, height: 800 },
	},
	// The site is already running; nothing to start.
	webServer: undefined,
};
