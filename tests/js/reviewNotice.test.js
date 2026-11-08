/**
 * The review notice script: posting the choice and removing the notice.
 */

import apiFetch from '@wordpress/api-fetch';

import { initReviewNotice } from '../../assets/js/review-notice/index';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/dom-ready', () => ( {
	__esModule: true,
	default: ( callback ) => callback(),
} ) );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const click = ( selector ) => {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );

	document.querySelector( selector ).dispatchEvent( event );

	return event;
};

describe( 'review notice', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { done: true, ask_after: 0 } );

		window.sitWcpgReviewNotice = { restPath: '/from/the/payload' };

		document.body.innerHTML = `
			<div data-sit-wcpg-review>
				<a href="#review" data-choice="review">Leave a review</a>
				<button type="button" data-choice="later"><span id="inner">Maybe later</span></button>
				<button type="button" data-choice="never">Never ask again</button>
				<button type="button" id="unrelated">Something else</button>
			</div>`;

		initReviewNotice();
	} );

	afterEach( () => {
		delete window.sitWcpgReviewNotice;
		document.body.innerHTML = '';
	} );

	it( 'posts the clicked choice to the payload path', () => {
		click( '[data-choice="later"]' );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/from/the/payload',
			method: 'POST',
			data: { choice: 'later' },
		} );
	} );

	it( 'finds the choice from a click on a child element', () => {
		click( '#inner' );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			choice: 'later',
		} );
	} );

	it( 'ignores clicks outside the choices', () => {
		click( '#unrelated' );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'removes the notice after "Never ask again"', async () => {
		const event = click( '[data-choice="never"]' );

		expect( event.defaultPrevented ).toBe( true );

		await flush();

		expect( document.querySelector( '[data-sit-wcpg-review]' ) ).toBeNull();
	} );

	it( 'does not preventDefault on the review link', async () => {
		const event = click( '[data-choice="review"]' );

		expect( event.defaultPrevented ).toBe( false );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			choice: 'review',
		} );

		await flush();

		expect( document.querySelector( '[data-sit-wcpg-review]' ) ).toBeNull();
	} );

	it( 'hides even if the request rejects', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network down' ) );

		click( '[data-choice="later"]' );

		await flush();

		expect( document.querySelector( '[data-sit-wcpg-review]' ) ).toBeNull();
	} );

	it( 'does nothing on a page without the notice', () => {
		document.body.innerHTML = '';

		expect( () => initReviewNotice() ).not.toThrow();
	} );
} );
