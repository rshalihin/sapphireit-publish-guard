/**
 * Captures the WordPress.org screenshot 5: publishing refused, with the reason
 * and where to fix it.
 *
 * Skipped unless SIT_WCPG_CAPTURE=1, so the suite never runs it: it is a
 * generator, not a test, and asserts only enough to be sure it photographs the
 * right screen. Run it with `npm run screenshots` (bin/capture-screenshots.js).
 */

/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { fixtures, guardNotice, openProduct, publish } from './utils';

const OUT = path.resolve( __dirname, '../../.wordpress-org/screenshot-5.png' );

test.describe( 'WordPress.org screenshots', () => {
	test.skip(
		'1' !== process.env.SIT_WCPG_CAPTURE,
		'Set SIT_WCPG_CAPTURE=1 (npm run screenshots) to capture.'
	);

	test.use( { viewport: { width: 1280, height: 800 } } );

	test( 'screenshot-5: a refused publish with its notice', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await openProduct( page, products.screenshot );
		await publish( page );
		await expect( guardNotice( page, 'blocked' ) ).toBeVisible();

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.screenshot( { path: OUT } );
	} );
} );
