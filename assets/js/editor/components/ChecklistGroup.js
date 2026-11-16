import { createElement } from '@wordpress/element';
import { _n, sprintf } from '@wordpress/i18n';

import { ChecklistItem } from './ChecklistItem';

/**
 * The count shown beside a group heading in the failing view: failures win over
 * warnings, because they are what blocks publishing.
 *
 * @param {Object[]} items Rule_Result arrays.
 * @return {{tone: string, text: string}|null} The count, or null when every row passed.
 */
export function groupCount( items ) {
	const failed = items.filter( ( item ) => 'fail' === item.status ).length;
	const warnings = items.filter(
		( item ) => 'warning' === item.status
	).length;

	if ( failed > 0 ) {
		return {
			tone: 'fail',
			text: sprintf(
				/* translators: %d: number of required checks failing in this group. */
				_n(
					'%d failing',
					'%d failing',
					failed,
					'sapphireit-publish-guard'
				),
				failed
			),
		};
	}

	if ( warnings > 0 ) {
		return {
			tone: 'warning',
			text: sprintf(
				/* translators: %d: number of warnings in this group. */
				_n(
					'%d warning',
					'%d warnings',
					warnings,
					'sapphireit-publish-guard'
				),
				warnings
			),
		};
	}

	return null;
}

/**
 * A group heading and its rows.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.label     Translated group label.
 * @param {Object[]} props.items     Rule_Result arrays in display order.
 * @param {boolean}  props.showCount Whether to show the "1 failing" count.
 */
export function ChecklistGroup( { label, items, showCount = false } ) {
	const count = showCount ? groupCount( items ) : null;

	return (
		<div className="sit-wcpg-checklist__section">
			<h4 className="sit-wcpg-checklist__group">
				<span className="sit-wcpg-checklist__group-label">
					{ label }
				</span>
				{ count && (
					<span
						className={ `sit-wcpg-checklist__group-count sit-wcpg-checklist__group-count--${ count.tone }` }
					>
						{ count.text }
					</span>
				) }
			</h4>
			<ul className="sit-wcpg-checklist__items">
				{ items.map( ( item ) => (
					<ChecklistItem key={ item.rule_id } item={ item } />
				) ) }
			</ul>
		</div>
	);
}
