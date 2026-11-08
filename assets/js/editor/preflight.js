import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The words for the server's pre-flight decision.
 *
 * The decision itself is made in PHP (`Support\Preflight`), so the statement can never
 * disagree with what the guard will do. This only phrases it.
 *
 * @param {Object|undefined} preflight The `preflight` object of a validation result.
 * @return {{kind: string, text: string}|null} Null for a missing or unknown kind.
 */
export function preflightMessage( preflight ) {
	if ( ! preflight ) {
		return null;
	}

	const { kind } = preflight;
	const required = parseInt( preflight.required, 10 ) || 0;
	const warnings = parseInt( preflight.warnings, 10 ) || 0;

	let text;

	switch ( kind ) {
		case 'ready':
			text = __(
				'This product can be published now.',
				'sapphireit-publish-guard'
			);
			break;

		case 'warnings':
			text = sprintf(
				/* translators: %d: number of advisory warnings. */
				_n(
					"This product can be published now. %d warning is advisory and won't stop it.",
					"This product can be published now. %d warnings are advisory and won't stop it.",
					warnings,
					'sapphireit-publish-guard'
				),
				warnings
			);
			break;

		case 'blocked':
			text = sprintf(
				/* translators: %d: number of required checks that fail. */
				_n(
					"This product can't be published yet — %d required check fails. Publishing now saves it as a draft.",
					"This product can't be published yet — %d required checks fail. Publishing now saves it as a draft.",
					required,
					'sapphireit-publish-guard'
				),
				required
			);
			break;

		case 'override':
			text = sprintf(
				/* translators: %d: number of required checks that fail. */
				_n(
					'%d required check fails. You can publish anyway, and the override will be recorded.',
					'%d required checks fail. You can publish anyway, and the override will be recorded.',
					required,
					'sapphireit-publish-guard'
				),
				required
			);
			break;

		case 'advisory':
			text = sprintf(
				/* translators: %d: number of required checks that fail. */
				_n(
					"%d required check fails, but this store doesn't block publishing, so it can still go live.",
					"%d required checks fail, but this store doesn't block publishing, so it can still go live.",
					required,
					'sapphireit-publish-guard'
				),
				required
			);
			break;

		case 'live':
			text =
				0 === required
					? __(
							'This product is live and passes every required check.',
							'sapphireit-publish-guard'
						)
					: sprintf(
							/* translators: %d: number of required checks that fail. */
							_n(
								'This product is live, but %d required check fails. Publish Guard never takes a live product offline.',
								'This product is live, but %d required checks fail. Publish Guard never takes a live product offline.',
								required,
								'sapphireit-publish-guard'
							),
							required
						);
			break;

		default:
			return null;
	}

	return { kind, text };
}
