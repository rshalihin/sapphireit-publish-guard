/**
 * SIT-WCPG-E2E-UI-02: the Dashboard (UI decisions U12, U14). Its numbers come from
 * the stored readiness, every stat links to the products behind it, and a top
 * issue narrows the products list to that check.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	PRODUCTS_LIST,
	SCREENS,
	fixtures,
	flushReadinessStats,
	listCount,
	runRebuildQueue,
	wp,
} from './utils';

/**
 * A stat card's value link, by its label.
 *
 * @param {Object} page  Playwright page, on the Dashboard.
 * @param {string} label Card label.
 * @return {Object} Locator.
 */
function stat( page, label ) {
	return page
		.locator( '.sit-wcpg-ui-stat' )
		.filter( {
			has: page.locator( '.sit-wcpg-ui-stat__label', {
				hasText: new RegExp( `^${ label }$` ),
			} ),
		} )
		.locator( '.sit-wcpg-ui-stat__value' );
}

/**
 * A link's number, without its screen-reader text.
 *
 * @param {Object} link Locator.
 * @return {Promise<number>} The number.
 */
async function numberOf( link ) {
	const text = await link.evaluate( ( node ) => node.firstChild.textContent );

	return Number( text.replace( /[^0-9]/g, '' ) );
}

/**
 * Products with a readiness flag, counted by WP-CLI (any status the list shows).
 *
 * @param {string[]} values `_sit_wcpg_readiness` values.
 * @return {number} Count.
 */
function seeded( values ) {
	return values
		.map( ( value ) =>
			Number(
				wp( [
					'post',
					'list',
					'--post_type=product',
					'--post_status=publish,draft,pending,private,future',
					'--meta_key=_sit_wcpg_readiness',
					`--meta_value=${ value }`,
					'--format=count',
				] )
			)
		)
		.reduce( ( sum, count ) => sum + count, 0 );
}

test.describe( 'UI-02: Dashboard', () => {
	test.beforeEach( () => {
		// Every flag current, and no 15-minute-old numbers.
		runRebuildQueue();
		flushReadinessStats();
	} );

	test( 'the numbers match the seeded catalog', async ( { page } ) => {
		await page.goto( SCREENS.dashboard );

		await expect(
			page.getByRole( 'heading', {
				level: 1,
				name: 'Publish Guard dashboard',
			} )
		).toBeAttached();
		await expect(
			page.locator( '.sit-wcpg-dashboard__recheck' )
		).toContainText( 'Rechecked automatically when settings change' );

		expect( await numberOf( stat( page, 'Ready to publish' ) ) ).toBe(
			seeded( [ 'pass', 'skipped' ] )
		);
		expect( await numberOf( stat( page, 'Not ready' ) ) ).toBe(
			seeded( [ 'fail' ] )
		);
		expect( await numberOf( stat( page, 'Warnings' ) ) ).toBe(
			seeded( [ 'warning' ] )
		);

		// The suite seeds unpriced drafts, so something is not ready.
		expect( await numberOf( stat( page, 'Not ready' ) ) ).toBeGreaterThan(
			0
		);
	} );

	for ( const [ label, filter ] of [
		[ 'Ready to publish', 'ready' ],
		[ 'Not ready', 'fail' ],
		[ 'Warnings', 'warning' ],
	] ) {
		test( `"${ label }" lands on the filtered list with the same count`, async ( {
			page,
		} ) => {
			await page.goto( SCREENS.dashboard );

			const link = stat( page, label );
			const value = await numberOf( link );

			await Promise.all( [
				page.waitForURL(
					new RegExp( `[?&]sit_wcpg_readiness=${ filter }(&|$)` )
				),
				link.click(),
			] );

			await expect(
				page.locator( '#sit-wcpg-readiness-filter' )
			).toHaveValue( filter );
			expect( await listCount( page ) ).toBe( value );
		} );
	}

	test( '"Live, would fail today" lands on Live products', async ( {
		page,
	} ) => {
		await page.goto( SCREENS.dashboard );

		const link = stat( page, 'Live, would fail today' );
		const value = await numberOf( link );

		await Promise.all( [
			page.waitForURL( /page=sit-wcpg-catalog/ ),
			link.click(),
		] );

		if ( 0 === value ) {
			await expect( page.locator( '.sit-wcpg-ui-empty' ) ).toBeVisible();
		} else {
			expect( await listCount( page ) ).toBe( value );
		}
	} );

	test( 'a top issue narrows the products list to that check', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await page.goto( SCREENS.dashboard );

		const issue = page.locator( '.sit-wcpg-dashboard__issue' ).first();

		await expect( issue ).toBeVisible();

		const label = await issue
			.locator( '.sit-wcpg-dashboard__issue-label' )
			.innerText();
		const link = issue.locator( '.sit-wcpg-dashboard__issue-count' );
		const value = await numberOf( link );

		await Promise.all( [
			page.waitForURL( /[?&]sit_wcpg_rule=[a-z0-9_-]+/ ),
			link.click(),
		] );

		await expect( page.locator( '.sit-wcpg-rule-chip' ) ).toContainText(
			`Failing: ${ label }`
		);
		expect( await listCount( page ) ).toBe( value );

		// The seeded unpriced draft is behind "Regular price".
		if ( 'Regular price' === label ) {
			await expect(
				page.locator( `#post-${ products.uiEditor }` )
			).toBeAttached();
		}

		// Removing the chip keeps "Not ready".
		await Promise.all( [
			page.waitForURL(
				( url ) => ! url.search.includes( 'sit_wcpg_rule' )
			),
			page.locator( '.sit-wcpg-rule-chip a' ).click(),
		] );
		await expect(
			page.locator( '#sit-wcpg-readiness-filter' )
		).toHaveValue( 'fail' );
		expect( page.url() ).toContain( PRODUCTS_LIST.split( '?' )[ 0 ] );
	} );
} );
