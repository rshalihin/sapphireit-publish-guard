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
		'rules][sku][severity': 'warning',
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
 * Shows the settings section that holds a field.
 *
 * Since 1.2.0 the screen shows one section at a time (UI decision U8); the
 * others are `hidden` but still in the form. The master switch sits in the page
 * bar, outside every section, so it needs nothing.
 *
 * @param {Object} page  Playwright page, on the settings screen.
 * @param {Object} field Locator of the input.
 * @return {Promise<void>}
 */
async function showSectionOf( page, field ) {
	const id = await field.evaluate(
		( input ) => input.closest( '[data-sit-wcpg-section]' )?.id || ''
	);

	if ( '' === id || ( await field.isVisible() ) ) {
		return;
	}

	await page
		.locator( `[data-sit-wcpg-section-nav] a[href="#${ id }"]` )
		.click();
	await page.locator( `#${ id }` ).waitFor( { state: 'visible' } );
}

/**
 * Turns a switch on or off.
 *
 * @param {Object}  page    Playwright page, on the settings screen.
 * @param {string}  keys    Key path.
 * @param {boolean} checked Wanted state.
 * @return {Promise<void>}
 */
async function setSwitch( page, keys, checked ) {
	const field = page.locator(
		`input[type="checkbox"][name="${ fieldName( keys ) }"]`
	);

	await showSectionOf( page, field );
	await field.setChecked( checked );
}

/**
 * Picks one option of a segmented control.
 *
 * The radio is visually hidden (UI decision U5) and its `<label>` is what a
 * merchant clicks, so the helper clicks the label too.
 *
 * @param {Object} page  Playwright page, on the settings screen.
 * @param {string} keys  Key path.
 * @param {string} value Option value.
 * @return {Promise<void>}
 */
async function pickRadio( page, keys, value ) {
	const field = page.locator(
		`input[type="radio"][name="${ fieldName( keys ) }"][value="${ value }"]`
	);

	await showSectionOf( page, field );

	if ( await field.isChecked() ) {
		return;
	}

	const id = await field.getAttribute( 'id' );

	await page.locator( `label[for="${ id }"]` ).click();
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
		await setSwitch( page, keys, checked );
	}

	for ( const [ keys, value ] of Object.entries( radios ) ) {
		await pickRadio( page, keys, value );
	}

	/*
	 * Save stays disabled while the form matches what it loaded with (UI
	 * decision U15). Nothing to change is still a save: global setup and the
	 * specs' teardowns rely on landing on a freshly saved screen, so submit the
	 * form directly, as the no-JavaScript Save button would.
	 */
	if ( await page.locator( '#submit' ).isDisabled() ) {
		await Promise.all( [
			page.waitForURL( /settings-updated=true/, {
				timeout: SAVE_TIMEOUT,
			} ),
			page
				.locator( '[data-sit-wcpg-settings-form]' )
				// requestSubmit(): the Save buttons are named "submit", which shadows form.submit().
				.evaluate( ( form ) => form.requestSubmit() ),
		] );

		return;
	}

	// The save round trip (options.php, then the redirect) can pass the 10 s action
	// timeout on a local site, so the click does not wait for it; the URL wait does.
	await Promise.all( [
		page.waitForURL( /settings-updated=true/, { timeout: SAVE_TIMEOUT } ),
		page.locator( '#submit' ).click( { noWaitAfter: true } ),
	] );
}

module.exports = {
	BASELINE,
	SAVE_TIMEOUT,
	SETTINGS_URL,
	fieldName,
	pickRadio,
	saveSettings,
	setSwitch,
};
