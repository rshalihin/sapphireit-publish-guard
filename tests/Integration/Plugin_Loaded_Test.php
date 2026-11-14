<?php
/**
 * Tests for the action that tells add-ons the plugin has booted.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use WP_UnitTestCase;

/**
 * An add-on boots on `sit_wcpg_loaded`, so it must fire exactly once and hand over the plugin.
 *
 * @since 1.2.0
 */
final class Plugin_Loaded_Test extends WP_UnitTestCase {

	/**
	 * The listener the test bootstrap added before the plugin loaded received the plugin.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_loaded_fired_once_with_the_plugin(): void {
		$this->assertSame( 1, did_action( 'sit_wcpg_loaded' ) );
		$this->assertSame( Plugin::instance(), $GLOBALS['sit_wcpg_test_loaded_plugin'] ?? null );
	}

	/**
	 * Booting again registers nothing twice and announces nothing twice.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_second_boot_does_not_fire_again(): void {
		Plugin::instance()->boot();

		$this->assertSame( 1, did_action( 'sit_wcpg_loaded' ) );
	}
}
