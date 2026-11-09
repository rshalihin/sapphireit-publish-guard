/**
 * SIT-WCPG-E2E-12: raising a rule makes the stored flags stale. The filtered
 * list says so until the background rebuild has run, then it is right.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	BASELINE,
	fixtures,
	readinessFilter,
	runRebuildQueue,
	saveSettings,
} from './utils';

test.describe( 'E2E-12: settings change and rebuild', () => {
	// Back to the baseline, with flags to match, even when the test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
		runRebuildQueue();
	} );

	test( 'shows the banner until the queue has run, then lists the product', async ( {
		page,
	} ) => {
		const { products } = fixtures();
		const row = page.locator( `#post-${ products.e2e12 }` );
		const banner = page.locator( '.notice-info', {
			hasText: 'Rechecking products against your current settings',
		} );

		runRebuildQueue();

		await saveSettings( page, {
			radios: { 'rules][sku][severity': 'required' },
		} );

		await page.goto( '/wp-admin/edit.php?post_type=product&s=E2E-12' );
		await readinessFilter( page, 'fail' );

		await expect( banner ).toHaveText(
			/^Rechecking products against your current settings: \d+ of \d+ done\. This filtered list will be complete when it finishes\.$/
		);
		await expect( row ).toHaveCount( 0 );

		runRebuildQueue();
		await page.reload();

		await expect( row ).toBeVisible();
		await expect( banner ).toHaveCount( 0 );
	} );
} );
