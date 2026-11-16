/**
 * ChecklistApp: the wiring between the field watcher and useValidation.
 */

import { act, fireEvent, render } from '@testing-library/react';
import { createElement } from '@wordpress/element';

import { ChecklistApp } from '../../assets/js/editor/components/ChecklistApp';
import { createClient } from '../../assets/js/editor/api';
import { watchFields } from '../../assets/js/editor/field-watcher';
import { FLOOR_MS } from '../../assets/js/editor/hooks/useValidation';
import { focusField } from '../../assets/js/editor/utils/focusField';

jest.mock( '../../assets/js/editor/utils/focusField', () => ( {
	focusField: jest.fn(),
} ) );

jest.mock( '../../assets/js/editor/api', () => ( {
	...jest.requireActual( '../../assets/js/editor/api' ),
	createClient: jest.fn(),
} ) );

jest.mock( '../../assets/js/editor/field-watcher', () => ( {
	watchFields: jest.fn(),
} ) );

/*
 * The real package pulls in an ESM-only dependency Jest can't load as CommonJS, and this
 * test is about wiring, not markup: plain elements stand in for the components used.
 */
jest.mock( '@wordpress/components', () => {
	const { createElement: el } = jest.requireActual( '@wordpress/element' );
	const passthrough = ( { children } ) => el( 'div', null, children );

	return {
		Button: ( { children, onClick } ) =>
			el( 'button', { type: 'button', onClick }, children ),
		Notice: ( { children, className } ) =>
			el( 'div', { className }, children ),
		Spinner: () => el( 'span' ),
		VisuallyHidden: passthrough,
	};
} );

const RESULT = {
	is_ready: false,
	results: [],
	counts: { evaluated: 1, passed: 0, warnings: 0, failed: 1, skipped: 0 },
	generated_at: 1700000000,
};

const DRAFT = { title: 'Blue mug' };

describe( 'ChecklistApp', () => {
	let client;
	let watcher;

	beforeEach( () => {
		jest.useFakeTimers();

		const pending = [];

		client = {
			pending,
			validate: jest.fn(
				() =>
					new Promise( ( resolve, reject ) =>
						pending.push( { resolve, reject } )
					)
			),
			abort: jest.fn(),
		};
		createClient.mockReturnValue( client );

		watcher = {
			args: null,
			stop: jest.fn(),
			snapshotNow: jest.fn( () => ( {
				draft: DRAFT,
				signature: '{}',
			} ) ),
		};
		watchFields.mockImplementation( ( args ) => {
			watcher.args = args;

			return watcher;
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.clearAllMocks();
	} );

	it( 'an external change honours the 1500 ms floor', async () => {
		render(
			<ChecklistApp
				data={ { restPath: '/products/12/validate', result: RESULT } }
			/>
		);

		// A first check completes, which starts the floor.
		act( () => watcher.args.onSnapshot( { title: 'a' } ) );
		await act( async () => client.pending[ 0 ].resolve( RESULT ) );

		act( () => watcher.args.onExternalChange() );

		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( FLOOR_MS - 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 1 );

		act( () => jest.advanceTimersByTime( 1 ) );
		expect( client.validate ).toHaveBeenCalledTimes( 2 );
		expect( client.validate ).toHaveBeenLastCalledWith( DRAFT );
		expect( watcher.snapshotNow ).toHaveBeenCalled();
	} );

	const BLOCKED = {
		...RESULT,
		preflight: { kind: 'blocked', required: 1, warnings: 0 },
	};

	const READY = {
		...RESULT,
		is_ready: true,
		preflight: { kind: 'ready', required: 0, warnings: 0 },
	};

	const statement = ( container ) =>
		container.querySelector( '.sit-wcpg-preflight' );

	it( 'renders preflight statement from result', () => {
		const { container } = render(
			<ChecklistApp
				data={ { restPath: '/products/12/validate', result: BLOCKED } }
			/>
		);

		expect( statement( container ).textContent ).toBe(
			"This product can't be published yet — 1 required check fails. Publishing now saves it as a draft."
		);
		expect(
			statement( container ).classList.contains(
				'sit-wcpg-preflight--blocked'
			)
		).toBe( true );
		expect( statement( container ).getAttribute( 'role' ) ).toBe(
			'status'
		);
	} );

	it( 'updates statement when a new result arrives', async () => {
		const { container } = render(
			<ChecklistApp
				data={ { restPath: '/products/12/validate', result: BLOCKED } }
			/>
		);

		act( () => watcher.args.onSnapshot( { regular_price: '9' } ) );
		await act( async () => client.pending[ 0 ].resolve( READY ) );

		expect( statement( container ).textContent ).toBe(
			'This product can be published now.'
		);
		expect(
			statement( container ).classList.contains(
				'sit-wcpg-preflight--ready'
			)
		).toBe( true );
	} );

	it( 'renders no statement for a result without one', () => {
		const { container } = render(
			<ChecklistApp
				data={ { restPath: '/products/12/validate', result: RESULT } }
			/>
		);

		expect( statement( container ) ).toBeNull();
	} );

	// The publish notice says what the preflight statement already says for `blocked`
	// and `override`; it shows only when no statement covers it (UI phase U09, V-U15).
	const GUARDED = {
		restPath: '/products/12/validate',
		postStatus: 'draft',
		settings: { blocksPublishing: true, canOverride: false },
	};

	const FAILING = { ...RESULT, required_failure_ids: [ 'price' ] };

	const publishNotice = ( container ) =>
		container.querySelector( '.sit-wcpg-checklist__notice--publish' );

	it( 'does not repeat the publish notice under a blocked statement', () => {
		const { container } = render(
			<ChecklistApp
				data={ {
					...GUARDED,
					result: {
						...FAILING,
						preflight: {
							kind: 'blocked',
							required: 1,
							warnings: 0,
						},
					},
				} }
			/>
		);

		expect( statement( container ) ).not.toBeNull();
		expect( publishNotice( container ) ).toBeNull();
	} );

	it( 'does not repeat the publish notice under an override statement', () => {
		const { container } = render(
			<ChecklistApp
				data={ {
					...GUARDED,
					settings: { blocksPublishing: true, canOverride: true },
					result: {
						...FAILING,
						preflight: {
							kind: 'override',
							required: 1,
							warnings: 0,
						},
					},
				} }
			/>
		);

		expect( statement( container ) ).not.toBeNull();
		expect( publishNotice( container ) ).toBeNull();
	} );

	it( 'shows the publish notice when no statement covers it', () => {
		const { container } = render(
			<ChecklistApp data={ { ...GUARDED, result: FAILING } } />
		);

		expect( statement( container ) ).toBeNull();
		expect( publishNotice( container ).textContent ).toBe(
			'Required checks are failing. If you publish now, the product will be saved as a draft instead.'
		);
	} );
} );

describe( 'ChecklistApp sections', () => {
	const row = ( ruleId, group, status, extra = {} ) => ( {
		rule_id: ruleId,
		group,
		status,
		label: `Label ${ ruleId }`,
		message: `Message ${ ruleId }`,
		...extra,
	} );

	const FIX = {
		selector: '#content',
		tab: '',
		label: 'Edit the description',
	};

	const MIXED = {
		is_ready: false,
		// Deliberately out of display order: the warning before the failure.
		results: [
			row( 'short', 'content', 'warning' ),
			row( 'title', 'content', 'pass' ),
			row( 'desc', 'content', 'fail', { fix: FIX } ),
			row( 'image', 'media', 'pass' ),
			row( 'gtin', 'shopping', 'fail' ),
			row( 'stock', 'inventory', 'skipped' ),
		],
		counts: { evaluated: 5, passed: 2, warnings: 1, failed: 2, skipped: 1 },
		generated_at: 1700000000,
	};

	const GROUPS = {
		content: 'Content',
		media: 'Media',
		inventory: 'Inventory',
		shopping: 'Google Shopping',
	};

	const mount = () =>
		render(
			<ChecklistApp
				data={ {
					restPath: '/products/12/validate',
					result: MIXED,
					groups: GROUPS,
				} }
			/>
		);

	const failingSections = ( container ) =>
		Array.from(
			container.querySelectorAll(
				'.sit-wcpg-checklist__failing > .sit-wcpg-checklist__section'
			)
		);

	const passedDetails = ( container ) =>
		container.querySelector( 'details.sit-wcpg-checklist__passed' );

	const toggle = ( details, open ) => {
		details.open = open;
		fireEvent( details, new window.Event( 'toggle' ) );
	};

	beforeEach( () => {
		createClient.mockReturnValue( {
			validate: jest.fn( () => new Promise( () => {} ) ),
			abort: jest.fn(),
		} );
		watchFields.mockReturnValue( {
			stop: jest.fn(),
			snapshotNow: jest.fn( () => ( { draft: {}, signature: '{}' } ) ),
		} );
		window.localStorage.clear();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		jest.clearAllMocks();
	} );

	it( 'failing items are listed before warnings', () => {
		const { container } = mount();
		const [ content ] = failingSections( container );
		const statuses = Array.from(
			content.querySelectorAll( '.sit-wcpg-checklist__item' )
		).map( ( item ) => item.className );

		expect( statuses ).toEqual( [
			'sit-wcpg-checklist__item sit-wcpg-checklist__item--fail',
			'sit-wcpg-checklist__item sit-wcpg-checklist__item--warning',
		] );
		expect(
			content.querySelector( '.sit-wcpg-checklist__group-count' )
				.textContent
		).toBe( '1 failing' );
	} );

	it( 'passed items are in a closed details with the count', () => {
		const { container } = mount();
		const details = passedDetails( container );

		expect( details ).not.toBeNull();
		expect( details.open ).toBe( false );
		expect( details.querySelector( 'summary' ).textContent ).toBe(
			'2 more passed'
		);
		expect(
			details.querySelectorAll( '.sit-wcpg-checklist__item--pass' )
		).toHaveLength( 2 );

		// No passed row is listed among the failing groups.
		failingSections( container ).forEach( ( section ) =>
			expect(
				section.querySelector( '.sit-wcpg-checklist__item--pass' )
			).toBeNull()
		);

		// Not applicable stays last and collapsed.
		const skipped = container.querySelector(
			'details.sit-wcpg-checklist__skipped'
		);

		expect( skipped.open ).toBe( false );
		expect( Array.from( container.querySelectorAll( 'details' ) ) ).toEqual(
			[ details, skipped ]
		);
	} );

	it( 'details open state is remembered', () => {
		const first = mount();

		act( () => toggle( passedDetails( first.container ), true ) );
		first.unmount();

		const second = mount();

		expect( passedDetails( second.container ).open ).toBe( true );

		act( () => toggle( passedDetails( second.container ), false ) );
		second.unmount();

		expect( passedDetails( mount().container ).open ).toBe( false );
	} );

	it( 'localStorage throwing does not break the panel', () => {
		jest.spyOn( window.Storage.prototype, 'getItem' ).mockImplementation(
			() => {
				throw new Error( 'SecurityError' );
			}
		);
		jest.spyOn( window.Storage.prototype, 'setItem' ).mockImplementation(
			() => {
				throw new Error( 'QuotaExceededError' );
			}
		);

		const { container } = mount();
		const details = passedDetails( container );

		expect( details.open ).toBe( false );
		expect( () => act( () => toggle( details, true ) ) ).not.toThrow();
		expect( passedDetails( container ).open ).toBe( true );
	} );

	it( 'an add-on group keeps its label and position', () => {
		const { container } = mount();
		const labels = failingSections( container ).map(
			( section ) =>
				section.querySelector( '.sit-wcpg-checklist__group-label' )
					.textContent
		);

		// Inventory has no failing row, so it is absent; the add-on group follows Content.
		expect( labels ).toEqual( [ 'Content', 'Google Shopping' ] );
	} );

	it( 'fix link still focuses the field', () => {
		const { container } = mount();
		const fix = container.querySelector( '.sit-wcpg-checklist__fix' );

		expect( fix.textContent ).toBe( 'Edit the description' );

		fireEvent.click( fix );

		expect( focusField ).toHaveBeenCalledWith( FIX );
	} );

	it( 'says all passed without "more" when nothing fails', () => {
		const passing = {
			...MIXED,
			is_ready: true,
			results: [ row( 'title', 'content', 'pass' ) ],
			counts: {
				evaluated: 1,
				passed: 1,
				warnings: 0,
				failed: 0,
				skipped: 0,
			},
		};
		const { container } = render(
			<ChecklistApp
				data={ {
					restPath: '/products/12/validate',
					result: passing,
					groups: GROUPS,
				} }
			/>
		);

		expect( failingSections( container ) ).toHaveLength( 0 );
		expect(
			passedDetails( container ).querySelector( 'summary' ).textContent
		).toBe( '1 passed' );
	} );
} );
