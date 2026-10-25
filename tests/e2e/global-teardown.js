/**
 * Deletes the fixtures global setup created.
 */
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const { deleteFixtures } = require( './fixtures' );

/**
 * @param {import('@playwright/test').FullConfig} config Playwright config.
 * @return {Promise<void>}
 */
module.exports = async function globalTeardown( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath: storageState,
	} );

	await requestUtils.setupRest();
	await deleteFixtures( requestUtils );
	await requestContext.dispose();
};
