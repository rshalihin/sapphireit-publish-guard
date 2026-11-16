/**
 * Captures the WordPress.org screenshots that show the plugin's own screens:
 *
 * 1. the checklist panel in the product editor;
 * 2. the products list with its Readiness column;
 * 3. the settings screen;
 * 4. the notice after a Bulk Edit refused a product;
 * 5. publishing refused, with the reason and where to fix it;
 * 6. the live products that would fail the current rules;
 * 7. the pre-flight statement on a product that can't be published yet;
 * 8. the Dashboard (UI phase U10).
 *
 * Screenshot 2 lists only the screenshot fixtures (their SKUs share `shot`), so
 * the list looks like a small store rather than the whole E2E catalog.
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
	BASELINE,
	SCREENS,
	checklist,
	fixtures,
	flushReadinessStats,
	guardNotice,
	openProduct,
	preflight,
	publish,
	runRebuildQueue,
	saveSettings,
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

		test( 'screenshot-7: the pre-flight statement on a blocked product', async ( {
			page,
		} ) => {
			const { products } = fixtures();

			await openProduct( page, products.shotPreflight );
			await expect( preflight( page ) ).toHaveClass(
				/sit-wcpg-preflight--blocked/
			);

			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			await page.screenshot( { path: out( 7 ) } );
		} );
	} );

	test.describe( 'the catalog screen', () => {
		// Back to the baseline, with flags to match, even when the capture failed.
		test.afterEach( async ( { page } ) => {
			await saveSettings( page, BASELINE );
			runRebuildQueue();
		} );

		test( 'screenshot-6: live products that would fail your rules', async ( {
			page,
		} ) => {
			await saveSettings( page, {
				radios: { 'rules][sku][severity': 'required' },
			} );
			runRebuildQueue();

			await page.goto(
				'/wp-admin/edit.php?post_type=product&page=sit-wcpg-catalog'
			);
			await expect( page.locator( '#the-list > tr' ) ).toHaveCount( 3 );

			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			await page.screenshot( { path: out( 6 ) } );
		} );
	} );

	test( 'screenshot-2: the Readiness column in the products list', async ( {
		page,
	} ) => {
		runRebuildQueue();

		await page.goto( '/wp-admin/edit.php?post_type=product&s=shot' );
		await expect(
			page.locator( '#the-list .sit-wcpg-readiness--fail' ).first()
		).toBeVisible();

		/*
		 * The fixture SKUs carry a run token, and the search line names the
		 * fixtures' shared word. Hide both and empty the search box, as a store would show it.
		 */
		await page.addStyleTag( {
			content: '.column-sku, .wrap .subtitle { display: none; }',
		} );
		await page.locator( '#post-search-input' ).fill( '' );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.screenshot( { path: out( 2 ) } );
	} );

	test( 'screenshot-3: the settings screen', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=sit-wcpg-settings' );
		await expect(
			page.getByRole( 'heading', { level: 1, name: 'Product checklist' } )
		).toBeVisible();

		await page.screenshot( { path: out( 3 ) } );
	} );

	test( 'screenshot-8: the Dashboard', async ( { page } ) => {
		// Current flags and fresh numbers, not the 15-minute cache.
		runRebuildQueue();
		flushReadinessStats();

		await page.goto( SCREENS.dashboard );
		await expect(
			page.locator( '.sit-wcpg-dashboard__headline' )
		).toBeVisible();

		await page.screenshot( { path: out( 8 ) } );
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
