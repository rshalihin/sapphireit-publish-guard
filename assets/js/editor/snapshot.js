/**
 * Pure DOM → draft conversion, and the signature that decides whether a draft is new.
 *
 * The draft carries exactly the fields listed in coding-plan.md section 8.2, read from
 * the classic product editor through the DOM contract verified in verification-notes.md
 * sections 14–16. Nothing is written to the DOM here, and nothing else is read from it.
 *
 * Values are not validated here: the server whitelists product types and stock
 * statuses and normalizes prices and dates, so the client never decides what is valid.
 */

/**
 * Value of a form control, or null when the control is not on the page.
 *
 * Null matters: a missing control must leave the key out of the draft, so the server
 * falls back to the stored value instead of treating the field as emptied.
 *
 * @param {Document} doc      Document to read from.
 * @param {string}   selector Control selector.
 * @return {string|null} The value, or null.
 */
function valueOf( doc, selector ) {
	const node = doc.querySelector( selector );

	return node && typeof node.value === 'string' ? node.value : null;
}

/**
 * Parse a comma-separated id list into unique positive integers.
 *
 * @param {string} value Raw list.
 * @return {number[]} Ids in first-seen order.
 */
export function parseIdList( value ) {
	const ids = [];

	String( value || '' )
		.split( ',' )
		.forEach( ( part ) => {
			const id = parseInt( part.trim(), 10 );

			if ( id > 0 && ! ids.includes( id ) ) {
				ids.push( id );
			}
		} );

	return ids;
}

/**
 * Ids of the `wp_editor()` instances the draft reads: core's description, and
 * WooCommerce's short description (`wp_editor( …, 'excerpt' )`).
 */
export const EDITOR_IDS = [ 'content', 'excerpt' ];

/**
 * A `wp_editor()` field, from the visual editor when it is showing, else from its
 * textarea — which TinyMCE only syncs on save.
 *
 * The TinyMCE instance is created asynchronously and is null in Text mode, so it is
 * resolved on every call and never cached.
 *
 * @param {Document} doc Document to read from.
 * @param {Window}   win Window that may hold `tinymce`.
 * @param {string}   id  Editor id, one of EDITOR_IDS.
 * @return {string|null} The text, or null when neither editor is present.
 */
function readEditor( doc, win, id ) {
	const editor =
		win.tinymce && win.tinymce.get ? win.tinymce.get( id ) : null;

	if ( editor && ! editor.isHidden() ) {
		return editor.getContent();
	}

	return valueOf( doc, `#${ id }` );
}

/**
 * Tag presence.
 *
 * The classic tag box holds tag *names* in a textarea, and a name typed a moment ago
 * has no id yet, so ids are not available client-side. The only rule reading tags asks
 * how many there are, so each distinct name is sent as a positional placeholder id
 * (`1..n`). Those numbers identify nothing and the server never resolves them — this is
 * the "otherwise by presence" branch of the DOM contract in coding-plan.md section 8.2.
 *
 * @param {Document} doc Document to read from.
 * @param {Window}   win Window that may hold core's tag delimiter.
 * @return {number[]|null} Placeholder ids, or null when there is no tag box.
 */
function readTagPresence( doc, win ) {
	const raw = valueOf( doc, '#tax-input-product_tag' );

	if ( null === raw ) {
		return null;
	}

	const delimiter =
		( win.tagsBoxL10n && win.tagsBoxL10n.tagDelimiter ) || ',';
	const names = [];

	raw.split( delimiter )
		.join( ',' )
		.split( ',' )
		.forEach( ( name ) => {
			const clean = name.trim();

			if ( clean && ! names.includes( clean ) ) {
				names.push( clean );
			}
		} );

	return names.map( ( name, index ) => index + 1 );
}

/**
 * Checked product categories.
 *
 * Both the "All" and the "Most used" tabs carry checkboxes for the same terms, so ids
 * are de-duplicated.
 *
 * @param {Document} doc Document to read from.
 * @return {number[]|null} Term ids, or null when there is no category box.
 */
function readCategories( doc ) {
	const box = doc.querySelector( '#product_catdiv' );

	if ( ! box ) {
		return null;
	}

	const ids = [];

	box.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach(
		( input ) => {
			const id = parseInt( input.value, 10 );

			if ( id > 0 && ! ids.includes( id ) ) {
				ids.push( id );
			}
		}
	);

	return ids;
}

/**
 * Build the draft from the current state of the editor.
 *
 * @param {Document} doc Document to read from.
 * @param {Window}   win Window holding editor globals.
 * @return {Object} The draft; keys whose controls are absent are omitted.
 */
export function buildSnapshot( doc = document, win = window ) {
	const draft = {};
	const set = ( key, value ) => {
		if ( null !== value && undefined !== value ) {
			draft[ key ] = value;
		}
	};

	set( 'title', valueOf( doc, '#title' ) );
	set( 'content', readEditor( doc, win, 'content' ) );
	set( 'excerpt', readEditor( doc, win, 'excerpt' ) );
	set( 'product_type', valueOf( doc, '#product-type' ) );
	set( 'regular_price', valueOf( doc, '#_regular_price' ) );
	set( 'sale_price', valueOf( doc, '#_sale_price' ) );
	set( 'sale_from', valueOf( doc, '#_sale_price_dates_from' ) );
	set( 'sale_to', valueOf( doc, '#_sale_price_dates_to' ) );
	set( 'sku', valueOf( doc, '#_sku' ) );
	set( 'stock_status', valueOf( doc, '#_stock_status' ) );

	const manageStock = doc.querySelector( '#_manage_stock' );

	if ( manageStock ) {
		draft.manage_stock = !! manageStock.checked;
	}

	const quantity = valueOf( doc, '#_stock' );

	if ( null !== quantity ) {
		const parsed = parseInt( quantity, 10 );

		draft.stock_quantity = Number.isNaN( parsed ) ? null : parsed;
	}

	// Core writes -1, not an empty value, when there is no featured image.
	const thumbnail = valueOf( doc, '#_thumbnail_id' );

	if ( null !== thumbnail ) {
		const parsed = parseInt( thumbnail, 10 );

		draft.featured_image_id = parsed > 0 ? parsed : 0;
	}

	const gallery = valueOf( doc, '#product_image_gallery' );

	if ( null !== gallery ) {
		draft.gallery_image_ids = parseIdList( gallery );
	}

	set( 'category_ids', readCategories( doc ) );
	set( 'tag_ids', readTagPresence( doc, win ) );

	return draft;
}

/**
 * Collapse text the way the server measures it: markup removed, whitespace collapsed.
 *
 * Only used for the signature. The server reduces every text field to a length after
 * stripping tags and collapsing whitespace, so a change that only touches markup — the
 * visual editor re-wrapping a paragraph, TinyMCE normalizing on focus — cannot change
 * the result and must not cost a request.
 *
 * @param {string} text Raw text.
 * @return {string} Normalized text.
 */
function normalizeText( text ) {
	return String( text )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
}

const TEXT_FIELDS = [ 'title', 'content', 'excerpt' ];
const ID_LIST_FIELDS = [ 'gallery_image_ids', 'category_ids' ];

/**
 * A stable string that is equal for two drafts iff the server would answer them alike.
 *
 * Keys are sorted, category and gallery ids are compared as sets (their order means
 * nothing to any rule), and text is compared the way the server measures it.
 *
 * @param {Object} draft A draft from buildSnapshot().
 * @return {string} The signature.
 */
export function signature( draft ) {
	const normalized = {};

	Object.keys( draft )
		.sort()
		.forEach( ( key ) => {
			let value = draft[ key ];

			if ( TEXT_FIELDS.includes( key ) ) {
				value = normalizeText( value );
			} else if ( ID_LIST_FIELDS.includes( key ) ) {
				value = [ ...value ].sort( ( a, b ) => a - b );
			} else if ( typeof value === 'string' ) {
				value = value.trim();
			}

			normalized[ key ] = value;
		} );

	return JSON.stringify( normalized );
}
