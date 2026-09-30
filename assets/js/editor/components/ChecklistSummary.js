import { createElement } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { StatusIcon } from './StatusIcon';

/**
 * The readiness pill, the server's "n of m checks passed" line and the issue count.
 *
 * The region is `aria-live="polite"`, so a screen reader hears readiness change while
 * the merchant types.
 *
 * @param {Object}      props         Props.
 * @param {Object|null} props.result  Validation_Result array, or null before the first.
 * @param {boolean}     props.loading Whether a request is in flight.
 */
export function ChecklistSummary( { result, loading } ) {
	if ( ! result ) {
		return (
			<p className="sit-wcpg-checklist__summary" aria-live="polite">
				<Spinner />
				<strong className="sit-wcpg-checklist__state">
					{ __( 'Checking…', 'sapphireit-publish-guard' ) }
				</strong>
			</p>
		);
	}

	const state = result.is_ready ? 'pass' : 'fail';
	const issues =
		( parseInt( result.counts.failed, 10 ) || 0 ) +
		( parseInt( result.counts.warnings, 10 ) || 0 );

	return (
		<p
			className={ `sit-wcpg-checklist__summary sit-wcpg-checklist__summary--${ state }` }
			aria-live="polite"
		>
			<StatusIcon status={ state } />{ ' ' }
			<strong className="sit-wcpg-checklist__state">
				{ result.is_ready
					? __( 'Ready to publish', 'sapphireit-publish-guard' )
					: __( 'Not ready to publish', 'sapphireit-publish-guard' ) }
			</strong>
			{ loading && (
				<span className="sit-wcpg-checklist__spinner">
					<Spinner />
				</span>
			) }
			<span className="sit-wcpg-checklist__counts">
				{ result.summary_label }
			</span>
			{ issues > 0 && (
				<span className="sit-wcpg-checklist__issues">
					{ sprintf(
						/* translators: %d: number of checks that did not pass. */
						_n(
							'%d issue',
							'%d issues',
							issues,
							'sapphireit-publish-guard'
						),
						issues
					) }
				</span>
			) }
		</p>
	);
}
