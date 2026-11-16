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
 * Adds "Dashboard" and "Settings" links beside core's "Deactivate" on the Plugins screen.
 *
 * The links point at the page slugs rather than menu paths, so they keep working wherever
 * the pages sit in the admin menu.
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
	 * Put the Dashboard and Settings links first in the plugin's row.
	 *
	 * Each is skipped for a user who could not open its page anyway.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 A Dashboard link comes before Settings.
	 *
	 * @param string[] $links Action links from core or an earlier filter.
	 * @return string[]
	 */
	public function filter_action_links( $links ) {
		$ours = array();

		if ( current_user_can( Dashboard_Page::CAPABILITY ) ) {
			$ours['dashboard'] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Dashboard_Page::MENU_SLUG ) ) . '">'
				. esc_html__( 'Dashboard', 'sapphireit-publish-guard' ) . '</a>';
		}

		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			$ours['settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG ) ) . '">'
				. esc_html__( 'Settings', 'sapphireit-publish-guard' ) . '</a>';
		}

		if ( array() === $ours ) {
			return $links;
		}

		return array_merge( $ours, (array) $links );
	}
}
