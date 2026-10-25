/**
 * Changes the plugin's settings the way a merchant does: on its settings
 * screen, under WooCommerce → Product Checklist.
 *
 * The option is not exposed over REST, and going through the form also proves
 * that the screen saves what the specs rely on.
 */

/**
 * The settings screen's address, relative to the site.
 *
 * @type {string}
 */
const SETTINGS_URL = '/wp-admin/admin.php?page=sit-wcpg-settings';

/**
 * How long a save (the settings form, or Publish) may take to land back on its
 * screen, in ms: the same 15 s the config gives `expect`.
 *
 * @type {number}
 */
const SAVE_TIMEOUT = 15_000;

/**
 * The settings every spec assumes unless it changes them itself: the shipped
 * defaults for the fields any spec touches.
 *
 * @type {Object}
 */
const BASELINE = {
	checkboxes: {
		enabled: true,
		'publishing][block_on_required_failure': true,
		'publishing][allow_admin_override': false,
	},
	radios: {
		'rules][child_products][severity': 'warning',
		'publishing][enforce_scope': 'authenticated',
	},
};

/**
 * A field's name attribute from its key path inside the option.
 *
 * @param {string} keys Key path, `a][b][c` for `sit_wcpg_settings[a][b][c]`.
 * @return {string} Name attribute.
 */
function fieldName( keys ) {
	return `sit_wcpg_settings[${ keys }]`;
}

/**
 * Opens the settings screen, applies the changes and saves.
 *
 * @param {Object} page                 Playwright page, signed in as an administrator.
 * @param {Object} changes              Changes.
 * @param {Object} [changes.checkboxes] Key path => checked.
 * @param {Object} [changes.radios]     Key path => value.
 * @return {Promise<void>}
 */
async function saveSettings( page, { checkboxes = {}, radios = {} } ) {
	await page.goto( SETTINGS_URL );

	for ( const [ keys, checked ] of Object.entries( checkboxes ) ) {
		await page
			.locator( `input[type="checkbox"][name="${ fieldName( keys ) }"]` )
			.setChecked( checked );
	}

	for ( const [ keys, value ] of Object.entries( radios ) ) {
		await page
			.locator(
				`input[type="radio"][name="${ fieldName( keys ) }"][value="${ value }"]`
			)
			.check();
	}

	// The save round trip (options.php, then the redirect) can pass the 10 s action
	// timeout on a local site, so the click does not wait for it; the URL wait does.
	await Promise.all( [
		page.waitForURL( /settings-updated=true/, { timeout: SAVE_TIMEOUT } ),
		page.locator( '#submit' ).click( { noWaitAfter: true } ),
	] );
}

module.exports = { BASELINE, SAVE_TIMEOUT, SETTINGS_URL, saveSettings };
