/**
 * SIT-WCPG-E2E-UI-04: Live products (UI phase U08). A live product that fails a
 * required check is listed with its failing checks as pills; with nothing
 * failing, the screen shows its empty state instead of a table.
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
	SCREENS,
	fixtures,
	readSettingsOption,
	runRebuildQueue,
	saveSettings,
	writeSettingsOption,
} from './utils';

test.describe( 'UI-04: Live products', () => {
	test.afterEach( async ( { page } ) => {
		await saveSettings( page, BASELINE );
		runRebuildQueue();
	} );

	test( 'a live product that now fails is listed with its pills', async ( {
		page,
		requestUtils,
	} ) => {
		const { products } = fixtures();

		// SKU is only a warning here, so it publishes.
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wc/v3/products/${ products.uiLive }`,
			data: { status: 'publish' },
		} );

		await saveSettings( page, {
			radios: { 'rules][sku][severity': 'required' },
		} );
		runRebuildQueue();

		await page.goto( SCREENS.liveProducts );

		await expect(
			page.locator( '.sit-wcpg-ui-pagebar__title' )
		).toHaveText( 'Live products that would fail today' );

		const row = page.locator( '#the-list > tr', {
			hasText: 'UI-04 Stoneware jug',
		} );
		const pills = row.locator(
			'.sit-wcpg-catalog__checks .sit-wcpg-ui-pill--required'
		);

		await expect( row ).toHaveCount( 1 );
		await expect( pills ).toHaveText( [ 'SKU' ] );
		await expect(
			row.getByRole( 'link', { name: /Edit product/ } )
		).toHaveAttribute(
			'href',
			new RegExp( `post\\.php\\?post=${ products.uiLive }&action=edit` )
		);
	} );

	test( 'with nothing failing, the empty state replaces the table', async ( {
		page,
	} ) => {
		const stored = readSettingsOption();
		const relaxed = structuredClone( stored );

		// Every check advisory: no live product can fail a required check.
		for ( const rule of Object.values( relaxed.rules ) ) {
			rule.severity = 'warning';
		}

		writeSettingsOption( relaxed );

		try {
			runRebuildQueue();
			await page.goto( SCREENS.liveProducts );

			await expect(
				page.locator( '.sit-wcpg-ui-empty__title' )
			).toHaveText( 'Every live product passes your required checks' );
			await expect( page.locator( '#the-list' ) ).toHaveCount( 0 );
			await expect(
				page.getByRole( 'link', { name: /Review your checklist/ } )
			).toBeVisible();
		} finally {
			writeSettingsOption( stored );
		}
	} );
} );
