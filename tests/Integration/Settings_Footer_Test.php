<?php
/**
 * Tests for the settings-screen footer.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Admin\Settings_Footer;
use WP_UnitTestCase;

/**
 * The settings screen's footer asks for a review and shows the plugin's version; every
 * other admin screen keeps core's footer.
 *
 * @since 1.1.0
 */
final class Settings_Footer_Test extends WP_UnitTestCase {

	/**
	 * The footer under test.
	 *
	 * @since 1.1.0
	 * @var Settings_Footer
	 */
	private Settings_Footer $footer;

	/**
	 * A fresh footer.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->footer = new Settings_Footer();
	}

	/**
	 * Leave no screen behind.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * Both filters are hooked, the version one after core's `core_update_footer`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_register_adds_both_filters() {
		$this->footer->register();

		$this->assertSame( 10, has_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) ) );
		$this->assertSame( Settings_Footer::VERSION_PRIORITY, has_filter( 'update_footer', array( $this->footer, 'filter_version' ) ) );
		$this->assertGreaterThan( has_filter( 'update_footer', 'core_update_footer' ), Settings_Footer::VERSION_PRIORITY );

		remove_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) );
		remove_filter( 'update_footer', array( $this->footer, 'filter_version' ), Settings_Footer::VERSION_PRIORITY );
	}

	/**
	 * On the settings screen the footer names the plugin and links to its review form.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_settings_screen_asks_for_a_review() {
		set_current_screen( Screen::SETTINGS_HOOK );

		$text = $this->footer->filter_footer_text( 'core text' );

		$this->assertStringContainsString( '<strong>SapphireIT Publish Guard</strong>', $text );
		$this->assertStringContainsString( 'href="' . esc_url( Settings_Footer::REVIEW_URL ) . '"', $text );
		$this->assertStringContainsString( 'class="sit-wcpg-rating-link"', $text );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $text );
		$this->assertStringContainsString( '>review<span class="screen-reader-text"> (opens in a new tab)</span></a>', $text );
		$this->assertStringNotContainsString( 'core text', $text );
	}

	/**
	 * The review link is neutral: no rating is preselected or asked for.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_review_request_does_not_ask_for_a_rating() {
		set_current_screen( Screen::SETTINGS_HOOK );

		$text = $this->footer->filter_footer_text( 'core text' );

		$this->assertStringNotContainsString( 'rate=', Settings_Footer::REVIEW_URL );
		$this->assertStringNotContainsString( '&#9733;', $text );
		$this->assertStringNotContainsString( 'five star', strtolower( $text ) );
	}

	/**
	 * On the settings screen the version is the plugin's.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_settings_screen_shows_the_plugin_version() {
		set_current_screen( Screen::SETTINGS_HOOK );

		$this->assertSame( 'Version ' . SIT_WCPG_VERSION, $this->footer->filter_version( 'Version 7.1.3' ) );
	}

	/**
	 * Any other screen keeps whatever it was given.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_other_screens_keep_the_core_footer() {
		set_current_screen( 'edit-product' );

		$this->assertSame( 'core text', $this->footer->filter_footer_text( 'core text' ) );
		$this->assertSame( 'Version 7.1.3', $this->footer->filter_version( 'Version 7.1.3' ) );
	}
}
