<?php
/**
 * A stand-in for WC_Product, used only when WooCommerce is not loaded.
 *
 * Just enough of the CRUD getter surface for Product_Context to read a product. Only the
 * unit suite sees this class; the integration suite gets the real one.
 *
 * @package ProductPublishGuard
 */

// The whole point of this class is to answer to WooCommerce's own name.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

/**
 * Minimal WooCommerce product double.
 *
 * @since 1.0.0
 */
class WC_Product {

	/**
	 * Stored product values.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $sit_wcpg_values;

	/**
	 * Construct the double.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Product values, keyed like the getters below.
	 */
	public function __construct( array $values = array() ) {
		$this->sit_wcpg_values = $values;
	}

	/**
	 * Read a stored value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Value key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	private function sit_wcpg_value( string $key, $default_value ) {
		return $this->sit_wcpg_values[ $key ] ?? $default_value;
	}

	/**
	 * Product id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->sit_wcpg_value( 'id', 0 );
	}

	/**
	 * Product type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_type() {
		return (string) $this->sit_wcpg_value( 'type', 'simple' );
	}

	/**
	 * Post status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_status( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'status', 'draft' );
	}

	/**
	 * Product name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_name( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'name', '' );
	}

	/**
	 * Long description.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_description( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'description', '' );
	}

	/**
	 * Short description.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_short_description( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'short_description', '' );
	}

	/**
	 * Featured image id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return int
	 */
	public function get_image_id( $context = 'view' ) {
		unset( $context );

		return (int) $this->sit_wcpg_value( 'image_id', 0 );
	}

	/**
	 * Gallery image ids.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return array
	 */
	public function get_gallery_image_ids( $context = 'view' ) {
		unset( $context );

		return (array) $this->sit_wcpg_value( 'gallery_image_ids', array() );
	}

	/**
	 * Regular price.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_regular_price( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'regular_price', '' );
	}

	/**
	 * Sale price.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_sale_price( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'sale_price', '' );
	}

	/**
	 * Start of the sale window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return DateTimeInterface|null
	 */
	public function get_date_on_sale_from( $context = 'view' ) {
		unset( $context );

		return $this->sit_wcpg_value( 'date_on_sale_from', null );
	}

	/**
	 * End of the sale window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return DateTimeInterface|null
	 */
	public function get_date_on_sale_to( $context = 'view' ) {
		unset( $context );

		return $this->sit_wcpg_value( 'date_on_sale_to', null );
	}

	/**
	 * Product SKU.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_sku( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'sku', '' );
	}

	/**
	 * Stock status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return string
	 */
	public function get_stock_status( $context = 'view' ) {
		unset( $context );

		return (string) $this->sit_wcpg_value( 'stock_status', 'instock' );
	}

	/**
	 * Whether stock is managed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return bool
	 */
	public function get_manage_stock( $context = 'view' ) {
		unset( $context );

		return (bool) $this->sit_wcpg_value( 'manage_stock', false );
	}

	/**
	 * Managed stock quantity.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Read context.
	 * @return int|null
	 */
	public function get_stock_quantity( $context = 'view' ) {
		unset( $context );

		return $this->sit_wcpg_value( 'stock_quantity', null );
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
