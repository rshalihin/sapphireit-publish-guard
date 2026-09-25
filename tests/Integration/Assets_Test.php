<?php
/**
 * Tests for conditional asset loading.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
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
		);
	}
}
