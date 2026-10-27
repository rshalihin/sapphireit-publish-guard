<?php
/**
 * The welcome notice shown once after activation.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Settings\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * A one-time, dismissible "thanks for installing" notice on the Plugins screen.
 *
 * Activation only sets a flag; it never redirects. The next Plugins screen a user who can
 * open the settings page loads prints the notice and clears the flag, so it shows once per
 * activation. A bulk, network or WP-CLI activation leaves the flag waiting for that visit.
 *
 * @since 1.1.0
 */
final class Activation_Notice {

	/**
	 * Option that holds the flag until the notice is shown.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const OPTION = 'sit_wcpg_activation_notice';

	/**
	 * The plugin logo, relative to the plugin root.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const LOGO = 'assets/images/icon.svg';

	/**
	 * Activation callback: flag the notice.
	 *
	 * Not autoloaded, because it is only read on the Plugins screen.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		update_option( self::OPTION, '1', false );
	}

	/**
	 * Whether the notice is still waiting to be shown.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public static function is_pending(): bool {
		return '1' === get_option( self::OPTION );
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Print, then forget, the notice.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Screen::is_plugins_screen() || ! current_user_can( Settings_Page::CAPABILITY ) || ! self::is_pending() ) {
			return;
		}

		delete_option( self::OPTION );

		printf(
			'<div class="notice notice-success is-dismissible sit-wcpg-brand-notice sit-wcpg-activation-notice">'
				. '<img class="sit-wcpg-brand-notice__logo" src="%1$s" width="64" height="64" alt="" />'
				. '<div class="sit-wcpg-brand-notice__body">'
				. '<h3 class="sit-wcpg-brand-notice__title">%2$s</h3>'
				. '<p>%3$s</p>'
				. '<p><a class="button button-primary" href="%4$s">%5$s</a></p>'
				. '</div></div>',
			esc_url( SIT_WCPG_URL . self::LOGO ),
			esc_html__( 'Thanks for installing SapphireIT Publish Guard!', 'sapphireit-publish-guard' ),
			esc_html__( 'Every product is now checked against a quality checklist, and a product that fails a required check is kept as a draft. Review the rules to choose what is required and whether failures block publishing.', 'sapphireit-publish-guard' ),
			esc_url( admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG ) ),
			esc_html__( 'Review checklist rules', 'sapphireit-publish-guard' )
		);
	}
}
