<?php
/**
 * Tests for the filter that loads an add-on's script before the editor panel.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * An add-on's script adds its JS filters before the panel's script runs, so it has to be a
 * dependency of it. The filter can add registered handles and nothing else.
 *
 * @since 1.2.0
 */
final class Editor_Script_Dependencies_Test extends WP_UnitTestCase {

	/**
	 * A clean script registry and an administrator.
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

		if ( ! file_exists( SIT_WCPG_PATH . 'build/editor.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/editor.js is missing.' );
		}
	}

	/**
	 * Leave no screen, post or script registry behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );

		$this->reset_dependencies();
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * Throw away the script and style registries.
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
	 * Enqueue the editor panel for a product and return its script's dependencies.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private function editor_dependencies(): array {
		$product = new WC_Product_Simple();
		$product->set_name( 'Edited product' );
		$product->save();

		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		set_current_screen( Screen::POST_TYPE );
		( new Assets( Plugin::instance()->settings() ) )->enqueue( 'post.php' );

		return wp_scripts()->registered[ Assets::EDITOR_HANDLE ]->deps;
	}

	/**
	 * The panel's own dependencies, as the build wrote them.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private function core_dependencies(): array {
		$asset = require SIT_WCPG_PATH . 'build/editor.asset.php';

		return $asset['dependencies'];
	}

	/**
	 * A handle an add-on registered becomes a dependency.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_registered_handle_is_added(): void {
		wp_register_script( 'acme-editor', 'https://example.org/acme.js', array(), '1', true );

		add_filter(
			'sit_wcpg_editor_script_dependencies',
			static function ( array $deps ): array {
				$deps[] = 'acme-editor';

				return $deps;
			}
		);

		$deps = $this->editor_dependencies();

		$this->assertContains( 'acme-editor', $deps );
		$this->assertSame( array_merge( $this->core_dependencies(), array( 'acme-editor' ) ), $deps );
	}

	/**
	 * A handle nobody registered, and a value that is not a handle, are dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unregistered_handle_is_dropped(): void {
		add_filter(
			'sit_wcpg_editor_script_dependencies',
			static function ( array $deps ): array {
				$deps[] = 'never-registered';
				$deps[] = array( 'not', 'a', 'handle' );
				$deps[] = '';

				return $deps;
			}
		);

		$this->assertSame( $this->core_dependencies(), $this->editor_dependencies() );
	}

	/**
	 * Whatever the filter returns, the panel keeps what it needs to run.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_core_dependencies_cannot_be_removed(): void {
		add_filter( 'sit_wcpg_editor_script_dependencies', '__return_empty_array' );

		$this->assertSame( $this->core_dependencies(), $this->editor_dependencies() );

		remove_all_filters( 'sit_wcpg_editor_script_dependencies' );
		add_filter( 'sit_wcpg_editor_script_dependencies', '__return_false' );

		$this->reset_dependencies();

		$this->assertSame( $this->core_dependencies(), $this->editor_dependencies() );
	}
}
