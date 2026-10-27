<?php
/**
 * The admin footer on the plugin's settings screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces core's footer text and version on the settings screen.
 *
 * The left side asks for a WordPress.org review, the way WooCommerce does on its own
 * screens; the right side shows the plugin's version instead of WordPress's. Every other
 * admin screen keeps core's footer untouched.
 *
 * @since 1.1.0
 */
final class Settings_Footer {

	/**
	 * Where the review link points: the plugin's WordPress.org review form, five stars preselected.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/sapphireit-publish-guard/reviews?rate=5#new-post';

	/**
	 * Priority of the `update_footer` filter.
	 *
	 * Core adds `core_update_footer` at the default priority 10, so this runs after it.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const VERSION_PRIORITY = 11;

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'admin_footer_text', array( $this, 'filter_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'filter_version' ), self::VERSION_PRIORITY );
	}

	/**
	 * The review request, on the settings screen only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Footer text from core or an earlier filter.
	 * @return string
	 */
	public function filter_footer_text( $text ) {
		if ( ! Screen::is_settings_screen() ) {
			return $text;
		}

		$stars = '<a href="' . esc_url( self::REVIEW_URL ) . '" class="sit-wcpg-rating-link" target="_blank" rel="noopener noreferrer"'
			. ' aria-label="' . esc_attr__( 'five star rating (opens in a new tab)', 'sapphireit-publish-guard' ) . '">'
			. '&#9733;&#9733;&#9733;&#9733;&#9733;</a>';

		return sprintf(
			/* translators: 1: plugin name, 2: five-star rating link. */
			esc_html__( 'If you like %1$s please leave us a %2$s rating. A huge thanks in advance!', 'sapphireit-publish-guard' ),
			'<strong>' . esc_html__( 'SapphireIT Publish Guard', 'sapphireit-publish-guard' ) . '</strong>',
			$stars
		);
	}

	/**
	 * The plugin's version, on the settings screen only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $content Footer version text from core or an earlier filter.
	 * @return string
	 */
	public function filter_version( $content ) {
		if ( ! Screen::is_settings_screen() ) {
			return $content;
		}

		return sprintf(
			/* translators: %s: plugin version number. */
			esc_html__( 'Version %s', 'sapphireit-publish-guard' ),
			esc_html( SIT_WCPG_VERSION )
		);
	}
}
