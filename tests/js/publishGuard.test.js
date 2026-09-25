/**
 * publish-guard.js: when the advisory applies, and that the prompt only ever asks —
 * it never disables the Publish button.
 */

import {
	MODE_BLOCK,
	MODE_OVERRIDE,
	installPublishConfirm,
	publishMode,
} from '../../assets/js/editor/publish-guard';

const FAILING = { required_failure_ids: [ 'price' ] };
const PASSING = { required_failure_ids: [] };

function payload( overrides = {} ) {
	return {
		postStatus: 'draft',
		...overrides,
		settings: {
			blocksPublishing: true,
			canOverride: false,
			...( overrides.settings || {} ),
		},
	};
}

describe( 'publishMode', () => {
	it( 'blocks a draft that fails a required check', () => {
		expect( publishMode( payload(), FAILING ) ).toBe( MODE_BLOCK );
	} );

	it( 'reports an override for a user allowed to override', () => {
		expect(
			publishMode(
				payload( { settings: { canOverride: true } } ),
				FAILING
			)
		).toBe( MODE_OVERRIDE );
	} );

	it( 'says nothing when every required check passes', () => {
		expect( publishMode( payload(), PASSING ) ).toBeNull();
		expect( publishMode( payload(), null ) ).toBeNull();
	} );

	it( 'says nothing when the guard is off', () => {
		expect(
			publishMode(
				payload( { settings: { blocksPublishing: false } } ),
				FAILING
			)
		).toBeNull();
	} );

	it( 'says nothing for a product that is already live', () => {
		expect(
			publishMode( payload( { postStatus: 'publish' } ), FAILING )
		).toBeNull();
	} );
} );

describe( 'installPublishConfirm', () => {
	let submitted;

	beforeEach( () => {
		document.body.innerHTML =
			'<form id="post"><input type="submit" id="save-post" value="Save Draft">' +
			'<input type="submit" id="publish" value="Publish"></form>';
		submitted = 0;
		document
			.getElementById( 'post' )
			.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				submitted++;
			} );
	} );

	function click( id ) {
		document.getElementById( id ).click();
	}

	it( 'stops the submit when the user cancels', () => {
		const confirm = jest.fn( () => false );
		const remove = installPublishConfirm( {
			getMode: () => MODE_BLOCK,
			confirm,
		} );

		click( 'publish' );

		expect( confirm ).toHaveBeenCalledTimes( 1 );
		expect( submitted ).toBe( 0 );
		remove();
	} );

	it( 'lets the submit through when the user confirms', () => {
		const remove = installPublishConfirm( {
			getMode: () => MODE_BLOCK,
			confirm: () => true,
		} );

		click( 'publish' );

		expect( submitted ).toBe( 1 );
		remove();
	} );

	it( 'never asks when no advisory applies', () => {
		const confirm = jest.fn( () => false );
		const remove = installPublishConfirm( {
			getMode: () => null,
			confirm,
		} );

		click( 'publish' );

		expect( confirm ).not.toHaveBeenCalled();
		expect( submitted ).toBe( 1 );
		remove();
	} );

	it( 'ignores the other submit buttons', () => {
		const confirm = jest.fn( () => false );
		const remove = installPublishConfirm( {
			getMode: () => MODE_BLOCK,
			confirm,
		} );

		click( 'save-post' );

		expect( confirm ).not.toHaveBeenCalled();
		expect( submitted ).toBe( 1 );
		remove();
	} );

	it( 'never disables the Publish button', () => {
		const remove = installPublishConfirm( {
			getMode: () => MODE_BLOCK,
			confirm: () => false,
		} );

		click( 'publish' );

		expect( document.getElementById( 'publish' ).disabled ).toBe( false );
		remove();
	} );

	it( 'does nothing without the editor form', () => {
		document.body.innerHTML = '';

		expect( () =>
			installPublishConfirm( { getMode: () => MODE_BLOCK } )()
		).not.toThrow();
	} );
} );
