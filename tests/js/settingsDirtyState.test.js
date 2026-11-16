/**
 * The settings screen's save state: clean, dirty, saving, Discard and the leave warning
 * (UI decisions U8, U15; phase U07).
 */

import { initDirtyState } from '../../assets/js/settings/dirty-state';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

const bar = ( position ) => `
	<div data-sit-wcpg-savebar="${ position }">
		<span data-sit-wcpg-savestate hidden>
			<span class="sit-wcpg-ui-savebar__dot" aria-hidden="true"></span>
			<span data-sit-wcpg-status ${
				'top' === position ? 'aria-live="polite"' : 'aria-hidden="true"'
			}></span>
		</span>
		<span class="spinner"></span>
		<button type="button" class="button" data-sit-wcpg-discard hidden>Discard</button>
		<input type="submit" name="submit" class="button button-primary" value="Save changes" />
	</div>`;

const render = () => {
	document.body.innerHTML = `
		<form method="post" action="options.php" data-sit-wcpg-settings-form>
			<input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php?page=sit-wcpg-settings" />
			${ bar( 'top' ) }
			<input type="checkbox" role="switch" id="sw" name="sit_wcpg_settings[rules][sku][enabled]" value="1" checked />
			<input type="radio" id="r1" name="sit_wcpg_settings[rules][sku][severity]" value="required" checked />
			<input type="radio" id="r2" name="sit_wcpg_settings[rules][sku][severity]" value="warning" />
			<input type="number" id="num" name="sit_wcpg_settings[thresholds][min_images]" value="2" />
			${ bar( 'bottom' ) }
		</form>`;

	return document.querySelector( 'form' );
};

const change = ( element ) =>
	element.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

const buttons = ( form ) =>
	Array.from(
		form.querySelectorAll( '[data-sit-wcpg-discard], input[type="submit"]' )
	);

const statuses = ( form ) =>
	Array.from( form.querySelectorAll( '[data-sit-wcpg-status]' ) ).map(
		( node ) => node.textContent
	);

const bars = ( form ) =>
	Array.from( form.querySelectorAll( '[data-sit-wcpg-savebar]' ) );

describe( 'settings dirty state', () => {
	let tracker;

	afterEach( () => {
		if ( tracker ) {
			tracker.destroy();
			tracker = null;
		}
	} );

	it( 'clean on load', () => {
		const form = render();

		tracker = initDirtyState( form );

		expect( tracker.getState() ).toBe( 'clean' );
		expect( statuses( form ) ).toEqual( [
			'All changes saved',
			'All changes saved',
		] );
		buttons( form ).forEach( ( button ) =>
			expect( button.disabled ).toBe( true )
		);
		bars( form ).forEach( ( node ) =>
			expect( node.dataset.state ).toBe( 'clean' )
		);
		form.querySelectorAll( '[data-sit-wcpg-discard]' ).forEach(
			( button ) => expect( button.hidden ).toBe( false )
		);
		form.querySelectorAll( '[data-sit-wcpg-savestate]' ).forEach(
			( node ) => expect( node.hidden ).toBe( false )
		);
	} );

	it( 'changing a switch marks dirty', () => {
		const form = render();
		const toggle = form.querySelector( '#sw' );

		tracker = initDirtyState( form );

		toggle.checked = false;
		change( toggle );

		expect( tracker.getState() ).toBe( 'dirty' );
		expect( statuses( form ) ).toEqual( [
			'Unsaved changes',
			'Unsaved changes',
		] );
		buttons( form ).forEach( ( button ) =>
			expect( button.disabled ).toBe( false )
		);
		bars( form ).forEach( ( node ) =>
			expect(
				node.classList.contains( 'sit-wcpg-ui-savebar--dirty' )
			).toBe( true )
		);
	} );

	it( 'changing it back marks clean', () => {
		const form = render();
		const toggle = form.querySelector( '#sw' );

		tracker = initDirtyState( form );

		toggle.checked = false;
		change( toggle );
		toggle.checked = true;
		change( toggle );

		expect( tracker.getState() ).toBe( 'clean' );
		buttons( form ).forEach( ( button ) =>
			expect( button.disabled ).toBe( true )
		);
	} );

	it( 'threshold number change marks dirty', () => {
		const form = render();
		const number = form.querySelector( '#num' );

		tracker = initDirtyState( form );

		number.value = '3';
		number.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( tracker.getState() ).toBe( 'dirty' );
	} );

	it( 'a radio change marks dirty', () => {
		const form = render();
		const radio = form.querySelector( '#r2' );

		tracker = initDirtyState( form );

		radio.checked = true;
		change( radio );

		expect( tracker.getState() ).toBe( 'dirty' );
	} );

	it( 'a hidden field change does not mark dirty', () => {
		const form = render();

		tracker = initDirtyState( form );

		form.querySelector( '[name="_wp_http_referer"]' ).value =
			'/wp-admin/admin.php?page=sit-wcpg-settings#elsewhere';
		tracker.update();

		expect( tracker.getState() ).toBe( 'clean' );
	} );

	it( 'discard restores values and marks clean', () => {
		const form = render();
		const toggle = form.querySelector( '#sw' );
		const number = form.querySelector( '#num' );
		const onDiscard = jest.fn();

		tracker = initDirtyState( form, { onDiscard } );

		toggle.checked = false;
		change( toggle );
		number.value = '7';
		change( number );

		form.querySelector( '[data-sit-wcpg-discard]' ).click();

		expect( toggle.checked ).toBe( true );
		expect( number.value ).toBe( '2' );
		expect( tracker.getState() ).toBe( 'clean' );
		expect(
			form.querySelector( '[data-sit-wcpg-status][aria-live]' )
				.textContent
		).toBe( 'Changes discarded' );
		buttons( form ).forEach( ( button ) =>
			expect( button.disabled ).toBe( true )
		);
		expect( onDiscard ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'submit marks saving and disables both bars', () => {
		const form = render();
		const toggle = form.querySelector( '#sw' );

		tracker = initDirtyState( form );

		toggle.checked = false;
		change( toggle );

		form.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);

		expect( tracker.getState() ).toBe( 'saving' );
		expect( form.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		buttons( form ).forEach( ( button ) =>
			expect( button.disabled ).toBe( true )
		);
		form.querySelectorAll( '.spinner' ).forEach( ( spinner ) =>
			expect( spinner.classList.contains( 'is-active' ) ).toBe( true )
		);
		expect( statuses( form ) ).toEqual( [ 'Saving…', 'Saving…' ] );
	} );

	it( 'beforeunload only prevents when dirty', () => {
		const form = render();
		const toggle = form.querySelector( '#sw' );
		const leave = () => {
			const event = new window.Event( 'beforeunload', {
				cancelable: true,
			} );

			window.dispatchEvent( event );

			return event.defaultPrevented;
		};

		tracker = initDirtyState( form );

		expect( leave() ).toBe( false );

		toggle.checked = false;
		change( toggle );

		expect( leave() ).toBe( true );

		form.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);

		expect( leave() ).toBe( false );
	} );
} );
