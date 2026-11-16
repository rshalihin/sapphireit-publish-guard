/**
 * SIT-WCPG-E2E-UI-01: the admin shell (UI decisions U6, U7). Its tabs follow the
 * user's capabilities, admin notices print below it, and it never makes the page
 * scroll sideways.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	BASELINE,
	SCREENS,
	createUser,
	deleteUser,
	expectNoHorizontalScroll,
	logIn,
	saveSettings,
	shellTabs,
} from './utils';

const EDITOR = 'sit-wcpg-ui-editor';

test.describe( 'UI-01: admin shell', () => {
	test( 'an administrator sees every tab, the current one marked', async ( {
		page,
	} ) => {
		await page.goto( SCREENS.dashboard );

		await expect( shellTabs( page ) ).toHaveText( [
			'Dashboard',
			'Checklist',
			'Live products',
			/^Channel Readiness\s+PRO/,
		] );
		await expect(
			shellTabs( page ).filter( { hasText: 'Dashboard' } )
		).toHaveAttribute( 'aria-current', 'page' );

		const upgrade = shellTabs( page ).filter( {
			hasText: 'Channel Readiness',
		} );

		await expect( upgrade ).toHaveAttribute( 'target', '_blank' );
		await expect( upgrade ).toHaveAttribute( 'rel', 'noopener' );
		await expect(
			page.locator( '.sit-wcpg-ui-header .sit-wcpg-ui-header__upgrade' )
		).toContainText( 'Upgrade to Pro' );

		await page.goto( SCREENS.checklist );
		await expect(
			shellTabs( page ).filter( { hasText: 'Checklist' } )
		).toHaveAttribute( 'aria-current', 'page' );

		await page.goto( SCREENS.liveProducts );
		await expect(
			shellTabs( page ).filter( { hasText: 'Live products' } )
		).toHaveAttribute( 'aria-current', 'page' );
	} );

	test.describe( 'a user who can edit products but not manage the store', () => {
		test.beforeAll( () => {
			createUser( EDITOR, 'editor', [ 'edit_products' ] );
		} );

		test.afterAll( () => {
			deleteUser( EDITOR );
		} );

		test( 'sees only the tabs their capability opens, and no Pro prompt', async ( {
			browser,
		} ) => {
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();

			await logIn( page, EDITOR );

			for ( const url of [ SCREENS.dashboard, SCREENS.liveProducts ] ) {
				await page.goto( url );

				await expect( shellTabs( page ) ).toHaveText( [
					'Dashboard',
					'Live products',
				] );
				await expect(
					page.locator(
						'.sit-wcpg-ui-header .sit-wcpg-ui-header__upgrade'
					)
				).toHaveCount( 0 );
			}

			await context.close();
		} );
	} );

	test( 'admin notices print below the header', async ( { page } ) => {
		// Saving prints core's "Settings saved." notice.
		await saveSettings( page, BASELINE );

		const notice = page.locator( '#setting-error-settings_updated' );

		await expect( notice ).toBeVisible();

		// querySelectorAll() returns matches in document order.
		const below = await page.evaluate( () => ( {
			ends: document.querySelectorAll( 'hr.wp-header-end' ).length,
			order: Array.from(
				document.querySelectorAll(
					'.sit-wcpg-ui-header, hr.wp-header-end, #setting-error-settings_updated'
				),
				( node ) => node.id || node.className
			),
		} ) );

		expect( below.ends ).toBe( 1 );
		expect( below.order ).toEqual( [
			'sit-wcpg-ui sit-wcpg-ui-header',
			'wp-header-end',
			'setting-error-settings_updated',
		] );
	} );

	for ( const width of [ 1440, 782, 375 ] ) {
		test( `no horizontal scroll at ${ width } px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 900 } );

			for ( const url of Object.values( SCREENS ) ) {
				await page.goto( url );
				await expect(
					page.locator( '.sit-wcpg-ui-header' )
				).toBeVisible();
				await expectNoHorizontalScroll( page );
			}
		} );
	}

	test( 'at 782 px the tabs scroll inside the header, not the page', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 782, height: 900 } );
		await page.goto( SCREENS.dashboard );

		const header = await page
			.locator( '.sit-wcpg-ui-header' )
			.evaluate( ( node ) => ( {
				scroll: node.scrollWidth,
				client: node.clientWidth,
			} ) );

		expect( header.scroll ).toBeLessThanOrEqual( header.client );

		// Every tab can still be reached.
		for ( const tab of await shellTabs( page ).all() ) {
			await tab.scrollIntoViewIfNeeded();
			await expect( tab ).toBeInViewport();
		}
	} );
} );
