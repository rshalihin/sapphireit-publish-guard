<?php
/**
 * Environment requirement checks and the failure notice.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Compat;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the plugin is allowed to boot.
 *
 * Failures are collected as structured data, never as translated strings: `check()`
 * runs on `plugins_loaded`, and calling a translation function that early triggers
 * WordPress 6.7's "translation loaded too early" notice. The strings are built in
 * render_notice(), which runs on `admin_notices` — long after `init`.
 *
 * @since 1.0.0
 */
final class Requirements {

	/**
	 * Collected failures for this request.
	 *
	 * Each entry is an array with a `code` key plus whatever that code needs to build
	 * its message (`required`, `actual`).
	 *
	 * @since 1.0.0
	 * @var array<int, array<string, string>>
	 */
	private static array $failures = array();

	/**
	 * Run every requirement check.
	 *
	 * On failure it registers the admin notice and returns false; the caller must then
	 * not boot the plugin. Nothing else in the plugin is loaded in that case.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the environment satisfies every requirement.
	 */
	public static function check(): bool {
		self::$failures = array();

		if ( version_compare( PHP_VERSION, SIT_WCPG_MIN_PHP, '<' ) ) {
			self::$failures[] = array(
				'code'     => 'php',
				'required' => SIT_WCPG_MIN_PHP,
				'actual'   => PHP_VERSION,
			);
		}

		$wp_version = get_bloginfo( 'version' );

		if ( version_compare( $wp_version, SIT_WCPG_MIN_WP, '<' ) ) {
			self::$failures[] = array(
				'code'     => 'wp',
				'required' => SIT_WCPG_MIN_WP,
				'actual'   => $wp_version,
			);
		}

		if ( ! self::woocommerce_is_active() ) {
			self::$failures[] = array( 'code' => 'wc_missing' );
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, SIT_WCPG_MIN_WC, '<' ) ) {
			self::$failures[] = array(
				'code'     => 'wc_version',
				'required' => SIT_WCPG_MIN_WC,
				'actual'   => WC_VERSION,
			);
		}

		if ( empty( self::$failures ) ) {
			return true;
		}

		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );

		return false;
	}

	/**
	 * Get the failures recorded by the last check().
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, string>> Structured failure records.
	 */
	public static function get_failures(): array {
		return self::$failures;
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * The `Requires Plugins: woocommerce` header already prevents activation without
	 * WooCommerce on WordPress 6.5+, but WooCommerce can still be deactivated
	 * afterwards, so the runtime check stays.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function woocommerce_is_active(): bool {
		return class_exists( 'WooCommerce', false ) || function_exists( 'WC' );
	}

	/**
	 * Whether the WooCommerce product block editor is handling the product post type.
	 *
	 * Defensive only. WooCommerce 11 force-disables the block editor for products and
	 * no longer registers the `product_block_editor` feature at all, so this returns
	 * false on any current install. It exists for older WooCommerce versions, where the
	 * editor panel cannot mount and only server-side enforcement applies.
	 *
	 * `FeaturesUtil::feature_is_enabled()` is used behind method_exists() because
	 * `Features::is_enabled()` is deprecated as of WooCommerce 11.1.0.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_product_block_editor_active(): bool {
		if (
			class_exists( FeaturesUtil::class )
			&& method_exists( FeaturesUtil::class, 'feature_is_enabled' )
			&& FeaturesUtil::feature_is_enabled( 'product_block_editor' )
		) {
			return true;
		}

		// Admin-only core function; absent on front-end and REST requests.
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			return false;
		}

		return (bool) use_block_editor_for_post_type( 'product' );
	}

	/**
	 * Render the requirement failure notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( empty( self::$failures ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Product Publish Guard has been stopped.', 'product-publish-guard' );
		echo '</strong></p><ul class="ul-disc">';

		foreach ( self::$failures as $failure ) {
			echo '<li>' . esc_html( self::describe( $failure ) ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Build the human-readable message for one failure record.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $failure A record produced by check().
	 * @return string Translated, unescaped message.
	 */
	private static function describe( array $failure ): string {
		$required = isset( $failure['required'] ) ? $failure['required'] : '';
		$actual   = isset( $failure['actual'] ) ? $failure['actual'] : '';

		switch ( $failure['code'] ) {
			case 'php':
				return sprintf(
					/* translators: 1: minimum required PHP version, 2: PHP version running on this site. */
					__( 'PHP %1$s or newer is required. This site runs PHP %2$s. Ask your host to upgrade PHP.', 'product-publish-guard' ),
					$required,
					$actual
				);

			case 'wp':
				return sprintf(
					/* translators: 1: minimum required WordPress version, 2: WordPress version on this site. */
					__( 'WordPress %1$s or newer is required. This site runs WordPress %2$s. Update WordPress from Dashboard, then Updates.', 'product-publish-guard' ),
					$required,
					$actual
				);

			case 'wc_version':
				return sprintf(
					/* translators: 1: minimum required WooCommerce version, 2: WooCommerce version on this site. */
					__( 'WooCommerce %1$s or newer is required. This site runs WooCommerce %2$s. Update WooCommerce from Plugins.', 'product-publish-guard' ),
					$required,
					$actual
				);

			case 'wc_missing':
			default:
				return __( 'WooCommerce is not active. Activate WooCommerce to use the product checklist.', 'product-publish-guard' );
		}
	}
}
