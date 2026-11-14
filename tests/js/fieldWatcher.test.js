/**
 * field-watcher.js: re-checks on WooCommerce's variation events (§6.6, R4).
 *
 * Variations are saved by AJAX and never enter the draft (D3), so these events must
 * re-check even though the snapshot's signature has not changed.
 */

import $ from 'jquery';
import { addFilter, removeAllFilters } from '@wordpress/hooks';

import { DEBOUNCE_MS, watchFields } from '../../assets/js/editor/field-watcher';

const MARKUP = `
	<form id="post">
		<input id="title" value="Blue mug" />
		<div id="woocommerce-product-data">
			<div id="variable_product_options">
				<div class="woocommerce_variations"></div>
			</div>
		</div>
	</form>
`;

describe( 'watchFields: variation events', () => {
	let watcher;
	let onSnapshot;
	let onExternalChange;

	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = MARKUP;

		onSnapshot = jest.fn();
		onExternalChange = jest.fn();
		watcher = watchFields( {
			onSnapshot,
			onExternalChange,
			doc: document,
			win: window,
		} );
	} );

	afterEach( () => {
		watcher.stop();
		jest.useRealTimers();
	} );

	function settle() {
		jest.advanceTimersByTime( DEBOUNCE_MS );
	}

	it( 're-checks after woocommerce_variations_saved even when the draft is unchanged', () => {
		$( '#woocommerce-product-data' ).trigger(
			'woocommerce_variations_saved'
		);

		jest.advanceTimersByTime( DEBOUNCE_MS - 1 );
		expect( onExternalChange ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );
		expect( onExternalChange ).toHaveBeenCalledTimes( 1 );
		expect( onSnapshot ).not.toHaveBeenCalled();
	} );

	it( 're-checks after woocommerce_variations_added bubbles from #variable_product_options', () => {
		$( '#variable_product_options' ).trigger(
			'woocommerce_variations_added',
			1
		);
		settle();

		expect( onExternalChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 're-checks after woocommerce_variations_removed', () => {
		$( '#woocommerce-product-data' ).trigger(
			'woocommerce_variations_removed'
		);
		settle();

		expect( onExternalChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 're-checks after woocommerce_variations_loaded', () => {
		// Bulk variation actions and "Generate variations" end in load_variations().
		$( '#woocommerce-product-data' ).trigger(
			'woocommerce_variations_loaded'
		);
		settle();

		expect( onExternalChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'debounces a burst of variation events into one re-check', () => {
		const $panel = $( '#woocommerce-product-data' );

		$panel.trigger( 'woocommerce_variations_saved' );
		jest.advanceTimersByTime( DEBOUNCE_MS / 2 );
		$panel.trigger( 'woocommerce_variations_loaded' );
		jest.advanceTimersByTime( DEBOUNCE_MS / 2 );
		$( '#variable_product_options' ).trigger(
			'woocommerce_variations_added',
			1
		);
		settle();

		expect( onExternalChange ).toHaveBeenCalledTimes( 1 );

		// The burst is over: the next event is a new re-check.
		$panel.trigger( 'woocommerce_variations_removed' );
		settle();

		expect( onExternalChange ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'sends one request, the draft, when a field also changed', () => {
		$( '#woocommerce-product-data' ).trigger(
			'woocommerce_variations_saved'
		);
		document.getElementById( 'title' ).value = 'Red mug';
		$( '#title' ).trigger( 'input' );
		settle();

		expect( onSnapshot ).toHaveBeenCalledTimes( 1 );
		expect( onSnapshot.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			title: 'Red mug',
		} );
		expect( onExternalChange ).not.toHaveBeenCalled();
	} );

	it( 'stops listening after stop()', () => {
		const $panel = $( '#woocommerce-product-data' );

		// A re-check already pending when the watcher stops never fires.
		$panel.trigger( 'woocommerce_variations_saved' );
		watcher.stop();
		settle();

		$panel.trigger( 'woocommerce_variations_saved' );
		$( '#variable_product_options' ).trigger(
			'woocommerce_variations_added',
			1
		);
		settle();

		expect( onExternalChange ).not.toHaveBeenCalled();
	} );
} );

describe( 'watchFields: add-on filters', () => {
	const PROBE_MARKUP = `
		<form id="post">
			<input id="title" value="Blue mug" />
			<div id="woocommerce-product-data"></div>
			<div id="probe-box"><input id="_probe_code" value="abc" /></div>
			<div id="postimagediv"></div>
		</form>
	`;

	let watcher;
	let onSnapshot;

	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = PROBE_MARKUP;
		onSnapshot = jest.fn();

		// The add-on's field reaches the draft, so a change to it is a new signature.
		addFilter(
			'sitWcpg.snapshot.extras',
			'test/probe',
			( extras, doc ) => ( {
				...extras,
				probe_code: doc.querySelector( '#_probe_code' ).value,
			} )
		);
	} );

	afterEach( () => {
		if ( watcher ) {
			watcher.stop();
		}

		removeAllFilters( 'sitWcpg.snapshot.extras' );
		removeAllFilters( 'sitWcpg.watcher.fields' );
		removeAllFilters( 'sitWcpg.watcher.containers' );
		jest.useRealTimers();
	} );

	function type( selector, value ) {
		document.querySelector( selector ).value = value;
		$( selector ).trigger( 'input' );
		jest.advanceTimersByTime( DEBOUNCE_MS );
	}

	it( 'ignores a field outside the watched ones until a filter adds it', () => {
		watcher = watchFields( { onSnapshot, doc: document, win: window } );

		type( '#_probe_code', 'abd' );

		expect( onSnapshot ).not.toHaveBeenCalled();
	} );

	it( 'a selector added through the filter triggers a snapshot', () => {
		addFilter( 'sitWcpg.watcher.fields', 'test/probe', ( fields ) => [
			...fields,
			'#probe-box input',
			42,
		] );

		watcher = watchFields( { onSnapshot, doc: document, win: window } );

		type( '#_probe_code', 'abd' );

		expect( onSnapshot ).toHaveBeenCalledTimes( 1 );
		expect( onSnapshot.mock.calls[ 0 ][ 0 ].extras ).toEqual( {
			probe_code: 'abd',
		} );
	} );

	it( 'core selectors survive a filter that returns an empty array', () => {
		addFilter( 'sitWcpg.watcher.fields', 'test/probe', () => [] );
		addFilter( 'sitWcpg.watcher.containers', 'test/probe', () => 'nope' );

		watcher = watchFields( { onSnapshot, doc: document, win: window } );

		type( '#title', 'Red mug' );

		expect( onSnapshot ).toHaveBeenCalledTimes( 1 );
		expect( onSnapshot.mock.calls[ 0 ][ 0 ].title ).toBe( 'Red mug' );
	} );

	it( 'an added container is observed', () => {
		const observed = [];
		const win = {
			MutationObserver: class {
				observe( node ) {
					observed.push( node.id );
				}

				disconnect() {}
			},
		};

		addFilter(
			'sitWcpg.watcher.containers',
			'test/probe',
			( containers ) => [ ...containers, '#probe-box', null ]
		);

		watcher = watchFields( { onSnapshot, doc: document, win } );

		expect( observed ).toEqual( [ 'postimagediv', 'probe-box' ] );
	} );
} );
