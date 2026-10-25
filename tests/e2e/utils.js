/**
 * Helpers shared by the end-to-end specs.
 */

/**
 * WordPress dependencies
 */
import { expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { loadFixtures } from './fixtures';
import { SAVE_TIMEOUT } from './settings';

export { BASELINE, saveSettings } from './settings';

/**
 * The fixture ids global setup created.
 *
 * @return {{products: Object<string, number>, variations: Object<string, number[]>}} Ids.
 */
export function fixtures() {
	return loadFixtures();
}

/**
 * The checklist meta box on the product editor.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
export function checklist( page ) {
	return page.locator( '#sit_wcpg_product_checklist' );
}

/**
 * One checklist row, by the check's label.
 *
 * @param {Object} page  Playwright page.
 * @param {string} label Exact rule label, e.g. "Regular price".
 * @return {Object} Locator of the `<li>`.
 */
export function checklistRow( page, label ) {
	return checklist( page )
		.locator( '.sit-wcpg-checklist__item' )
		.filter( {
			has: page.locator( '.sit-wcpg-checklist__label', {
				hasText: new RegExp( `^${ label }$` ),
			} ),
		} );
}

/**
 * Waits until the checklist has a result and is not checking.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
export async function waitForChecklist( page ) {
	const app = checklist( page ).locator( '.sit-wcpg-checklist__app' );

	await expect( app ).toHaveAttribute( 'aria-busy', 'false' );
	await expect(
		checklist( page ).locator( '.sit-wcpg-checklist__item' ).first()
	).toBeAttached();
}

/**
 * Opens a product in the classic editor and waits for its checklist.
 *
 * @param {Object} page Playwright page.
 * @param {number} id   Product id.
 * @return {Promise<void>}
 */
export async function openProduct( page, id ) {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await waitForChecklist( page );
}

/**
 * Clicks Publish, answers the plugin's confirmation, and waits for the reload.
 *
 * The confirmation appears only when the checklist expects a refusal or an
 * override. Accepting it submits the form, so the server decides, as it would
 * for a merchant who clicks OK.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<string|null>} The confirmation text, or null when none was shown.
 */
export async function publish( page ) {
	let prompt = null;

	page.once( 'dialog', async ( dialog ) => {
		prompt = dialog.message();
		await dialog.accept();
	} );

	// As in saveSettings(): the URL wait, not the click, waits out the save.
	await Promise.all( [
		page.waitForURL( /[?&]message=\d+/, { timeout: SAVE_TIMEOUT } ),
		page.locator( '#publish' ).click( { noWaitAfter: true } ),
	] );
	await waitForChecklist( page );

	return prompt;
}

/**
 * A product's stored status, read over the WooCommerce REST API.
 *
 * @param {Object} requestUtils Request utils.
 * @param {number} id           Product id.
 * @return {Promise<string>} Status.
 */
export async function productStatus( requestUtils, id ) {
	const product = await requestUtils.rest( {
		path: `/wc/v3/products/${ id }`,
	} );

	return product.status;
}

/**
 * Opens the Variations tab and waits for the variations to load.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
export async function openVariationsTab( page ) {
	await page
		.locator( '#woocommerce-product-data li.variations_options > a' )
		.click();
	await expect(
		page
			.locator( '#variable_product_options .woocommerce_variation' )
			.first()
	).toBeVisible();
}

/**
 * Prices one stored variation in the Variations tab and saves the variations.
 *
 * @param {Object} page        Playwright page.
 * @param {number} variationId Variation id.
 * @param {string} price       Regular price.
 * @return {Promise<void>}
 */
export async function priceVariation( page, variationId, price ) {
	await openVariationsTab( page );

	const row = page.locator(
		'#variable_product_options .woocommerce_variation',
		{
			has: page.locator(
				`input.variable_post_id[value="${ variationId }"]`
			),
		}
	);

	// The variation's fields are collapsed until its heading is clicked.
	const field = row.locator( 'input[name^="variable_regular_price"]' );

	if ( ! ( await field.isVisible() ) ) {
		await row.locator( 'h3' ).first().click();
	}

	await field.fill( price );
	await field.dispatchEvent( 'change' );

	const save = page.locator(
		'#variable_product_options button.save-variation-changes'
	);

	await expect( save ).toBeEnabled();

	const saved = page.waitForResponse(
		( response ) =>
			response.url().includes( 'admin-ajax.php' ) &&
			response
				.request()
				.postData()
				?.includes( 'action=woocommerce_save_variations' )
	);

	await save.click();
	await saved;
}

/**
 * Core's "Product …" message after a save.
 *
 * WooCommerce prints its own `#message` inside Product data, so the id alone
 * matches two elements; only core's carries `updated`.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
export function postMessage( page ) {
	return page.locator( '#message.updated' );
}

/**
 * The plugin's notice on the current screen.
 *
 * @param {Object} page Playwright page.
 * @param {string} kind `blocked` or `override`.
 * @return {Object} Locator.
 */
export function guardNotice( page, kind ) {
	return page.locator( `.sit-wcpg-notice--${ kind }` );
}
