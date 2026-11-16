/**
 * ChecklistSummary (score ring, state line, counts) and ChecklistFooter (Re-check).
 */

import { fireEvent, render } from '@testing-library/react';
import { createElement } from '@wordpress/element';

import { ChecklistSummary } from '../../assets/js/editor/components/ChecklistSummary';
import { ChecklistFooter } from '../../assets/js/editor/components/ChecklistFooter';

// The real package pulls in an ESM-only dependency Jest can't load as CommonJS.
jest.mock( '@wordpress/components', () => {
	const { createElement: el } = jest.requireActual( '@wordpress/element' );
	const passthrough = ( { children } ) => el( 'div', null, children );

	return {
		Button: ( { children, onClick } ) =>
			el( 'button', { type: 'button', onClick }, children ),
		Notice: passthrough,
		Spinner: () => el( 'span' ),
		VisuallyHidden: passthrough,
	};
} );

const result = ( counts, extra = {} ) => ( {
	is_ready: 0 === counts.failed,
	results: [],
	counts: { skipped: 0, ...counts },
	...extra,
} );

describe( 'ChecklistSummary', () => {
	it( 'summary shows ring with passed of applicable', () => {
		const { container } = render(
			<ChecklistSummary
				result={ result(
					{ evaluated: 14, passed: 9, failed: 3, warnings: 2 },
					{ summary_label: '9 of 14 checks passed' }
				) }
				loading={ false }
			/>
		);

		const ring = container.querySelector( '[role="img"]' );

		expect( ring ).not.toBeNull();
		expect( ring.getAttribute( 'aria-label' ) ).toBe(
			'9 of 14 checks passed'
		);
		expect(
			container.querySelector( '.sit-wcpg-checklist__ring-value' )
				.textContent
		).toBe( '9/14' );
	} );

	it( 'ring label falls back to the counts without a server label', () => {
		const { container } = render(
			<ChecklistSummary
				result={ result( {
					evaluated: 2,
					passed: 2,
					failed: 0,
					warnings: 0,
				} ) }
				loading={ false }
			/>
		);

		expect(
			container
				.querySelector( '[role="img"]' )
				.getAttribute( 'aria-label' )
		).toBe( '2 of 2 checks passed' );
	} );

	it( 'state line says Not ready to publish when a required check fails', () => {
		const { container } = render(
			<ChecklistSummary
				result={ result( {
					evaluated: 14,
					passed: 9,
					failed: 3,
					warnings: 2,
				} ) }
				loading={ false }
			/>
		);

		expect(
			container.querySelector( '.sit-wcpg-checklist__state' ).textContent
		).toBe( 'Not ready to publish' );
		expect(
			container.querySelector( '.sit-wcpg-checklist__counts' ).textContent
		).toBe( '3 required · 2 warnings' );
		expect(
			container.querySelector( '.sit-wcpg-checklist__ring--fail' )
		).not.toBeNull();
	} );

	it( 'colours the ring by state', () => {
		const { container, rerender } = render(
			<ChecklistSummary
				result={ result( {
					evaluated: 3,
					passed: 2,
					failed: 0,
					warnings: 1,
				} ) }
				loading={ false }
			/>
		);

		expect(
			container.querySelector( '.sit-wcpg-checklist__ring--warning' )
		).not.toBeNull();
		expect(
			container.querySelector( '.sit-wcpg-checklist__state' ).textContent
		).toBe( 'Ready to publish' );
		expect(
			container.querySelector( '.sit-wcpg-checklist__counts' ).textContent
		).toBe( '1 warning' );

		rerender(
			<ChecklistSummary
				result={ result( {
					evaluated: 3,
					passed: 3,
					failed: 0,
					warnings: 0,
				} ) }
				loading={ false }
			/>
		);

		expect(
			container.querySelector( '.sit-wcpg-checklist__ring--pass' )
		).not.toBeNull();
		expect(
			container.querySelector( '.sit-wcpg-checklist__counts' ).textContent
		).toBe( 'All checks passed' );
	} );

	it( 'shows a checking state before the first result', () => {
		const { container } = render(
			<ChecklistSummary result={ null } loading={ true } />
		);

		expect(
			container.querySelector( '.sit-wcpg-checklist__state' ).textContent
		).toBe( 'Checking…' );
	} );
} );

describe( 'ChecklistFooter', () => {
	it( 're-check button calls onRecheck', () => {
		const onRecheck = jest.fn();
		const { container } = render(
			<ChecklistFooter
				checkedAt={ null }
				error={ null }
				onRecheck={ onRecheck }
			/>
		);

		fireEvent.click(
			container.querySelector( '.sit-wcpg-checklist__recheck' )
		);

		expect( onRecheck ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says the panel updates as you type', () => {
		const { container } = render(
			<ChecklistFooter
				checkedAt={ 1700000000000 }
				error={ null }
				onRecheck={ () => {} }
			/>
		);

		const time = container.querySelector( '.sit-wcpg-checklist__time' );

		expect( time.textContent ).toMatch(
			/^Checked at .+ · updates as you type$/
		);
	} );
} );
