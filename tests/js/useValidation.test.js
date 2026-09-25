/**
 * useValidation: the state machine, the 1500 ms floor, abort and error handling.
 */

import { act, renderHook } from '@testing-library/react';

import {
	ABORTED,
	FAILED,
	FLOOR_MS,
	REQUEST,
	RESOLVED,
	initState,
	reducer,
	useValidation,
} from '../../assets/js/editor/hooks/useValidation';

const RESULT = {
	is_ready: false,
	results: [],
	counts: { evaluated: 1, passed: 0, warnings: 0, failed: 1, skipped: 0 },
	generated_at: 1700000000,
};

const NEWER = { ...RESULT, is_ready: true };

describe( 'reducer', () => {
	it( 'starts ready with the server result, or idle without one', () => {
		expect( initState( RESULT ) ).toMatchObject( {
			status: 'ready',
			result: RESULT,
			stale: false,
			checkedAt: 1700000000000,
		} );
		expect( initState( null ) ).toMatchObject( {
			status: 'idle',
			result: null,
			checkedAt: null,
		} );
	} );

	it( 'keeps the last result visible, marked stale, while loading', () => {
		const state = reducer( initState( RESULT ), { type: REQUEST, id: 1 } );

		expect( state ).toMatchObject( {
			status: 'loading',
			result: RESULT,
			stale: true,
			requestId: 1,
		} );
	} );

	it( 'is not stale when there was nothing to show', () => {
		expect(
			reducer( initState( null ), { type: REQUEST, id: 1 } ).stale
		).toBe( false );
	} );

	it( 'resolves to the new result and clears a previous error', () => {
		let state = reducer( initState( RESULT ), { type: REQUEST, id: 1 } );

		state = reducer( state, {
			type: FAILED,
			id: 1,
			error: { kind: 'server' },
		} );
		state = reducer( state, { type: REQUEST, id: 2 } );
		state = reducer( state, {
			type: RESOLVED,
			id: 2,
			result: NEWER,
			at: 5,
		} );

		expect( state ).toMatchObject( {
			status: 'ready',
			result: NEWER,
			stale: false,
			error: null,
			checkedAt: 5,
		} );
	} );

	it( 'keeps the last good result on error', () => {
		let state = reducer( initState( RESULT ), { type: REQUEST, id: 1 } );

		state = reducer( state, {
			type: FAILED,
			id: 1,
			error: { kind: 'session' },
		} );

		expect( state ).toMatchObject( {
			status: 'error',
			result: RESULT,
			stale: false,
			error: { kind: 'session' },
		} );
	} );

	it( 'ignores a late settle from a superseded request', () => {
		let state = reducer( initState( RESULT ), { type: REQUEST, id: 1 } );

		state = reducer( state, { type: REQUEST, id: 2 } );

		// Request 1 was aborted by request 2 and rejects afterwards.
		expect( reducer( state, { type: ABORTED, id: 1 } ) ).toBe( state );
		expect(
			reducer( state, { type: RESOLVED, id: 1, result: NEWER, at: 1 } )
		).toBe( state );
		expect(
			reducer( state, { type: FAILED, id: 1, error: { kind: 'server' } } )
		).toBe( state );
	} );

	it( 'returns quietly to the previous state when the newest request is aborted', () => {
		const loading = reducer( initState( RESULT ), {
			type: REQUEST,
			id: 1,
		} );

		expect( reducer( loading, { type: ABORTED, id: 1 } ) ).toMatchObject( {
			status: 'ready',
			result: RESULT,
			stale: false,
		} );

		let errored = reducer( loading, {
			type: FAILED,
			id: 1,
			error: { kind: 'server' },
		} );

		errored = reducer( errored, { type: REQUEST, id: 2 } );

		expect( reducer( errored, { type: ABORTED, id: 2 } ).status ).toBe(
			'error'
		);
	} );
} );

/**
 * A client whose requests the test settles by hand.
 *
 * @return {Object} Client with a `pending` list.
 */
function manualClient() {
	const pending = [];

	return {
		pending,
		validate: jest.fn(
			( draft ) =>
				new Promise( ( resolve, reject ) =>
					pending.push( { draft, resolve, reject } )
				)
		),
		abort: jest.fn(),
	};
}

describe( 'useValidation', () => {
	let clock;

	beforeEach( () => {
		jest.useFakeTimers();
		clock = 100000;
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	function setup( client ) {
		return renderHook( () =>
			useValidation( { initialResult: RESULT, client, now: () => clock } )
		);
	}

	it( 'goes loading → ready with the server answer', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );

		expect( client.validate ).toHaveBeenCalledWith( { title: 'a' } );
		expect( result.current.state.status ).toBe( 'loading' );
		expect( result.current.state.stale ).toBe( true );

		await act( async () => client.pending[ 0 ].resolve( NEWER ) );

		expect( result.current.state ).toMatchObject( {
			status: 'ready',
			result: NEWER,
			checkedAt: clock,
		} );
	} );

	it( 'waits out the 1500 ms floor after a completed request', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( NEWER ) );

		clock += 500;
		act( () => result.current.check( { title: 'b' } ) );

		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( FLOOR_MS - 500 - 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 2 );
		expect( client.validate ).toHaveBeenLastCalledWith( { title: 'b' } );
	} );

	it( 'sends only the newest draft queued behind the floor', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( NEWER ) );

		act( () => result.current.check( { title: 'b' } ) );
		act( () => result.current.check( { title: 'c' } ) );
		act( () => jest.advanceTimersByTime( FLOOR_MS ) );

		expect( client.validate ).toHaveBeenCalledTimes( 2 );
		expect( client.validate ).toHaveBeenLastCalledWith( { title: 'c' } );
	} );

	it( 'lets the Re-check button bypass the floor', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( NEWER ) );

		act( () =>
			result.current.check( { title: 'a' }, { immediate: true } )
		);

		expect( client.validate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'stays loading when a superseded request is aborted', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		act( () =>
			result.current.check( { title: 'b' }, { immediate: true } )
		);

		await act( async () =>
			client.pending[ 0 ].reject( { kind: 'abort' } )
		);

		expect( result.current.state.status ).toBe( 'loading' );

		await act( async () => client.pending[ 1 ].resolve( NEWER ) );

		expect( result.current.state.result ).toBe( NEWER );
	} );

	it( 'keeps the last good result on a server error', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () =>
			client.pending[ 0 ].reject( { kind: 'server' } )
		);

		expect( result.current.state ).toMatchObject( {
			status: 'error',
			result: RESULT,
			error: { kind: 'server' },
		} );
	} );

	it( 'stops automatic checks after the session expired, but not Re-check', async () => {
		const client = manualClient();
		const { result } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () =>
			client.pending[ 0 ].reject( { kind: 'session' } )
		);

		clock += FLOOR_MS * 10;
		act( () => result.current.check( { title: 'b' } ) );
		act( () => jest.advanceTimersByTime( FLOOR_MS * 10 ) );

		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () =>
			result.current.check( { title: 'b' }, { immediate: true } )
		);

		expect( client.validate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'aborts the in-flight request and the pending timer on unmount', async () => {
		const client = manualClient();
		const { result, unmount } = setup( client );

		act( () => result.current.check( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( NEWER ) );
		act( () => result.current.check( { title: 'b' } ) );

		unmount();
		jest.advanceTimersByTime( FLOOR_MS );

		expect( client.abort ).toHaveBeenCalled();
		expect( client.validate ).toHaveBeenCalledTimes( 1 );
	} );
} );
