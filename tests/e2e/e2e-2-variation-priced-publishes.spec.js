/**
 * SIT-WCPG-E2E-2: pricing the variation in Variations → Save changes, then
 * Publish, succeeds, and the checklist shows no failure and no warning.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	checklist,
	checklistRow,
	fixtures,
	guardNotice,
	openProduct,
	postMessage,
	priceVariation,
	productStatus,
	publish,
} from './utils';

test( 'E2E-2: once every enabled variation is priced, Publish succeeds', async ( {
	page,
	requestUtils,
} ) => {
	const { products, variations } = fixtures();

	await openProduct( page, products.e2e2 );
	await priceVariation( page, variations.e2e2[ 1 ], '49.00' );
	await expect( checklistRow( page, 'Regular price' ) ).toHaveClass(
		/sit-wcpg-checklist__item--pass/
	);

	const prompt = await publish( page );

	expect( prompt ).toBeNull();
	await expect( postMessage( page ) ).toContainText( 'Product published.' );
	await expect( guardNotice( page, 'blocked' ) ).toHaveCount( 0 );
	expect( await productStatus( requestUtils, products.e2e2 ) ).toBe(
		'publish'
	);

	await expect(
		checklist( page ).locator( '.sit-wcpg-checklist__summary' )
	).toContainText( 'Ready to publish' );
	await expect(
		checklist( page ).locator(
			'.sit-wcpg-checklist__item--fail, .sit-wcpg-checklist__item--warning'
		)
	).toHaveCount( 0 );
} );
