/**
 * SIT-WCPG-E2E-16: `wp sit-wcpg` against a real catalog: `check` names the
 * failing products, `--strict` fails a deployment, `reindex` reports its work.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { fixtures, wp } from './utils';

test.describe( 'E2E-16: WP-CLI', () => {
	test( 'check --fail-only --format=ids prints the failing product', () => {
		const { products } = fixtures();
		const ids = wp( [ 'sit-wcpg', 'check', '--fail-only', '--format=ids' ] )
			.split( /\s+/ )
			.map( Number );

		expect( ids ).toContain( products.e2e16Fail );
		expect( ids ).not.toContain( products.e2e16Pass );
	} );

	test( 'check --strict exits with code 1 and says how many fail', () => {
		const failing = wp( [
			'sit-wcpg',
			'check',
			'--fail-only',
			'--format=count',
		] );
		let failure = null;

		try {
			wp( [ 'sit-wcpg', 'check', '--strict', '--format=count' ] );
		} catch ( error ) {
			failure = error;
		}

		expect( failure?.status ).toBe( 1 );
		expect( failure?.stderr ).toContain(
			`Error: ${ failing } products fail a required check.`
		);
	} );

	test( 'reindex reports every product it rechecked', () => {
		const total = wp( [ 'sit-wcpg', 'check', '--format=count' ] );

		expect( wp( [ 'sit-wcpg', 'reindex' ] ) ).toContain(
			`Success: Rechecked ${ total } products against the current settings.`
		);
	} );
} );
