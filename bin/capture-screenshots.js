/**
 * Regenerates the WordPress.org screenshots that Playwright can produce:
 * `.wordpress-org/screenshot-1.png`, `-3`, `-4` and `-5` (see the spec for
 * what each shows). Screenshot 2 is still taken by hand.
 *
 * Runs tests/e2e/screenshots.spec.js against the dedicated Laragon site the
 * same way bin/e2e-local.js runs the suite. SIT_WCPG_CAPTURE=1 is what
 * un-skips the spec.
 *
 * Usage, from the plugin root:
 *
 *     npm run screenshots
 *     npm run screenshots -- --base-url=http://sit-wcpg-e2e.test/ --user=admin --pass=password
 */
const { run } = require( './e2e-local' );

run( process.argv.slice( 2 ), { SIT_WCPG_CAPTURE: '1' }, [
	'tests/e2e/screenshots.spec.js',
] );
