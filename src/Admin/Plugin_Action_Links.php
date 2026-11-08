<?php
/**
 * The plugin's row on the Plugins screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Settings\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Settings" link beside core's "Deactivate" on the Plugins screen.
 *
 * The link points at the page slug rather than a menu path, so it keeps working wherever
 * the settings page sits in the admin menu.
 *
 * @since 1.1.0
 */
final class Plugin_Action_Links {

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( SIT_WCPG_FILE ), array( $this, 'filter_action_links' ) );
	}

	/**
	 * Put the Settings link first in the plugin's row.
	 *
	 * Skipped for a user who could not open the settings page anyway.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $links Action links from core or an earlier filter.
	 * @return string[]
	 */
	public function filter_action_links( $links ) {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			return $links;
		}

		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'sapphireit-publish-guard' ) . '</a>';

		return array_merge( array( 'settings' => $settings ), (array) $links );
	}
}
