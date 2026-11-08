/**
 * SIT-WCPG-E2E-1: publishing a variable product with an unpriced enabled
 * variation is refused, and the notice names where to fix it.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	fixtures,
	guardNotice,
	openProduct,
	postMessage,
	productStatus,
	publish,
} from './utils';

test( 'E2E-1: an unpriced enabled variation keeps the product a draft', async ( {
	page,
	requestUtils,
} ) => {
	const { products, variations } = fixtures();
	const unpriced = variations.e2e1[ 1 ];

	await openProduct( page, products.e2e1 );

	const prompt = await publish( page );

	expect( prompt ).toContain( 'saved as a draft instead of being published' );

	const notice = guardNotice( page, 'blocked' );

	await expect( notice ).toContainText(
		'This product was not published because required checks failed:'
	);
	await expect( notice ).toContainText(
		`1 of 2 enabled variations has no valid regular price (first: variation #${ unpriced }) — enter one in Product data → Variations → Regular price.`
	);
	await expect( postMessage( page ) ).toContainText(
		'Product saved, but not published.'
	);
	expect( await productStatus( requestUtils, products.e2e1 ) ).toBe(
		'draft'
	);
} );
