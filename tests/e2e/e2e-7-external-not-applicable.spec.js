/**
 * SIT-WCPG-E2E-7: an external product shows stock status under "Not applicable".
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { checklist, checklistRow, fixtures, openProduct } from './utils';

test( 'E2E-7: stock status does not apply to an external product', async ( {
	page,
} ) => {
	const { products } = fixtures();

	await openProduct( page, products.e2e7 );

	const skipped = checklist( page ).locator( '.sit-wcpg-checklist__skipped' );

	await expect( skipped.locator( 'summary' ) ).toContainText(
		'Not applicable'
	);
	await skipped.locator( 'summary' ).click();

	const row = skipped
		.locator( '.sit-wcpg-checklist__item' )
		.filter( { hasText: 'Stock status' } );

	await expect( row ).toBeVisible();
	await expect( row ).toHaveClass( /sit-wcpg-checklist__item--skip/ );
	await expect( checklistRow( page, 'Stock status' ) ).toHaveCount( 1 );

	// Nothing else on a complete external product blocks or warns.
	await expect(
		checklist( page ).locator(
			'.sit-wcpg-checklist__item--fail, .sit-wcpg-checklist__item--warning'
		)
	).toHaveCount( 0 );
} );
