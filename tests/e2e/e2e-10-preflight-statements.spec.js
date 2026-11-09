/**
 * SIT-WCPG-E2E-10: the pre-flight statement says what publishing will do, and
 * changes as the product is edited, without a save.
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
	preflight,
	publish,
	saveSettings,
} from './utils';

test.describe( 'E2E-10: pre-flight statements', () => {
	// Back to the baseline even when a test failed half-way.
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
	} );

	test( 'turns from blocked to ready as the price is typed', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await openProduct( page, products.e2e10 );

		await expect( preflight( page ) ).toHaveText(
			"This product can't be published yet — 1 required check fails. Publishing now saves it as a draft."
		);
		await expect( preflight( page ) ).toHaveClass(
			/sit-wcpg-preflight--blocked/
		);

		// A reload would drop this marker.
		await page.evaluate( () => {
			window.sitWcpgE2eNoReload = true;
		} );

		await page.locator( '#_regular_price' ).fill( '119.00' );

		await expect( preflight( page ) ).toHaveText(
			'This product can be published now.'
		);
		await expect( preflight( page ) ).toHaveClass(
			/sit-wcpg-preflight--ready/
		);
		expect( await page.evaluate( () => window.sitWcpgE2eNoReload ) ).toBe(
			true
		);
	} );

	test( 'tells an administrator who may override that it will be recorded', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await saveSettings( page, {
			checkboxes: { 'publishing][allow_admin_override': true },
		} );
		await openProduct( page, products.e2e10 );

		await expect( preflight( page ) ).toHaveText(
			'1 required check fails. You can publish anyway, and the override will be recorded.'
		);
		await expect( preflight( page ) ).toHaveClass(
			/sit-wcpg-preflight--override/
		);
	} );

	test( 'says a published product is live', async ( { page } ) => {
		const { products } = fixtures();

		await openProduct( page, products.e2e10Live );
		await publish( page );

		await expect( preflight( page ) ).toHaveText(
			'This product is live and passes every required check.'
		);
		await expect( preflight( page ) ).toHaveClass(
			/sit-wcpg-preflight--live/
		);
	} );
} );
