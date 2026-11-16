<?php
/**
 * Tests for the admin shell header and its seams.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Admin_Shell;
use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Dashboard_Page;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings_Page;
use WP_UnitTestCase;

/**
 * The branded header above every Publish Guard screen (UI decisions U6, U7), and the seams
 * an add-on uses to join it (U9 S12, U10 S13, U11 S14, U13 S15).
 *
 * @since 1.2.0
 */
final class Admin_Shell_Test extends WP_UnitTestCase {

	/**
	 * Filters a test added, removed again in tear-down.
	 *
	 * @since 1.2.0
	 * @var array<int, array{string, callable}>
	 */
	private array $filters = array();

	/**
	 * A shop manager, and the admin menu functions.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * Leave no filter, page or screen behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}

		unset( $GLOBALS['_parent_pages'], $GLOBALS['current_screen'] );

		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.

		parent::tear_down();
	}

	/**
	 * Add a filter that tear-down removes.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $hook     Filter name.
	 * @param callable $callback Callback.
	 * @return void
	 */
	private function filter( string $hook, callable $callback ): void {
		add_filter( $hook, $callback, 10, 2 );

		$this->filters[] = array( $hook, $callback );
	}

	/**
	 * Add add-on tabs through the seam.
	 *
	 * @since 1.2.0
	 *
	 * @param array[] $tabs Entries to append.
	 * @return void
	 */
	private function add_tabs( array $tabs ): void {
		$this->filter(
			Admin_Shell::TABS_FILTER,
			static function ( $core ) use ( $tabs ) {
				return array_merge( $core, $tabs );
			}
		);
	}

	/**
	 * An add-on tab that passes validation.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id    Tab id.
	 * @param array  $extra Fields to override.
	 * @return array
	 */
	private function addon_tab( string $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'         => $id,
				'label'      => 'Tab ' . $id,
				'url'        => admin_url( 'admin.php?page=' . $id ),
				'capability' => 'manage_woocommerce',
			),
			$extra
		);
	}

	/**
	 * Register the Dashboard page, so its tab is printed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function register_dashboard_page(): void {
		( new Dashboard_Page( Plugin::instance()->settings() ) )->add_menu();
	}

	/**
	 * The header as printed.
	 *
	 * @since 1.2.0
	 *
	 * @param string $active Active tab id.
	 * @return string
	 */
	private function render( string $active = Admin_Shell::TAB_CHECKLIST ): string {
		ob_start();
		Admin_Shell::render( $active );

		return (string) ob_get_clean();
	}

	/**
	 * Tab ids, in order.
	 *
	 * @since 1.2.0
	 *
	 * @param string $active Active tab id.
	 * @return string[]
	 */
	private function tab_ids( string $active = Admin_Shell::TAB_CHECKLIST ): array {
		return array_column( Admin_Shell::tabs( $active ), 'id' );
	}

	/**
	 * The header carries the logo, the name, the version and the notice anchor.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_render_prints_logo_name_version_and_wp_header_end(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'class="sit-wcpg-ui sit-wcpg-ui-header"', $html );
		$this->assertStringContainsString( 'sit-wcpg-ui-header__logo', $html );
		$this->assertStringContainsString( '<svg', $html );
		// `wp_kses()` lowercases attribute names; the HTML parser restores `viewBox` on SVG.
		$this->assertStringContainsStringIgnoringCase( 'viewBox="0 0 24 24"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'Publish Guard', $html );
		$this->assertStringContainsString( SIT_WCPG_VERSION, $html );
		$this->assertStringContainsString( '<nav class="sit-wcpg-ui-header__nav" aria-label="Publish Guard">', $html );
		$this->assertStringNotContainsString( 'role="banner"', $html );
		$this->assertSame( 1, substr_count( $html, '<hr class="wp-header-end"' ) );
		$this->assertStringEndsWith( '<hr class="wp-header-end" />', trim( $html ) );
		$this->assertStringContainsString( 'href="' . esc_url( Admin_Shell::DOCS_URL ) . '"', $html );
	}

	/**
	 * A tab the user cannot open is not printed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_core_tabs_follow_capabilities(): void {
		$this->register_dashboard_page();

		$this->assertSame(
			array( Admin_Shell::TAB_DASHBOARD, Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, Admin_Shell::TAB_UPGRADE ),
			$this->tab_ids()
		);

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

		$this->assertSame( array( Admin_Shell::TAB_DASHBOARD, Admin_Shell::TAB_LIVE_PRODUCTS ), $this->tab_ids() );
	}

	/**
	 * Until the Dashboard page is registered (U05), its tab is not printed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_dashboard_tab_needs_the_dashboard_page(): void {
		$this->assertNotContains( Admin_Shell::TAB_DASHBOARD, $this->tab_ids() );
	}

	/**
	 * Core tab URLs point at the existing pages.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_core_tab_urls_point_at_their_pages(): void {
		$urls = array_column( Admin_Shell::tabs( Admin_Shell::TAB_CHECKLIST ), 'url', 'id' );

		$this->assertSame( admin_url( 'admin.php?page=sit-wcpg-settings' ), $urls[ Admin_Shell::TAB_CHECKLIST ] );
		$this->assertSame( admin_url( 'edit.php?post_type=product&page=sit-wcpg-catalog' ), $urls[ Admin_Shell::TAB_LIVE_PRODUCTS ] );
		$this->assertSame( Admin_Shell::PRO_URL, $urls[ Admin_Shell::TAB_UPGRADE ] );
	}

	/**
	 * Exactly the active tab is marked current.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_active_tab_has_aria_current_page(): void {
		$html = $this->render( Admin_Shell::TAB_LIVE_PRODUCTS );

		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertMatchesRegularExpression( '#<a class="sit-wcpg-ui-header__tab sit-wcpg-ui-header__tab--current" href="[^"]*page=sit-wcpg-catalog"[^>]*aria-current="page"#', $html );
	}

	/**
	 * An add-on tab comes after the core tabs and before the upgrade tab.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_tab_is_appended_after_core_tabs(): void {
		$this->add_tabs( array( $this->addon_tab( 'channels', array( 'badge' => 'New' ) ) ) );

		$this->assertSame(
			array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, 'channels', Admin_Shell::TAB_UPGRADE ),
			$this->tab_ids()
		);

		$html = $this->render( 'channels' );

		$this->assertStringContainsString( 'Tab channels', $html );
		$this->assertStringContainsString( '>New<', $html );
		$this->assertMatchesRegularExpression( '#href="[^"]*page=channels"[^>]*aria-current="page"#', $html );
	}

	/**
	 * A tab that leaves the admin is dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_tab_with_external_url_is_dropped(): void {
		$this->add_tabs(
			array(
				$this->addon_tab( 'external', array( 'url' => 'https://example.org/wp-admin/admin.php?page=x' ) ),
				$this->addon_tab( 'relative', array( 'url' => 'admin.php?page=x' ) ),
				$this->addon_tab( 'front', array( 'url' => home_url( '/shop/' ) ) ),
			)
		);

		$this->assertSame( array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, Admin_Shell::TAB_UPGRADE ), $this->tab_ids() );
	}

	/**
	 * Malformed and duplicate ids are dropped; the first of a duplicate wins.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_tab_with_bad_id_or_duplicate_id_is_dropped(): void {
		$this->add_tabs(
			array(
				$this->addon_tab( 'Bad_Id' ),
				$this->addon_tab( '9lives' ),
				$this->addon_tab( str_repeat( 'a', 41 ) ),
				$this->addon_tab( 'twice', array( 'label' => 'First' ) ),
				$this->addon_tab( 'twice', array( 'label' => 'Second' ) ),
				array( 'id' => 'nolabel' ),
				'not an array',
			)
		);

		$tabs = Admin_Shell::tabs( Admin_Shell::TAB_CHECKLIST );

		$this->assertSame( array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, 'twice', Admin_Shell::TAB_UPGRADE ), array_column( $tabs, 'id' ) );
		$this->assertSame( 'First', $tabs[2]['label'] );
	}

	/**
	 * The core tabs are Free's, whatever the filter returns.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_core_tab_cannot_be_removed_or_relabelled(): void {
		$this->filter(
			Admin_Shell::TABS_FILTER,
			function () {
				return array(
					$this->addon_tab( Admin_Shell::TAB_CHECKLIST, array( 'label' => 'Hijacked' ) ),
					$this->addon_tab( 'extra' ),
				);
			}
		);

		$tabs = Admin_Shell::tabs( Admin_Shell::TAB_CHECKLIST );

		$this->assertSame( array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, 'extra', Admin_Shell::TAB_UPGRADE ), array_column( $tabs, 'id' ) );
		$this->assertSame( 'Checklist', $tabs[0]['label'] );
		$this->assertSame( admin_url( 'admin.php?page=sit-wcpg-settings' ), $tabs[0]['url'] );
	}

	/**
	 * At most four add-on tabs, in the order added.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_more_than_four_addon_tabs_keeps_the_first_four(): void {
		$this->add_tabs( array_map( array( $this, 'addon_tab' ), array( 'one', 'two', 'three', 'four', 'five' ) ) );

		$this->assertSame(
			array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, 'one', 'two', 'three', 'four', Admin_Shell::TAB_UPGRADE ),
			$this->tab_ids()
		);
	}

	/**
	 * An add-on tab needs its capability, and a capability at all.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_tab_capability_is_enforced(): void {
		$this->add_tabs(
			array(
				$this->addon_tab( 'admins', array( 'capability' => 'manage_options' ) ),
				$this->addon_tab( 'nocap', array( 'capability' => '' ) ),
				$this->addon_tab( 'managers' ),
			)
		);

		$this->assertSame( array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, 'managers', Admin_Shell::TAB_UPGRADE ), $this->tab_ids() );
	}

	/**
	 * Header items are pills, toned, and a link when they carry an admin URL.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_header_items_render_as_pills_with_tone_class(): void {
		$this->filter(
			Admin_Shell::ITEMS_FILTER,
			static function ( $items ) {
				$items[] = array(
					'id'    => 'license',
					'label' => 'Licence active',
					'tone'  => 'success',
					'url'   => admin_url( 'admin.php?page=licence' ),
				);
				$items[] = array(
					'id'    => 'feed',
					'label' => 'Feed stale',
					'tone'  => 'danger',
				);
				$items[] = array(
					'id'    => 'plain',
					'label' => 'Plain',
				);

				return $items;
			}
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<a class="sit-wcpg-ui-pill sit-wcpg-ui-pill--success" href="[^"]*page=licence">Licence active</a>#', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required">Feed stale</span>', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--neutral">Plain</span>', $html );
	}

	/**
	 * At most three header items; malformed ones are dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_fourth_item_is_dropped(): void {
		$this->filter(
			Admin_Shell::ITEMS_FILTER,
			static function () {
				return array(
					array(
						'id'    => 'bad',
						'label' => 'Bad tone',
						'tone'  => 'purple',
					),
					array(
						'id'    => 'a',
						'label' => 'A',
					),
					array(
						'id'    => 'b',
						'label' => 'B',
					),
					array(
						'id'    => 'c',
						'label' => 'C',
					),
					array(
						'id'    => 'd',
						'label' => 'D',
					),
				);
			}
		);

		$this->assertSame( array( 'a', 'b', 'c' ), array_column( Admin_Shell::header_items( Admin_Shell::TAB_CHECKLIST ), 'id' ) );
	}

	/**
	 * The upgrade button and tab show until the filter turns them off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_upgrade_button_and_tab_show_by_default_and_hide_when_filter_returns_false(): void {
		$this->assertTrue( Admin_Shell::show_upgrade_prompts() );

		$html = $this->render();

		$this->assertStringContainsString( 'sit-wcpg-ui-header__upgrade', $html );
		$this->assertStringContainsString( 'Upgrade to Pro', $html );
		$this->assertContains( Admin_Shell::TAB_UPGRADE, $this->tab_ids() );

		$this->filter( Admin_Shell::PROMPTS_FILTER, '__return_false' );

		$html = $this->render();

		$this->assertFalse( Admin_Shell::show_upgrade_prompts() );
		$this->assertStringNotContainsString( 'sit-wcpg-ui-header__upgrade', $html );
		$this->assertStringNotContainsString( Admin_Shell::PRO_URL, $html );
		$this->assertNotContains( Admin_Shell::TAB_UPGRADE, $this->tab_ids() );
	}

	/**
	 * A user who cannot manage WooCommerce sees no upgrade prompt.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_upgrade_prompts_need_manage_woocommerce(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

		$this->assertStringNotContainsString( Admin_Shell::PRO_URL, $this->render( Admin_Shell::TAB_LIVE_PRODUCTS ) );
	}

	/**
	 * Every link that leaves the admin opens a new tab without an opener.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_upgrade_links_open_in_new_tab_with_noopener(): void {
		$html = $this->render();

		preg_match_all( '#<a [^>]*href="(https://sapphireitlabs\.com/[^"]*|https://wordpress\.org/[^"]*)"[^>]*>#', $html, $links );

		$this->assertCount( 3, $links[0] );

		foreach ( $links[0] as $link ) {
			$this->assertStringContainsString( 'target="_blank"', $link );
			$this->assertStringContainsString( 'rel="noopener"', $link );
		}
	}

	/**
	 * The header's upgrade button goes to pricing; the tab goes to the sales page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_upgrade_button_uses_pricing_url_and_learn_more_links_use_pro_url(): void {
		$html = $this->render();

		$this->assertSame( Admin_Shell::PRO_URL . '#pricing', Admin_Shell::PRO_PRICING_URL );
		$this->assertMatchesRegularExpression( '#<a class="sit-wcpg-ui-button sit-wcpg-ui-button--primary sit-wcpg-ui-header__upgrade" href="' . preg_quote( esc_url( Admin_Shell::PRO_PRICING_URL ), '#' ) . '"#', $html );
		$this->assertMatchesRegularExpression( '#<a class="sit-wcpg-ui-header__tab" href="' . preg_quote( esc_url( Admin_Shell::PRO_URL ), '#' ) . '"#', $html );
		$this->assertStringNotContainsString( 'utm_', $html );
	}

	/**
	 * Free's two shell screens enqueue the shell stylesheet; nothing else does.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shell_style_enqueued_on_shell_screens_only(): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/shell.css' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/shell.css is missing.' );
		}

		$screens = array(
			Screen::DASHBOARD_HOOK => true,
			Screen::SETTINGS_HOOK  => true,
			Screen::CATALOG_HOOK   => true,
			'index.php'            => false,
			'edit.php'             => false,
			'plugins.php'          => false,
		);

		foreach ( $screens as $hook_suffix => $expected ) {
			$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.

			set_current_screen( $hook_suffix );

			( new Assets( Plugin::instance()->settings() ) )->enqueue( $hook_suffix );

			$this->assertSame( $expected, wp_style_is( Admin_Shell::STYLE_HANDLE, 'enqueued' ), $hook_suffix );
		}
	}

	/**
	 * A filter that returns something other than an array leaves the core tabs.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_returning_non_array_falls_back_to_core_tabs(): void {
		$this->filter( Admin_Shell::TABS_FILTER, '__return_null' );
		$this->filter( Admin_Shell::ITEMS_FILTER, '__return_empty_string' );

		$this->assertSame( array( Admin_Shell::TAB_CHECKLIST, Admin_Shell::TAB_LIVE_PRODUCTS, Admin_Shell::TAB_UPGRADE ), $this->tab_ids() );
		$this->assertSame( array(), Admin_Shell::header_items( Admin_Shell::TAB_CHECKLIST ) );
	}

	/**
	 * Both Free screens print the shell first, inside a `sit-wcpg-ui` wrap with a page bar.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_free_screens_render_the_shell_above_the_page_bar(): void {
		$settings = new Settings_Page( Plugin::instance()->settings() );

		ob_start();
		$settings->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="sit-wcpg-ui sit-wcpg-ui-header">', trim( $html ) );
		$this->assertStringContainsString( '<div class="wrap sit-wcpg-ui sit-wcpg-settings">', $html );
		$this->assertMatchesRegularExpression( '#<div class="sit-wcpg-ui-pagebar">.*<h1 class="sit-wcpg-ui-pagebar__title">Product checklist</h1>#s', $html );
		$this->assertMatchesRegularExpression( '#sit-wcpg-ui-header__tab--current" href="[^"]*page=sit-wcpg-settings"#', $html );
	}

	/**
	 * The logo tile carries the brand mark of `assets/images/icon.svg`: a filled green
	 * shield with a white tick, hidden from assistive technology (U6).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_logo_is_the_filled_shield_brand_mark(): void {
		$html = $this->render();

		$this->assertSame( 1, preg_match( '#<span class="sit-wcpg-ui-header__logo">(<svg.*?</svg>)</span>#s', $html, $logo ) );
		$this->assertStringContainsStringIgnoringCase( 'viewBox="0 0 256 256"', $logo[1] );
		$this->assertStringContainsString( 'fill="#00A32A"', $logo[1] );
		$this->assertStringContainsString( 'stroke="#fff"', $logo[1] );
		$this->assertStringContainsString( 'aria-hidden="true"', $logo[1] );
	}

	/**
	 * The upgrade button is the navy primary button with a crown (U6).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_upgrade_button_is_primary_with_crown(): void {
		$html = $this->render();

		$this->assertSame( 1, preg_match( '#<a class="[^"]*sit-wcpg-ui-header__upgrade"[^>]*>(.*?)</a>#s', $html, $button ) );
		$this->assertStringContainsString( '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"', $button[1] );
		$this->assertStringContainsString( 'Upgrade to Pro', $button[1] );
		$this->assertStringNotContainsString( 'sit-wcpg-ui-button--gold', $html );
	}
}
