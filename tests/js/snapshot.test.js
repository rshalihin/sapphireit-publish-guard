/**
 * snapshot.js: the DOM contract and signature stability.
 */

import {
	buildSnapshot,
	parseIdList,
	signature,
} from '../../assets/js/editor/snapshot';

const EDITOR_MARKUP = `
	<form id="post">
		<input id="title" value="Blue mug" />
		<textarea id="content"><p>A sturdy mug.</p></textarea>
		<textarea id="excerpt">Short.</textarea>
		<div id="woocommerce-product-data">
			<select id="product-type"><option value="simple" selected>Simple</option></select>
			<input id="_regular_price" value="19.99" />
			<input id="_sale_price" value="" />
			<input id="_sale_price_dates_from" value="" />
			<input id="_sale_price_dates_to" value="" />
			<input id="_sku" value="MUG-1" />
			<input id="_manage_stock" type="checkbox" checked />
			<input id="_stock" value="4" />
			<select id="_stock_status"><option value="instock" selected>In stock</option></select>
		</div>
		<div id="postimagediv"><input type="hidden" id="_thumbnail_id" value="-1" /></div>
		<input type="hidden" id="product_image_gallery" value="12, 7,12,,0" />
		<div id="product_catdiv">
			<ul id="product_catchecklist">
				<li><input type="checkbox" value="15" checked /></li>
				<li><input type="checkbox" value="22" /></li>
			</ul>
			<ul id="product_cat-pop">
				<li><input type="checkbox" value="15" checked /></li>
			</ul>
		</div>
		<div id="tagsdiv-product_tag">
			<textarea id="tax-input-product_tag">red, kitchen,red</textarea>
		</div>
	</form>
`;

function mount( markup = EDITOR_MARKUP ) {
	document.body.innerHTML = markup;
}

describe( 'buildSnapshot', () => {
	beforeEach( () => mount() );

	it( 'reads every draft field from the classic editor', () => {
		expect( buildSnapshot( document, {} ) ).toEqual( {
			title: 'Blue mug',
			content: '<p>A sturdy mug.</p>',
			excerpt: 'Short.',
			product_type: 'simple',
			regular_price: '19.99',
			sale_price: '',
			sale_from: '',
			sale_to: '',
			sku: 'MUG-1',
			stock_status: 'instock',
			manage_stock: true,
			stock_quantity: 4,
			featured_image_id: 0,
			gallery_image_ids: [ 12, 7 ],
			category_ids: [ 15 ],
			tag_ids: [ 1, 2 ],
		} );
	} );

	it( 'maps the -1 "no featured image" sentinel, and any value <= 0, to 0', () => {
		[ '-1', '0', '', 'abc' ].forEach( ( value ) => {
			document.getElementById( '_thumbnail_id' ).value = value;
			expect( buildSnapshot( document, {} ).featured_image_id ).toBe( 0 );
		} );

		document.getElementById( '_thumbnail_id' ).value = '31';
		expect( buildSnapshot( document, {} ).featured_image_id ).toBe( 31 );
	} );

	it( 'omits fields whose controls are absent instead of sending them empty', () => {
		mount( '<input id="title" value="Only a title" />' );

		expect( buildSnapshot( document, {} ) ).toEqual( {
			title: 'Only a title',
		} );
	} );

	it( 'reads the visual editor when it is showing', () => {
		const tinymce = {
			get: ( id ) =>
				'content' === id
					? {
							isHidden: () => false,
							getContent: () => '<p>Visual</p>',
						}
					: null,
		};

		expect( buildSnapshot( document, { tinymce } ).content ).toBe(
			'<p>Visual</p>'
		);
	} );

	it( 'falls back to the textarea in Text mode', () => {
		const tinymce = {
			get: () => ( { isHidden: () => true, getContent: () => 'stale' } ),
		};

		expect( buildSnapshot( document, { tinymce } ).content ).toBe(
			'<p>A sturdy mug.</p>'
		);
	} );

	it( 'reads the short description from its own visual editor', () => {
		// WooCommerce renders it with wp_editor( …, 'excerpt' ); the textarea is
		// stale until save, so reading it would miss every edit.
		const tinymce = {
			get: ( id ) =>
				'excerpt' === id
					? {
							isHidden: () => false,
							getContent: () => '<p>Edited short text</p>',
						}
					: null,
		};
		const draft = buildSnapshot( document, { tinymce } );

		expect( draft.excerpt ).toBe( '<p>Edited short text</p>' );
		expect( draft.content ).toBe( '<p>A sturdy mug.</p>' );
	} );

	it( 'falls back to the textarea before the visual editor exists', () => {
		const tinymce = { get: () => null };

		expect( buildSnapshot( document, { tinymce } ).content ).toBe(
			'<p>A sturdy mug.</p>'
		);
	} );

	it( 'honours a localized tag delimiter', () => {
		document.getElementById( 'tax-input-product_tag' ).value = 'a، b، c';

		expect(
			buildSnapshot( document, { tagsBoxL10n: { tagDelimiter: '،' } } )
				.tag_ids
		).toEqual( [ 1, 2, 3 ] );
	} );

	it( 'sends no tags for an empty tag box', () => {
		document.getElementById( 'tax-input-product_tag' ).value = ' , ';

		expect( buildSnapshot( document, {} ).tag_ids ).toEqual( [] );
	} );

	it( 'sends null for a stock quantity that is not a number', () => {
		document.getElementById( '_stock' ).value = '';

		expect( buildSnapshot( document, {} ).stock_quantity ).toBeNull();
	} );
} );

describe( 'parseIdList', () => {
	it( 'keeps unique positive integers in first-seen order', () => {
		expect( parseIdList( '5, 3,5,-2,0,x,,9' ) ).toEqual( [ 5, 3, 9 ] );
		expect( parseIdList( '' ) ).toEqual( [] );
		expect( parseIdList( undefined ) ).toEqual( [] );
	} );
} );

describe( 'signature', () => {
	beforeEach( () => mount() );

	it( 'is identical for two snapshots of an unchanged editor', () => {
		expect( signature( buildSnapshot( document, {} ) ) ).toBe(
			signature( buildSnapshot( document, {} ) )
		);
	} );

	it( 'does not depend on key order', () => {
		expect( signature( { a: '1', b: '2' } ) ).toBe(
			signature( { b: '2', a: '1' } )
		);
	} );

	it( 'treats category and gallery ids as sets', () => {
		expect(
			signature( { category_ids: [ 3, 1 ], gallery_image_ids: [ 9, 4 ] } )
		).toBe(
			signature( { category_ids: [ 1, 3 ], gallery_image_ids: [ 4, 9 ] } )
		);
	} );

	it( 'does not mutate the draft while sorting', () => {
		const draft = { category_ids: [ 3, 1 ] };

		signature( draft );
		expect( draft.category_ids ).toEqual( [ 3, 1 ] );
	} );

	it( 'ignores markup-only and whitespace-only text changes', () => {
		expect( signature( { content: '<p>A  sturdy\nmug.</p>' } ) ).toBe(
			signature( { content: 'A sturdy mug.' } )
		);
		expect( signature( { title: 'Blue mug ' } ) ).toBe(
			signature( { title: 'Blue mug' } )
		);
	} );

	it( 'changes when a value the server reads changes', () => {
		const base = buildSnapshot( document, {} );

		[
			{ title: 'Green mug' },
			{ content: '<p>A sturdy mug!</p>' },
			{ regular_price: '20' },
			{ sku: '' },
			{ featured_image_id: 31 },
			{ category_ids: [ 15, 22 ] },
			{ tag_ids: [ 1 ] },
			{ manage_stock: false },
			{ stock_quantity: 0 },
		].forEach( ( change ) => {
			expect( signature( { ...base, ...change } ) ).not.toBe(
				signature( base )
			);
		} );
	} );
} );
