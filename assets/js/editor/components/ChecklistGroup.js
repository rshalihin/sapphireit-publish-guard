import { createElement, Fragment } from '@wordpress/element';

import { ChecklistItem } from './ChecklistItem';

/**
 * A group heading and its rows.
 *
 * @param {Object}   props       Props.
 * @param {string}   props.label Translated group label.
 * @param {Object[]} props.items Rule_Result arrays in display order.
 */
export function ChecklistGroup( { label, items } ) {
	return (
		<>
			<h4 className="sit-wcpg-checklist__group">{ label }</h4>
			<ul className="sit-wcpg-checklist__items">
				{ items.map( ( item ) => (
					<ChecklistItem key={ item.rule_id } item={ item } />
				) ) }
			</ul>
		</>
	);
}
