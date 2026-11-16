<?php
/**
 * Tests for the admin shell stylesheet's registration and RTL style data.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Admin_Shell;
use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * The `sit-wcpg-shell` stylesheet (UI phase U02) is registered on every admin request and
 * enqueued only on the shell screens (`Admin_Shell_Test`); every Free stylesheet swaps to
 * its `-rtl.css` twin on RTL sites (verification note V-U3).
 *
 * @since 1.2.0
 */
final class Shell_Style_Test extends WP_UnitTestCase {

	/**
	 * Fresh script and style registries, an unsaved option and an administrator.
	 *
	 * @since 1.2.0
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
	 * Leave no screen, post, option or registered asset behind.
	 *
	 * @since 1.2.0
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
	 * Drop the script and style registries.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function reset_dependencies(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
	}

	/**
	 * The shell stylesheet's handle.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function shell_handle(): string {
		return Admin_Shell::STYLE_HANDLE;
	}

	/**
	 * Run the enqueuer as `admin_enqueue_scripts` would on the given screen.
	 *
	 * @since 1.2.0
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
	 * Skip when the stylesheets have not been built.
	 *
	 * @since 1.2.0
	 *
	 * @param string $file File name inside `build/`.
	 * @return void
	 */
	private function require_build( string $file ): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/' . $file ) ) {
			$this->markTestSkipped( "Run `npm run build` first: build/{$file} is missing." );
		}
	}

	/**
	 * Every Free stylesheet carries `rtl` => `replace`, so RTL sites get the built
	 * `-rtl.css` file instead of the LTR one.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_free_stylesheet_gets_rtl_replace_style_data(): void {
		$this->require_build( 'admin.css' );
		$this->require_build( 'editor.css' );
		$this->require_build( 'shell.css' );

		$this->enqueue_on( 'edit.php', 'edit-' . Screen::POST_TYPE );

		$this->assertTrue( wp_style_is( Assets::ADMIN_HANDLE, 'enqueued' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( Assets::ADMIN_HANDLE, 'rtl' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( $this->shell_handle(), 'rtl' ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'Edited product' );
		$product->save();

		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		$this->enqueue_on( 'post.php', Screen::POST_TYPE );

		$this->assertTrue( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( Assets::EDITOR_HANDLE, 'rtl' ) );
	}

	/**
	 * The editor panel's stylesheet loads after the shell's, which carries its tokens and font.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_editor_style_depends_on_the_shell_style(): void {
		$this->require_build( 'editor.css' );
		$this->require_build( 'shell.css' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Edited product' );
		$product->save();

		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		$this->enqueue_on( 'post.php', Screen::POST_TYPE );

		$this->assertTrue( wp_style_is( Assets::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertContains( $this->shell_handle(), wp_styles()->registered[ Assets::EDITOR_HANDLE ]->deps );
		$this->assertContains( 'wp-components', wp_styles()->registered[ Assets::EDITOR_HANDLE ]->deps );
	}

	/**
	 * The shell stylesheet is registered on any admin screen, ready for a screen to enqueue.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_any_screen
	 *
	 * @param string $hook_suffix The admin page's hook suffix.
	 * @param string $screen_id   The screen core would set up for it.
	 * @return void
	 */
	public function test_shell_style_is_registered_on_any_screen( string $hook_suffix, string $screen_id ): void {
		$this->require_build( 'shell.css' );

		$this->enqueue_on( $hook_suffix, $screen_id );

		$this->assertTrue( wp_style_is( $this->shell_handle(), 'registered' ) );
		$this->assertSame( SIT_WCPG_URL . 'build/shell.css', wp_styles()->registered[ $this->shell_handle() ]->src );
	}

	/**
	 * Admin screens inside and outside the plugin's own matrix.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_any_screen(): array {
		return array(
			'dashboard'     => array( 'index.php', 'dashboard' ),
			'posts list'    => array( 'edit.php', 'edit-post' ),
			'products list' => array( 'edit.php', 'edit-' . Screen::POST_TYPE ),
			'settings page' => array( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK ),
			'catalog'       => array( Screen::CATALOG_HOOK, Screen::CATALOG_HOOK ),
			'plugins'       => array( 'plugins.php', 'plugins' ),
		);
	}

	/**
	 * The hook the enqueuer runs on registers the shell stylesheet.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shell_style_is_registered_on_admin_enqueue_scripts(): void {
		$this->require_build( 'shell.css' );

		set_current_screen( 'dashboard' );

		$assets = new Assets( Plugin::instance()->settings() );
		$assets->register();

		do_action( 'admin_enqueue_scripts', 'index.php' );

		remove_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );

		$this->assertTrue( wp_style_is( $this->shell_handle(), 'registered' ) );
	}

	/**
	 * The products list loads the kit for its Readiness pills and rule chip (UI phase U08).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shell_style_is_enqueued_on_the_products_list(): void {
		$this->require_build( 'shell.css' );

		$this->enqueue_on( 'edit.php', 'edit-' . Screen::POST_TYPE );

		$this->assertTrue( wp_style_is( $this->shell_handle(), 'enqueued' ) );
	}

	/**
	 * The posts list is not a Publish Guard screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shell_style_is_not_enqueued_on_the_posts_list(): void {
		$this->enqueue_on( 'edit.php', 'edit-post' );

		$this->assertFalse( wp_style_is( $this->shell_handle(), 'enqueued' ) );
	}

	/**
	 * Registered is not enqueued: the WordPress dashboard loads none of it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shell_style_is_not_enqueued_on_the_wordpress_dashboard(): void {
		$this->enqueue_on( 'index.php', 'dashboard' );

		$this->assertFalse( wp_style_is( $this->shell_handle(), 'enqueued' ) );
	}

	/**
	 * An unbuilt checkout leaves the shell stylesheet unregistered, without an error.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_missing_build_leaves_it_unregistered_without_error(): void {
		$file  = SIT_WCPG_PATH . 'build/shell.css';
		$moved = $file . '.sit-wcpg-test';
		$built = file_exists( $file );

		// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- Test fixture: hides the build artefact for one assertion.
		if ( $built ) {
			rename( $file, $moved );
		}

		try {
			$this->enqueue_on( 'edit.php', 'edit-' . Screen::POST_TYPE );

			$this->assertFalse( wp_style_is( $this->shell_handle(), 'registered' ) );
		} finally {
			if ( $built ) {
				rename( $moved, $file );
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
	}
}
