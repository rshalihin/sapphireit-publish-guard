/**
 * SIT-WCPG-E2E-6: a grouped product with no linked products gets a warning.
 * Raised to required, the same check refuses to publish it.
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
	checklistRow,
	fixtures,
	guardNotice,
	openProduct,
	productStatus,
	publish,
	saveSettings,
} from './utils';

const MESSAGE =
	'This grouped product contains no products — add some in Product data → Linked Products → Grouped products.';

test.describe( 'E2E-6: grouped product with no linked products', () => {
	// Back to the baseline even when the test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
	} );

	test( 'warns by default', async ( { page } ) => {
		const { products } = fixtures();

		await openProduct( page, products.e2e6Warn );

		const row = checklistRow( page, 'Variations and grouped products' );

		await expect( row ).toHaveClass( /sit-wcpg-checklist__item--warning/ );
		await expect( row ).toContainText( MESSAGE );
	} );

	test( 'is refused when the check is required', async ( {
		page,
		requestUtils,
	} ) => {
		const { products } = fixtures();

		await saveSettings( page, {
			radios: { 'rules][child_products][severity': 'required' },
		} );
		await openProduct( page, products.e2e6Refuse );
		await expect(
			checklistRow( page, 'Variations and grouped products' )
		).toHaveClass( /sit-wcpg-checklist__item--fail/ );

		await publish( page );

		await expect( guardNotice( page, 'blocked' ) ).toContainText( MESSAGE );
		expect( await productStatus( requestUtils, products.e2e6Refuse ) ).toBe(
			'draft'
		);
	} );
} );
