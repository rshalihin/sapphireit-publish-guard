/**
 * The one network call the panel makes.
 *
 * `@wordpress/api-fetch` adds the `X-WP-Nonce` header through core's nonce middleware,
 * so no credential is handled here.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Error kinds the UI distinguishes.
 *
 * - `abort`   — superseded by a newer request or cancelled; never shown.
 * - `session` — the nonce or the login expired after a long idle; a reload fixes it.
 * - `server`  — anything else; a retry may fix it.
 */
export const ERROR_ABORT = 'abort';
export const ERROR_SESSION = 'session';
export const ERROR_SERVER = 'server';

const SESSION_CODES = [
	'rest_cookie_invalid_nonce',
	'rest_not_logged_in',
	'rest_forbidden',
];

/**
 * Map whatever api-fetch rejected with onto one of the three kinds.
 *
 * @param {unknown} error   The rejection value.
 * @param {boolean} aborted Whether this request's signal was aborted.
 * @return {{kind: string}} The mapped error.
 */
export function toError( error, aborted ) {
	if ( aborted || ( error && error.name === 'AbortError' ) ) {
		return { kind: ERROR_ABORT };
	}

	const status = error && error.data ? error.data.status : 0;
	const code = error && error.code ? String( error.code ) : '';

	if (
		401 === status ||
		403 === status ||
		SESSION_CODES.includes( code ) ||
		code.endsWith( '_forbidden' )
	) {
		return { kind: ERROR_SESSION };
	}

	return { kind: ERROR_SERVER };
}

/**
 * Whether a response has the shape of a validation result.
 *
 * @param {unknown} response Parsed response body.
 * @return {boolean} True for a result.
 */
function isResult( response ) {
	return (
		!! response &&
		Array.isArray( response.results ) &&
		!! response.counts &&
		typeof response.is_ready === 'boolean'
	);
}

/**
 * Create a client bound to one product's validate route.
 *
 * The client owns the AbortController: starting a request aborts the previous one, so
 * there is never more than one request in flight.
 *
 * @param {string}                                path    REST path from the payload's `restPath`.
 * @param {(options: Object) => Promise<unknown>} fetcher api-fetch, injectable for tests.
 * @return {{validate: (draft: Object) => Promise<Object>, abort: () => void}} The client.
 */
export function createClient( path, fetcher = apiFetch ) {
	let controller = null;

	function abort() {
		if ( controller ) {
			controller.abort();
			controller = null;
		}
	}

	function validate( draft ) {
		abort();

		const current = new AbortController();

		controller = current;

		const settle = () => {
			if ( controller === current ) {
				controller = null;
			}
		};

		return fetcher( {
			path,
			method: 'POST',
			data: { draft },
			signal: current.signal,
		} ).then(
			( response ) => {
				settle();

				if ( current.signal.aborted ) {
					throw { kind: ERROR_ABORT };
				}

				if ( ! isResult( response ) ) {
					throw { kind: ERROR_SERVER };
				}

				return response;
			},
			( error ) => {
				settle();

				throw toError( error, current.signal.aborted );
			}
		);
	}

	return { validate, abort };
}
