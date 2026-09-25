<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Compat;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares which WooCommerce features this plugin is compatible with.
 *
 * Only High-Performance Order Storage is declared. The plugin never touches orders,
 * so it is compatible by construction; declaring it keeps the plugin off the
 * "incompatible" list on the WooCommerce HPOS settings screen.
 *
 * `product_block_editor` is deliberately not declared: that feature id does not exist
 * in current WooCommerce, and declare_compatibility() returns false for unknown ids.
 *
 * @since 1.0.0
 */
final class Woo_Compat {

	/**
	 * Declare feature compatibility.
	 *
	 * Must run inside `before_woocommerce_init`; FeaturesUtil refuses the declaration
	 * outside that hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', SIT_WCPG_FILE, true );
	}
}
