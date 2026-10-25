/**
 * Signs in, resets the settings the specs depend on, and creates the fixtures.
 *
 * Runs once before the suite, in the Playwright runner's process.
 */
const { chromium, request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const { createFixtures, deleteFixtures } = require( './fixtures' );
const { BASELINE, saveSettings } = require( './settings' );

/**
 * @param {import('@playwright/test').FullConfig} config Playwright config.
 * @return {Promise<void>}
 */
module.exports = async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath: storageState,
	} );

	// Signs in and saves the cookies every spec starts from.
	await requestUtils.setupRest();

	// A run that was interrupted leaves its fixtures behind.
	await deleteFixtures( requestUtils );
	await createFixtures( requestUtils );

	await requestContext.dispose();

	// A spec that failed half-way may have left a setting changed.
	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL, storageState } );

	await saveSettings( page, BASELINE );
	await browser.close();
};
