/**
 * The shared icon set: same names and paths as `Admin\Icons`, same accessible markup.
 */

import { render } from '@testing-library/react';
import { createElement } from '@wordpress/element';

import { ICONS, Icon } from '../../assets/js/shared/icons';

// Written by `php bin/icon-names.php` from `src/Admin/Icons.php`; `Icons_Test` keeps it current.
import fixture from '../fixtures/icon-names.json';

describe( 'icons', () => {
	it( 'PHP and JS sets have the same names', () => {
		expect( Object.keys( ICONS ) ).toEqual( Object.keys( fixture ) );
	} );

	it( 'PHP and JS sets draw the same paths', () => {
		expect( ICONS ).toEqual( fixture );
	} );

	it( 'renders an svg hidden from assistive technology', () => {
		const { container } = render(
			createElement( Icon, { name: 'shield', size: 20 } )
		);
		const svg = container.querySelector( 'svg' );

		expect( svg ).not.toBeNull();
		expect( svg.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( svg.getAttribute( 'focusable' ) ).toBe( 'false' );
		expect( svg.getAttribute( 'width' ) ).toBe( '20' );
		expect( svg.getAttribute( 'class' ) ).toBe( 'sit-wcpg-ui-icon' );
		expect( svg.querySelectorAll( 'path' ) ).toHaveLength(
			fixture.shield.paths.length
		);
	} );

	it( 'renders nothing for an unknown name', () => {
		const { container } = render(
			createElement( Icon, { name: 'not-an-icon' } )
		);

		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'clamps the size to 12–48', () => {
		const { container } = render(
			createElement(
				'div',
				null,
				createElement( Icon, { name: 'check', size: 2 } ),
				createElement( Icon, { name: 'check', size: 99 } )
			)
		);
		const sizes = Array.from( container.querySelectorAll( 'svg' ) ).map(
			( svg ) => svg.getAttribute( 'width' )
		);

		expect( sizes ).toEqual( [ '12', '48' ] );
	} );
} );
