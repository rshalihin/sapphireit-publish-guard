/**
 * The settings screen's section nav: one section at a time, driven by the URL hash
 * (UI decision U8; phase U07).
 */

import { initSectionNav } from '../../assets/js/settings/section-nav';

const PAGE = '/wp-admin/admin.php?page=sit-wcpg-settings';

const section = ( key, field ) => `
	<section class="sit-wcpg-settings__section" id="sit-wcpg-section-${ key }" data-sit-wcpg-section>
		<h2 id="sit-wcpg-section-${ key }-title">${ key }</h2>
		${ field }
	</section>`;

const render = ( invalid = '' ) => {
	document.body.innerHTML = `
		<form method="post" action="options.php" data-sit-wcpg-settings-form${
			invalid ? ` data-sit-wcpg-invalid="${ invalid }"` : ''
		}>
			<input type="hidden" name="_wp_http_referer" value="${ PAGE }" />
			<nav data-sit-wcpg-section-nav>
				<a class="sit-wcpg-ui-nav__item" href="#sit-wcpg-section-content">Content</a>
				<a class="sit-wcpg-ui-nav__item" href="#sit-wcpg-section-media">Media</a>
				<a class="sit-wcpg-ui-nav__item" href="#sit-wcpg-section-publishing">Publishing</a>
			</nav>
			${ section(
				'content',
				'<input type="checkbox" name="sit_wcpg_settings[rules][title][enabled]" value="1" checked />'
			) }
			${ section(
				'media',
				'<input type="number" id="sit-wcpg-threshold-min-images" name="sit_wcpg_settings[thresholds][min_images]" value="2" />'
			) }
			${ section(
				'publishing',
				'<input type="checkbox" name="sit_wcpg_settings[publishing][allow_admin_override]" value="1" checked />'
			) }
		</form>`;

	return {
		form: document.querySelector( 'form' ),
		nav: document.querySelector( 'nav' ),
	};
};

const visible = () =>
	Array.from( document.querySelectorAll( '[data-sit-wcpg-section]' ) )
		.filter( ( node ) => ! node.hidden )
		.map( ( node ) => node.id );

const current = () =>
	Array.from( document.querySelectorAll( 'nav a[aria-current="true"]' ) ).map(
		( link ) => link.getAttribute( 'href' )
	);

describe( 'settings section nav', () => {
	let nav;

	beforeEach( () => {
		window.history.replaceState( null, '', PAGE );
	} );

	afterEach( () => {
		if ( nav ) {
			nav.destroy();
			nav = null;
		}
	} );

	it( 'shows the hash section', () => {
		window.history.replaceState(
			null,
			'',
			PAGE + '#sit-wcpg-section-media'
		);

		nav = initSectionNav( render() );

		expect( visible() ).toEqual( [ 'sit-wcpg-section-media' ] );
		expect( current() ).toEqual( [ '#sit-wcpg-section-media' ] );
	} );

	it( 'unknown hash falls back to first', () => {
		window.history.replaceState( null, '', PAGE + '#not-a-section' );

		nav = initSectionNav( render() );

		expect( visible() ).toEqual( [ 'sit-wcpg-section-content' ] );
		expect( current() ).toEqual( [ '#sit-wcpg-section-content' ] );
	} );

	it( 'clicking a link updates hash, aria-current and focus', () => {
		nav = initSectionNav( render() );

		document
			.querySelector( 'a[href="#sit-wcpg-section-publishing"]' )
			.click();

		expect( window.location.hash ).toBe( '#sit-wcpg-section-publishing' );
		expect( visible() ).toEqual( [ 'sit-wcpg-section-publishing' ] );
		expect( current() ).toEqual( [ '#sit-wcpg-section-publishing' ] );
		expect(
			document.querySelectorAll( '.sit-wcpg-ui-nav__item--current' )
		).toHaveLength( 1 );
		expect( document.activeElement.id ).toBe(
			'sit-wcpg-section-publishing-title'
		);
		expect(
			document.querySelector( '[name="_wp_http_referer"]' ).value
		).toBe( PAGE + '#sit-wcpg-section-publishing' );
	} );

	it( 'hidden sections keep their inputs in the form', () => {
		const { form, nav: element } = render();

		nav = initSectionNav( { form, nav: element } );

		const names = Array.from( new window.FormData( form ).keys() );

		expect( visible() ).toHaveLength( 1 );
		expect( names ).toEqual(
			expect.arrayContaining( [
				'sit_wcpg_settings[rules][title][enabled]',
				'sit_wcpg_settings[thresholds][min_images]',
				'sit_wcpg_settings[publishing][allow_admin_override]',
			] )
		);
	} );

	it( 'error opens the section with the invalid field', () => {
		window.history.replaceState(
			null,
			'',
			PAGE + '#sit-wcpg-section-publishing'
		);

		nav = initSectionNav( render( 'sit-wcpg-threshold-min-images' ) );

		expect( visible() ).toEqual( [ 'sit-wcpg-section-media' ] );
		expect( current() ).toEqual( [ '#sit-wcpg-section-media' ] );
	} );

	it( 'a browser validation failure opens the section with the field', () => {
		const { form, nav: element } = render();

		nav = initSectionNav( { form, nav: element } );

		form.querySelector( '#sit-wcpg-threshold-min-images' ).dispatchEvent(
			new window.Event( 'invalid', { cancelable: true } )
		);

		expect( visible() ).toEqual( [ 'sit-wcpg-section-media' ] );
	} );
} );
