/**
 * SIT-WCPG-E2E-13: a live product that a stricter rule would now refuse is
 * listed under Products → Publish Guard, and stays live.
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
	openProduct,
	productStatus,
	publish,
	runRebuildQueue,
	saveSettings,
} from './utils';

test.describe( 'E2E-13: catalog view', () => {
	// Back to the baseline, with flags to match, even when the test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
		runRebuildQueue();
	} );

	test( 'lists a live product that now fails, and leaves it published', async ( {
		page,
		requestUtils,
	} ) => {
		const { products } = fixtures();

		// SKU is only a warning here, so nothing stops this.
		await openProduct( page, products.e2e13 );
		expect( await publish( page ) ).toBeNull();
		expect( await productStatus( requestUtils, products.e2e13 ) ).toBe(
			'publish'
		);

		await saveSettings( page, {
			radios: { 'rules][sku][severity': 'required' },
		} );
		runRebuildQueue();

		await page.goto(
			'/wp-admin/edit.php?post_type=product&page=sit-wcpg-catalog'
		);

		await expect( page.locator( 'h1' ) ).toHaveText(
			'Live products that would fail your rules'
		);
		await expect(
			page.getByText(
				'These products are published. Under the checks you have set as required, each one would be refused if it were published today. They stay live — Publish Guard never takes a product offline.'
			)
		).toBeVisible();

		const row = page.locator( '#the-list > tr', {
			hasText: 'E2E-13 Hemp market bag',
		} );

		await expect( row.locator( '.sit-wcpg-catalog__checks' ) ).toHaveText(
			'SKU'
		);
		await expect( row.locator( '.sit-wcpg-catalog__stale' ) ).toHaveCount(
			0
		);
		expect( await productStatus( requestUtils, products.e2e13 ) ).toBe(
			'publish'
		);
	} );
} );
