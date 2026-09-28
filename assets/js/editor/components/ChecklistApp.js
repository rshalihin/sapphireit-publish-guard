import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useMemo,
	useRef,
} from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { createClient } from '../api';
import { watchFields } from '../field-watcher';
import { useValidation } from '../hooks/useValidation';
import {
	installPublishConfirm,
	publishMode,
	warningText,
} from '../publish-guard';
import { ChecklistFooter } from './ChecklistFooter';
import { ChecklistGroup } from './ChecklistGroup';
import { ChecklistItem } from './ChecklistItem';
import { ChecklistSummary } from './ChecklistSummary';

/**
 * Rows grouped for display: known groups in the payload's order, then any group a
 * third-party rule invented, with skipped rows pulled out to go last.
 *
 * @param {Object[]} results Rule_Result arrays in display order.
 * @param {Object}   labels  Translated group labels keyed by group, in display order.
 * @return {{groups: Array, skipped: Object[]}} Grouped rows.
 */
function groupResults( results, labels ) {
	const byGroup = {};
	const skipped = [];

	results.forEach( ( item ) => {
		if ( 'skipped' === item.status ) {
			skipped.push( item );

			return;
		}

		( byGroup[ item.group ] = byGroup[ item.group ] || [] ).push( item );
	} );

	const order = [
		...Object.keys( labels ),
		...Object.keys( byGroup ).filter( ( group ) => ! ( group in labels ) ),
	];

	const groups = order
		.filter( ( group ) => byGroup[ group ] )
		.map( ( group ) => ( {
			key: group,
			label: labels[ group ] || group,
			items: byGroup[ group ],
		} ) );

	return { groups, skipped };
}

/**
 * The panel's composition root: wires the field watcher to useValidation and renders
 * whatever result the server last returned. It never decides pass or fail itself.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data The bootstrap payload (`window.sitWcpgEditorData`).
 */
export function ChecklistApp( { data } ) {
	const client = useMemo(
		() => createClient( data.restPath ),
		[ data.restPath ]
	);
	const { state, check } = useValidation( {
		initialResult: data.result,
		client,
	} );

	const watcher = useRef( null );
	const checkRef = useRef( check );

	checkRef.current = check;

	useEffect( () => {
		watcher.current = watchFields( {
			onSnapshot: ( draft ) => checkRef.current( draft ),
		} );

		return () => watcher.current.stop();
	}, [] );

	const { result, status, stale, error, checkedAt } = state;
	const mode = publishMode( data, result );
	const modeRef = useRef( mode );

	modeRef.current = mode;

	// Installed once; reads the latest mode at click time.
	useEffect(
		() => installPublishConfirm( { getMode: () => modeRef.current } ),
		[]
	);

	const recheck = useCallback( () => {
		if ( watcher.current ) {
			check( watcher.current.snapshotNow().draft, { immediate: true } );
		}
	}, [ check ] );

	const loading = 'loading' === status;

	let body = null;

	if ( result && result.results.length === 0 ) {
		body = (
			<p className="sit-wcpg-checklist__empty">
				{ __( 'No checks are enabled.', 'sapphireit-publish-guard' ) }
			</p>
		);
	} else if ( result ) {
		const { groups, skipped } = groupResults(
			result.results,
			data.groups || {}
		);

		body = (
			<>
				{ groups.map( ( group ) => (
					<ChecklistGroup
						key={ group.key }
						label={ group.label }
						items={ group.items }
					/>
				) ) }
				{ skipped.length > 0 && (
					<details className="sit-wcpg-checklist__skipped">
						<summary className="sit-wcpg-checklist__group sit-wcpg-checklist__group--skipped">
							{ sprintf(
								/* translators: %d: number of checks that do not apply to this product. */
								_n(
									'Not applicable (%d)',
									'Not applicable (%d)',
									skipped.length,
									'sapphireit-publish-guard'
								),
								skipped.length
							) }
						</summary>
						<ul className="sit-wcpg-checklist__items sit-wcpg-checklist__items--skipped">
							{ skipped.map( ( item ) => (
								<ChecklistItem
									key={ item.rule_id }
									item={ item }
								/>
							) ) }
						</ul>
					</details>
				) }
			</>
		);
	}

	return (
		<div className="sit-wcpg-checklist__app" aria-busy={ loading }>
			<ChecklistSummary result={ result } loading={ loading } />
			{ mode && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="sit-wcpg-checklist__notice sit-wcpg-checklist__notice--publish"
				>
					{ warningText( mode ) }
				</Notice>
			) }
			<div
				className={
					'sit-wcpg-checklist__body' +
					( stale ? ' sit-wcpg-checklist__body--stale' : '' )
				}
			>
				{ body }
			</div>
			<ChecklistFooter
				checkedAt={ checkedAt }
				error={ 'error' === status ? error : null }
				onRecheck={ recheck }
			/>
		</div>
	);
}
