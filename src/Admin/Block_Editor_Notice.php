<?php
/**
 * The notice shown while WooCommerce's product block editor is turned on.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Compat\Requirements;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the merchant the checklist panel is unavailable (coding-plan.md section 7.2.1, A7).
 *
 * The panel is built for the classic product editor only. Older WooCommerce versions let
 * a store switch products to the block editor, where the panel cannot mount; publishing
 * is still enforced there, because that editor saves through the WooCommerce REST API
 * (Layer B). WooCommerce 11 force-disables the block editor for products, so on a
 * current install this notice never appears.
 *
 * It is printed on the products list and the plugin settings screen only — the classic
 * screens a merchant still reaches, since the block editor is a `wc-admin` React page.
 *
 * @since 1.0.0
 */
final class Block_Editor_Notice {

	/**
	 * Capability needed to see the notice.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CAPABILITY = 'edit_products';

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings The merchant's configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Print the notice when the block editor is handling products.
	 *
	 * The screen, the setting and the capability are checked before the editor
	 * detection, so every other admin screen pays for one screen comparison only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Screen::is_product_list_screen() && ! Screen::is_settings_screen() ) {
			return;
		}

		if (
			! $this->settings->is_enabled()
			|| ! current_user_can( self::CAPABILITY )
			|| ! Requirements::is_product_block_editor_active()
		) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'The product checklist is not shown in the new product editor.', 'sapphireit-publish-guard' );
		echo '</strong></p><p>';
		echo esc_html__( 'SapphireIT Publish Guard shows its checklist in the classic product editor only. WooCommerce\'s new product editor is turned on, so the checklist will not appear while you edit products. Publishing rules are still enforced whenever a product is saved.', 'sapphireit-publish-guard' );
		echo '</p><p>';
		echo esc_html__( 'To see the checklist again, turn the new product editor off under WooCommerce, Settings, Advanced, Features.', 'sapphireit-publish-guard' );
		echo '</p></div>';
	}
}
