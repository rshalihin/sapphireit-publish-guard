<?php
/**
 * Normalized product data for the rule engine.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The single data boundary between the rule engine and WordPress or WooCommerce.
 *
 * Rules read this object and nothing else. That is what makes them pure functions, and
 * therefore unit-testable with no WordPress bootstrap at all (see `from_array()`).
 *
 * Every accessor is memoized and null-safe, so a full eleven-rule run touches the
 * WooCommerce data store once.
 *
 * Merge semantics for the override factories are the crux of the design: a key that is
 * **present** in the override array wins, a key that is **absent** falls back to the
 * stored product. This is what makes Quick Edit (which posts a price but no description)
 * and Bulk Edit (which posts only a status) correct with no special-casing in the rules.
 *
 * @since 1.0.0
 */
final class Product_Context {

	/**
	 * The stored product, when there is one.
	 *
	 * @since 1.0.0
	 * @var \WC_Product|null
	 */
	private ?\WC_Product $product;

	/**
	 * Seeded overrides and memoized resolutions, keyed by accessor name.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $values;

	/**
	 * The keys an override array may set, mapped to their normalizer.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private const OVERRIDABLE = array(
		'product_id'               => 'id',
		'product_type'             => 'text',
		'post_status'              => 'text',
		'title'                    => 'text',
		'description'              => 'text',
		'short_description'        => 'text',
		'featured_image_id'        => 'id',
		'has_valid_featured_image' => 'bool',
		'gallery_image_ids'        => 'ids',
		'regular_price'            => 'text',
		'sale_price'               => 'text',
		'sale_from'                => 'text',
		'sale_to'                  => 'text',
		'sku'                      => 'text',
		'stock_status'             => 'text',
		'manage_stock'             => 'bool',
		'stock_quantity'           => 'nullable_int',
		'category_ids'             => 'ids',
		'tag_ids'                  => 'ids',
		'default_category_id'      => 'id',
	);

	/**
	 * Aliases accepted from the editor draft payload, mapped to canonical keys.
	 *
	 * The browser sends the WordPress field names; the engine speaks WooCommerce.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private const ALIASES = array(
		'content' => 'description',
		'excerpt' => 'short_description',
	);

	/**
	 * Build a context. Use one of the factories instead.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product|null $product The stored product, if any.
	 * @param array            $values  Pre-seeded values, already normalized.
	 */
	private function __construct( ?\WC_Product $product, array $values ) {
		$this->product = $product;
		$this->values  = $values;
	}

	/**
	 * Build a context from a saved product.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product The saved product.
	 * @return Product_Context
	 */
	public static function from_product( \WC_Product $product ): Product_Context {
		return new self( $product, array() );
	}

	/**
	 * Build a context from a saved product plus an unsaved draft snapshot.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product   The saved product.
	 * @param array       $overrides Sanitized draft values; only present keys win.
	 * @return Product_Context
	 */
	public static function from_product_with_overrides( \WC_Product $product, array $overrides ): Product_Context {
		return new self( $product, self::normalize( $overrides ) );
	}

	/**
	 * Build a context from an in-flight save request.
	 *
	 * Same merge semantics as `from_product_with_overrides()`, but the stored product may
	 * be absent: a product inserted for the first time has nothing stored yet, and every
	 * accessor then falls back to its empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product|null $product   The stored product, or null for a new post.
	 * @param array            $overrides `Save_Request_Reader` output; only present keys win.
	 * @return Product_Context
	 */
	public static function from_save_request( ?\WC_Product $product, array $overrides ): Product_Context {
		return new self( $product, self::normalize( $overrides ) );
	}

	/**
	 * Build a context from a plain array, with no product behind it.
	 *
	 * Intended for unit tests: every accessor is pre-seeded, so no WordPress or
	 * WooCommerce function is called for anything but the text-length normalization.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Context values; absent keys take a safe default.
	 * @return Product_Context
	 */
	public static function from_array( array $data ): Product_Context {
		$values = self::normalize( $data );

		// Derived from the featured image id unless the caller states it, so that a test
		// context never needs the media library.
		if ( ! array_key_exists( 'has_valid_featured_image', $values ) ) {
			$values['has_valid_featured_image'] = ( $values['featured_image_id'] ?? 0 ) > 0;
		}

		foreach ( self::OVERRIDABLE as $key => $type ) {
			if ( ! array_key_exists( $key, $values ) ) {
				$values[ $key ] = self::default_for( $type );
			}
		}

		return new self( null, $values );
	}

	/**
	 * Resolve aliases to canonical keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Raw override array.
	 * @return array
	 */
	private static function aliased( array $raw ): array {
		$resolved = array();

		foreach ( $raw as $key => $value ) {
			$key = self::ALIASES[ $key ] ?? $key;

			$resolved[ $key ] = $value;
		}

		return $resolved;
	}

	/**
	 * Whitelist and coerce an override array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Raw override array.
	 * @return array Only known keys, each coerced to its documented type.
	 */
	private static function normalize( array $raw ): array {
		$normalized = array();

		foreach ( self::aliased( $raw ) as $key => $value ) {
			if ( ! isset( self::OVERRIDABLE[ $key ] ) ) {
				continue;
			}

			$normalized[ $key ] = self::coerce( self::OVERRIDABLE[ $key ], $value );
		}

		return $normalized;
	}

	/**
	 * Coerce one value to its documented type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type  Normalizer name.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function coerce( string $type, $value ) {
		switch ( $type ) {
			case 'id':
				// Core writes -1 into #_thumbnail_id when there is no image; any
				// non-positive value means "not set".
				return max( 0, (int) $value );
			case 'bool':
				return (bool) $value;
			case 'nullable_int':
				return ( null === $value || '' === $value ) ? null : (int) $value;
			case 'ids':
				return self::coerce_ids( $value );
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Coerce a value to a list of unique, positive integer identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private static function coerce_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = ( is_string( $value ) && '' !== $value ) ? explode( ',', $value ) : array();
		}

		$ids = array();

		foreach ( $value as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The empty value for a normalizer type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Normalizer name.
	 * @return mixed
	 */
	private static function default_for( string $type ) {
		switch ( $type ) {
			case 'id':
				return 0;
			case 'bool':
				return false;
			case 'nullable_int':
				return null;
			case 'ids':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Return a memoized value, resolving it from the product on first access.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $key      Value key.
	 * @param callable $resolver Produces the value when it is not already known.
	 * @return mixed
	 */
	private function value( string $key, callable $resolver ) {
		if ( ! array_key_exists( $key, $this->values ) ) {
			$this->values[ $key ] = $resolver();
		}

		return $this->values[ $key ];
	}

	/**
	 * Collapse markup, shortcodes and whitespace, then count characters.
	 *
	 * The one definition of "length", shared by both content-length rules. Shortcodes are
	 * stripped, never executed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw field value.
	 * @return int
	 */
	private static function text_length( string $raw ): int {
		$text      = wp_strip_all_tags( strip_shortcodes( $raw ), true );
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		if ( ! is_string( $collapsed ) ) {
			$collapsed = $text;
		}

		$collapsed = trim( $collapsed );

		return function_exists( 'mb_strlen' ) ? mb_strlen( $collapsed ) : strlen( $collapsed );
	}

	/**
	 * Read term identifiers for the product through the object term cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private function term_ids( string $taxonomy ): array {
		if ( null === $this->product ) {
			return array();
		}

		$terms = get_the_terms( $this->product->get_id(), $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$ids = array();

		foreach ( $terms as $term ) {
			if ( isset( $term->term_id ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}

	/**
	 * Format a WooCommerce date property as a comparable string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $getter WC_Product getter name.
	 * @return string Empty string when the date is not set.
	 */
	private function product_date( string $getter ): string {
		if ( null === $this->product ) {
			return '';
		}

		$date = $this->product->{$getter}( 'edit' );

		return $date instanceof \DateTimeInterface ? $date->format( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * The stored product, when there is one.
	 *
	 * @since 1.0.0
	 *
	 * @return \WC_Product|null
	 */
	public function get_product(): ?\WC_Product {
		return $this->product;
	}

	/**
	 * Product identifier. Zero for a product that has never been saved.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return (int) $this->value(
			'product_id',
			function () {
				return null === $this->product ? 0 : (int) $this->product->get_id();
			}
		);
	}

	/**
	 * WooCommerce product type, for example `simple` or `variable`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_product_type(): string {
		return (string) $this->value(
			'product_type',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_type();
			}
		);
	}

	/**
	 * Post status of the product.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_post_status(): string {
		return (string) $this->value(
			'post_status',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_status( 'edit' );
			}
		);
	}

	/**
	 * Product title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_title(): string {
		return (string) $this->value(
			'title',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_name( 'edit' );
			}
		);
	}

	/**
	 * Long description, as stored.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return (string) $this->value(
			'description',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_description( 'edit' );
			}
		);
	}

	/**
	 * Short description, as stored.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_description(): string {
		return (string) $this->value(
			'short_description',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_short_description( 'edit' );
			}
		);
	}

	/**
	 * Character count of the long description, markup and shortcodes removed.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_description_length(): int {
		return (int) $this->value(
			'description_length',
			function () {
				return self::text_length( $this->get_description() );
			}
		);
	}

	/**
	 * Character count of the short description, markup and shortcodes removed.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_short_description_length(): int {
		return (int) $this->value(
			'short_description_length',
			function () {
				return self::text_length( $this->get_short_description() );
			}
		);
	}

	/**
	 * Featured image attachment identifier. Zero when there is none.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_featured_image_id(): int {
		return (int) $this->value(
			'featured_image_id',
			function () {
				return null === $this->product ? 0 : max( 0, (int) $this->product->get_image_id( 'edit' ) );
			}
		);
	}

	/**
	 * Whether the featured image exists in the media library and is an image.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_valid_featured_image(): bool {
		return (bool) $this->value(
			'has_valid_featured_image',
			function () {
				$id = $this->get_featured_image_id();

				return $id > 0 && wp_attachment_is_image( $id );
			}
		);
	}

	/**
	 * Gallery attachment identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_gallery_image_ids(): array {
		return (array) $this->value(
			'gallery_image_ids',
			function () {
				return null === $this->product ? array() : self::coerce_ids( $this->product->get_gallery_image_ids( 'edit' ) );
			}
		);
	}

	/**
	 * Number of distinct images on the product, featured image included.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_image_count(): int {
		return (int) $this->value(
			'image_count',
			function () {
				$ids      = $this->get_gallery_image_ids();
				$featured = $this->get_featured_image_id();

				if ( $featured > 0 && ! in_array( $featured, $ids, true ) ) {
					$ids[] = $featured;
				}

				return count( $ids );
			}
		);
	}

	/**
	 * Regular price, as stored. Kept as a string so "0" and "" stay distinguishable.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_regular_price(): string {
		return (string) $this->value(
			'regular_price',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_regular_price( 'edit' );
			}
		);
	}

	/**
	 * Sale price, as stored.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sale_price(): string {
		return (string) $this->value(
			'sale_price',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_sale_price( 'edit' );
			}
		);
	}

	/**
	 * Start of the sale window as `Y-m-d H:i:s`, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sale_from(): string {
		return (string) $this->value(
			'sale_from',
			function () {
				return $this->product_date( 'get_date_on_sale_from' );
			}
		);
	}

	/**
	 * End of the sale window as `Y-m-d H:i:s`, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sale_to(): string {
		return (string) $this->value(
			'sale_to',
			function () {
				return $this->product_date( 'get_date_on_sale_to' );
			}
		);
	}

	/**
	 * Product SKU.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sku(): string {
		return (string) $this->value(
			'sku',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_sku( 'edit' );
			}
		);
	}

	/**
	 * Stock status, for example `instock`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_stock_status(): string {
		return (string) $this->value(
			'stock_status',
			function () {
				return null === $this->product ? '' : (string) $this->product->get_stock_status( 'edit' );
			}
		);
	}

	/**
	 * Whether stock is managed at product level.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function get_manage_stock(): bool {
		return (bool) $this->value(
			'manage_stock',
			function () {
				return null === $this->product ? false : (bool) $this->product->get_manage_stock( 'edit' );
			}
		);
	}

	/**
	 * Managed stock quantity, or null when stock is not managed.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function get_stock_quantity(): ?int {
		$quantity = $this->value(
			'stock_quantity',
			function () {
				if ( null === $this->product ) {
					return null;
				}

				$stored = $this->product->get_stock_quantity( 'edit' );

				return null === $stored ? null : (int) $stored;
			}
		);

		return null === $quantity ? null : (int) $quantity;
	}

	/**
	 * Product category term identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_category_ids(): array {
		return (array) $this->value(
			'category_ids',
			function () {
				return $this->term_ids( 'product_cat' );
			}
		);
	}

	/**
	 * Product tag term identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_tag_ids(): array {
		return (array) $this->value(
			'tag_ids',
			function () {
				return $this->term_ids( 'product_tag' );
			}
		);
	}

	/**
	 * The store's default product category term identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_default_category_id(): int {
		return (int) $this->value(
			'default_category_id',
			function () {
				return max( 0, (int) get_option( 'default_product_cat', 0 ) );
			}
		);
	}

	/**
	 * Whether the only category on the product is the store default.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_only_default_category(): bool {
		return (bool) $this->value(
			'is_only_default_category',
			function () {
				$categories = $this->get_category_ids();
				$default    = $this->get_default_category_id();

				return $default > 0 && array( $default ) === array_values( $categories );
			}
		);
	}
}
