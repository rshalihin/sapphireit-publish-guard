/**
 * SIT-WCPG-E2E-11: the readiness filter searches the whole catalog, so it finds
 * a failing product the first page of the list does not show.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { fixtures, readinessFilter, runRebuildQueue } from './utils';

test( 'E2E-11: "Not ready" finds a product on page 2 and "Ready" leaves it out', async ( {
	page,
} ) => {
	const { products } = fixtures();
	const failing = page.locator( `#post-${ products.e2e11Fail }` );
	const total = page.locator( '.tablenav.top .displaying-num' );

	// Other specs change settings, which queues a rebuild.
	runRebuildQueue();

	// The search narrows the list to this spec's products; the filter keeps it.
	await page.goto( '/wp-admin/edit.php?post_type=product&s=E2E-11' );

	await expect( total ).toHaveText( '26 items' );
	await expect( failing ).toHaveCount( 0 );
	await expect(
		page.locator( '#sit-wcpg-readiness-filter option' )
	).toHaveText( [
		'All readiness states',
		'Ready',
		'Warnings',
		'Not ready',
		'Not checked yet',
	] );

	await readinessFilter( page, 'fail' );

	await expect( total ).toHaveText( '1 item' );
	await expect( page.locator( '#the-list > tr' ) ).toHaveCount( 1 );
	await expect( failing ).toBeVisible();

	await readinessFilter( page, 'ready' );

	await expect( total ).toHaveText( '25 items' );
	await expect( failing ).toHaveCount( 0 );
} );
