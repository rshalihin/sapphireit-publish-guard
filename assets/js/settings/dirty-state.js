/**
 * Save state of the settings form (UI decision U15): clean, dirty or saving, shown in
 * every save bar the form holds, plus Discard and the leave warning.
 *
 * The form is the single source of truth: the state is the form's serialized values
 * compared with the values it loaded with. Discard is `form.reset()`, so it needs no
 * request. Hidden fields (nonce, referer) are not settings and are left out, so the
 * section nav can rewrite the referer without marking the form dirty.
 *
 * Markup the script reads, per bar (`[data-sit-wcpg-savebar]`):
 * - `[data-sit-wcpg-savestate]`: the status (dot + text), `hidden` until this runs;
 * - `[data-sit-wcpg-status]`: the text; the top one is the `aria-live` region, the
 *   bottom one is `aria-hidden`, so the status is announced once;
 * - `[data-sit-wcpg-discard]`: Discard, `hidden` until this runs;
 * - `input[type="submit"]`: Save;
 * - `.spinner`: core's spinner, shown while saving.
 */

import { __ } from '@wordpress/i18n';

export const CLEAN = 'clean';
export const DIRTY = 'dirty';
export const SAVING = 'saving';

const BAR = '[data-sit-wcpg-savebar]';
const MODIFIER = 'sit-wcpg-ui-savebar--';
const SKIPPED = [ 'hidden', 'submit', 'button', 'reset', 'image', 'file' ];

/**
 * The form's settings values as one string, in document order.
 *
 * @param {HTMLFormElement} form The settings form.
 * @return {string} Serialized values.
 */
export function serialize( form ) {
	const pairs = [];

	Array.from( form.elements ).forEach( ( element ) => {
		const { name, type, disabled } = element;

		if ( ! name || disabled || SKIPPED.includes( type ) ) {
			return;
		}

		if (
			( 'checkbox' === type || 'radio' === type ) &&
			! element.checked
		) {
			return;
		}

		pairs.push( name + '=' + element.value );
	} );

	return JSON.stringify( pairs );
}

/**
 * The visible status text for a state.
 *
 * @param {string} state One of CLEAN, DIRTY, SAVING.
 * @return {string} Translated text.
 */
function statusText( state ) {
	if ( SAVING === state ) {
		return __( 'Saving…', 'sapphireit-publish-guard' );
	}

	if ( DIRTY === state ) {
		return __( 'Unsaved changes', 'sapphireit-publish-guard' );
	}

	return __( 'All changes saved', 'sapphireit-publish-guard' );
}

/**
 * @typedef {Object} DirtyState
 * @property {() => string|null} getState The current state.
 * @property {() => void}        update   Recompute the state from the form.
 * @property {() => void}        destroy  Remove the listeners.
 */

/**
 * Track the form's save state.
 *
 * @param {HTMLFormElement} form                The settings form.
 * @param {Object}          [options]           Options.
 * @param {() => void}      [options.onDiscard] Called after Discard, e.g. to move focus
 *                                              off the now-disabled button.
 * @return {DirtyState} The tracker.
 */
export function initDirtyState( form, { onDiscard } = {} ) {
	const bars = Array.from( form.querySelectorAll( BAR ) );
	const initial = serialize( form );
	let state = null;
	let submitting = false;

	const render = ( next, text = statusText( next ) ) => {
		state = next;

		bars.forEach( ( bar ) => {
			bar.dataset.state = next;

			[ CLEAN, DIRTY, SAVING ].forEach( ( candidate ) =>
				bar.classList.toggle( MODIFIER + candidate, candidate === next )
			);

			bar.querySelectorAll(
				'[data-sit-wcpg-discard], input[type="submit"]'
			).forEach( ( button ) => {
				button.disabled = DIRTY !== next;
			} );

			bar.querySelectorAll( '.spinner' ).forEach( ( spinner ) =>
				spinner.classList.toggle( 'is-active', SAVING === next )
			);

			bar.querySelectorAll( '[data-sit-wcpg-status]' ).forEach(
				( node ) => {
					if ( node.textContent !== text ) {
						node.textContent = text;
					}
				}
			);
		} );

		if ( SAVING === next ) {
			form.setAttribute( 'aria-busy', 'true' );
		} else {
			form.removeAttribute( 'aria-busy' );
		}
	};

	const update = () => {
		if ( submitting ) {
			return;
		}

		const next = serialize( form ) === initial ? CLEAN : DIRTY;

		if ( next !== state ) {
			render( next );
		}
	};

	const onSubmit = () => {
		submitting = true;
		render( SAVING );
	};

	const onClick = ( event ) => {
		if ( ! event.target.closest( '[data-sit-wcpg-discard]' ) ) {
			return;
		}

		event.preventDefault();
		form.reset();
		render( CLEAN, __( 'Changes discarded', 'sapphireit-publish-guard' ) );

		if ( onDiscard ) {
			onDiscard();
		}
	};

	const onBeforeUnload = ( event ) => {
		if ( submitting || DIRTY !== state ) {
			return;
		}

		event.preventDefault();
		// Older browsers show the prompt only when returnValue is set.
		event.returnValue = '';
	};

	// Back/forward cache: a page restored after a submit is no longer saving.
	const onPageShow = ( event ) => {
		if ( event.persisted ) {
			submitting = false;
			state = null;
			update();
		}
	};

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );
	form.addEventListener( 'submit', onSubmit );
	form.addEventListener( 'click', onClick );
	window.addEventListener( 'beforeunload', onBeforeUnload );
	window.addEventListener( 'pageshow', onPageShow );

	// Set the text before revealing it, so the live region announces nothing on load.
	update();

	bars.forEach( ( bar ) =>
		bar
			.querySelectorAll(
				'[data-sit-wcpg-savestate], [data-sit-wcpg-discard]'
			)
			.forEach( ( node ) => {
				node.hidden = false;
			} )
	);

	return {
		getState: () => state,
		update,
		destroy() {
			form.removeEventListener( 'input', update );
			form.removeEventListener( 'change', update );
			form.removeEventListener( 'submit', onSubmit );
			form.removeEventListener( 'click', onClick );
			window.removeEventListener( 'beforeunload', onBeforeUnload );
			window.removeEventListener( 'pageshow', onPageShow );
		},
	};
}
