<?php
/**
 * Stand-ins for the WooCommerce product subclasses, used only when WooCommerce is not loaded.
 *
 * The engine checks these with `instanceof`, so the unit suite needs real classes under
 * WooCommerce's own names. Each one only fixes its type and, for the variation, reproduces
 * the two `view`-context inheritances the engine must not be fooled by.
 *
 * @package ProductPublishGuard
 */

// The whole point of these classes is to answer to WooCommerce's own names, and they
// belong together.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Minimal WooCommerce variation double.
 *
 * @since 1.1.0
 */
class WC_Product_Variation extends WC_Product {

	/**
	 * Product type.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_type() {
		return (string) $this->sit_wcpg_value( 'type', 'variation' );
	}

	/**
	 * SKU. Outside the `edit` context an empty SKU inherits the seeded `parent_sku`, as
	 * WooCommerce does.
	 *
	 * @since 1.1.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_sku( $context = 'view' ) {
		$sku = parent::get_sku( $context );

		if ( 'edit' !== $context && '' === $sku ) {
			return (string) $this->sit_wcpg_value( 'parent_sku', '' );
		}

		return $sku;
	}

	/**
	 * Stock quantity. Outside the `edit` context a parent-managed variation reports the
	 * seeded `parent_stock_quantity`, as WooCommerce does.
	 *
	 * @since 1.1.0
	 *
	 * @param string $context Read context.
	 * @return int|null
	 */
	public function get_stock_quantity( $context = 'view' ) {
		if ( 'edit' !== $context && 'parent' === $this->get_manage_stock( $context ) ) {
			return $this->sit_wcpg_value( 'parent_stock_quantity', null );
		}

		return parent::get_stock_quantity( $context );
	}
}

/**
 * Minimal WooCommerce variable product double.
 *
 * @since 1.1.0
 */
class WC_Product_Variable extends WC_Product {

	/**
	 * Product type.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_type() {
		return (string) $this->sit_wcpg_value( 'type', 'variable' );
	}
}

/**
 * Minimal WooCommerce grouped product double.
 *
 * @since 1.1.0
 */
class WC_Product_Grouped extends WC_Product {

	/**
	 * Product type.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_type() {
		return (string) $this->sit_wcpg_value( 'type', 'grouped' );
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
