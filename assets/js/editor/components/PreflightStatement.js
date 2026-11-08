import { createElement } from '@wordpress/element';

import { preflightMessage } from '../preflight';

/**
 * One sentence saying whether this product can be published, and what happens if it is.
 *
 * @param {Object}           props           Props.
 * @param {Object|undefined} props.preflight The `preflight` object of the latest result.
 */
export function PreflightStatement( { preflight } ) {
	const message = preflightMessage( preflight );

	if ( ! message ) {
		return null;
	}

	return (
		<p
			className={ `sit-wcpg-preflight sit-wcpg-preflight--${ message.kind }` }
			role="status"
		>
			{ message.text }
		</p>
	);
}
