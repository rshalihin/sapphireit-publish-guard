import { createElement } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import { ERROR_SESSION } from '../api';

/**
 * The error notice, if any.
 *
 * A session error asks for a reload, because a retry would fail the same way. Anything
 * else offers a retry. Either way the last good result stays on screen above it.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.error   Error from api.js.
 * @param {() => void} props.onRetry Retry handler.
 */
function ErrorNotice( { error, onRetry } ) {
	if ( ERROR_SESSION === error.kind ) {
		return (
			<Notice
				status="warning"
				isDismissible={ false }
				className="sit-wcpg-checklist__notice"
			>
				{ __(
					'Your session expired — reload the page to continue checking.',
					'sapphireit-publish-guard'
				) }
			</Notice>
		);
	}

	return (
		<Notice
			status="error"
			isDismissible={ false }
			className="sit-wcpg-checklist__notice"
			actions={ [
				{
					label: __( 'Try again', 'sapphireit-publish-guard' ),
					onClick: onRetry,
				},
			] }
		>
			{ __(
				'Could not refresh the checklist.',
				'sapphireit-publish-guard'
			) }
		</Notice>
	);
}

/**
 * Last-checked time, the manual Re-check fallback and the error notice.
 *
 * @param {Object}      props           Props.
 * @param {number|null} props.checkedAt Time of the last good result, in milliseconds.
 * @param {Object|null} props.error     Error from api.js, or null.
 * @param {() => void}  props.onRecheck Re-check handler; while a request is in flight it
 *                                      supersedes that request.
 */
export function ChecklistFooter( { checkedAt, error, onRecheck } ) {
	return (
		<div className="sit-wcpg-checklist__footer">
			{ error && <ErrorNotice error={ error } onRetry={ onRecheck } /> }
			<div className="sit-wcpg-checklist__footer-row">
				<span className="sit-wcpg-checklist__time">
					{ checkedAt
						? sprintf(
								/* translators: %s: time of day the checklist was last checked. */
								__(
									'Checked at %s',
									'sapphireit-publish-guard'
								),
								dateI18n(
									getSettings().formats.time,
									checkedAt
								)
							)
						: '' }
				</span>
				<Button
					variant="secondary"
					size="small"
					className="sit-wcpg-checklist__recheck"
					onClick={ onRecheck }
				>
					{ __( 'Re-check', 'sapphireit-publish-guard' ) }
				</Button>
			</div>
		</div>
	);
}
