<?php
/**
 * Tests for the Dashboard page.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Admin_Shell;
use ProductPublishGuard\Admin\Catalog_Page;
use ProductPublishGuard\Admin\Dashboard_Page;
use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Admin\Settings_Footer;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Stats;
use ProductPublishGuard\Support\Readiness_Store;
use WP_UnitTestCase;
use WPDieException;

/**
 * WooCommerce → Publish Guard: catalog health, stat cards, most common issues, active
 * checks, the Pro card and the help cards (UI decisions U7, U12, U13, U14).
 *
 * The numbers are seeded through the statistics' own cache, so each test states exactly
 * what the page is given.
 *
 * @since 1.2.0
 */
final class Dashboard_Page_Test extends WP_UnitTestCase {

	/**
	 * A shop manager, a current index and no cached numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		delete_transient( Readiness_Stats::TRANSIENT );
		$this->set_index( array() );
	}

	/**
	 * Leave no cached numbers, index, menu or filter behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_transient( Readiness_Stats::TRANSIENT );
		delete_option( Readiness_Rebuild::OPTION );
		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		remove_all_filters( Admin_Shell::PROMPTS_FILTER );

		unset( $GLOBALS['submenu'], $GLOBALS['_parent_pages'], $GLOBALS['_registered_pages'] );

		parent::tear_down();
	}

	/**
	 * Write the rebuild index.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Fields to override.
	 * @return void
	 */
	private function set_index( array $values ): void {
		update_option(
			Readiness_Rebuild::OPTION,
			array_merge(
				array(
					'version'    => SIT_WCPG_VERSION,
					'signature'  => Readiness_Store::signature( Plugin::instance()->settings() ),
					'state'      => Readiness_Rebuild::STATE_COMPLETE,
					'total'      => 40,
					'done'       => 40,
					'started_at' => time(),
				),
				$values
			),
			false
		);
	}

	/**
	 * Seed the statistics the page reads.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Fields to override.
	 * @return void
	 */
	private function seed_stats( array $values ): void {
		set_transient(
			Readiness_Stats::TRANSIENT,
			array_merge(
				array(
					'ready'        => 0,
					'warning'      => 0,
					'fail'         => 0,
					'unchecked'    => 0,
					'total'        => 0,
					'live_fail'    => 0,
					'top_failures' => array(),
					'capped'       => false,
					'computed_at'  => time(),
				),
				$values
			),
			Readiness_Stats::TTL
		);
	}

	/**
	 * A typical catalog: 40 products, 34 checked and passing, 3 not ready (1 live), 3 unchecked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function seed_typical(): void {
		$this->seed_stats(
			array(
				'ready'        => 30,
				'warning'      => 4,
				'fail'         => 3,
				'unchecked'    => 3,
				'total'        => 40,
				'live_fail'    => 1,
				'top_failures' => array(
					'featured_image' => 2,
					'price'          => 1,
				),
			)
		);
	}

	/**
	 * Switch the checklist off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function disable_checklist(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
	}

	/**
	 * The page as printed.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function page(): string {
		ob_start();

		try {
			( new Dashboard_Page( Plugin::instance()->settings() ) )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The products list filtered to a state, as the page links it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filter A Readiness_Store filter.
	 * @param string $rule   A rule id, or ''.
	 * @return string
	 */
	private function list_url( string $filter, string $rule = '' ): string {
		$args = array(
			'post_type'                 => Screen::POST_TYPE,
			Readiness_Filter::QUERY_VAR => $filter,
		);

		if ( '' !== $rule ) {
			$args[ Readiness_Filter::RULE_QUERY_VAR ] = $rule;
		}

		return esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) );
	}

	/**
	 * The page sits under WooCommerce as "Publish Guard", ahead of Product Checklist.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_menu_entry_under_woocommerce_before_product_checklist(): void {
		$page     = new Dashboard_Page( Plugin::instance()->settings() );
		$settings = new Settings_Page( Plugin::instance()->settings() );

		$page->register();
		$settings->register();

		$this->assertSame( Dashboard_Page::MENU_PRIORITY, has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
		$this->assertLessThan( Settings_Page::MENU_PRIORITY, Dashboard_Page::MENU_PRIORITY );
		$this->assertSame( 'woocommerce_page_' . Dashboard_Page::MENU_SLUG, Screen::DASHBOARD_HOOK );

		$page->add_menu();
		$settings->add_menu();

		$slugs = array_column( $GLOBALS['submenu'][ Dashboard_Page::PARENT ], 2 );
		$entry = $GLOBALS['submenu'][ Dashboard_Page::PARENT ][ array_search( Dashboard_Page::MENU_SLUG, $slugs, true ) ];

		$this->assertLessThan( array_search( Settings_Page::MENU_SLUG, $slugs, true ), array_search( Dashboard_Page::MENU_SLUG, $slugs, true ) );
		$this->assertSame( 'Publish Guard', $entry[0] );
		$this->assertSame( Dashboard_Page::CAPABILITY, $entry[1] );
		$this->assertSame( 'Publish Guard', $entry[3] );

		remove_all_actions( 'admin_menu' );
	}

	/**
	 * A user without `edit_products` is stopped by the callback itself.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_user_without_edit_products_gets_wp_die(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		$this->page();
	}

	/**
	 * The ring and the sentence use `(ready + warning) / (total − unchecked)`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_health_percentage_and_sentence_match_stats(): void {
		$this->seed_typical();
		// The shell prints the Dashboard tab once the page is in the menu.
		( new Dashboard_Page( Plugin::instance()->settings() ) )->add_menu();

		$html = $this->page();

		// 34 of 37 checked products: 91.9 %, shown as 91 % so a near miss never reads as 100 %.
		$this->assertStringContainsString( 'role="img" aria-label="91% of checked products pass every required check"', $html );
		$this->assertMatchesRegularExpression( '#<span class="sit-wcpg-dashboard__ring-value"[^>]*>91%</span>#', $html );
		$this->assertStringContainsString( '34 of 37 products pass every required check', $html );
		$this->assertStringContainsString( '2 products not yet live are held back until they pass.', $html );
		$this->assertStringContainsString( '1 live product would be refused if it were published today.', $html );
		$this->assertStringContainsString( 'Rechecked automatically when settings change', $html );
		$this->assertStringContainsString( '<div class="wrap sit-wcpg-ui sit-wcpg-dashboard">', $html );
		$this->assertMatchesRegularExpression( '#sit-wcpg-ui-header__tab--current" href="[^"]*page=' . Dashboard_Page::MENU_SLUG . '"#', $html );
	}

	/**
	 * With nothing checked there is no ring, only the empty state.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_nothing_checked_replaces_the_ring_with_the_empty_state(): void {
		$this->seed_stats(
			array(
				'unchecked' => 5,
				'total'     => 5,
			)
		);

		$html = $this->page();

		$this->assertStringNotContainsString( 'sit-wcpg-dashboard__ring', $html );
		$this->assertStringContainsString( 'Nothing checked yet — products are checked when they are saved or when a recheck runs.', $html );
	}

	/**
	 * Each stat card's number links to the products list filtered by that state.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_stat_links_point_to_filtered_products_list(): void {
		$this->seed_typical();

		$html = $this->page();

		foreach ( array(
			Readiness_Store::FILTER_READY   => '30',
			Readiness_Store::FILTER_FAIL    => '3',
			Readiness_Store::FILTER_WARNING => '4',
		) as $filter => $value ) {
			$this->assertMatchesRegularExpression(
				'#<a class="sit-wcpg-ui-stat__value[^"]*" href="' . preg_quote( $this->list_url( $filter ), '#' ) . '">' . $value . '<#',
				$html,
				$filter
			);
		}

		$catalog = esc_url( add_query_arg( 'page', Catalog_Page::MENU_SLUG, admin_url( Catalog_Page::PARENT ) ) );

		$this->assertMatchesRegularExpression( '#<a class="sit-wcpg-ui-stat__value[^"]*" href="' . preg_quote( $catalog, '#' ) . '">1<#', $html );
		$this->assertStringContainsString( 'href="' . $this->list_url( Readiness_Store::FILTER_FAIL ) . '">Show not-ready products', $html );
		$this->assertStringContainsString( 'href="' . $catalog . '">Live products report', $html );
	}

	/**
	 * "Not checked yet" is a fifth card only while something is unchecked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_not_checked_card_only_when_unchecked_above_zero(): void {
		$this->seed_typical();

		$this->assertStringContainsString( 'href="' . $this->list_url( Readiness_Store::FILTER_UNCHECKED ) . '">3<', $this->page() );
		$this->assertSame( 5, substr_count( $this->page(), '<div class="sit-wcpg-ui-stat' ) );

		$this->seed_stats(
			array(
				'ready' => 10,
				'total' => 10,
			)
		);

		$html = $this->page();

		$this->assertStringNotContainsString( 'Not checked yet', $html );
		$this->assertSame( 4, substr_count( $html, '<div class="sit-wcpg-ui-stat' ) );
	}

	/**
	 * The most common issues show the rule label, its group, a bar and a linked count.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_top_failures_list_rule_labels_and_counts_with_rule_links(): void {
		$this->seed_typical();

		$html  = $this->page();
		$label = Plugin::instance()->registry()->get( 'featured_image' )->get_label();

		$this->assertStringContainsString( 'Most common issues', $html );
		$this->assertStringContainsString( '>' . esc_html( $label ) . '<', $html );
		$this->assertMatchesRegularExpression( '#href="' . preg_quote( $this->list_url( Readiness_Store::FILTER_FAIL, 'featured_image' ), '#' ) . '"[^>]*>2<#', $html );
		$this->assertMatchesRegularExpression( '#href="' . preg_quote( $this->list_url( Readiness_Store::FILTER_FAIL, 'price' ), '#' ) . '"[^>]*>1<#', $html );
		$this->assertLessThan( strpos( $html, 'sit_wcpg_rule=price' ), strpos( $html, 'sit_wcpg_rule=featured_image' ) );
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'Required checks only', $html );
		$this->assertStringNotContainsString( 'Based on the first', $html );
	}

	/**
	 * With nothing failing, the issues card says so instead of listing nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_failures_shows_the_issues_empty_state(): void {
		$this->seed_stats(
			array(
				'ready' => 10,
				'total' => 10,
			)
		);

		$html = $this->page();

		$this->assertStringContainsString( 'No product fails a required check.', $html );
		$this->assertStringNotContainsString( 'role="progressbar"', $html );
	}

	/**
	 * A capped scan says what the list is based on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_capped_scan_shows_the_based_on_first_n_caption(): void {
		$this->seed_stats(
			array(
				'fail'         => 6000,
				'total'        => 6000,
				'top_failures' => array( 'price' => 5000 ),
				'capped'       => true,
			)
		);

		$this->assertStringContainsString( 'Based on the first ' . number_format_i18n( Readiness_Stats::SCAN_LIMIT ) . ' not-ready products.', $this->page() );
	}

	/**
	 * Every non-managed rule shows an on/off pill, and the header counts them.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_active_checks_show_on_off_pills_and_count(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['rules']['sku']['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
		$this->set_index( array() );
		$this->seed_typical();

		$rules = array_filter(
			array_keys( $stored['rules'] ),
			static function ( string $id ): bool {
				return Plugin::instance()->registry()->has( $id );
			}
		);
		$on    = count(
			array_filter(
				$rules,
				static function ( string $id ) use ( $settings ): bool {
					return $settings->rule_is_enabled( $id );
				}
			)
		);

		$html = $this->page();

		$this->assertStringContainsString( 'Active checks', $html );
		$this->assertStringContainsString( '>' . $on . ' of ' . count( $rules ) . ' on<', $html );
		$this->assertSame( $on, substr_count( $html, 'sit-wcpg-ui-pill sit-wcpg-ui-pill--success sit-wcpg-dashboard__check-state">On<' ) );
		$this->assertSame( count( $rules ) - $on, substr_count( $html, 'sit-wcpg-ui-pill sit-wcpg-ui-pill--neutral sit-wcpg-dashboard__check-state">Off<' ) );
		$this->assertStringNotContainsString( 'role="switch"', $html, 'The dashboard saves nothing, so it shows pills, not switches.' );
	}

	/**
	 * While a rebuild runs, the progress line replaces "Rechecked automatically".
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rebuild_in_progress_shows_progress_line(): void {
		$this->seed_typical();
		$this->set_index(
			array(
				'state' => Readiness_Rebuild::STATE_BUILDING,
				'total' => 40,
				'done'  => 15,
			)
		);

		$html = $this->page();

		if ( Readiness_Rebuild::scheduler_available() ) {
			$this->assertStringContainsString( 'Rechecking products against your current settings: 15 of 40 done.', $html );
		} else {
			$this->assertStringContainsString( esc_html( Readiness_Filter::no_scheduler_message() ), $html );
		}

		$this->assertStringNotContainsString( 'Rechecked automatically when settings change', $html );
	}

	/**
	 * A switched-off checklist shows its own state, with the way back to the settings.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_checklist_disabled_shows_the_turned_off_state_with_a_link_to_settings(): void {
		$this->disable_checklist();

		$html = $this->page();

		$this->assertStringContainsString( 'The product checklist is turned off, so nothing is being checked.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( add_query_arg( 'page', Settings_Page::MENU_SLUG, admin_url( 'admin.php' ) ) ) . '">Turn it on in Checklist settings', $html );
		$this->assertStringNotContainsString( 'sit-wcpg-ui-stat', $html );
		$this->assertStringNotContainsString( 'Most common issues', $html );
	}

	/**
	 * The Pro card and the upgrade tab follow the prompts filter (U13).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_pro_card_and_upgrade_tab_hidden_when_prompts_filter_false(): void {
		$this->seed_typical();

		$html = $this->page();

		$this->assertStringContainsString( 'sit-wcpg-dashboard__pro', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Admin_Shell::PRO_URL ) . '" target="_blank" rel="noopener">See what Pro adds', $html );

		add_filter( Admin_Shell::PROMPTS_FILTER, '__return_false' );

		$html = $this->page();

		$this->assertStringNotContainsString( 'sit-wcpg-dashboard__pro', $html );
		$this->assertStringNotContainsString( 'sapphireitlabs.com', $html );
		$this->assertDoesNotMatchRegularExpression( '/\bPRO\b|\bPro\b/', wp_strip_all_tags( $html ) );
		$this->assertStringContainsString( 'sit-wcpg-dashboard__health--wide', $html );
	}

	/**
	 * Links to the Checklist settings need `manage_woocommerce`; the rest of the page does not.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shop_manager_sees_manage_links_editor_without_manage_woocommerce_does_not(): void {
		$this->seed_typical();

		$settings_url = esc_url( add_query_arg( 'page', Settings_Page::MENU_SLUG, admin_url( 'admin.php' ) ) );
		$html         = $this->page();

		$this->assertStringContainsString( 'href="' . $settings_url . '">Manage all checks', $html );
		$this->assertStringContainsString( 'sit-wcpg-dashboard__pro', $html );

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

		$html = $this->page();

		$this->assertStringNotContainsString( $settings_url, $html );
		$this->assertStringNotContainsString( 'sit-wcpg-dashboard__pro', $html );
		$this->assertStringContainsString( 'Active checks', $html );
		$this->assertStringContainsString( 'href="' . $this->list_url( Readiness_Store::FILTER_FAIL ) . '"', $html );
	}

	/**
	 * The help cards point at the docs, the forum and the review form.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_help_cards_link_docs_support_and_review(): void {
		$this->seed_typical();

		$html = $this->page();

		$this->assertStringContainsString( 'href="' . esc_url( Admin_Shell::DOCS_URL ) . '" target="_blank" rel="noopener">Read the docs', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Dashboard_Page::SUPPORT_URL ) . '" target="_blank" rel="noopener">Open a topic', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Settings_Footer::REVIEW_URL ) . '" target="_blank" rel="noopener">Leave a review', $html );
	}

	/**
	 * The page validates nothing and queries nothing directly; it reads the stored numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_validation_runs_on_render(): void {
		$this->seed_typical();

		$validations = 0;
		$count       = static function ( $result ) use ( &$validations ) {
			++$validations;

			return $result;
		};

		add_filter( 'sit_wcpg_validation_result', $count );
		$this->page();
		remove_filter( 'sit_wcpg_validation_result', $count );

		$this->assertSame( 0, $validations );
	}

	/**
	 * The screen predicate recognises the Dashboard's hook suffix and nothing else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_is_dashboard_screen_matches_its_hook(): void {
		$this->assertTrue( Screen::is_dashboard_screen( Screen::DASHBOARD_HOOK ) );
		$this->assertFalse( Screen::is_dashboard_screen( Screen::SETTINGS_HOOK ) );
	}
}
