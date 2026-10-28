/**
 * Captures the WordPress.org screenshots that show the plugin's own screens:
 *
 * 1. the checklist panel in the product editor;
 * 3. the settings screen;
 * 4. the notice after a Bulk Edit refused a product;
 * 5. publishing refused, with the reason and where to fix it.
 *
 * Screenshot 2, the products list with its readiness column, needs a catalog
 * that looks like a real store, so it is still taken by hand.
 *
 * Skipped unless SIT_WCPG_CAPTURE=1, so the suite never runs it: it is a
 * generator, not a test, and asserts only enough to be sure it photographs the
 * right screen. Run it with `npm run screenshots` (bin/capture-screenshots.js).
 */

/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	checklist,
	fixtures,
	guardNotice,
	openProduct,
	publish,
} from './utils';

const OUT_DIR = path.resolve( __dirname, '../../.wordpress-org' );

/**
 * Where a screenshot is written.
 *
 * @param {number} number Screenshot number, as in readme.txt.
 * @return {string} Absolute path.
 */
function out( number ) {
	return path.join( OUT_DIR, `screenshot-${ number }.png` );
}

test.describe( 'WordPress.org screenshots', () => {
	test.skip(
		'1' !== process.env.SIT_WCPG_CAPTURE,
		'Set SIT_WCPG_CAPTURE=1 (npm run screenshots) to capture.'
	);

	test.use( { viewport: { width: 1440, height: 900 } } );

	test.describe( 'the full checklist panel', () => {
		// Tall enough for every group of the panel to fit beside the editor.
		test.use( { viewport: { width: 1440, height: 1100 } } );

		test( 'screenshot-1: the checklist panel in the product editor', async ( {
			page,
		} ) => {
			const { products } = fixtures();

			await openProduct( page, products.shotEditor );
			await expect( checklist( page ) ).toContainText(
				'Not ready to publish'
			);

			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			await page.screenshot( { path: out( 1 ) } );
		} );
	} );

	test( 'screenshot-3: the settings screen', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=sit-wcpg-settings' );
		await expect(
			page.getByRole( 'heading', { name: 'Product Checklist' } )
		).toBeVisible();

		await page.screenshot( { path: out( 3 ) } );
	} );

	test( 'screenshot-4: the notice after a Bulk Edit refused a product', async ( {
		page,
	} ) => {
		const { products } = fixtures();
		const ids = [
			products.shotBulkPass1,
			products.shotBulkPass2,
			products.shotBulkFail,
		];

		await page.goto( '/wp-admin/edit.php?post_type=product&s=Waxed' );

		for ( const id of ids ) {
			await page.locator( `#cb-select-${ id }` ).check();
		}

		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'edit' );
		await page.locator( '#doaction' ).click();

		const bulk = page.locator( '#bulk-edit' );

		await expect( bulk ).toBeVisible();
		await bulk
			.locator( 'select[name="_status"]' )
			.selectOption( 'publish' );
		await Promise.all( [
			page.waitForEvent( 'load' ),
			page.locator( '#bulk_edit' ).click(),
		] );

		await expect( guardNotice( page, 'blocked' ) ).toBeVisible();

		/*
		 * The fixture SKUs carry a run token and wrap the SKU column onto four
		 * lines. Hide it, as a merchant could under Screen Options, without
		 * changing the E2E admin's saved column preferences.
		 */
		await page.addStyleTag( { content: '.column-sku { display: none; }' } );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.screenshot( { path: out( 4 ) } );
	} );

	test( 'screenshot-5: a refused publish with its notice', async ( {
		page,
	} ) => {
		const { products } = fixtures();

		await openProduct( page, products.screenshot );
		await publish( page );
		await expect( guardNotice( page, 'blocked' ) ).toBeVisible();

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.screenshot( { path: out( 5 ) } );
	} );
} );
