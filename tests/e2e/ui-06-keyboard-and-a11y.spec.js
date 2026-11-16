/**
 * SIT-WCPG-E2E-UI-06: accessibility of the premium admin UI (UI plan U10).
 *
 * axe-core scans Publish Guard's own markup on every screen it draws: the shell
 * and page on the Dashboard, Checklist and Live products, the Readiness pills and
 * rule chip on the products list, and the readiness panel in the product editor.
 * WordPress's and WooCommerce's own markup around it is not scanned: this plugin
 * cannot fix it. No serious or critical violation is allowed.
 *
 * Then the Checklist screen is used with the keyboard alone: pick a section,
 * change a setting, save.
 */

/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	BASELINE,
	PRODUCTS_LIST,
	SCREENS,
	fieldName,
	fixtures,
	openProduct,
	readSettingsOption,
	runRebuildQueue,
	saveSettings,
} from './utils';

/**
 * Runs axe on the given regions and fails on any serious or critical violation.
 *
 * @param {Object}   page    Playwright page.
 * @param {string[]} regions CSS selectors to scan.
 * @return {Promise<void>}
 */
async function expectNoSeriousViolations( page, regions ) {
	let builder = new AxeBuilder( { page } ).withTags( [
		'wcag2a',
		'wcag2aa',
		'wcag21a',
		'wcag21aa',
	] );

	for ( const region of regions ) {
		builder = builder.include( region );
	}

	const { violations } = await builder.analyze();
	const serious = violations
		.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) )
		.map(
			( v ) =>
				`${ v.id } (${ v.impact }): ${ v.help } — ${ v.nodes
					.map( ( n ) => n.target.join( ' ' ) )
					.join( ', ' ) }`
		);

	expect( serious, `axe on ${ page.url() }` ).toEqual( [] );
}

/**
 * Presses Tab until the focused element matches, or fails after `max` presses.
 *
 * @param {Object} page     Playwright page.
 * @param {string} selector CSS selector the focused element must match.
 * @param {number} [max]    Most presses.
 * @return {Promise<void>}
 */
async function tabTo( page, selector, max = 80 ) {
	for ( let i = 0; i < max; i++ ) {
		await page.keyboard.press( 'Tab' );

		const focused = page.locator( ':focus' );

		if (
			( await focused.count() ) > 0 &&
			( await focused
				.first()
				.evaluate( ( node, css ) => node.matches( css ), selector ) )
		) {
			return;
		}
	}

	throw new Error( `Tab never reached ${ selector }` );
}

/**
 * The focused element's outline, to prove the focus ring is drawn.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<string>} `outline-style`.
 */
function focusOutline( page ) {
	return page
		.locator( ':focus' )
		.evaluate(
			( node ) =>
				node.ownerDocument.defaultView.getComputedStyle( node )
					.outlineStyle
		);
}

const SHELL = [ '.sit-wcpg-ui-header', '.wrap.sit-wcpg-ui' ];

test.describe( 'UI-06: accessibility', () => {
	test.beforeAll( () => {
		runRebuildQueue();
	} );

	test( 'axe: Dashboard', async ( { page } ) => {
		await page.goto( SCREENS.dashboard );
		await expectNoSeriousViolations( page, SHELL );
	} );

	test( 'axe: Checklist', async ( { page } ) => {
		await page.goto( SCREENS.checklist );
		await expectNoSeriousViolations( page, SHELL );
	} );

	test( 'axe: Checklist without its script (every section shown)', async ( {
		page,
	} ) => {
		/*
		 * axe runs as a script, so the page cannot have JavaScript off. Blocking the
		 * settings script gives the same markup: every section shown, no save state
		 * (UI decision U8). UI-03 covers the real JavaScript-off save.
		 */
		await page.route( '**/build/settings.js*', ( route ) => route.abort() );
		await page.goto( SCREENS.checklist );

		const sections = page.locator( '[data-sit-wcpg-section]' );

		await expect( sections.filter( { visible: true } ) ).toHaveCount(
			await sections.count()
		);
		await expectNoSeriousViolations( page, SHELL );
	} );

	test.describe( 'with a live failure listed', () => {
		test.afterEach( async ( { page } ) => {
			await saveSettings( page, BASELINE );
			runRebuildQueue();
		} );

		test( 'axe: Live products', async ( { page } ) => {
			await saveSettings( page, {
				radios: { 'rules][sku][severity': 'required' },
			} );
			runRebuildQueue();

			await page.goto( SCREENS.liveProducts );
			await expect(
				page.locator( '#the-list > tr' ).first()
			).toBeVisible();
			await expectNoSeriousViolations( page, SHELL );
		} );
	} );

	test( 'axe: products list pills and rule chip', async ( { page } ) => {
		await page.goto(
			`${ PRODUCTS_LIST }&sit_wcpg_readiness=fail&sit_wcpg_rule=price`
		);
		await expect( page.locator( '.sit-wcpg-rule-chip' ) ).toBeVisible();
		await expectNoSeriousViolations( page, [
			'.sit-wcpg-rule-chip',
			'#the-list .column-sit_wcpg_readiness',
		] );
	} );

	test( 'axe: product editor readiness panel', async ( { page } ) => {
		const { products } = fixtures();

		await openProduct( page, products.uiEditor );
		await expectNoSeriousViolations( page, [
			'#sit_wcpg_product_checklist',
		] );
	} );

	test.describe( 'keyboard only', () => {
		test.afterEach( async ( { page } ) => {
			await saveSettings( page, BASELINE );
			runRebuildQueue();
		} );

		test( 'pick a section, change a setting and save, without a mouse', async ( {
			page,
		} ) => {
			const scope = fieldName( 'publishing][enforce_scope' );
			const publishingLink =
				'[data-sit-wcpg-section-nav] a[href="#sit-wcpg-section-publishing"]';

			await page.goto( SCREENS.checklist );
			expect( readSettingsOption().publishing.enforce_scope ).toBe(
				'authenticated'
			);

			// WordPress's skip link jumps past the admin bar and menu to the page,
			// where the header tabs come first, then the section nav.
			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator(
					'a.screen-reader-shortcut[href="#wpbody-content"]'
				)
			).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await tabTo( page, '.sit-wcpg-ui-header__tabs a' );
			expect( await focusOutline( page ) ).not.toBe( 'none' );

			await tabTo( page, publishingLink );
			expect( await focusOutline( page ) ).not.toBe( 'none' );
			await page.keyboard.press( 'Enter' );

			await expect(
				page.locator( '#sit-wcpg-section-publishing' )
			).toBeVisible();

			// The section nav moves focus into the opened section.
			await expect(
				page.locator( '#sit-wcpg-section-publishing :focus' )
			).toHaveCount( 1 );

			// Into the scope radios; an arrow key picks the next one.
			await tabTo( page, `input[name="${ scope }"]` );
			expect( await focusOutline( page ) ).not.toBe( 'none' );
			await page.keyboard.press( 'ArrowRight' );

			await expect(
				page.locator( `input[name="${ scope }"][value="editor"]` )
			).toBeChecked();
			await expect(
				page.locator( '[data-sit-wcpg-savebar]' ).first()
			).toHaveAttribute( 'data-state', 'dirty' );

			// On to the bottom bar's Save.
			await tabTo( page, '#submit' );
			await Promise.all( [
				page.waitForURL( /settings-updated=true/ ),
				page.keyboard.press( 'Enter' ),
			] );

			expect( readSettingsOption().publishing.enforce_scope ).toBe(
				'editor'
			);
		} );
	} );
} );
