import { createElement, Fragment } from '@wordpress/element';
import { VisuallyHidden } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { Icon } from '../../shared/icons';

/**
 * Icon per status, from the shared set (UI decision U5). The PHP fallback
 * (`Editor_Meta_Box::STATUS_ICONS`) draws the same names, so the panel does not change
 * shape when React takes over.
 */
export const STATUS_ICONS = {
	pass: 'check',
	warning: 'alert',
	fail: 'cross',
	skipped: 'ban',
};

/**
 * The spoken equivalent of each icon: status is never conveyed by a symbol or a colour
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
 * A status dot with its visually hidden label.
 *
 * @param {Object} props        Props.
 * @param {string} props.status One of pass | warning | fail | skipped.
 */
export function StatusIcon( { status } ) {
	const known = Object.prototype.hasOwnProperty.call( STATUS_ICONS, status )
		? status
		: 'skipped';

	return (
		<>
			<span
				className={ `sit-wcpg-checklist__icon sit-wcpg-checklist__icon--${ known }` }
				aria-hidden="true"
			>
				<Icon name={ STATUS_ICONS[ known ] } size={ 12 } />
			</span>
			<VisuallyHidden>{ statusLabel( status ) }</VisuallyHidden>
		</>
	);
}
