/**
 * WP-CLI on the dedicated E2E site, for what a browser cannot see: the options
 * the plugin keeps, its own commands, and running the background queue on demand.
 *
 * Calls the phar on PHP 8.1 or later, never `wp.bat`: that runs the `php` on
 * PATH, which may be below the plugin's floor, and the plugin does not boot there.
 *
 * CommonJS, like settings.js and fixtures.js, because global setup needs it too.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * WordPress root of the E2E site.
 *
 * @type {string}
 */
const E2E_PATH = process.env.SIT_WCPG_E2E_PATH || 'G:/laragon/www/sit-wcpg-e2e';

/**
 * PHP binary, 8.1 or later.
 *
 * @type {string}
 */
const PHP_BIN =
	process.env.SIT_WCPG_E2E_PHP ||
	'G:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe';

/**
 * The WP-CLI phar.
 *
 * @type {string}
 */
const WP_CLI_BIN = process.env.SIT_WCPG_E2E_WP_CLI || 'C:/wp-cli/wp-cli.phar';

/**
 * The mu-plugin that stops Action Scheduler running by itself on the E2E site,
 * written by bin/setup-e2e-site.php.
 *
 * @type {string}
 */
const MANUAL_QUEUE_PLUGIN = path.join(
	E2E_PATH,
	'wp-content/mu-plugins/sit-wcpg-e2e-manual-queue.php'
);

/**
 * Runs a WP-CLI command on the E2E site.
 *
 * A non-zero exit throws, with `status`, `stdout` and `stderr` on the error.
 *
 * @param {string[]} args Command and arguments.
 * @return {string} Trimmed standard output.
 */
function wp( args ) {
	return execFileSync(
		PHP_BIN,
		[ WP_CLI_BIN, `--path=${ E2E_PATH }`, ...args ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	).trim();
}

/**
 * Runs every queued readiness job, including the pages each job queues after it.
 */
function runRebuildQueue() {
	wp( [ 'action-scheduler', 'run', '--group=sit-wcpg' ] );
}

/**
 * Stops the run early when the site cannot hold the queue back: e2e-12 has to
 * see a rebuild in progress, and Action Scheduler's own runner could finish it.
 */
function assertManualQueue() {
	if ( ! fs.existsSync( MANUAL_QUEUE_PLUGIN ) ) {
		throw new Error(
			`${ MANUAL_QUEUE_PLUGIN } is missing. Run: php bin/setup-e2e-site.php`
		);
	}
}

module.exports = { assertManualQueue, runRebuildQueue, wp };
