<?php
/**
 * Tests for conditional asset loading.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Activation_Notice;
use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Review_Notice_Controller;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * The exact matrix of coding-plan.md section 11.1: the editor gets the React panel and
 * its payload, the products list and the settings page get one stylesheet, and every
 * other screen gets nothing (manual row 23, definition of done item 18).
 *
 * @since 1.0.0
 */
final class Assets_Test extends WP_UnitTestCase {

	/**
	 * Fresh script and style registries, an unsaved option and an administrator.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_dependencies();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no screen, post, option or enqueued asset behind.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );

		$this->reset_dependencies();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * Drop the script and style registries so each test starts with nothing enqueued.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function reset_dependencies(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
	}

	/**
	 * Run the enqueuer as `admin_enqueue_scripts` would on the given screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The admin page's hook suffix.
	 * @param string $screen_id   The screen core would set up for it.
	 * @return void
	 */
	private function enqueue_on( string $hook_suffix, string $screen_id ): void {
		set_current_screen( $screen_id );

		( new Assets( Plugin::instance()->settings() ) )->enqueue( $hook_suffix );
	}

	/**
	 * Open the product editor on a saved product.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix `post.php` or `post-new.php`.
	 * @return int The product id.
	 */
	private function open_product_editor( string $hook_suffix = 'post.php' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Edited product' );
		$product->save();

		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		$this->enqueue_on( $hook_suffix, Screen::POST_TYPE );

		return $product->get_id();
	}

	/**
	 * Skip when the JavaScript has not been built, since nothing can be enqueued then.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_build(): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/editor.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/editor.js is missing.' );
		}
	}

	/**
	 * Assert that none of the plugin's assets are enqueued.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function assert_nothing_enqueued(): void {
		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ), 'The editor script was enqueued.' );
		$this->assertFalse( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ), 'The editor stylesheet was enqueued.' );
		$this->assertFalse( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ), 'The admin stylesheet was enqueued.' );
	}

	/**
	 * The product editor gets the panel, its stylesheet and the bootstrap payload.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_product_editor_gets_the_panel_and_its_payload(): void {
		$this->require_build();

		$product_id = $this->open_product_editor();

		$this->assertTrue( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );

		// React comes from core's handles, never from the bundle.
		$this->assertContains( 'wp-element', wp_scripts()->registered[ Assets::EDITOR_HANDLE ]->deps );

		$inline = implode( '', (array) wp_scripts()->get_data( Assets::EDITOR_HANDLE, 'before' ) );

		$this->assertStringStartsWith( 'window.' . Assets::PAYLOAD_GLOBAL . ' = ', $inline );
		$this->assertStringContainsString( '"productId":' . $product_id, $inline );
		$this->assertStringContainsString(
			wp_json_encode( '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate' ),
			$inline
		);
	}

	/**
	 * The first paint knows the pre-flight statement without a request.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_payload_result_carries_preflight(): void {
		$this->require_build();

		$product_id = $this->open_product_editor();
		$inline     = implode( '', (array) wp_scripts()->get_data( Assets::EDITOR_HANDLE, 'before' ) );
		$payload    = json_decode( substr( $inline, strlen( 'window.' . Assets::PAYLOAD_GLOBAL . ' = ' ), -1 ), true );

		$this->assertSame( $product_id, $payload['productId'] );
		$this->assertSame( 'live', $payload['result']['preflight']['kind'] );
		$this->assertIsInt( $payload['result']['preflight']['required'] );
		$this->assertIsInt( $payload['result']['preflight']['warnings'] );
	}

	/**
	 * A new product's editor is the product editor too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_new_product_screen_gets_the_panel(): void {
		$this->require_build();

		$this->open_product_editor( 'post-new.php' );

		$this->assertTrue( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * With the checklist switched off, the editor loads nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_disabled_checklist_loads_nothing_on_the_editor(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$this->open_product_editor();

		$this->assert_nothing_enqueued();
	}

	/**
	 * The products list gets the admin stylesheet and no JavaScript.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_products_list_gets_only_the_admin_stylesheet(): void {
		$this->enqueue_on( 'edit.php', 'edit-' . Screen::POST_TYPE );

		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * The settings page gets the admin stylesheet and no JavaScript.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_settings_page_gets_only_the_admin_stylesheet(): void {
		$this->enqueue_on( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK );

		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * The catalog screen gets the admin stylesheet.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_admin_style_loads_on_catalog_screen(): void {
		$this->enqueue_on( Screen::CATALOG_HOOK, Screen::CATALOG_HOOK );

		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
	}

	/**
	 * The catalog screen is server-rendered and needs no JavaScript.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_script_loads_on_catalog_screen(): void {
		$this->enqueue_on( Screen::CATALOG_HOOK, Screen::CATALOG_HOOK );

		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * The Plugins screen gets the admin stylesheet while the welcome notice is pending.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_plugins_screen_gets_the_admin_stylesheet_for_the_welcome_notice(): void {
		Activation_Notice::activate();

		$this->enqueue_on( 'plugins.php', 'plugins' );

		delete_option( Activation_Notice::OPTION );

		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
	}

	/**
	 * Make the review request due, or not.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $due Whether it should be due.
	 * @return void
	 */
	private function set_review_due( bool $due ): void {
		update_option(
			Review_Notice::OPTION,
			array(
				'first_seen' => time() - 31 * DAY_IN_SECONDS,
				'blocked_at' => $due ? time() - DAY_IN_SECONDS : 0,
				'ask_after'  => 0,
				'done'       => false,
			),
			false
		);
	}

	/**
	 * The screens the review request can appear on.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_review_screens(): array {
		return array(
			'products list' => array( 'edit.php', 'edit-' . Screen::POST_TYPE ),
			'settings'      => array( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK ),
			'catalog'       => array( Screen::CATALOG_HOOK, Screen::CATALOG_HOOK ),
		);
	}

	/**
	 * Skip when the review script has not been built.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function require_review_build(): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/review-notice.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/review-notice.js is missing.' );
		}
	}

	/**
	 * The script loads only with the notice it drives.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_review_screens
	 *
	 * @param string $hook_suffix The admin page's hook suffix.
	 * @param string $screen_id   The screen core would set up for it.
	 * @return void
	 */
	public function test_review_script_only_when_due_on_listed_screens( string $hook_suffix, string $screen_id ): void {
		$this->require_review_build();

		$this->set_review_due( false );
		$this->enqueue_on( $hook_suffix, $screen_id );

		$this->assertFalse( wp_script_is( Assets::REVIEW_HANDLE, 'enqueued' ), 'Enqueued before it was due.' );

		$this->reset_dependencies();
		$this->set_review_due( true );
		$this->enqueue_on( $hook_suffix, $screen_id );

		delete_option( Review_Notice::OPTION );

		$this->assertTrue( wp_script_is( Assets::REVIEW_HANDLE, 'enqueued' ) );
		$this->assertContains( 'wp-api-fetch', wp_scripts()->registered[ Assets::REVIEW_HANDLE ]->deps );
	}

	/**
	 * A due request adds nothing to any other screen.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_other_screens
	 *
	 * @param string $hook_suffix The admin page's hook suffix.
	 * @param string $screen_id   The screen core would set up for it.
	 * @return void
	 */
	public function test_review_script_not_on_other_screens( string $hook_suffix, string $screen_id ): void {
		$this->set_review_due( true );
		$this->enqueue_on( $hook_suffix, $screen_id );

		delete_option( Review_Notice::OPTION );

		$this->assertFalse( wp_script_is( Assets::REVIEW_HANDLE, 'enqueued' ) );
	}

	/**
	 * A user who cannot see the notice does not get its script.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_review_script_not_for_shop_manager_without_manage_woocommerce(): void {
		$role = 'sit_wcpg_catalog_editor';

		add_role(
			$role,
			'SIT-WCPG catalog editor',
			array(
				'read'          => true,
				'edit_products' => true,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$this->set_review_due( true );
		$this->enqueue_on( 'edit.php', 'edit-' . Screen::POST_TYPE );

		delete_option( Review_Notice::OPTION );
		remove_role( $role );

		$this->assertFalse( wp_script_is( Assets::REVIEW_HANDLE, 'enqueued' ) );
	}

	/**
	 * The script is told where to post, and never works it out.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_review_payload_has_rest_path(): void {
		$this->require_review_build();

		$this->set_review_due( true );
		$this->enqueue_on( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK );

		delete_option( Review_Notice::OPTION );

		$inline = implode( '', (array) wp_scripts()->get_data( Assets::REVIEW_HANDLE, 'before' ) );

		$this->assertStringStartsWith( 'window.' . Assets::REVIEW_PAYLOAD_GLOBAL . ' = ', $inline );
		$this->assertStringContainsString(
			wp_json_encode( '/' . Validate_Controller::REST_NAMESPACE . Review_Notice_Controller::ROUTE ),
			$inline
		);
	}

	/**
	 * Every other screen gets nothing, including the post screens that share the
	 * product screens' hook suffixes.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider data_other_screens
	 *
	 * @param string $hook_suffix The admin page's hook suffix.
	 * @param string $screen_id   The screen core would set up for it.
	 * @return void
	 */
	public function test_other_screens_load_nothing( string $hook_suffix, string $screen_id ): void {
		$this->enqueue_on( $hook_suffix, $screen_id );

		$this->assert_nothing_enqueued();
	}

	/**
	 * Screens outside the matrix.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_other_screens(): array {
		return array(
			'dashboard'           => array( 'index.php', 'dashboard' ),
			'posts list'          => array( 'edit.php', 'edit-post' ),
			'post editor'         => array( 'post.php', 'post' ),
			'new page'            => array( 'post-new.php', 'page' ),
			'orders (legacy)'     => array( 'edit.php', 'edit-shop_order' ),
			'orders (HPOS)'       => array( 'woocommerce_page_wc-orders', 'woocommerce_page_wc-orders' ),
			'woocommerce options' => array( 'woocommerce_page_wc-settings', 'woocommerce_page_wc-settings' ),
			'product categories'  => array( 'edit-tags.php', 'edit-product_cat' ),
			'plugins, no notice'  => array( 'plugins.php', 'plugins' ),
		);
	}
}
