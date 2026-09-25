/**
 * Client-side publish assistance (coding-plan.md §6.3.5).
 *
 * UX only, never enforcement: the server decides on every save, whatever happens here,
 * and the Publish button is never disabled — hiding the real outcome is exactly the
 * anti-pattern the plan warns about. This only tells the merchant, before they click,
 * what the server is going to do.
 */

import { __ } from '@wordpress/i18n';

/** The advisory applies: publishing will be refused. */
export const MODE_BLOCK = 'block';

/** The advisory applies: publishing will go through on this user's override. */
export const MODE_OVERRIDE = 'override';

/**
 * Which advisory, if any, applies to the current result.
 *
 * None when the guard is off, when the product is already live (enforcement covers
 * transitions into publish only, §6.4) or when no required check fails.
 *
 * @param {Object}      data   The bootstrap payload.
 * @param {Object|null} result The latest Validation_Result array.
 * @return {string|null} MODE_BLOCK, MODE_OVERRIDE or null.
 */
export function publishMode( data, result ) {
	const settings = ( data && data.settings ) || {};

	if ( ! settings.blocksPublishing || 'publish' === data.postStatus ) {
		return null;
	}

	if (
		! result ||
		! Array.isArray( result.required_failure_ids ) ||
		0 === result.required_failure_ids.length
	) {
		return null;
	}

	return settings.canOverride ? MODE_OVERRIDE : MODE_BLOCK;
}

/**
 * The inline warning for a mode.
 *
 * @param {string} mode MODE_BLOCK or MODE_OVERRIDE.
 * @return {string} Translated text.
 */
export function warningText( mode ) {
	return MODE_OVERRIDE === mode
		? __(
				'Required checks are failing. You can still publish, because you are allowed to override this check.',
				'product-publish-guard'
			)
		: __(
				'Required checks are failing. If you publish now, the product will be saved as a draft instead.',
				'product-publish-guard'
			);
}

/**
 * The confirmation prompt for a mode.
 *
 * @param {string} mode MODE_BLOCK or MODE_OVERRIDE.
 * @return {string} Translated text.
 */
export function confirmText( mode ) {
	return MODE_OVERRIDE === mode
		? __(
				'Required checks are failing. Publish this product anyway?',
				'product-publish-guard'
			)
		: __(
				'Required checks are failing, so this product will be saved as a draft instead of being published. Save it anyway?',
				'product-publish-guard'
			);
}

/**
 * Ask before the Publish button submits a product that fails required checks.
 *
 * Listens for the click in the capture phase on the form, so a "Cancel" stops the
 * event before core's post.js click handler greys out the submit buttons, and the
 * form is never submitted.
 *
 * @param {Object}                options           Options.
 * @param {() => string|null}     options.getMode   Current mode, read at click time.
 * @param {(msg:string)=>boolean} [options.confirm] Prompt; defaults to window.confirm.
 * @param {Document}              [options.doc]     Document; injectable for tests.
 * @return {() => void} Removes the listener.
 */
export function installPublishConfirm( {
	getMode,
	confirm = ( message ) => window.confirm( message ), // eslint-disable-line no-alert
	doc = document,
} ) {
	const form = doc.getElementById( 'post' );

	if ( ! form ) {
		return () => {};
	}

	const onClick = ( event ) => {
		const button =
			event.target && event.target.closest
				? event.target.closest( '#publish' )
				: null;

		if ( ! button || ! form.contains( button ) ) {
			return;
		}

		const mode = getMode();

		if ( ! mode || confirm( confirmText( mode ) ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
	};

	form.addEventListener( 'click', onClick, true );

	return () => form.removeEventListener( 'click', onClick, true );
}
