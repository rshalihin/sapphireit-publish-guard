/**
 * SIT-WCPG-E2E-14: an administrator's override from the classic editor adds
 * exactly one entry to the override log, though the editor saves twice.
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
	postMessage,
	publish,
	saveSettings,
	wp,
} from './utils';

test.describe( 'E2E-14: override recorded', () => {
	// Back to the baseline even when the test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
	} );

	test( 'logs one entry with the product and the save path', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await saveSettings( page, {
			checkboxes: { 'publishing][allow_admin_override': true },
		} );
		await openProduct( page, products.e2e14 );
		await publish( page );

		await expect( postMessage( page ) ).toContainText(
			'Product published.'
		);

		const log = JSON.parse(
			wp( [ 'option', 'get', 'sit_wcpg_override_log', '--format=json' ] )
		);
		const entries = log.filter(
			( entry ) => entry.product_id === products.e2e14
		);

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].save_path ).toBe( 'classic' );
		expect( entries[ 0 ].rule_ids ).toEqual( [ 'price' ] );
		expect( entries[ 0 ].user_id ).toBeGreaterThan( 0 );
	} );
} );
