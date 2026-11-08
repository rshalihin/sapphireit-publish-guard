<?php
/**
 * One enabled variation, as the rule engine sees it.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable snapshot of the variation values the rules read.
 *
 * Built by `Child_Loader` from stored variations, or by `from_array()` in the unit suite.
 * No message may ever interpolate its strings: only its integers reach a message.
 *
 * @since 1.1.0
 */
final class Variation_Data {

	/**
	 * The variation manages its own stock.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const MANAGE_YES = 'yes';

	/**
	 * Stock is not managed.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const MANAGE_NO = 'no';

	/**
	 * The parent manages the stock for the variation.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const MANAGE_PARENT = 'parent';

	/**
	 * The values, keyed like the `from_array()` input, already normalized.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $values;

	/**
	 * Build a snapshot. Use one of the factories instead.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values Normalized values.
	 */
	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Read a stored variation.
	 *
	 * Everything is read in the `edit` context, so nothing is inherited from the parent,
	 * except the two values whose inheritance the rules rely on: manage stock, which only
	 * reports `'parent'` outside `edit`, and the quantity that goes with it.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Product $variation The stored variation.
	 * @return Variation_Data
	 */
	public static function from_product( \WC_Product $variation ): Variation_Data {
		$manage = $variation->get_manage_stock( 'view' );

		if ( self::MANAGE_PARENT === $manage ) {
			$manage = self::MANAGE_PARENT;
		} else {
			$manage = $manage ? self::MANAGE_YES : self::MANAGE_NO;
		}

		return self::from_array(
			array(
				'id'                => $variation->get_id(),
				'regular_price'     => $variation->get_regular_price( 'edit' ),
				'sale_price'        => $variation->get_sale_price( 'edit' ),
				'sale_from'         => self::date( $variation->get_date_on_sale_from( 'edit' ) ),
				'sale_to'           => self::date( $variation->get_date_on_sale_to( 'edit' ) ),
				'sku'               => $variation->get_sku( 'edit' ),
				'stock_status'      => $variation->get_stock_status( 'edit' ),
				'manage_stock'      => $manage,
				'stock_quantity'    => $variation->get_stock_quantity( 'view' ),
				'image_id'          => $variation->get_image_id( 'edit' ),
				'gallery_image_ids' => $variation->get_gallery_image_ids( 'edit' ),
			)
		);
	}

	/**
	 * Build a snapshot from a plain array. Absent keys take a safe default.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Variation values.
	 * @return Variation_Data
	 */
	public static function from_array( array $data ): Variation_Data {
		$values = array(
			'id'                => max( 0, (int) ( $data['id'] ?? 0 ) ),
			'image_id'          => max( 0, (int) ( $data['image_id'] ?? 0 ) ),
			'gallery_image_ids' => Product_Context::coerce_ids( $data['gallery_image_ids'] ?? array() ),
			'manage_stock'      => self::MANAGE_NO,
			'stock_quantity'    => null,
		);

		foreach ( array( 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'sku', 'stock_status' ) as $key ) {
			$values[ $key ] = ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) ? (string) $data[ $key ] : '';
		}

		$manage = $data['manage_stock'] ?? '';

		if ( in_array( $manage, array( self::MANAGE_YES, self::MANAGE_NO, self::MANAGE_PARENT ), true ) ) {
			$values['manage_stock'] = $manage;
		}

		$quantity = $data['stock_quantity'] ?? null;

		if ( null !== $quantity && '' !== $quantity ) {
			$values['stock_quantity'] = (int) $quantity;
		}

		return new self( $values );
	}

	/**
	 * Format a WooCommerce date as a comparable string.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $date Date object or null.
	 * @return string Empty string when the date is not set.
	 */
	private static function date( $date ): string {
		return $date instanceof \DateTimeInterface ? $date->format( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * Variation identifier.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->values['id'];
	}

	/**
	 * Regular price, as stored. Kept as a string so "0" and "" stay distinguishable.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_regular_price(): string {
		return $this->values['regular_price'];
	}

	/**
	 * Sale price, as stored.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_sale_price(): string {
		return $this->values['sale_price'];
	}

	/**
	 * Start of the sale window as `Y-m-d H:i:s`, or an empty string.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_sale_from(): string {
		return $this->values['sale_from'];
	}

	/**
	 * End of the sale window as `Y-m-d H:i:s`, or an empty string.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_sale_to(): string {
		return $this->values['sale_to'];
	}

	/**
	 * The variation's own SKU, never the one it inherits from the parent.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_sku(): string {
		return $this->values['sku'];
	}

	/**
	 * Stock status, for example `instock`.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_stock_status(): string {
		return $this->values['stock_status'];
	}

	/**
	 * Who manages the stock: one of the MANAGE_ constants.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_manage_stock(): string {
		return $this->values['manage_stock'];
	}

	/**
	 * Managed stock quantity, the parent's under parent-managed stock, or null.
	 *
	 * @since 1.1.0
	 *
	 * @return int|null
	 */
	public function get_stock_quantity(): ?int {
		return $this->values['stock_quantity'];
	}

	/**
	 * The variation's own image, zero when there is none.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_image_id(): int {
		return $this->values['image_id'];
	}

	/**
	 * The variation's own gallery image identifiers.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_gallery_image_ids(): array {
		return $this->values['gallery_image_ids'];
	}
}
