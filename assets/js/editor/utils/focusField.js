/**
 * Take the merchant to the field a rule's fix target names.
 *
 * Opens the WooCommerce product-data tab first when the target lives in one, opens a
 * collapsed meta box, then scrolls to and focuses the element. It never navigates:
 * the editor may hold unsaved changes.
 */

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Whether a node is rendered.
 *
 * @param {Element} node Node.
 * @return {boolean} True when visible.
 */
function isVisible( node ) {
	return !! (
		node.offsetWidth ||
		node.offsetHeight ||
		node.getClientRects().length
	);
}

/**
 * Open the product-data tab a field lives in.
 *
 * WooCommerce renders one `li.{panel}_options` per tab; clicking its link is exactly
 * what the merchant would do, so WooCommerce's own handler shows the panel.
 *
 * @param {Document} doc   Document.
 * @param {string}   panel Panel key, e.g. `general` or `inventory`.
 */
function openPanel( doc, panel ) {
	if ( ! /^[a-z0-9_-]+$/i.test( panel ) ) {
		return;
	}

	const tab = doc.querySelector(
		`#woocommerce-product-data .${ panel }_options > a`
	);

	if ( tab ) {
		tab.click();
	}
}

/**
 * Focus a fix target.
 *
 * @param {Object}   fix          The rule's `fix` array.
 * @param {string}   fix.selector Element selector.
 * @param {string}   [fix.panel]  Product-data panel key.
 * @param {Document} [doc]        Document.
 * @param {Window}   [win]        Window.
 * @return {boolean} Whether an element was found.
 */
export function focusField(
	{ selector, panel = '' },
	doc = document,
	win = window
) {
	if ( ! selector ) {
		return false;
	}

	let target = null;

	try {
		target = doc.querySelector( selector );
	} catch {
		// A malformed selector from a third-party rule must not break the panel.
		return false;
	}

	if ( ! target ) {
		return false;
	}

	if ( panel ) {
		openPanel( doc, panel );
	}

	const postbox = target.closest( '.postbox.closed' );

	if ( postbox ) {
		postbox.classList.remove( 'closed' );
	}

	// The description textarea is hidden while the visual editor is showing.
	const editor =
		win.tinymce && win.tinymce.get && target.id
			? win.tinymce.get( target.id )
			: null;

	if ( editor && ! editor.isHidden() ) {
		editor.getContainer().scrollIntoView( { block: 'center' } );
		editor.focus();

		return true;
	}

	let focusable = target;

	if ( ! target.matches( FOCUSABLE ) || ! isVisible( target ) ) {
		focusable =
			Array.from( target.querySelectorAll( FOCUSABLE ) ).find(
				isVisible
			) || null;
	}

	target.scrollIntoView( { block: 'center' } );

	if ( focusable ) {
		focusable.focus( { preventScroll: true } );
	}

	return true;
}
