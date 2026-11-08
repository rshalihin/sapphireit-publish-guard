/**
 * The pre-flight statement: the exact words the server's decision maps to.
 */

import { preflightMessage } from '../../assets/js/editor/preflight';

describe( 'preflightMessage', () => {
	it.each( [
		[ 'ready', 0, 0, 'This product can be published now.' ],
		[
			'warnings',
			0,
			1,
			"This product can be published now. 1 warning is advisory and won't stop it.",
		],
		[
			'warnings',
			0,
			3,
			"This product can be published now. 3 warnings are advisory and won't stop it.",
		],
		[
			'blocked',
			1,
			0,
			"This product can't be published yet — 1 required check fails. Publishing now saves it as a draft.",
		],
		[
			'blocked',
			2,
			0,
			"This product can't be published yet — 2 required checks fail. Publishing now saves it as a draft.",
		],
		[
			'override',
			1,
			0,
			'1 required check fails. You can publish anyway, and the override will be recorded.',
		],
		[
			'override',
			4,
			0,
			'4 required checks fail. You can publish anyway, and the override will be recorded.',
		],
		[
			'advisory',
			1,
			0,
			"1 required check fails, but this store doesn't block publishing, so it can still go live.",
		],
		[
			'advisory',
			2,
			0,
			"2 required checks fail, but this store doesn't block publishing, so it can still go live.",
		],
		[
			'live',
			0,
			0,
			'This product is live and passes every required check.',
		],
		[
			'live',
			1,
			0,
			'This product is live, but 1 required check fails. Publish Guard never takes a live product offline.',
		],
		[
			'live',
			3,
			0,
			'This product is live, but 3 required checks fail. Publish Guard never takes a live product offline.',
		],
	] )( '%s, %i required, %i warnings', ( kind, required, warnings, text ) => {
		expect( preflightMessage( { kind, required, warnings } ) ).toEqual( {
			kind,
			text,
		} );
	} );

	it.each( [ [ undefined ], [ null ], [ {} ], [ { kind: 'bogus' } ] ] )(
		'has nothing to say for %j',
		( preflight ) => {
			expect( preflightMessage( preflight ) ).toBeNull();
		}
	);
} );
