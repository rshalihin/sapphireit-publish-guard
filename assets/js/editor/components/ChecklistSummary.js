import { createElement } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Ring geometry, in the 64 × 64 viewBox. The PHP fallback
 * (`Editor_Meta_Box::RING_RADIUS`) draws the same ring.
 */
const RING_RADIUS = 27;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

/**
 * Integer count from a result's `counts`.
 *
 * @param {Object} counts Result counts.
 * @param {string} key    Count name.
 * @return {number} The count, 0 when missing.
 */
const count = ( counts, key ) => parseInt( counts[ key ], 10 ) || 0;

/**
 * The ring's tone: any required failure, else any warning, else all passed.
 *
 * @param {Object} counts Result counts.
 * @return {string} fail | warning | pass.
 */
export function ringTone( counts ) {
	if ( count( counts, 'failed' ) > 0 ) {
		return 'fail';
	}

	return count( counts, 'warnings' ) > 0 ? 'warning' : 'pass';
}

/**
 * "3 required · 2 warnings", or "All checks passed".
 *
 * @param {Object} counts Result counts.
 * @return {string} Translated counts line.
 */
export function countsLine( counts ) {
	const failed = count( counts, 'failed' );
	const warnings = count( counts, 'warnings' );
	const parts = [];

	if ( failed > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of required checks that fail. */
				_n(
					'%d required',
					'%d required',
					failed,
					'sapphireit-publish-guard'
				),
				failed
			)
		);
	}

	if ( warnings > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of checks with a warning. */
				_n(
					'%d warning',
					'%d warnings',
					warnings,
					'sapphireit-publish-guard'
				),
				warnings
			)
		);
	}

	if ( parts.length === 0 ) {
		return count( counts, 'evaluated' ) > 0
			? __( 'All checks passed', 'sapphireit-publish-guard' )
			: __( 'No checks apply', 'sapphireit-publish-guard' );
	}

	if ( parts.length === 1 ) {
		return parts[ 0 ];
	}

	return sprintf(
		/* translators: 1: e.g. "3 required", 2: e.g. "2 warnings". */
		__( '%1$s · %2$s', 'sapphireit-publish-guard' ),
		parts[ 0 ],
		parts[ 1 ]
	);
}

/**
 * The score ring: passed of applicable, read out as the server's summary label.
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Validation_Result array.
 */
function ScoreRing( { result } ) {
	const passed = count( result.counts, 'passed' );
	const applicable = count( result.counts, 'evaluated' );
	const share = applicable > 0 ? Math.min( 1, passed / applicable ) : 0;
	const dash = ( share * RING_CIRCUMFERENCE ).toFixed( 2 );
	const label =
		result.summary_label ||
		sprintf(
			/* translators: 1: number of checks that passed, 2: number of checks evaluated. */
			_n(
				'%1$d of %2$d check passed',
				'%1$d of %2$d checks passed',
				applicable,
				'sapphireit-publish-guard'
			),
			passed,
			applicable
		);

	return (
		<span
			className={ `sit-wcpg-checklist__ring sit-wcpg-checklist__ring--${ ringTone(
				result.counts
			) }` }
		>
			<svg
				width="64"
				height="64"
				viewBox="0 0 64 64"
				role="img"
				aria-label={ label }
				focusable="false"
			>
				<circle
					className="sit-wcpg-checklist__ring-track"
					cx="32"
					cy="32"
					r={ RING_RADIUS }
				/>
				{ passed > 0 && (
					<circle
						className="sit-wcpg-checklist__ring-bar"
						cx="32"
						cy="32"
						r={ RING_RADIUS }
						strokeDasharray={ `${ dash } ${ RING_CIRCUMFERENCE.toFixed(
							2
						) }` }
						transform="rotate(-90 32 32)"
					/>
				) }
			</svg>
			<span className="sit-wcpg-checklist__ring-value" aria-hidden="true">
				{ `${ passed }/${ applicable }` }
			</span>
		</span>
	);
}

/**
 * The score ring, the readiness state line and the counts.
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
			<div className="sit-wcpg-checklist__summary" aria-live="polite">
				<Spinner />
				<strong className="sit-wcpg-checklist__state">
					{ __( 'Checking…', 'sapphireit-publish-guard' ) }
				</strong>
			</div>
		);
	}

	const state = result.is_ready ? 'pass' : 'fail';

	return (
		<div
			className={ `sit-wcpg-checklist__summary sit-wcpg-checklist__summary--${ state }` }
			aria-live="polite"
		>
			<ScoreRing result={ result } />
			<span className="sit-wcpg-checklist__headline">
				<strong className="sit-wcpg-checklist__state">
					{ result.is_ready
						? __( 'Ready to publish', 'sapphireit-publish-guard' )
						: __(
								'Not ready to publish',
								'sapphireit-publish-guard'
							) }
				</strong>
				<span className="sit-wcpg-checklist__counts">
					{ countsLine( result.counts ) }
				</span>
			</span>
			{ loading && (
				<span className="sit-wcpg-checklist__spinner">
					<Spinner />
				</span>
			) }
		</div>
	);
}
