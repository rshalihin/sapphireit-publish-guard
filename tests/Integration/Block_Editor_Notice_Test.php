<?php
/**
 * Tests for the block-editor notice.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Block_Editor_Notice;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use WP_UnitTestCase;

/**
 * Coding-plan.md section 7.2.1 / A7, manual row 25: while the product block editor is
 * on, the products list and the settings screen explain that the panel is unavailable;
 * nothing is printed anywhere else, to anyone who cannot edit products, or when the
 * checklist is off (SIT-WCPG-TEST-8).
 *
 * @since 1.0.0
 */
final class Block_Editor_Notice_Test extends WP_UnitTestCase {

	/**
	 * Whether the block editor is simulated as on.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private bool $block_editor = true;

	/**
	 * An administrator, an unsaved option and a controllable block-editor flag.
	 *
	 * WooCommerce 11 no longer has the `product_block_editor` feature, so the flag is
	 * driven through core's `use_block_editor_for_post_type` filter — the fallback path
	 * `Requirements::is_product_block_editor_active()` reads.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/post.php';

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();

		add_filter( 'use_block_editor_for_post_type', array( $this, 'filter_block_editor' ), 999, 2 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no screen, filter or option behind.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );
		remove_filter( 'use_block_editor_for_post_type', array( $this, 'filter_block_editor' ), 999 );

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();

		parent::tear_down();
	}

	/**
	 * Simulate the block editor for products.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $use_block Core's answer.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function filter_block_editor( $use_block, $post_type ) {
		return 'product' === $post_type ? $this->block_editor : $use_block;
	}

	/**
	 * Render the notice on a screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $screen_id Screen id for set_current_screen().
	 * @return string Printed markup.
	 */
	private function render_on( string $screen_id ): string {
		set_current_screen( $screen_id );

		ob_start();
		( new Block_Editor_Notice( Plugin::instance()->settings() ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * The two screens the notice belongs on.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_notice_screens(): array {
		return array(
			'products list' => array( 'edit-product' ),
			'settings page' => array( Screen::SETTINGS_HOOK ),
		);
	}

	/**
	 * The notice explains the limitation and that publishing is still enforced.
	 *
	 * @dataProvider provide_notice_screens
	 *
	 * @param string $screen_id Screen id.
	 * @return void
	 */
	public function test_the_notice_shows_on_its_screens( string $screen_id ): void {
		$html = $this->render_on( $screen_id );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'classic product editor only', $html );
		$this->assertStringContainsString( 'still enforced', $html );
	}

	/**
	 * No other screen pays for it, the product editor included.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_on_other_screens(): void {
		$this->assertSame( '', $this->render_on( 'dashboard' ) );
		$this->assertSame( '', $this->render_on( 'product' ) );
		$this->assertSame( '', $this->render_on( 'edit-post' ) );
	}

	/**
	 * The classic editor — every current WooCommerce — never sees it.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_under_the_classic_editor(): void {
		$this->block_editor = false;

		$this->assertSame( '', $this->render_on( 'edit-product' ) );
	}

	/**
	 * A disabled checklist has no panel to miss.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_when_the_checklist_is_disabled(): void {
		$settings            = Plugin::instance()->settings()->get_all();
		$settings['enabled'] = false;
		update_option( Settings::OPTION_NAME, $settings );
		Plugin::instance()->settings()->refresh();

		$this->assertSame( '', $this->render_on( 'edit-product' ) );
	}

	/**
	 * A user who cannot edit products is not told about the product editor.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_without_edit_products(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render_on( Screen::SETTINGS_HOOK ) );
	}

	/**
	 * The notice is wired on boot.
	 *
	 * @return void
	 */
	public function test_it_is_registered_on_admin_notices(): void {
		$notice = new Block_Editor_Notice( Plugin::instance()->settings() );
		$notice->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $notice, 'render' ) ) );
	}
}
