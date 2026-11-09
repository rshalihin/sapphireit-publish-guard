/**
 * SIT-WCPG-E2E-15: once due, the review notice shows on the products list, and
 * "Never ask again" ends it for good.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { wp } from './utils';

const OPTION = 'sit_wcpg_review_notice';
const DAY = 86400;

test.describe( 'E2E-15: review notice', () => {
	test.afterEach( () => {
		wp( [ 'option', 'delete', OPTION ] );
	} );

	test( 'shows when due and stays gone after "Never ask again"', async ( {
		page,
	} ) => {
		const now = Math.floor( Date.now() / 1000 );

		wp( [
			'option',
			'update',
			OPTION,
			JSON.stringify( {
				first_seen: now - 31 * DAY,
				blocked_at: now - DAY,
				ask_after: 0,
				done: false,
			} ),
			'--format=json',
		] );

		await page.goto( '/wp-admin/edit.php?post_type=product' );

		const notice = page.locator( '.sit-wcpg-review-notice' );

		await expect( notice ).toContainText(
			'Publish Guard has been stopping incomplete products from going live on this store. If it has helped, a short review on WordPress.org helps other store owners find it.'
		);
		await expect(
			notice.getByRole( 'link', { name: 'Leave a review' } )
		).toBeVisible();
		await expect(
			notice.getByRole( 'button', { name: 'Maybe later' } )
		).toBeVisible();

		const recorded = page.waitForResponse(
			( response ) =>
				response.url().includes( 'review-notice' ) &&
				'POST' === response.request().method()
		);

		await notice.getByRole( 'button', { name: 'Never ask again' } ).click();
		expect( ( await recorded ).ok() ).toBe( true );

		await expect( notice ).toHaveCount( 0 );

		await page.reload();

		await expect( page.locator( '#the-list' ) ).toBeAttached();
		await expect( notice ).toHaveCount( 0 );

		const state = JSON.parse(
			wp( [ 'option', 'get', OPTION, '--format=json' ] )
		);

		expect( state.done ).toBe( true );
	} );
} );
