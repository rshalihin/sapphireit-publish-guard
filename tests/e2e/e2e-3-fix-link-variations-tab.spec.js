/**
 * SIT-WCPG-E2E-3: the fix link of a variation failure opens the Variations tab.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { checklistRow, fixtures, openProduct } from './utils';

test( 'E2E-3: the fix link opens Product data → Variations', async ( {
	page,
} ) => {
	const { products } = fixtures();

	await openProduct( page, products.e2e3 );

	const row = checklistRow( page, 'Regular price' );

	await expect( row ).toHaveClass( /sit-wcpg-checklist__item--fail/ );
	await expect( page.locator( '#variable_product_options' ) ).toBeHidden();

	await row
		.locator( '.sit-wcpg-checklist__fix', {
			hasText: 'Open the Variations tab',
		} )
		.click();

	await expect(
		page.locator( '#woocommerce-product-data li.variations_options' )
	).toHaveClass( /active/ );
	await expect( page.locator( '#variable_product_options' ) ).toBeVisible();
	await expect(
		page
			.locator( '#variable_product_options .woocommerce_variation' )
			.first()
	).toBeVisible();
} );
