/**
 * Records the merchant's answer to the review request, then removes the notice.
 *
 * Loaded only while the request is due (`Admin\Assets`). "Leave a review" is an ordinary
 * link that opens in a new tab; the two buttons do nothing else. If the request fails the
 * notice still goes away for this page view, and simply comes back on the next one.
 */

import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

/**
 * Attach the click handler to the notice, if this page has one.
 */
export function initReviewNotice() {
	const notice = document.querySelector( '[data-sit-wcpg-review]' );

	if ( ! notice ) {
		return;
	}

	notice.addEventListener( 'click', ( event ) => {
		const control = event.target.closest( '[data-choice]' );

		if ( ! control ) {
			return;
		}

		const { choice } = control.dataset;

		if ( 'review' !== choice ) {
			event.preventDefault();
		}

		const hide = () => notice.remove();

		apiFetch( {
			path: window.sitWcpgReviewNotice.restPath,
			method: 'POST',
			data: { choice },
		} ).then( hide, hide );
	} );
}

domReady( initReviewNotice );
