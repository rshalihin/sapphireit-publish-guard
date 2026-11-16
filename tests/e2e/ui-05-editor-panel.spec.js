/**
 * SIT-WCPG-E2E-UI-05: the product editor's readiness panel (UI phase U09).
 * Failing checks come first, the passed ones are folded away, and a fix link
 * focuses the field that fixes it.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { checklist, checklistRow, fixtures, openProduct } from './utils';

test( 'UI-05: failing first, passed collapsed, the fix link focuses the field', async ( {
	page,
} ) => {
	const { products } = fixtures();

	await openProduct( page, products.uiEditor );

	const panel = checklist( page );
	const failing = panel.locator( '.sit-wcpg-checklist__failing' );
	const passed = panel.locator( 'details.sit-wcpg-checklist__passed' );

	await expect(
		panel.locator(
			'.sit-wcpg-checklist__summary--fail .sit-wcpg-checklist__state'
		)
	).toHaveText( 'Not ready to publish' );

	// The only failure is in the failing view, which comes before the passed rows.
	await expect( failing.locator( '.sit-wcpg-checklist__item' ) ).toHaveCount(
		1
	);
	await expect( failing ).toContainText( 'Regular price' );

	// querySelectorAll() returns matches in document order.
	const order = await panel.evaluate( ( node ) =>
		Array.from(
			node.querySelectorAll(
				'.sit-wcpg-checklist__failing, .sit-wcpg-checklist__passed'
			),
			( section ) => section.className
		)
	);

	expect( order ).toEqual( [
		'sit-wcpg-checklist__failing',
		'sit-wcpg-checklist__passed',
	] );

	// Passed rows are collapsed: present, not shown.
	await expect( passed ).not.toHaveAttribute( 'open', /.*/ );
	await expect( passed.locator( 'summary' ) ).toContainText(
		/\d+ more passed/
	);

	const passedRow = checklistRow( page, 'Product title' );

	await expect( passedRow ).toBeAttached();
	await expect( passedRow ).toBeHidden();

	await passed.locator( 'summary' ).click();
	await expect( passedRow ).toBeVisible();
	await passed.locator( 'summary' ).click();
	await expect( passedRow ).toBeHidden();

	// The fix link moves focus to the field.
	await checklistRow( page, 'Regular price' )
		.locator( '.sit-wcpg-checklist__fix' )
		.click();

	await expect( page.locator( '#_regular_price' ) ).toBeFocused();
} );
