/**
 * SIT-WCPG-E2E-4: Quick Edit refuses to publish a failing product.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { fixtures, guardNotice, productStatus } from './utils';

test( 'E2E-4: Quick Edit to Published leaves a failing product a draft', async ( {
	page,
	requestUtils,
} ) => {
	const { products } = fixtures();
	const id = products.e2e4;
	const list = '/wp-admin/edit.php?post_type=product&s=E2E-4';

	await page.goto( list );

	const row = page.locator( `#post-${ id }` );

	await row.hover();
	await row.locator( 'button.editinline' ).click();

	const editor = page.locator( `#edit-${ id }` );

	await editor.locator( 'select[name="_status"]' ).selectOption( 'publish' );
	await Promise.all( [
		page.waitForResponse(
			( response ) =>
				response.url().includes( 'admin-ajax.php' ) &&
				!! response
					.request()
					.postData()
					?.includes( 'action=inline-save' )
		),
		editor.locator( 'button.save' ).click(),
	] );
	await expect( editor ).toBeHidden();

	expect( await productStatus( requestUtils, id ) ).toBe( 'draft' );

	// The refusal is reported on the next screen load.
	await page.goto( list );

	const notice = guardNotice( page, 'blocked' );

	await expect( notice ).toContainText(
		'1 product was not published because required checks failed:'
	);
	await expect( notice ).toContainText( `Product #${ id }: Regular price` );
} );
