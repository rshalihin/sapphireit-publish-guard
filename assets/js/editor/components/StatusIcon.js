import { createElement, Fragment } from '@wordpress/element';
import { VisuallyHidden } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Glyphs match the server-rendered fallback, so the panel does not change shape when
 * React takes over.
 */
const GLYPHS = {
	pass: '✓',
	warning: '!',
	fail: '✗',
	skipped: '–',
};

/**
 * The spoken equivalent of each glyph: status is never conveyed by a symbol or a colour
 * alone.
 *
 * @param {string} status Row status.
 * @return {string} Translated label.
 */
export function statusLabel( status ) {
	switch ( status ) {
		case 'pass':
			return __( 'Passed', 'sapphireit-publish-guard' );
		case 'warning':
			return __( 'Warning', 'sapphireit-publish-guard' );
		case 'fail':
			return __( 'Failed', 'sapphireit-publish-guard' );
		default:
			return __( 'Not applicable', 'sapphireit-publish-guard' );
	}
}

/**
 * A status glyph with its visually hidden label.
 *
 * @param {Object} props        Props.
 * @param {string} props.status One of pass | warning | fail | skipped.
 */
export function StatusIcon( { status } ) {
	return (
		<>
			<span className="sit-wcpg-checklist__icon" aria-hidden="true">
				{ GLYPHS[ status ] || GLYPHS.skipped }
			</span>
			<VisuallyHidden>{ statusLabel( status ) }</VisuallyHidden>
		</>
	);
}
