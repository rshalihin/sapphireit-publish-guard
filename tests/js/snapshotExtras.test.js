/**
 * snapshot.js: extras contributed by an add-on through `sitWcpg.snapshot.extras`.
 */

import { addFilter, removeAllFilters } from '@wordpress/hooks';

import { buildSnapshot, signature } from '../../assets/js/editor/snapshot';

const HOOK = 'sitWcpg.snapshot.extras';

describe( 'buildSnapshot: extras', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<form id="post">
				<input id="title" value="Blue mug" />
				<input id="_probe_code" value="abc" />
			</form>
		`;
	} );

	afterEach( () => {
		removeAllFilters( HOOK );
	} );

	it( 'has no extras key when nothing is on the filter', () => {
		expect( buildSnapshot( document, window ) ).not.toHaveProperty(
			'extras'
		);
	} );

	it( "merges a filter's object as extras", () => {
		const seen = [];

		addFilter( HOOK, 'test/probe', ( extras, doc, win ) => {
			seen.push( [ doc, win ] );

			return {
				...extras,
				probe_code: doc.querySelector( '#_probe_code' ).value,
			};
		} );

		const draft = buildSnapshot( document, window );

		expect( draft.extras ).toEqual( { probe_code: 'abc' } );
		expect( draft.title ).toBe( 'Blue mug' );
		expect( seen ).toEqual( [ [ document, window ] ] );
	} );

	it.each( [
		[ '{}', {} ],
		[ 'an array', [ 'abc' ] ],
		[ 'a string', 'abc' ],
		[ 'null', null ],
	] )(
		'leaves the draft without an extras key when the filter returns %s',
		( label, value ) => {
			addFilter( HOOK, 'test/probe', () => value );

			expect( buildSnapshot( document, window ) ).not.toHaveProperty(
				'extras'
			);
		}
	);

	it( 'keeps the draft when a filter throws', () => {
		addFilter( HOOK, 'test/probe', () => {
			throw new Error( 'An add-on bug.' );
		} );

		const draft = buildSnapshot( document, window );

		expect( draft.title ).toBe( 'Blue mug' );
		expect( draft ).not.toHaveProperty( 'extras' );
	} );
} );

describe( 'signature: extras', () => {
	it( 'changes when an extra changes', () => {
		const before = signature( {
			title: 'Blue mug',
			extras: { probe_code: 'abc' },
		} );
		const after = signature( {
			title: 'Blue mug',
			extras: { probe_code: 'abd' },
		} );

		expect( after ).not.toBe( before );
		expect( signature( { title: 'Blue mug' } ) ).not.toBe( before );
	} );

	it( 'ignores the key order inside extras', () => {
		const first = signature( {
			extras: { a: 1, b: { y: [ 2, 1 ], x: 'v' } },
		} );
		const second = signature( {
			extras: { b: { x: 'v', y: [ 2, 1 ] }, a: 1 },
		} );

		expect( second ).toBe( first );
	} );

	it( 'keeps the order of a list inside extras', () => {
		expect( signature( { extras: { a: [ 1, 2 ] } } ) ).not.toBe(
			signature( { extras: { a: [ 2, 1 ] } } )
		);
	} );
} );
