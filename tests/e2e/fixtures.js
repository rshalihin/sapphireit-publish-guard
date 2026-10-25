/**
 * The products the specs act on, created as drafts through the WooCommerce REST
 * API in global setup and deleted in global teardown.
 *
 * Every fixture is complete under the default settings except for the one
 * thing its spec is about, so a spec sees exactly one failure (or none). Each
 * spec gets products of its own: specs publish them, so none is shared.
 *
 * The ids are handed to the specs in a JSON file under the artifacts folder,
 * because global setup runs in another process than the workers.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const IMAGE = path.join( __dirname, 'fixtures/product.png' );

/**
 * Where global setup writes the created ids.
 *
 * @return {string} Absolute path.
 */
function fixturesPath() {
	return path.join(
		process.env.WP_ARTIFACTS_PATH ||
			path.resolve( __dirname, '../../artifacts' ),
		'sit-wcpg-e2e-fixtures.json'
	);
}

/**
 * The fields every complete product shares: two images, a category that is not
 * the store default, a tag, a SKU, and both descriptions over their minimum
 * lengths (150 and 50 characters).
 *
 * @param {Object} shared Ids created once: `{ category, tag, images }`.
 * @param {string} sku    Unique SKU.
 * @param {string} name   Product name.
 * @return {Object} WC REST product fields.
 */
function complete( shared, sku, name ) {
	return {
		name,
		status: 'draft',
		sku,
		description:
			'Knitted from soft, breathable merino wool, this piece keeps its shape wash after wash. ' +
			'A relaxed fit, ribbed cuffs and a clean neckline make it easy to wear on its own or layered.',
		short_description:
			'A soft merino essential with a relaxed fit, made to be worn every day.',
		categories: [ { id: shared.category } ],
		tags: [ { id: shared.tag } ],
		images: shared.images.map( ( id ) => ( { id } ) ),
	};
}

/**
 * A variable product: one "Size" attribute and one variation per option.
 *
 * @param {Object}             shared Shared ids.
 * @param {string}             sku    Unique parent SKU; variations inherit it.
 * @param {string}             name   Product name.
 * @param {Array<string|null>} prices One regular price per variation; null leaves it unpriced.
 * @return {Object} `{ product, variations }`.
 */
function variable( shared, sku, name, prices ) {
	const options = [ 'Small', 'Medium', 'Large', 'X-Large' ].slice(
		0,
		prices.length
	);

	return {
		product: {
			...complete( shared, sku, name ),
			type: 'variable',
			attributes: [
				{ name: 'Size', options, visible: true, variation: true },
			],
		},
		variations: prices.map( ( price, index ) => ( {
			status: 'publish',
			stock_status: 'instock',
			attributes: [ { name: 'Size', option: options[ index ] } ],
			...( null === price ? {} : { regular_price: price } ),
		} ) ),
	};
}

/**
 * A simple product, priced or not.
 *
 * @param {Object}      shared Shared ids.
 * @param {string}      sku    Unique SKU.
 * @param {string}      name   Product name.
 * @param {string|null} price  Regular price; null leaves it empty.
 * @return {Object} `{ product }`.
 */
function simple( shared, sku, name, price ) {
	return {
		product: {
			...complete( shared, sku, name ),
			type: 'simple',
			stock_status: 'instock',
			...( null === price ? {} : { regular_price: price } ),
		},
	};
}

/**
 * Every fixture, keyed by the name the specs use.
 *
 * @param {Object} shared Shared ids.
 * @param {string} run    Token that keeps SKUs unique across runs.
 * @return {Object<string, Object>} Definitions.
 */
function definitions( shared, run ) {
	const sku = ( key ) => `sit-wcpg-e2e-${ run }-${ key }`;

	return {
		e2e1: variable( shared, sku( 'e2e1' ), 'E2E-1 Merino crew sweater', [
			'49.00',
			null,
		] ),
		e2e2: variable( shared, sku( 'e2e2' ), 'E2E-2 Merino crew sweater', [
			'49.00',
			null,
		] ),
		e2e3: variable( shared, sku( 'e2e3' ), 'E2E-3 Merino crew sweater', [
			'49.00',
			null,
		] ),
		e2e4: simple( shared, sku( 'e2e4' ), 'E2E-4 Linen tote bag', null ),
		e2e5Pass1: simple(
			shared,
			sku( 'e2e5a' ),
			'E2E-5 Canvas apron',
			'24.00'
		),
		e2e5Pass2: simple(
			shared,
			sku( 'e2e5b' ),
			'E2E-5 Canvas cap',
			'18.00'
		),
		e2e5Fail: simple( shared, sku( 'e2e5c' ), 'E2E-5 Canvas bag', null ),
		e2e6Warn: {
			product: {
				...complete( shared, sku( 'e2e6a' ), 'E2E-6 Starter kit' ),
				type: 'grouped',
			},
		},
		e2e6Refuse: {
			product: {
				...complete( shared, sku( 'e2e6b' ), 'E2E-6 Travel kit' ),
				type: 'grouped',
			},
		},
		e2e7: {
			product: {
				...complete( shared, sku( 'e2e7' ), 'E2E-7 Partner gift card' ),
				type: 'external',
				regular_price: '25.00',
				external_url: 'https://example.org/gift-card',
				button_text: 'Buy from our partner',
			},
		},
		e2e8: variable( shared, sku( 'e2e8' ), 'E2E-8 Merino crew sweater', [
			'49.00',
			null,
		] ),
		e2e9: simple( shared, sku( 'e2e9' ), 'E2E-9 Linen napkins', null ),
		screenshot: variable( shared, sku( 'shot' ), 'Merino crew sweater', [
			'59.00',
			'59.00',
			null,
		] ),
	};
}

/**
 * Creates a term, or returns the id of the one that already exists.
 *
 * @param {Object} requestUtils Request utils.
 * @param {string} taxonomy     `categories` or `tags`.
 * @param {string} name         Term name.
 * @return {Promise<number>} Term id.
 */
async function ensureTerm( requestUtils, taxonomy, name ) {
	try {
		const term = await requestUtils.rest( {
			method: 'POST',
			path: `/wc/v3/products/${ taxonomy }`,
			data: { name },
		} );

		return term.id;
	} catch ( error ) {
		// WooCommerce answers term_exists with the existing id.
		const existing = error?.data?.resource_id;

		if ( existing ) {
			return existing;
		}

		throw error;
	}
}

/**
 * Creates the shared terms and images, then every fixture product as a draft.
 *
 * @param {Object} requestUtils Request utils, signed in as an administrator.
 * @return {Promise<Object>} What was created: `{ products, variations, media }`.
 */
async function createFixtures( requestUtils ) {
	const media = [];

	for ( let i = 0; i < 2; i++ ) {
		media.push( ( await requestUtils.uploadMedia( IMAGE ) ).id );
	}

	const shared = {
		category: await ensureTerm( requestUtils, 'categories', 'Knitwear' ),
		tag: await ensureTerm( requestUtils, 'tags', 'merino' ),
		images: media,
	};

	const created = { products: {}, variations: {}, media };
	const run = Date.now().toString( 36 );

	for ( const [ key, definition ] of Object.entries(
		definitions( shared, run )
	) ) {
		const product = await requestUtils.rest( {
			method: 'POST',
			path: '/wc/v3/products',
			data: definition.product,
		} );

		created.products[ key ] = product.id;
		created.variations[ key ] = [];

		for ( const variation of definition.variations || [] ) {
			const stored = await requestUtils.rest( {
				method: 'POST',
				path: `/wc/v3/products/${ product.id }/variations`,
				data: variation,
			} );

			created.variations[ key ].push( stored.id );
		}
	}

	fs.mkdirSync( path.dirname( fixturesPath() ), { recursive: true } );
	fs.writeFileSync( fixturesPath(), JSON.stringify( created, null, '\t' ) );

	return created;
}

/**
 * Deletes everything createFixtures() made. Variations go with their parent.
 *
 * @param {Object} requestUtils Request utils.
 * @return {Promise<void>}
 */
async function deleteFixtures( requestUtils ) {
	let created;

	try {
		created = JSON.parse( fs.readFileSync( fixturesPath(), 'utf8' ) );
	} catch {
		return;
	}

	for ( const id of Object.values( created.products ) ) {
		await requestUtils
			.rest( {
				method: 'DELETE',
				path: `/wc/v3/products/${ id }`,
				params: { force: true },
			} )
			.catch( () => {} );
	}

	for ( const id of created.media ) {
		await requestUtils
			.rest( {
				method: 'DELETE',
				path: `/wp/v2/media/${ id }`,
				params: { force: true },
			} )
			.catch( () => {} );
	}

	fs.rmSync( fixturesPath(), { force: true } );
}

/**
 * The ids global setup created.
 *
 * @return {Object} `{ products, variations, media }`.
 */
function loadFixtures() {
	return JSON.parse( fs.readFileSync( fixturesPath(), 'utf8' ) );
}

module.exports = { createFixtures, deleteFixtures, loadFixtures };
