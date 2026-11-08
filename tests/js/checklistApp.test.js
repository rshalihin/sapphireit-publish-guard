/**
 * ChecklistApp: the wiring between the field watcher and useValidation.
 */

import { act, render } from '@testing-library/react';
import { createElement } from '@wordpress/element';

import { ChecklistApp } from '../../assets/js/editor/components/ChecklistApp';
import { createClient } from '../../assets/js/editor/api';
import { watchFields } from '../../assets/js/editor/field-watcher';
import { FLOOR_MS } from '../../assets/js/editor/hooks/useValidation';

jest.mock( '../../assets/js/editor/api', () => ( {
	...jest.requireActual( '../../assets/js/editor/api' ),
	createClient: jest.fn(),
} ) );

jest.mock( '../../assets/js/editor/field-watcher', () => ( {
	watchFields: jest.fn(),
} ) );

/*
 * The real package pulls in an ESM-only dependency Jest can't load as CommonJS, and this
 * test is about wiring, not markup: plain elements stand in for the components used.
 */
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

const RESULT = {
	is_ready: false,
	results: [],
	counts: { evaluated: 1, passed: 0, warnings: 0, failed: 1, skipped: 0 },
	generated_at: 1700000000,
};

const DRAFT = { title: 'Blue mug' };

describe( 'ChecklistApp', () => {
	let client;
	let watcher;

	beforeEach( () => {
		jest.useFakeTimers();

		const pending = [];

		client = {
			pending,
			validate: jest.fn(
				() =>
					new Promise( ( resolve, reject ) =>
						pending.push( { resolve, reject } )
					)
			),
			abort: jest.fn(),
		};
		createClient.mockReturnValue( client );

		watcher = {
			args: null,
			stop: jest.fn(),
			snapshotNow: jest.fn( () => ( {
				draft: DRAFT,
				signature: '{}',
			} ) ),
		};
		watchFields.mockImplementation( ( args ) => {
			watcher.args = args;

			return watcher;
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.clearAllMocks();
	} );

	it( 'an external change honours the 1500 ms floor', async () => {
		render(
			<ChecklistApp
				data={ { restPath: '/products/12/validate', result: RESULT } }
			/>
		);

		// A first check completes, which starts the floor.
		act( () => watcher.args.onSnapshot( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( RESULT ) );

		act( () => watcher.args.onExternalChange() );

		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( FLOOR_MS - 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 2 );
		expect( client.validate ).toHaveBeenLastCalledWith( DRAFT );
		expect( watcher.snapshotNow ).toHaveBeenCalled();
	} );
} );
