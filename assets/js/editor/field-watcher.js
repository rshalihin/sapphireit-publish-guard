/**
 * Watches the classic product editor and emits debounced, de-duplicated snapshots.
 *
 * The only file allowed to use jQuery (coding-plan.md section 10.3): WooCommerce's
 * product data panels and core's editor announce changes through jQuery-only events.
 *
 * Request-avoidance rules owned here (section 8.2):
 * 1. 800 ms trailing debounce;
 * 2. signature dedupe — a snapshot equal to the last one emitted is dropped, except after
 *    a variation event: variations are stored, not drafted, so the signature can't see them;
 * 5. watching pauses while the tab is hidden and stops for good on form submit.
 * The single in-flight request (3) is api.js's; the 1500 ms floor (4) is useValidation's.
 */

import $ from 'jquery';

import { EDITOR_IDS, buildSnapshot, signature } from './snapshot';

export const DEBOUNCE_MS = 800;

/**
 * Controls whose own `input`/`change` events describe a draft change.
 *
 * Bound by delegation on the post form, so WooCommerce showing, hiding or re-rendering
 * a panel can never leave a stale binding behind.
 */
const DELEGATED_FIELDS = [
	'#title',
	'#content',
	'#excerpt',
	'#product_catdiv input',
	'#woocommerce-product-data :input',
].join( ', ' );

/**
 * Containers whose children are replaced wholesale by AJAX: the featured-image box
 * (which destroys and recreates `#_thumbnail_id`), the gallery list, the tag list, and
 * the category list (a newly added category arrives already checked, with no event).
 * The containers are observed, never the inputs inside them.
 */
const OBSERVED_CONTAINERS = [
	'#postimagediv',
	'#woocommerce-product-images',
	'#tagsdiv-product_tag .tagchecklist',
	'#product_catchecklist',
];

/**
 * WooCommerce's variation events, fired on (or bubbling up to) `#woocommerce-product-data`
 * once variations are stored (R4). Bulk variation actions and "Generate variations" end in
 * `load_variations()`, which fires `woocommerce_variations_loaded`; so do opening the tab
 * and paging, which only cost a debounced, floored re-check.
 */
const VARIATION_EVENTS = [
	'woocommerce_variations_saved',
	'woocommerce_variations_added',
	'woocommerce_variations_removed',
	'woocommerce_variations_loaded',
];

/**
 * Start watching.
 *
 * @param {Object}                                     args                    Arguments.
 * @param {(draft: Object, signature: string) => void} args.onSnapshot         Called for each new draft.
 * @param {() => void}                                 [args.onExternalChange] Called when stored data the draft
 *                                                                             doesn't carry (variations) changed.
 * @param {Document}                                   [args.doc]              Document.
 * @param {Window}                                     [args.win]              Window.
 * @return {{stop: () => void, snapshotNow: () => {draft: Object, signature: string}}} Controls.
 */
export function watchFields( {
	onSnapshot,
	onExternalChange = () => {},
	doc = document,
	win = window,
} ) {
	const namespace = '.sitWcpgWatcher';
	const $doc = $( doc );
	const $form = $( '#post', doc );
	const $productData = $( '#woocommerce-product-data', doc );
	const observers = [];
	const boundEditors = new WeakSet();

	let timer = null;
	let stopped = false;

	/*
	 * Set by a variation event. The snapshot carries no variation fields, so signature
	 * dedupe would drop the change: this forces a re-check through it.
	 */
	let externalPending = false;

	/*
	 * The baseline is the editor as it was painted, which is what the server-rendered
	 * result describes — so merely opening a product sends nothing.
	 */
	let lastSignature = signature( buildSnapshot( doc, win ) );

	function flush() {
		timer = null;

		if ( stopped || 'hidden' === doc.visibilityState ) {
			return;
		}

		const draft = buildSnapshot( doc, win );
		const next = signature( draft );

		if ( next !== lastSignature ) {
			// A new draft is validated against the stored variations anyway: one request.
			externalPending = false;
			lastSignature = next;
			onSnapshot( draft, next );

			return;
		}

		if ( externalPending ) {
			externalPending = false;
			onExternalChange();
		}
	}

	function schedule() {
		if ( stopped ) {
			return;
		}

		if ( timer ) {
			clearTimeout( timer );
		}

		timer = setTimeout( flush, DEBOUNCE_MS );
	}

	function scheduleExternal() {
		if ( stopped ) {
			return;
		}

		externalPending = true;
		schedule();
	}

	/**
	 * Bind the visual editor. It may already exist at mount or arrive later, and core
	 * announces both cases, so every instance is bound at most once.
	 *
	 * @param {Object} editor TinyMCE editor.
	 */
	function bindEditor( editor ) {
		if (
			! editor ||
			! EDITOR_IDS.includes( editor.id ) ||
			boundEditors.has( editor )
		) {
			return;
		}

		boundEditors.add( editor );
		editor.on( 'input change keyup SetContent', schedule );
	}

	$form.on(
		`input${ namespace } change${ namespace }`,
		DELEGATED_FIELDS,
		schedule
	);

	// WooCommerce clears the sale dates without firing any event.
	$form.on( `click${ namespace }`, '.cancel_sale_schedule', schedule );

	// A type change alters which rules apply.
	$( doc.body ).on(
		`woocommerce-product-type-change${ namespace }`,
		schedule
	);

	// Variations are stored by AJAX, never read from the draft (D3).
	$productData.on(
		VARIATION_EVENTS.map( ( event ) => event + namespace ).join( ' ' ),
		scheduleExternal
	);

	$doc.on( `tinymce-editor-init${ namespace }`, ( event, editor ) =>
		bindEditor( editor )
	);

	if ( win.tinymce && win.tinymce.get ) {
		EDITOR_IDS.forEach( ( id ) => bindEditor( win.tinymce.get( id ) ) );
	}

	if ( win.MutationObserver ) {
		OBSERVED_CONTAINERS.forEach( ( selector ) => {
			const node = doc.querySelector( selector );

			if ( node ) {
				const observer = new win.MutationObserver( schedule );

				observer.observe( node, { childList: true, subtree: true } );
				observers.push( observer );
			}
		} );
	}

	function onVisibility() {
		if ( 'hidden' === doc.visibilityState ) {
			if ( timer ) {
				clearTimeout( timer );
				timer = null;
			}

			return;
		}

		// Catch up on anything that changed while hidden; dedupe drops a no-op.
		schedule();
	}

	doc.addEventListener( 'visibilitychange', onVisibility );

	function stop() {
		if ( stopped ) {
			return;
		}

		stopped = true;
		externalPending = false;

		if ( timer ) {
			clearTimeout( timer );
			timer = null;
		}

		$form.off( namespace );
		$productData.off( namespace );
		$( doc.body ).off( namespace );
		$doc.off( namespace );
		observers.forEach( ( observer ) => observer.disconnect() );
		doc.removeEventListener( 'visibilitychange', onVisibility );

		/*
		 * TinyMCE has no namespaced unbinding; `stopped` turns its handler into a no-op,
		 * and the page is about to unload anyway.
		 */
	}

	// The page is leaving; a request now would only be aborted by the navigation.
	$form.on( `submit${ namespace }`, stop );

	/**
	 * Snapshot the editor right now, for the Re-check button.
	 *
	 * Records the signature, so the debounced path does not send the same draft again.
	 *
	 * @return {{draft: Object, signature: string}} The draft and its signature.
	 */
	function snapshotNow() {
		if ( timer ) {
			clearTimeout( timer );
			timer = null;
		}

		// The caller checks this draft against the stored variations: nothing is left over.
		externalPending = false;

		const draft = buildSnapshot( doc, win );

		lastSignature = signature( draft );

		return { draft, signature: lastSignature };
	}

	return { stop, snapshotNow };
}
