<?php
/**
 * The settings screen's script (UI phase U07).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use DOMDocument;
use DOMXPath;
use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Settings\Settings_Sanitizer;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WP_UnitTestCase;

/**
 * The `sit-wcpg-settings` script loads on the settings screen only, with translations,
 * and the screen is complete without it (UI decisions U8, U15).
 *
 * @since 1.2.0
 */
final class Settings_Assets_Test extends WP_UnitTestCase {

	/**
	 * Fresh registries, an unsaved option and an administrator.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_dependencies();

		delete_option( Settings::OPTION_NAME );
		delete_option( Readiness_Rebuild::OPTION );
		Plugin::instance()->settings()->refresh();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no screen, settings error or enqueued asset behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['wp_settings_errors'] );

		$this->reset_dependencies();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();

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
	 * Skip when the settings script has not been built.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function require_build(): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/settings.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/settings.js is missing.' );
		}
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
	 * The settings screen as rendered, parsed.
	 *
	 * @since 1.2.0
	 *
	 * @return DOMXPath
	 */
	private function render(): DOMXPath {
		$registry = new Rule_Registry();

		Rules_Provider::populate( $registry );

		$settings = new Settings( $registry );
		$rebuild  = new Readiness_Rebuild( $settings, new Readiness_Indexer( $settings, new Readiness_Store() ) );

		ob_start();
		( new Settings_Page( $settings, $registry, $rebuild ) )->render_page();
		$html = (string) ob_get_clean();

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}

	/**
	 * The script loads on the settings screen, after dom-ready and i18n, and nowhere else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_settings_script_enqueued_only_on_the_settings_screen(): void {
		$this->require_build();

		$this->enqueue_on( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK );

		$this->assertTrue( wp_script_is( Assets::SETTINGS_HANDLE, 'enqueued' ) );

		$deps = wp_scripts()->registered[ Assets::SETTINGS_HANDLE ]->deps;

		$this->assertContains( 'wp-dom-ready', $deps );
		$this->assertContains( 'wp-i18n', $deps );
		$this->assertNotContains( 'jquery', $deps );

		$others = array(
			array( Screen::DASHBOARD_HOOK, Screen::DASHBOARD_HOOK ),
			array( Screen::CATALOG_HOOK, Screen::CATALOG_HOOK ),
			array( 'edit.php', 'edit-' . Screen::POST_TYPE ),
			array( 'plugins.php', 'plugins' ),
			array( 'index.php', 'dashboard' ),
			array( 'options-general.php', 'options-general' ),
		);

		foreach ( $others as $screen ) {
			$this->reset_dependencies();
			$this->enqueue_on( $screen[0], $screen[1] );

			$this->assertFalse( wp_script_is( Assets::SETTINGS_HANDLE, 'enqueued' ), $screen[0] );
		}
	}

	/**
	 * The script's strings are translatable.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_translations_set(): void {
		$this->require_build();

		$this->enqueue_on( Screen::SETTINGS_HOOK, Screen::SETTINGS_HOOK );

		$script = wp_scripts()->registered[ Assets::SETTINGS_HANDLE ];

		$this->assertSame( 'sapphireit-publish-guard', $script->textdomain );
		$this->assertSame( SIT_WCPG_PATH . 'languages', $script->translations_path );
	}

	/**
	 * Without the script every section shows, Discard and the save state stay hidden, and
	 * both Save buttons submit the form to `options.php`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_script_missing_page_still_renders_and_submits(): void {
		$xpath = $this->render();
		$form  = $xpath->query( '//form[@data-sit-wcpg-settings-form]' );

		$this->assertSame( 1, $form->length );
		$this->assertSame( 'options.php', $form->item( 0 )->getAttribute( 'action' ) );
		$this->assertSame( 'post', $form->item( 0 )->getAttribute( 'method' ) );
		$this->assertSame( 1, $xpath->query( '//form//input[@name="option_page" and @value="' . Settings_Page::OPTION_GROUP . '"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//input[@name="_wpnonce"]' )->length );

		$sections = $xpath->query( '//form//*[@data-sit-wcpg-section]' );

		$this->assertGreaterThan( 3, $sections->length );

		foreach ( $sections as $section ) {
			$this->assertFalse( $section->hasAttribute( 'hidden' ), $section->getAttribute( 'id' ) . ' shows without JavaScript.' );
		}

		$bars = $xpath->query( '//form//*[@data-sit-wcpg-savebar]' );

		$this->assertSame( 2, $bars->length );

		foreach ( $bars as $bar ) {
			$save = $xpath->query( './/input[@type="submit"]', $bar );

			$this->assertSame( 1, $save->length );
			$this->assertFalse( $save->item( 0 )->hasAttribute( 'disabled' ) );
			$this->assertFalse( $save->item( 0 )->hasAttribute( 'form' ), 'Save sits inside the form.' );

			$discard = $xpath->query( './/button[@data-sit-wcpg-discard]', $bar );

			$this->assertSame( 1, $discard->length );
			$this->assertSame( 'button', $discard->item( 0 )->getAttribute( 'type' ) );
			$this->assertTrue( $discard->item( 0 )->hasAttribute( 'hidden' ) );

			$this->assertTrue( $xpath->query( './/*[@data-sit-wcpg-savestate]', $bar )->item( 0 )->hasAttribute( 'hidden' ) );
		}

		// The status is announced once: the top bar's text is the live region.
		$this->assertSame( 1, $xpath->query( '//*[@data-sit-wcpg-savebar="top"]//*[@data-sit-wcpg-status and @aria-live="polite"]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@data-sit-wcpg-savebar="bottom"]//*[@data-sit-wcpg-status and @aria-hidden="true" and not(@aria-live)]' )->length );

		$this->assertSame( 1, $xpath->query( '//form//*[@data-sit-wcpg-section-nav]' )->length );
		$this->assertFalse( $form->item( 0 )->hasAttribute( 'data-sit-wcpg-invalid' ) );
		$this->assertSame( 0, $xpath->query( '//script' )->length, 'No inline JavaScript.' );
	}

	/**
	 * A clamped threshold names its field, so the script opens that field's section.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_clamped_threshold_names_the_invalid_field(): void {
		add_settings_error( Settings::OPTION_NAME, Settings_Sanitizer::CLAMP_ERROR_PREFIX . 'min_images', 'Clamped.', 'warning' );

		$xpath = $this->render();
		$form  = $xpath->query( '//form[@data-sit-wcpg-settings-form]' )->item( 0 );
		$id    = $form->getAttribute( 'data-sit-wcpg-invalid' );

		$this->assertSame( 'sit-wcpg-threshold-min-images', $id );

		$field = $xpath->query( '//input[@id="' . $id . '"]' );

		$this->assertSame( 1, $field->length );
		$this->assertSame( Settings::OPTION_NAME . '[thresholds][min_images]', $field->item( 0 )->getAttribute( 'name' ) );
		$this->assertSame( 1, $xpath->query( 'ancestor::*[@data-sit-wcpg-section]', $field->item( 0 ) )->length );
	}

	/**
	 * An unrelated notice (core's "Settings saved.") names no field.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_saved_notice_names_no_field(): void {
		add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
		add_settings_error( Settings::OPTION_NAME, Settings_Sanitizer::CLAMP_ERROR_PREFIX . 'not_a_threshold', 'Odd.', 'warning' );

		$form = $this->render()->query( '//form[@data-sit-wcpg-settings-form]' )->item( 0 );

		$this->assertFalse( $form->hasAttribute( 'data-sit-wcpg-invalid' ) );
	}
}
