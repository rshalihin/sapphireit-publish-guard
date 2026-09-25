<?php
/**
 * Turns an admin save payload into Product_Context overrides.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that knows the shape of the classic editor, Quick Edit and Bulk Edit
 * payloads (coding-plan.md section 6.3.1).
 *
 * Its input is the `$postarr` core hands `wp_insert_post_data`, never a superglobal:
 * `edit_post()` passes the whole form through, and `bulk_edit_posts()` passes the shared
 * request per post with the taxonomy lists already merged with the current terms. A
 * nested `wp_update_post()` for some other product therefore carries none of this form's
 * fields.
 *
 * A field is believed only when the path that is saving it will actually persist it.
 * Core applies `_thumbnail_id` and `tax_input` on every path; WooCommerce applies its
 * product fields only when its own nonce verifies. Reading a field WooCommerce is about
 * to ignore would let a crafted request pass the guard on data that never gets saved.
 *
 * Nothing here writes anything.
 *
 * @since 1.0.0
 */
final class Save_Request_Reader {

	/**
	 * Source: the classic product editor (`post.php`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_CLASSIC = 'classic';

	/**
	 * Source: Quick Edit on the products list (`inline-save`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_QUICK_EDIT = 'quick_edit';

	/**
	 * Source: Bulk Edit on the products list.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_BULK_EDIT = 'bulk_edit';

	/**
	 * Source: a REST request.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_REST = 'rest';

	/**
	 * Source: any other write — another plugin, a theme, an importer.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_PROGRAMMATIC = 'programmatic';

	/**
	 * Most identifiers read from one list, applied before any per-item work (section 9.9).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const ID_CAP = 100;

	/**
	 * Most tags read from one list.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const TAG_CAP = 200;

	/**
	 * Post-row fields, mapped to context keys.
	 *
	 * These come from `$data`, which `wp_update_post()` has already merged with the stored
	 * row — so a Quick Edit that posts no content is read as the stored description.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private const ROW_FIELDS = array(
		'post_title'   => 'title',
		'post_content' => 'description',
		'post_excerpt' => 'short_description',
		'post_status'  => 'post_status',
	);

	/**
	 * The sources recognised as an admin save payload.
	 *
	 * The `editor` enforcement scope covers exactly these.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function admin_sources(): array {
		return array( self::SOURCE_CLASSIC, self::SOURCE_QUICK_EDIT, self::SOURCE_BULK_EDIT );
	}

	/**
	 * Name the kind of request that is saving a post.
	 *
	 * An admin payload counts only when it targets `$post_id`: a plugin that updates a
	 * different product from inside this save must not be mistaken for the form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data The `$postarr` handed to `wp_insert_post_data`.
	 * @param int   $post_id   The post being written; 0 skips the target check.
	 * @return string One of the SOURCE_* constants.
	 */
	public static function detect_source( array $post_data, int $post_id = 0 ): string {
		$action = isset( $post_data['action'] ) && is_scalar( $post_data['action'] ) ? (string) $post_data['action'] : '';

		if ( isset( $post_data['bulk_edit'] ) && self::targets_bulk( $post_data, $post_id ) ) {
			return self::SOURCE_BULK_EDIT;
		}

		if ( 'inline-save' === $action && self::targets( $post_data, $post_id ) ) {
			return self::SOURCE_QUICK_EDIT;
		}

		if ( 'editpost' === $action && self::targets( $post_data, $post_id ) ) {
			return self::SOURCE_CLASSIC;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::SOURCE_REST;
		}

		return self::SOURCE_PROGRAMMATIC;
	}

	/**
	 * Build the override array for `Product_Context::from_save_request()`.
	 *
	 * Only keys the request really changes are returned; everything else falls back to
	 * the stored product. Both inputs arrive slashed and are unslashed here.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data   The `$postarr` handed to `wp_insert_post_data`.
	 * @param array $insert_data The `$data` handed to `wp_insert_post_data`.
	 * @param int   $post_id     The post being written; 0 for a new post.
	 * @return array
	 */
	public static function read( array $post_data, array $insert_data, int $post_id = 0 ): array {
		$post_data   = wp_unslash( $post_data );
		$insert_data = wp_unslash( $insert_data );
		$overrides   = array();

		if ( $post_id > 0 ) {
			$overrides['product_id'] = $post_id;
		}

		foreach ( self::ROW_FIELDS as $field => $key ) {
			if ( array_key_exists( $field, $insert_data ) && is_scalar( $insert_data[ $field ] ) ) {
				$overrides[ $key ] = (string) $insert_data[ $field ];
			}
		}

		$overrides = array_merge( $overrides, self::core_fields( $post_data ) );

		switch ( self::detect_source( $post_data, $post_id ) ) {
			case self::SOURCE_CLASSIC:
				if ( self::nonce_verifies( $post_data, 'woocommerce_meta_nonce', 'woocommerce_save_data' ) ) {
					$overrides = array_merge( $overrides, self::classic_fields( $post_data ) );
				}
				break;

			case self::SOURCE_QUICK_EDIT:
			case self::SOURCE_BULK_EDIT:
				if ( self::nonce_verifies( $post_data, 'woocommerce_quick_edit_nonce', 'woocommerce_quick_edit_nonce' ) ) {
					// WooCommerce picks its branch by this marker, not by the core action.
					$overrides = array_merge(
						$overrides,
						empty( $post_data['woocommerce_quick_edit'] ) ? self::bulk_fields( $post_data ) : self::quick_fields( $post_data )
					);
				}
				break;
		}

		return $overrides;
	}

	/**
	 * Whether a single-post payload targets this post.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed or slashed payload; only integers are read.
	 * @param int   $post_id   The post being written.
	 * @return bool
	 */
	private static function targets( array $post_data, int $post_id ): bool {
		if ( 0 === $post_id ) {
			return true;
		}

		return isset( $post_data['post_ID'] ) && is_scalar( $post_data['post_ID'] ) && absint( $post_data['post_ID'] ) === $post_id;
	}

	/**
	 * Whether a Bulk Edit payload includes this post.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Payload.
	 * @param int   $post_id   The post being written.
	 * @return bool
	 */
	private static function targets_bulk( array $post_data, int $post_id ): bool {
		if ( 0 === $post_id ) {
			return true;
		}

		return isset( $post_data['post'] ) && is_array( $post_data['post'] )
			&& in_array( $post_id, array_map( 'absint', array_filter( $post_data['post'], 'is_scalar' ) ), true );
	}

	/**
	 * Whether the nonce WooCommerce will check before saving its fields verifies.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $post_data Unslashed payload.
	 * @param string $field     Nonce field name.
	 * @param string $action    Nonce action.
	 * @return bool
	 */
	private static function nonce_verifies( array $post_data, string $field, string $action ): bool {
		if ( empty( $post_data[ $field ] ) || ! is_scalar( $post_data[ $field ] ) ) {
			return false;
		}

		return false !== wp_verify_nonce( (string) $post_data[ $field ], $action );
	}

	/**
	 * Fields core itself applies inside `wp_insert_post()`, whatever the source.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed payload.
	 * @return array
	 */
	private static function core_fields( array $post_data ): array {
		$fields = array();

		if ( isset( $post_data['_thumbnail_id'] ) && is_scalar( $post_data['_thumbnail_id'] ) ) {
			// Core writes -1 for "no image"; any non-positive value means none.
			$fields['featured_image_id'] = max( 0, (int) $post_data['_thumbnail_id'] );
		}

		$tax_input = isset( $post_data['tax_input'] ) && is_array( $post_data['tax_input'] ) ? $post_data['tax_input'] : array();

		// Core skips a taxonomy the user may not assign, so the reader must too.
		if ( array_key_exists( 'product_cat', $tax_input ) && self::can_assign( 'product_cat' ) ) {
			$fields['category_ids'] = self::ids( $tax_input['product_cat'], self::ID_CAP );
		}

		if ( array_key_exists( 'product_tag', $tax_input ) && self::can_assign( 'product_tag' ) ) {
			$fields['tag_ids'] = self::tag_placeholders( $tax_input['product_tag'] );
		}

		return $fields;
	}

	/**
	 * Fields `WC_Meta_Box_Product_Data::save()` applies from the classic editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed payload.
	 * @return array
	 */
	private static function classic_fields( array $post_data ): array {
		$fields = self::text_fields(
			$post_data,
			array(
				'_regular_price'         => array( 'regular_price', 'price' ),
				'_sale_price'            => array( 'sale_price', 'price' ),
				'_sale_price_dates_from' => array( 'sale_from', 'date' ),
				'_sale_price_dates_to'   => array( 'sale_to', 'date' ),
				'_sku'                   => array( 'sku', 'text' ),
				'_stock_status'          => array( 'stock_status', 'key' ),
				'product-type'           => array( 'product_type', 'key' ),
			)
		);

		if ( isset( $post_data['product_image_gallery'] ) ) {
			$fields['gallery_image_ids'] = self::ids( $post_data['product_image_gallery'], self::ID_CAP );
		}

		// A checkbox: absent means unticked.
		$fields['manage_stock'] = ! empty( $post_data['_manage_stock'] );

		if ( $fields['manage_stock'] && isset( $post_data['_stock'] ) ) {
			$fields['stock_quantity'] = self::quantity( $post_data['_stock'] );
		}

		return $fields;
	}

	/**
	 * Fields `WC_Admin_Post_Types::quick_edit_save()` applies.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed payload.
	 * @return array
	 */
	private static function quick_fields( array $post_data ): array {
		$fields = self::text_fields(
			$post_data,
			array(
				'_regular_price'         => array( 'regular_price', 'price' ),
				'_sale_price'            => array( 'sale_price', 'price' ),
				'_sale_price_dates_from' => array( 'sale_from', 'date' ),
				'_sale_price_dates_to'   => array( 'sale_to', 'date' ),
				'_sku'                   => array( 'sku', 'text' ),
			)
		);

		// WooCommerce ignores an empty stock status here rather than clearing it.
		if ( ! empty( $post_data['_stock_status'] ) && is_scalar( $post_data['_stock_status'] ) ) {
			$fields['stock_status'] = sanitize_key( (string) $post_data['_stock_status'] );
		}

		$fields['manage_stock'] = ! empty( $post_data['_manage_stock'] );

		if ( $fields['manage_stock'] && isset( $post_data['_stock'] ) ) {
			$fields['stock_quantity'] = self::quantity( $post_data['_stock'] );
		}

		return $fields;
	}

	/**
	 * Fields `WC_Admin_Post_Types::bulk_edit_save()` applies.
	 *
	 * In Bulk Edit an empty field means "no change". Prices are read only in the "set to"
	 * mode (`1`) with a value; the relative modes depend on each product's stored price
	 * and are left to it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed payload.
	 * @return array
	 */
	private static function bulk_fields( array $post_data ): array {
		$fields = array();

		foreach ( array( 'regular', 'sale' ) as $type ) {
			$mode  = isset( $post_data[ "change_{$type}_price" ] ) && is_scalar( $post_data[ "change_{$type}_price" ] ) ? absint( $post_data[ "change_{$type}_price" ] ) : 0;
			$price = isset( $post_data[ "_{$type}_price" ] ) ? self::price( $post_data[ "_{$type}_price" ] ) : '';

			if ( 1 === $mode && '' !== $price ) {
				$fields[ "{$type}_price" ] = $price;

				// WooCommerce drops the sale window whenever a bulk edit changes a price.
				$fields['sale_from'] = '';
				$fields['sale_to']   = '';
			}
		}

		if ( ! empty( $post_data['_stock_status'] ) && is_scalar( $post_data['_stock_status'] ) ) {
			$fields['stock_status'] = sanitize_key( (string) $post_data['_stock_status'] );
		}

		if ( ! empty( $post_data['_manage_stock'] ) && is_scalar( $post_data['_manage_stock'] ) ) {
			$fields['manage_stock'] = 'yes' === (string) $post_data['_manage_stock'];
		}

		$stock_mode = isset( $post_data['change_stock'] ) && is_scalar( $post_data['change_stock'] ) ? absint( $post_data['change_stock'] ) : 0;

		// Modes 2 and 3 add to or subtract from each product's own stock.
		if ( ! empty( $fields['manage_stock'] ) && $stock_mode > 0 && ! in_array( $stock_mode, array( 2, 3 ), true ) && isset( $post_data['_stock'] ) ) {
			$fields['stock_quantity'] = self::quantity( $post_data['_stock'] );
		}

		return $fields;
	}

	/**
	 * Read present scalar fields through their normalizer.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Unslashed payload.
	 * @param array $map       Field name => array( context key, normalizer ).
	 * @return array
	 */
	private static function text_fields( array $post_data, array $map ): array {
		$fields = array();

		foreach ( $map as $field => $target ) {
			if ( ! isset( $post_data[ $field ] ) || ! is_scalar( $post_data[ $field ] ) ) {
				continue;
			}

			list( $key, $normalizer ) = $target;

			$value = (string) $post_data[ $field ];

			switch ( $normalizer ) {
				case 'price':
					$fields[ $key ] = self::price( $value );
					break;
				case 'date':
					$fields[ $key ] = self::date( $value );
					break;
				case 'key':
					$fields[ $key ] = sanitize_key( $value );
					break;
				default:
					$fields[ $key ] = sanitize_text_field( $value );
			}
		}

		return $fields;
	}

	/**
	 * Whether the current user may assign terms in a taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private static function can_assign( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return is_object( $object ) && isset( $object->cap->assign_terms ) && current_user_can( $object->cap->assign_terms );
	}

	/**
	 * Cap, then parse, a list of identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Array or comma-separated string.
	 * @param int   $cap   Most entries considered.
	 * @return int[]
	 */
	private static function ids( $value, int $cap ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value, $cap + 1 );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( array_slice( $value, 0, $cap ) as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * One placeholder id per distinct tag.
	 *
	 * The payload mixes existing term ids with names of tags about to be created, and
	 * `Tags_Rule` only counts — so the tags are counted by presence and never resolved,
	 * which also means a save never creates or looks up a term here.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Array of ids/names, or a comma-separated string.
	 * @return int[]
	 */
	private static function tag_placeholders( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value, self::TAG_CAP + 1 );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$names = array();

		foreach ( array_slice( $value, 0, self::TAG_CAP ) as $tag ) {
			$tag = is_scalar( $tag ) ? strtolower( trim( (string) $tag ) ) : '';

			if ( '' !== $tag && '0' !== $tag ) {
				$names[ $tag ] = true;
			}
		}

		return array() === $names ? array() : range( 1, count( $names ) );
	}

	/**
	 * Normalize a price to WooCommerce's decimal string; empty stays empty.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function price( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $value ) : $value;
	}

	/**
	 * Normalize a sale-window date to `Y-m-d H:i:s`, exactly as the REST route does.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value.
	 * @return string Empty for an empty or unparseable value.
	 */
	private static function date( string $value ): string {
		$value = trim( $value );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value );

			if ( $date instanceof DateTimeImmutable && $date->format( $format ) === $value ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}

		return '';
	}

	/**
	 * A managed stock quantity; empty means "not set".
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function quantity( $value ): ?int {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
