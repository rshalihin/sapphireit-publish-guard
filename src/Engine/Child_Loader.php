<?php
/**
 * Reads variations and grouped children from storage.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns a parent into the child data the rules read.
 *
 * Variations come from storage only, never from a draft (D3). Query cost is constant: the
 * child posts are primed in one pass, with their meta and terms, before any child object
 * is built. There is deliberately no cap on the number of variations: a cap would be a
 * silent bypass.
 *
 * @since 1.1.0
 */
final class Child_Loader {

	/**
	 * Enabled variations already read in this request, keyed by parent id.
	 *
	 * @since 1.1.0
	 * @var array<int, Variation_Data[]>
	 */
	private static array $variations = array();

	/**
	 * Variation ids seeded by `prime()`, enabled or not, keyed by parent id.
	 *
	 * @since 1.1.0
	 * @var array<int, int[]>
	 */
	private static array $child_ids = array();

	/**
	 * Not instantiable.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {}

	/**
	 * The enabled variations of a parent.
	 *
	 * "Enabled" means the variation's own status is `publish` (D4). The ids come from
	 * `get_children()`, never `get_visible_children()`: on a store that hides out-of-stock
	 * items, that one also drops out-of-stock variations, which would let an unpriced one
	 * through.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Product $parent_product The parent product.
	 * @return Variation_Data[]
	 */
	public static function variations( \WC_Product $parent_product ): array {
		$parent_id = (int) $parent_product->get_id();

		if ( $parent_id > 0 && isset( self::$variations[ $parent_id ] ) ) {
			return self::$variations[ $parent_id ];
		}

		$ids        = Product_Context::coerce_ids( self::variation_ids( $parent_product ) );
		$variations = array();

		if ( array() !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}

		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );

			if ( $variation instanceof \WC_Product_Variation && 'publish' === $variation->get_status( 'edit' ) ) {
				$variations[] = Variation_Data::from_product( $variation );
			}
		}

		if ( $parent_id > 0 ) {
			self::$variations[ $parent_id ] = $variations;
		}

		return $variations;
	}

	/**
	 * Child ids of a parent, enabled or not.
	 *
	 * A parent that is not stored as variable yet (the draft type says variable, the stored
	 * one does not) is read by post parent, with the same statuses and order as
	 * WooCommerce's own `read_children()`, so that the gap can never become a bypass (§3.2).
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Product $parent_product The parent product.
	 * @return array
	 */
	private static function variation_ids( \WC_Product $parent_product ): array {
		$parent_id = (int) $parent_product->get_id();

		if ( $parent_id > 0 && isset( self::$child_ids[ $parent_id ] ) ) {
			return self::$child_ids[ $parent_id ];
		}

		if ( $parent_product instanceof \WC_Product_Variable ) {
			return (array) $parent_product->get_children();
		}

		if ( $parent_id <= 0 ) {
			return array();
		}

		return get_posts( self::children_query( array( 'post_parent' => $parent_id ), 'ids' ) );
	}

	/**
	 * Arguments for a variation query with WooCommerce's `read_children()` statuses and order.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $where  The parent clause.
	 * @param string $fields The `fields` value.
	 * @return array
	 */
	private static function children_query( array $where, string $fields ): array {
		return array_merge(
			$where,
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => $fields,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'posts_per_page' => -1,
			)
		);
	}

	/**
	 * Prime everything `variations()` and `grouped()` will read for a whole page of parents.
	 *
	 * One query finds every variation of every parent on the page, and its ids seed the
	 * memo, so no row reads the `get_children()` transient (R9). Grouped children come from
	 * the parents' meta. Then one pass primes all those posts, and `$also_prime`, with their
	 * meta and terms: the list column passes its images, which a separate pass would cost
	 * two more queries for (D11).
	 *
	 * The seeded ids skip third-party `woocommerce_variable_children_args` and
	 * `woocommerce_get_children` filters, which the single-product path still honours. Every
	 * parent on the page is queried, not only variable ones: it is one query either way,
	 * and a non-variable parent's seeded ids are never read on a list page.
	 *
	 * @since 1.1.0
	 *
	 * @param array $parent_ids Product ids on the page.
	 * @param array $also_prime Other post ids to prime in the same pass, such as the page's images.
	 * @return void
	 */
	public static function prime( array $parent_ids, array $also_prime = array() ): void {
		$parent_ids = Product_Context::coerce_ids( $parent_ids );

		if ( array() === $parent_ids ) {
			return;
		}

		$seeded = array_fill_keys( $parent_ids, array() );
		// `id=>parent` returns a map of variation id to parent id, in query order.
		$rows = get_posts( self::children_query( array( 'post_parent__in' => $parent_ids ), 'id=>parent' ) );

		foreach ( $rows as $id => $parent ) {
			if ( isset( $seeded[ (int) $parent ] ) ) {
				$seeded[ (int) $parent ][] = (int) $id;
			}
		}

		// No query when the list screen has already primed the parents' meta.
		update_meta_cache( 'post', $parent_ids );

		$to_prime = $also_prime;

		foreach ( $seeded as $parent => $ids ) {
			self::$child_ids[ $parent ] = $ids;

			$to_prime = array_merge( $to_prime, $ids, (array) get_post_meta( $parent, '_children', true ) );
		}

		// A page can hold thousands of ids: no per-id in_array() here.
		$to_prime = array_values( array_unique( array_filter( array_map( 'intval', $to_prime ) ) ) );

		if ( array() !== $to_prime ) {
			_prime_post_caches( $to_prime, true, true );
		}
	}

	/**
	 * The linked products of a grouped product that still exist, and how many are published.
	 *
	 * "Exists" means a `product` post that is not in the trash. The CSV importer's
	 * `importing` placeholders therefore exist but are not published.
	 *
	 * @since 1.1.0
	 *
	 * @param array $ids Linked product ids.
	 * @return array{ids: int[], published: int}
	 */
	public static function grouped( array $ids ): array {
		$ids      = Product_Context::coerce_ids( $ids );
		$existing = array();
		$count    = 0;

		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( null === $post || 'product' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}

			$existing[] = $id;

			if ( 'publish' === $post->post_status ) {
				++$count;
			}
		}

		return array(
			'ids'       => $existing,
			'published' => $count,
		);
	}

	/**
	 * Forget the memo for one parent, or for every parent when the id is 0.
	 *
	 * @since 1.1.0
	 *
	 * @param int $parent_id Parent product id, or 0 for all.
	 * @return void
	 */
	public static function flush( int $parent_id = 0 ): void {
		if ( $parent_id <= 0 ) {
			self::$variations = array();
			self::$child_ids  = array();

			return;
		}

		unset( self::$variations[ $parent_id ], self::$child_ids[ $parent_id ] );
	}
}
