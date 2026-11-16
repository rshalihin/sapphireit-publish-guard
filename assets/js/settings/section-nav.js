/**
 * Section nav of the settings form (UI decision U8): one section at a time.
 *
 * Without this script every section shows and the nav links are plain anchors. With it,
 * the section named by the URL hash shows (default: the first one); the others carry the
 * `hidden` attribute but stay inside the form, so every field still submits.
 *
 * Which section opens, in order:
 * 1. the one holding the field the server reported (`data-sit-wcpg-invalid` on the form);
 * 2. the one named by the hash;
 * 3. the first.
 *
 * The section is also written into the form's `_wp_http_referer`, which `options.php`
 * redirects back to, so the same section is open after a save.
 */

const CURRENT = 'sit-wcpg-ui-nav__item--current';

/**
 * @typedef {Object} SectionNav
 * @property {(id: string, options?: {focus?: boolean}) => HTMLElement} show         Show a section.
 * @property {() => HTMLElement|null}                                   current      The open section.
 * @property {() => void}                                               focusCurrent Focus the open section's heading.
 * @property {() => void}                                               destroy      Remove the listeners.
 */

/**
 * Show one section at a time.
 *
 * @param {Object}          elements      Elements.
 * @param {HTMLFormElement} elements.form The settings form.
 * @param {HTMLElement}     elements.nav  The section nav.
 * @return {SectionNav|null} The nav, or null without sections.
 */
export function initSectionNav( { form, nav } ) {
	const sections = Array.from(
		form.querySelectorAll( '[data-sit-wcpg-section]' )
	);

	if ( ! sections.length ) {
		return null;
	}

	const links = nav
		? Array.from( nav.querySelectorAll( 'a[href^="#"]' ) )
		: [];
	const referer = form.querySelector( 'input[name="_wp_http_referer"]' );
	let active = null;
	let invalidHandled = false;

	const find = ( id ) =>
		sections.find( ( section ) => section.id === id ) || null;

	const heading = ( section ) =>
		section.querySelector( 'h2, h3, [id$="-title"]' );

	const focusCurrent = () => {
		const target = active && heading( active );

		if ( ! target ) {
			return;
		}

		if ( ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}

		target.focus();
	};

	const show = ( id, { focus = false } = {} ) => {
		const section = find( id ) || sections[ 0 ];

		active = section;

		sections.forEach( ( candidate ) => {
			candidate.hidden = candidate !== section;
		} );

		links.forEach( ( link ) => {
			const isCurrent = link.getAttribute( 'href' ) === '#' + section.id;

			link.classList.toggle( CURRENT, isCurrent );

			if ( isCurrent ) {
				link.setAttribute( 'aria-current', 'true' );
			} else {
				link.removeAttribute( 'aria-current' );
			}
		} );

		if ( referer ) {
			referer.value =
				referer.value.replace( /#.*$/, '' ) + '#' + section.id;
		}

		if ( focus ) {
			focusCurrent();
		}

		return section;
	};

	const fromHash = () =>
		decodeURIComponent( window.location.hash.replace( /^#/, '' ) );

	const onClick = ( event ) => {
		const link = event.target.closest( 'a[href^="#"]' );

		if ( ! link || ! links.includes( link ) ) {
			return;
		}

		const section = find( link.getAttribute( 'href' ).slice( 1 ) );

		if ( ! section ) {
			return;
		}

		event.preventDefault();
		window.history.replaceState( null, '', '#' + section.id );
		show( section.id, { focus: true } );
	};

	const onHashChange = () => {
		if ( find( fromHash() ) ) {
			show( fromHash(), { focus: true } );
		}
	};

	// The browser cannot focus an invalid field inside a hidden section: open it first.
	const onInvalid = ( event ) => {
		if ( invalidHandled ) {
			return;
		}

		const section = event.target.closest( '[data-sit-wcpg-section]' );

		invalidHandled = true;
		window.setTimeout( () => {
			invalidHandled = false;
		}, 0 );

		if ( section && section.hidden ) {
			show( section.id );
		}
	};

	const invalid = form.dataset.sitWcpgInvalid
		? form.ownerDocument.getElementById( form.dataset.sitWcpgInvalid )
		: null;
	const invalidSection =
		invalid && form.contains( invalid )
			? invalid.closest( '[data-sit-wcpg-section]' )
			: null;

	show( invalidSection ? invalidSection.id : fromHash() );

	if ( nav ) {
		nav.addEventListener( 'click', onClick );
	}

	window.addEventListener( 'hashchange', onHashChange );
	form.addEventListener( 'invalid', onInvalid, true );

	return {
		show,
		current: () => active,
		focusCurrent,
		destroy() {
			if ( nav ) {
				nav.removeEventListener( 'click', onClick );
			}

			window.removeEventListener( 'hashchange', onHashChange );
			form.removeEventListener( 'invalid', onInvalid, true );
		},
	};
}
