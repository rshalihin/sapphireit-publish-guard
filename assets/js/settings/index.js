/**
 * The Product Checklist settings screen (UI decisions U8, U15): section nav, save state,
 * Discard and the leave warning.
 *
 * Loaded only on that screen (`Admin\Assets`). It only enhances a server-rendered
 * Settings API form: without it every section shows and Save submits.
 */

import domReady from '@wordpress/dom-ready';

import { initDirtyState } from './dirty-state';
import { initSectionNav } from './section-nav';

/**
 * Wire the screen up, if this page has the settings form.
 */
export function initSettings() {
	const form = document.querySelector( '[data-sit-wcpg-settings-form]' );

	if ( ! form ) {
		return;
	}

	const sections = initSectionNav( {
		form,
		nav: form.querySelector( '[data-sit-wcpg-section-nav]' ),
	} );

	initDirtyState( form, {
		onDiscard: () => sections && sections.focusCurrent(),
	} );
}

domReady( initSettings );
