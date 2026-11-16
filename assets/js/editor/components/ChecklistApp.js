import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
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
import { Icon } from '../../shared/icons';
import { readOpen, writeOpen } from '../utils/rememberedOpen';
import { ChecklistFooter } from './ChecklistFooter';
import { ChecklistGroup } from './ChecklistGroup';
import { ChecklistItem } from './ChecklistItem';
import { ChecklistSummary } from './ChecklistSummary';
import { PreflightStatement } from './PreflightStatement';

/**
 * Display rank inside a failing group: required failures first, then warnings.
 */
const ISSUE_RANK = { fail: 0, warning: 1 };

/**
 * Preflight kinds whose statement already says what the publish notice would say
 * (`blocked` ↔ MODE_BLOCK, `override` ↔ MODE_OVERRIDE).
 */
const STATEMENT_COVERS_NOTICE = [ 'blocked', 'override' ];

/**
 * Rows split for display (UI phase U09): the failing view, the passed rows and the rows
 * that do not apply.
 *
 * Both grouped lists keep the editor's groups in the payload's order (core groups, then
 * add-on groups), then any group a third-party rule invented. Inside a failing group the
 * required failures come before the warnings; otherwise the server's order is kept.
 *
 * @param {Object[]} results Rule_Result arrays in display order.
 * @param {Object}   labels  Translated group labels keyed by group, in display order.
 * @return {{failing: Array, passed: Array, passedCount: number, skipped: Object[]}} Rows.
 */
export function partitionResults( results, labels ) {
	const issues = {};
	const passes = {};
	const skipped = [];
	let passedCount = 0;

	results.forEach( ( item ) => {
		if ( 'skipped' === item.status ) {
			skipped.push( item );

			return;
		}

		let bucket = issues;

		if ( 'pass' === item.status ) {
			bucket = passes;
			passedCount++;
		}

		( bucket[ item.group ] = bucket[ item.group ] || [] ).push( item );
	} );

	const order = Object.keys( labels );

	results.forEach( ( item ) => {
		if ( ! order.includes( item.group ) ) {
			order.push( item.group );
		}
	} );

	const toGroups = ( bucket ) =>
		order
			.filter( ( group ) => bucket[ group ] )
			.map( ( group ) => ( {
				key: group,
				label: labels[ group ] || group,
				items: bucket[ group ],
			} ) );

	const rank = ( item ) => ISSUE_RANK[ item.status ] ?? 2;

	const failing = toGroups( issues ).map( ( group ) => ( {
		...group,
		// A stable sort: equal ranks keep the server's order.
		items: group.items
			.map( ( item, index ) => ( { item, index } ) )
			.sort(
				( a, b ) => rank( a.item ) - rank( b.item ) || a.index - b.index
			)
			.map( ( { item } ) => item ),
	} ) );

	return { failing, passed: toGroups( passes ), passedCount, skipped };
}

/**
 * The disclosure chevron: one icon per state (the stylesheet shows the right one).
 */
function Chevron() {
	return (
		<>
			<span
				className="sit-wcpg-checklist__chevron sit-wcpg-checklist__chevron--closed"
				aria-hidden="true"
			>
				<Icon name="chevron-right" size={ 14 } />
			</span>
			<span
				className="sit-wcpg-checklist__chevron sit-wcpg-checklist__chevron--open"
				aria-hidden="true"
			>
				<Icon name="chevron-down" size={ 14 } />
			</span>
		</>
	);
}

/**
 * Storage name of the passed section's open state (see utils/rememberedOpen.js).
 */
const PASSED_OPEN = 'checklist-passed-open';

/**
 * The passed rows, collapsed into one `<details>` whose open state is remembered.
 *
 * @param {Object}  props           Props.
 * @param {Array}   props.groups    Passed rows by group.
 * @param {number}  props.count     Number of passed rows.
 * @param {boolean} props.hasIssues Whether a failing view is shown above it.
 */
function PassedSection( { groups, count, hasIssues } ) {
	const [ open, setOpen ] = useState( () => readOpen( PASSED_OPEN ) );

	const onToggle = ( event ) => {
		const next = event.currentTarget.open;

		if ( next !== open ) {
			setOpen( next );
			writeOpen( PASSED_OPEN, next );
		}
	};

	const label = hasIssues
		? sprintf(
				/* translators: %d: number of checks that passed, listed below the failing ones. */
				_n(
					'%d more passed',
					'%d more passed',
					count,
					'sapphireit-publish-guard'
				),
				count
			)
		: sprintf(
				/* translators: %d: number of checks that passed. */
				_n(
					'%d passed',
					'%d passed',
					count,
					'sapphireit-publish-guard'
				),
				count
			);

	return (
		<details
			className="sit-wcpg-checklist__passed"
			open={ open }
			onToggle={ onToggle }
		>
			<summary className="sit-wcpg-checklist__disclosure sit-wcpg-checklist__disclosure--passed">
				<Chevron />
				{ label }
			</summary>
			{ groups.map( ( group ) => (
				<ChecklistGroup
					key={ group.key }
					label={ group.label }
					items={ group.items }
				/>
			) ) }
		</details>
	);
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
			// Variations changed in storage: re-check, still behind the 1500 ms floor.
			onExternalChange: () =>
				checkRef.current( watcher.current.snapshotNow().draft ),
		} );

		return () => watcher.current.stop();
	}, [] );

	const { result, status, stale, error, checkedAt } = state;
	const mode = publishMode( data, result );
	const modeRef = useRef( mode );

	// The `blocked` and `override` preflight statements already say what the publish
	// notice says, so the notice shows only when no statement covers it. The mode itself
	// (and the confirmation prompt it drives) is unchanged.
	const showPublishNotice =
		Boolean( mode ) &&
		! STATEMENT_COVERS_NOTICE.includes( result?.preflight?.kind );

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
		const { failing, passed, passedCount, skipped } = partitionResults(
			result.results,
			data.groups || {}
		);

		body = (
			<>
				{ failing.length > 0 && (
					<div className="sit-wcpg-checklist__failing">
						{ failing.map( ( group ) => (
							<ChecklistGroup
								key={ group.key }
								label={ group.label }
								items={ group.items }
								showCount
							/>
						) ) }
					</div>
				) }
				{ passedCount > 0 && (
					<PassedSection
						groups={ passed }
						count={ passedCount }
						hasIssues={ failing.length > 0 }
					/>
				) }
				{ skipped.length > 0 && (
					<details className="sit-wcpg-checklist__skipped">
						<summary className="sit-wcpg-checklist__disclosure sit-wcpg-checklist__disclosure--skipped">
							<Chevron />
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
			<PreflightStatement preflight={ result?.preflight } />
			{ showPublishNotice && (
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
