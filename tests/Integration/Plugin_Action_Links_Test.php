<?php
/**
 * Tests for the Plugins-screen action links.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Plugin_Action_Links;
use WP_UnitTestCase;

/**
 * The plugin's row on the Plugins screen leads with a Settings link, for users who can
 * open the settings page.
 *
 * @since 1.1.0
 */
final class Plugin_Action_Links_Test extends WP_UnitTestCase {

	/**
	 * The links under test.
	 *
	 * @since 1.1.0
	 * @var Plugin_Action_Links
	 */
	private Plugin_Action_Links $links;

	/**
	 * Fresh links.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->links = new Plugin_Action_Links();
	}

	/**
	 * The filter is hooked to this plugin's row only.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_register_filters_this_plugins_row() {
		$this->links->register();

		$hook = 'plugin_action_links_' . plugin_basename( SIT_WCPG_FILE );

		$this->assertSame( 10, has_filter( $hook, array( $this->links, 'filter_action_links' ) ) );

		remove_filter( $hook, array( $this->links, 'filter_action_links' ) );
	}

	/**
	 * A user who can manage WooCommerce gets the Settings link ahead of Deactivate.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_settings_link_comes_first() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$links = $this->links->filter_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertSame(
			'<a href="' . esc_url( admin_url( 'admin.php?page=sit-wcpg-settings' ) ) . '">Settings</a>',
			$links['settings']
		);
	}

	/**
	 * A user who cannot open the settings page gets the row unchanged.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_no_link_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$given = array( 'deactivate' => '<a href="#">Deactivate</a>' );

		$this->assertSame( $given, $this->links->filter_action_links( $given ) );
	}
}
