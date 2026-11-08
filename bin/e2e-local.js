/**
 * Runs the Playwright suite against the dedicated Laragon site instead of
 * wp-env, so the end-to-end tests need no Docker.
 *
 * Maps --base-url, --user and --pass to WP_BASE_URL, WP_USERNAME and
 * WP_PASSWORD, defaulting to http://sit-wcpg-e2e.test/, admin and password.
 * Setting them here keeps the command identical on PowerShell, cmd and bash.
 *
 * Usage, from the plugin root:
 *
 *     npm run test:e2e:local
 *     npm run test:e2e:local -- --base-url=http://sit-wcpg-e2e.test/ --user=admin --pass=password
 *     npm run test:e2e:local -- --headed tests/e2e/e2e-1-variation-price-refused.spec.js
 *
 * Unrecognised arguments are passed through to `wp-scripts test-playwright`.
 * Provision the site once with `php bin/setup-e2e-site.php --install`.
 *
 * Exported for bin/capture-screenshots.js, which runs one spec the same way.
 */
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const CONFIG = path.join( ROOT, 'tests/e2e/playwright.config.js' );

const OPTIONS = {
	'base-url': 'WP_BASE_URL',
	user: 'WP_USERNAME',
	pass: 'WP_PASSWORD',
};

const DEFAULTS = {
	WP_BASE_URL: 'http://sit-wcpg-e2e.test/',
	WP_USERNAME: 'admin',
	WP_PASSWORD: 'password',
};

/**
 * Runs `wp-scripts test-playwright` with the suite's config and exits with its code.
 *
 * @param {string[]} argv  Arguments after the script name.
 * @param {Object}   [env] Extra environment variables.
 * @param {string[]} [pre] Arguments placed before the pass-through ones.
 */
function run( argv, env = {}, pre = [] ) {
	const childEnv = { ...process.env, ...env };
	const passthrough = [];

	for ( const arg of argv ) {
		const match = /^--([^=]+)=(.*)$/.exec( arg );

		if ( match && OPTIONS[ match[ 1 ] ] ) {
			childEnv[ OPTIONS[ match[ 1 ] ] ] = match[ 2 ];
		} else {
			passthrough.push( arg );
		}
	}

	for ( const [ name, value ] of Object.entries( DEFAULTS ) ) {
		childEnv[ name ] = childEnv[ name ] || value;
	}

	// e2e-test-utils-playwright resolves paths against this, so it needs the slash.
	if ( ! childEnv.WP_BASE_URL.endsWith( '/' ) ) {
		childEnv.WP_BASE_URL += '/';
	}

	// Only Chromium is used: install it, and stop wp-scripts installing every browser.
	const install = spawnSync(
		process.execPath,
		[ require.resolve( '@playwright/test/cli' ), 'install', 'chromium' ],
		{ stdio: 'inherit', env: childEnv }
	);

	if ( install.status !== 0 ) {
		process.exit( install.status === null ? 1 : install.status );
	}

	childEnv.PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD = '1';

	// eslint-disable-next-line no-console
	console.log(
		`Running end-to-end tests against ${ childEnv.WP_BASE_URL } as ${ childEnv.WP_USERNAME }\n`
	);

	const result = spawnSync(
		process.execPath,
		[
			require.resolve( '@wordpress/scripts/bin/wp-scripts.js' ),
			'test-playwright',
			'--config',
			CONFIG,
			...pre,
			...passthrough,
		],
		{ stdio: 'inherit', env: childEnv, cwd: ROOT }
	);

	process.exit( result.status === null ? 1 : result.status );
}

if ( require.main === module ) {
	run( process.argv.slice( 2 ) );
}

module.exports = { run };
