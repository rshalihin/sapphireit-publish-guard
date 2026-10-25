/**
 * SIT-WCPG-E2E-8: after Variations → Save changes, the live checklist refreshes
 * without a page reload.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { checklistRow, fixtures, openProduct, priceVariation } from './utils';

test( 'E2E-8: saving variations re-checks the product in place', async ( {
	page,
} ) => {
	const { products, variations } = fixtures();

	await openProduct( page, products.e2e8 );
	await expect( checklistRow( page, 'Regular price' ) ).toHaveClass(
		/sit-wcpg-checklist__item--fail/
	);

	// A reload would drop this marker.
	await page.evaluate( () => {
		window.sitWcpgE2eNoReload = true;
	} );

	await priceVariation( page, variations.e2e8[ 1 ], '49.00' );

	await expect( checklistRow( page, 'Regular price' ) ).toHaveClass(
		/sit-wcpg-checklist__item--pass/
	);
	expect( await page.evaluate( () => window.sitWcpgE2eNoReload ) ).toBe(
		true
	);
} );
