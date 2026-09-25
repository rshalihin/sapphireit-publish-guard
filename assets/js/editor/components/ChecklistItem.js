import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';

import { focusField } from '../utils/focusField';
import { StatusIcon } from './StatusIcon';

/**
 * One checklist row.
 *
 * The message and the fix action appear only when the row did not pass. Every string
 * rendered here comes from the server as plain text and is rendered as a text child, so
 * React escapes it.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item A Rule_Result array.
 */
export function ChecklistItem( { item } ) {
	const { status, label, message, fix } = item;
	const showDetail = 'pass' !== status;
	const hasFix = showDetail && !! fix && !! fix.selector;

	return (
		<li
			className={ `sit-wcpg-checklist__item sit-wcpg-checklist__item--${ status }` }
		>
			<StatusIcon status={ status } />{ ' ' }
			<span className="sit-wcpg-checklist__label">{ label }</span>
			{ showDetail && message && (
				<span className="sit-wcpg-checklist__message">{ message }</span>
			) }
			{ hasFix && (
				<Button
					variant="link"
					className="sit-wcpg-checklist__fix"
					onClick={ () => focusField( fix ) }
				>
					{ fix.label || label }
				</Button>
			) }
		</li>
	);
}
