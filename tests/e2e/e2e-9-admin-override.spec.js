/**
 * SIT-WCPG-E2E-9: with the override allowed, an administrator publishes past a
 * required failure and sees the override notice.
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
	guardNotice,
	openProduct,
	postMessage,
	productStatus,
	publish,
	saveSettings,
} from './utils';

test.describe( 'E2E-9: administrator override', () => {
	// Back to the baseline even when the test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
	} );

	test( 'publishes past a required failure and says so', async ( {
		page,
		requestUtils,
	} ) => {
		const { products } = fixtures();

		await saveSettings( page, {
			checkboxes: { 'publishing][allow_admin_override': true },
		} );
		await openProduct( page, products.e2e9 );

		const prompt = await publish( page );

		expect( prompt ).toBe(
			'Required checks are failing. Publish this product anyway?'
		);
		await expect( postMessage( page ) ).toContainText(
			'Product published.'
		);

		const notice = guardNotice( page, 'override' );

		await expect( notice ).toContainText(
			'This product was published although required checks failed, because you are allowed to override the publishing guard:'
		);
		await expect( notice ).toContainText(
			'The regular price is empty — enter it in Product data → General → Regular price.'
		);
		expect( await productStatus( requestUtils, products.e2e9 ) ).toBe(
			'publish'
		);
	} );
} );
