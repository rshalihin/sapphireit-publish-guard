/**
 * All validation state for the panel.
 *
 * States: `idle` (no result yet), `loading`, `ready`, `error`. The last good result is
 * kept through `loading` and `error` alike — a request in flight marks it `stale` and
 * the panel dims it, but never blanks it.
 */

import { useCallback, useEffect, useReducer, useRef } from '@wordpress/element';

import { ERROR_ABORT, ERROR_SESSION } from '../api';

/**
 * Minimum gap between one request completing and the next starting (section 8.2 rule 4).
 */
export const FLOOR_MS = 1500;

export const REQUEST = 'REQUEST';
export const RESOLVED = 'RESOLVED';
export const FAILED = 'FAILED';
export const ABORTED = 'ABORTED';

/**
 * Initial state from the server-rendered result.
 *
 * @param {Object|null} result The bootstrap result, or null.
 * @return {Object} State.
 */
export function initState( result ) {
	return {
		status: result ? 'ready' : 'idle',
		result: result || null,
		stale: false,
		error: null,
		requestId: 0,
		checkedAt:
			result && result.generated_at ? result.generated_at * 1000 : null,
	};
}

/**
 * The state machine.
 *
 * Every settling action carries the id of the request it settles. An action for any
 * request but the newest is ignored: an aborted request rejects asynchronously, after
 * its successor has already moved the state to `loading`, and must not undo that.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action.
 * @return {Object} Next state.
 */
export function reducer( state, action ) {
	if ( REQUEST === action.type ) {
		return {
			...state,
			status: 'loading',
			stale: null !== state.result,
			requestId: action.id,
		};
	}

	if ( action.id !== state.requestId ) {
		return state;
	}

	switch ( action.type ) {
		case RESOLVED:
			return {
				...state,
				status: 'ready',
				result: action.result,
				stale: false,
				error: null,
				checkedAt: action.at,
			};

		case FAILED:
			return {
				...state,
				status: 'error',
				stale: false,
				error: action.error,
			};

		case ABORTED:
			// Nothing newer replaced it (e.g. the panel unmounted): fall back quietly.
			if ( 'loading' !== state.status ) {
				return state;
			}

			let status = state.result ? 'ready' : 'idle';

			if ( state.error ) {
				status = 'error';
			}

			return { ...state, status, stale: false };

		default:
			return state;
	}
}

/**
 * Drive validation requests for the panel.
 *
 * @param {Object}       args               Arguments.
 * @param {Object|null}  args.initialResult Server-rendered result.
 * @param {Object}       args.client        Client from api.js createClient().
 * @param {() => number} [args.now]         Clock, injectable for tests.
 * @return {{state: Object, check: (draft: Object, options?: {immediate?: boolean}) => void}} State and trigger.
 */
export function useValidation( { initialResult, client, now = Date.now } ) {
	const [ state, dispatch ] = useReducer( reducer, initialResult, initState );

	const nextId = useRef( 0 );
	const lastCompletedAt = useRef( 0 );
	const floorTimer = useRef( null );
	const sessionExpired = useRef( false );

	const start = useCallback(
		( draft ) => {
			nextId.current += 1;

			const id = nextId.current;

			dispatch( { type: REQUEST, id } );

			client.validate( draft ).then(
				( result ) => {
					lastCompletedAt.current = now();
					sessionExpired.current = false;
					dispatch( { type: RESOLVED, id, result, at: now() } );
				},
				( error ) => {
					if ( error && ERROR_ABORT === error.kind ) {
						dispatch( { type: ABORTED, id } );

						return;
					}

					lastCompletedAt.current = now();
					sessionExpired.current =
						!! error && ERROR_SESSION === error.kind;
					dispatch( { type: FAILED, id, error } );
				}
			);
		},
		[ client, now ]
	);

	/**
	 * Request a validation of `draft`.
	 *
	 * Automatic checks honour the 1500 ms floor and stop after the session expired (every
	 * further request would fail the same way). `immediate` — the Re-check button — does
	 * neither.
	 */
	const check = useCallback(
		( draft, { immediate = false } = {} ) => {
			if ( floorTimer.current ) {
				clearTimeout( floorTimer.current );
				floorTimer.current = null;
			}

			if ( immediate ) {
				start( draft );

				return;
			}

			if ( sessionExpired.current ) {
				return;
			}

			const wait = lastCompletedAt.current + FLOOR_MS - now();

			if ( wait > 0 ) {
				floorTimer.current = setTimeout( () => {
					floorTimer.current = null;
					start( draft );
				}, wait );

				return;
			}

			start( draft );
		},
		[ start, now ]
	);

	useEffect(
		() => () => {
			if ( floorTimer.current ) {
				clearTimeout( floorTimer.current );
			}

			client.abort();
		},
		[ client ]
	);

	return { state, check };
}
