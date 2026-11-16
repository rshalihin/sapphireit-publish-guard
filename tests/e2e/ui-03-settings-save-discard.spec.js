/**
 * SIT-WCPG-E2E-UI-03: the Checklist settings screen's save actions and section
 * nav (UI decisions U8, U15), with and without JavaScript.
 */

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
	fieldName,
	pickRadio,
	readSettingsOption,
	runRebuildQueue,
	saveSettings,
} from './utils';

const SKU = 'rules][sku][severity';
const PUBLISHING = 'sit-wcpg-section-publishing';
const INVENTORY = 'sit-wcpg-section-inventory';

/**
 * Both save bars.
 *
 * @param {Object} page Playwright page, on the settings screen.
 * @return {Object} Locator.
 */
function bars( page ) {
	return page.locator( '[data-sit-wcpg-savebar]' );
}

/**
 * Asserts both bars show one state, with Save and Discard enabled only when dirty.
 *
 * @param {Object} page  Playwright page.
 * @param {string} state `clean` or `dirty`.
 * @return {Promise<void>}
 */
async function expectState( page, state ) {
	await expect( bars( page ) ).toHaveCount( 2 );

	for ( const bar of await bars( page ).all() ) {
		await expect( bar ).toHaveAttribute( 'data-state', state );

		const enabled = 'dirty' === state;

		for ( const button of [
			bar.locator( '[data-sit-wcpg-discard]' ),
			bar.locator( 'input[type="submit"]' ),
		] ) {
			await ( enabled
				? expect( button ).toBeEnabled()
				: expect( button ).toBeDisabled() );
		}
	}
}

/**
 * The SKU severity radio for a value.
 *
 * @param {Object} page  Playwright page.
 * @param {string} value Severity.
 * @return {Object} Locator.
 */
function skuRadio( page, value ) {
	return page.locator(
		`input[type="radio"][name="${ fieldName( SKU ) }"][value="${ value }"]`
	);
}

test.describe( 'UI-03: Checklist settings', () => {
	test.afterEach( async ( { page } ) => {
		page.removeAllListeners( 'dialog' );
		await saveSettings( page, BASELINE );
		runRebuildQueue();
	} );

	test( 'change marks both bars dirty, and Discard puts the form back', async ( {
		page,
	} ) => {
		await page.goto( SCREENS.checklist );
		await expectState( page, 'clean' );
		await expect( bars( page ).first() ).toContainText(
			'All changes saved'
		);

		await pickRadio( page, SKU, 'required' );

		await expectState( page, 'dirty' );
		await expect( bars( page ).first() ).toContainText( 'Unsaved changes' );

		await bars( page ).last().locator( '[data-sit-wcpg-discard]' ).click();

		await expectState( page, 'clean' );
		await expect( skuRadio( page, 'warning' ) ).toBeChecked();
		await expect( bars( page ).first() ).toContainText(
			'Changes discarded'
		);
	} );

	test( 'Save at the top saves, and a reload shows the saved value', async ( {
		page,
	} ) => {
		await page.goto( SCREENS.checklist );
		await pickRadio( page, SKU, 'required' );

		await Promise.all( [
			page.waitForURL( /settings-updated=true/ ),
			page.locator( '#sit-wcpg-save-top' ).click( { noWaitAfter: true } ),
		] );

		// The section that was open is open again (the referer carries it).
		await expect( page.locator( `#${ INVENTORY }` ) ).toBeVisible();

		await page.reload();
		await expect( skuRadio( page, 'required' ) ).toBeChecked();
		await expectState( page, 'clean' );
		expect( readSettingsOption().rules.sku.severity ).toBe( 'required' );
	} );

	test( 'leaving with unsaved changes asks first', async ( { page } ) => {
		await page.goto( SCREENS.checklist );
		await pickRadio( page, SKU, 'required' );

		let asked = null;

		page.once( 'dialog', async ( dialog ) => {
			asked = dialog.type();
			await dialog.dismiss();
		} );

		// A tab link is a real navigation, so the browser runs beforeunload.
		await page
			.locator( '.sit-wcpg-ui-header__tabs a', { hasText: 'Dashboard' } )
			.click( { noWaitAfter: true } );

		await expect.poll( () => asked ).toBe( 'beforeunload' );
		await expect( page ).toHaveURL( /page=sit-wcpg-settings/ );
		await expect( skuRadio( page, 'required' ) ).toBeChecked();

		// After Discard, leaving does not ask.
		await bars( page ).first().locator( '[data-sit-wcpg-discard]' ).click();

		asked = null;
		await Promise.all( [
			page.waitForURL( /page=sit-wcpg-dashboard/ ),
			page
				.locator( '.sit-wcpg-ui-header__tabs a', {
					hasText: 'Dashboard',
				} )
				.click(),
		] );
		expect( asked ).toBeNull();
	} );

	test( 'the section in the URL hash survives a reload', async ( {
		page,
	} ) => {
		await page.goto( `${ SCREENS.checklist }#${ PUBLISHING }` );

		const sections = page.locator( '[data-sit-wcpg-section]' );

		await expect( page.locator( `#${ PUBLISHING }` ) ).toBeVisible();
		await expect( sections.filter( { visible: true } ) ).toHaveCount( 1 );
		await expect(
			page.locator(
				`[data-sit-wcpg-section-nav] a[href="#${ PUBLISHING }"]`
			)
		).toHaveClass( /sit-wcpg-ui-nav__item--current/ );

		await page.reload();

		await expect( page.locator( `#${ PUBLISHING }` ) ).toBeVisible();
		await expect( sections.filter( { visible: true } ) ).toHaveCount( 1 );
	} );

	test( 'without JavaScript every section shows and the bottom Save saves', async ( {
		browser,
		page,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: await page.context().storageState(),
		} );
		const noJs = await context.newPage();

		await noJs.goto( SCREENS.checklist );

		const sections = noJs.locator( '[data-sit-wcpg-section]' );
		const total = await sections.count();

		expect( total ).toBeGreaterThan( 1 );
		await expect( sections.filter( { visible: true } ) ).toHaveCount(
			total
		);

		// No save state to show, nothing to discard; Save is enabled.
		await expect(
			noJs.locator( '[data-sit-wcpg-discard]' ).first()
		).toBeHidden();
		await expect( noJs.locator( '#submit' ) ).toBeEnabled();

		const id = await skuRadio( noJs, 'required' ).getAttribute( 'id' );

		await noJs.locator( `label[for="${ id }"]` ).click();
		await Promise.all( [
			noJs.waitForURL( /settings-updated=true/ ),
			noJs.locator( '#submit' ).click( { noWaitAfter: true } ),
		] );

		await expect( skuRadio( noJs, 'required' ) ).toBeChecked();
		expect( readSettingsOption().rules.sku.severity ).toBe( 'required' );

		await context.close();
	} );
} );
