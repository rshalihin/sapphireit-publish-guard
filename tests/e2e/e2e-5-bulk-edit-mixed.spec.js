/**
 * SIT-WCPG-E2E-5: a mixed Bulk Edit publishes the passing products, refuses the
 * failing one, and reports it in the list notice.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { fixtures, guardNotice, productStatus } from './utils';

test( 'E2E-5: Bulk Edit to Published refuses only the failing product', async ( {
	page,
	requestUtils,
} ) => {
	const { products } = fixtures();
	const ids = [ products.e2e5Pass1, products.e2e5Pass2, products.e2e5Fail ];

	await page.goto( '/wp-admin/edit.php?post_type=product&s=E2E-5' );

	for ( const id of ids ) {
		await page.locator( `#cb-select-${ id }` ).check();
	}

	await page.locator( '#bulk-action-selector-top' ).selectOption( 'edit' );
	await page.locator( '#doaction' ).click();

	const bulk = page.locator( '#bulk-edit' );

	await expect( bulk ).toBeVisible();
	await bulk.locator( 'select[name="_status"]' ).selectOption( 'publish' );
	await Promise.all( [
		page.waitForEvent( 'load' ),
		page.locator( '#bulk_edit' ).click(),
	] );

	const notice = guardNotice( page, 'blocked' );

	await expect( notice ).toContainText(
		'1 product was not published because required checks failed:'
	);
	await expect( notice ).toContainText(
		`Product #${ products.e2e5Fail }: Regular price`
	);
	await expect( notice.locator( 'li' ) ).toHaveCount( 1 );

	expect( await productStatus( requestUtils, products.e2e5Pass1 ) ).toBe(
		'publish'
	);
	expect( await productStatus( requestUtils, products.e2e5Pass2 ) ).toBe(
		'publish'
	);
	expect( await productStatus( requestUtils, products.e2e5Fail ) ).toBe(
		'draft'
	);
} );
