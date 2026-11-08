<?php
/**
 * Tests for the one-time welcome notice.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Activation_Notice;
use WP_UnitTestCase;

/**
 * Activation flags the notice; the next Plugins screen a settings user loads prints it
 * once, and nothing else does.
 *
 * @since 1.1.0
 */
final class Activation_Notice_Test extends WP_UnitTestCase {

	/**
	 * The notice under test.
	 *
	 * @since 1.1.0
	 * @var Activation_Notice
	 */
	private Activation_Notice $notice;

	/**
	 * A fresh notice, no flag and an administrator.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Activation_Notice::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->notice = new Activation_Notice();
	}

	/**
	 * Leave no screen or flag behind.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );
		delete_option( Activation_Notice::OPTION );

		parent::tear_down();
	}

	/**
	 * Print the notice and return what it printed.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->notice->render();

		return (string) ob_get_clean();
	}

	/**
	 * The notice prints on `admin_notices`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_notices() {
		$this->notice->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $this->notice, 'render' ) ) );

		remove_action( 'admin_notices', array( $this->notice, 'render' ) );
	}

	/**
	 * Activating the plugin flags the notice without autoloading the flag.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_activation_flags_the_notice() {
		$this->assertFalse( Activation_Notice::is_pending() );

		Activation_Notice::activate();

		$this->assertTrue( Activation_Notice::is_pending() );
		$this->assertArrayNotHasKey( Activation_Notice::OPTION, wp_load_alloptions() );
	}

	/**
	 * The Plugins screen shows the logo, the welcome and the settings link once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_plugins_screen_shows_it_once() {
		Activation_Notice::activate();
		set_current_screen( 'plugins' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice notice-success is-dismissible sit-wcpg-brand-notice sit-wcpg-activation-notice', $html );
		$this->assertStringContainsString( 'src="' . esc_url( SIT_WCPG_URL . Activation_Notice::LOGO ) . '"', $html );
		$this->assertStringContainsString( 'Thanks for installing SapphireIT Publish Guard!', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=sit-wcpg-settings' ) ) . '"', $html );
		$this->assertFileExists( SIT_WCPG_PATH . Activation_Notice::LOGO );

		$this->assertFalse( Activation_Notice::is_pending() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Any other screen leaves the notice waiting.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_other_screens_leave_it_pending() {
		Activation_Notice::activate();
		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->render() );
		$this->assertTrue( Activation_Notice::is_pending() );
	}

	/**
	 * A user who cannot open the settings page leaves it waiting for one who can.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_leaves_it_pending() {
		Activation_Notice::activate();
		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render() );
		$this->assertTrue( Activation_Notice::is_pending() );
	}
}
