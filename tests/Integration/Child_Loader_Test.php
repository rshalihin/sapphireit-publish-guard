<?php
/**
 * The child loader against real WooCommerce storage.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Child_Loader;
use ProductPublishGuard\Engine\Variation_Data;

/**
 * Covers D4 ("enabled" is the variation's own status), parent-managed stock, grouped
 * existence and the per-request memo.
 *
 * @since 1.1.0
 */
class Child_Loader_Test extends Publish_Guard_Test_Case {

	/**
	 * Start every test with an empty memo.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Child_Loader::flush();
	}

	/**
	 * Leave no memo behind.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		Child_Loader::flush();

		parent::tear_down();
	}

	/**
	 * The ids of a list of variation snapshots.
	 *
	 * @since 1.1.0
	 *
	 * @param Variation_Data[] $variations Snapshots.
	 * @return int[]
	 */
	private static function ids( array $variations ): array {
		return array_map(
			static function ( Variation_Data $variation ) {
				return $variation->get_id();
			},
			$variations
		);
	}

	/**
	 * The enabled variations of a stored parent.
	 *
	 * @since 1.1.0
	 *
	 * @param int $parent_id Parent id.
	 * @return Variation_Data[]
	 */
	private static function variations_of( int $parent_id ): array {
		return Child_Loader::variations( wc_get_product( $parent_id ) );
	}

	/**
	 * A private (disabled) variation is not loaded.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_disabled_variations_are_excluded(): void {
		$parent_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array(
					'regular_price' => '',
					'status'        => 'private',
				),
				array( 'regular_price' => '12' ),
			)
		);

		$children   = wc_get_product( $parent_id )->get_children();
		$variations = self::variations_of( $parent_id );

		$this->assertCount( 3, $children );
		$this->assertSame( array( $children[0], $children[2] ), self::ids( $variations ) );
		$this->assertSame( '10', $variations[0]->get_regular_price() );
		$this->assertSame( '12', $variations[1]->get_regular_price() );
	}

	/**
	 * Hiding out-of-stock items from the catalog must not hide an out-of-stock variation
	 * from the gate (D4): that would let an unpriced one through.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_out_of_stock_variation_is_included_when_store_hides_out_of_stock(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );

		$parent_id = $this->variable_product(
			array(
				array(
					'regular_price' => '10',
					'stock_status'  => 'instock',
				),
				array(
					'regular_price' => '',
					'stock_status'  => 'outofstock',
				),
			)
		);

		$parent     = wc_get_product( $parent_id );
		$children   = $parent->get_children();
		$variations = self::variations_of( $parent_id );

		$this->assertNotContains( $children[1], $parent->get_visible_children(), 'The store setting hides the variation from the catalog.' );
		$this->assertSame( $children, self::ids( $variations ) );
		$this->assertSame( 'outofstock', $variations[1]->get_stock_status() );
		$this->assertSame( '', $variations[1]->get_regular_price() );
	}

	/**
	 * A variation whose stock the parent manages reports `parent` and the parent's quantity.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_parent_managed_stock_reports_parent_and_quantity(): void {
		$parent_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array(
					'regular_price'  => '10',
					'manage_stock'   => true,
					'stock_quantity' => 3,
				),
			),
			'draft',
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5,
			)
		);

		$variations = self::variations_of( $parent_id );

		$this->assertSame( Variation_Data::MANAGE_PARENT, $variations[0]->get_manage_stock() );
		$this->assertSame( 5, $variations[0]->get_stock_quantity() );
		$this->assertSame( Variation_Data::MANAGE_YES, $variations[1]->get_manage_stock() );
		$this->assertSame( 3, $variations[1]->get_stock_quantity() );
	}

	/**
	 * Trashed and deleted children don't exist; a draft exists but isn't published.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_counts_existing_and_published(): void {
		$trashed   = $this->passing_product( 'publish' );
		$draft     = $this->passing_product( 'draft' );
		$published = $this->passing_product( 'publish' );
		$deleted   = $this->passing_product( 'publish' );
		$page      = self::factory()->post->create( array( 'post_type' => 'page' ) );

		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );

		$this->assertSame(
			array(
				'ids'       => array( $draft, $published ),
				'published' => 1,
			),
			Child_Loader::grouped( array( $trashed, $draft, $published, $deleted, $page ) )
		);
	}

	/**
	 * The memo answers until it is flushed, for one parent or for all of them.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_flush_forgets_the_memo(): void {
		global $wpdb;

		$parent_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array( 'regular_price' => '12' ),
			)
		);

		$this->assertCount( 2, self::variations_of( $parent_id ) );

		// Disable one variation behind WooCommerce's back, so no save hook can flush.
		$children = wc_get_product( $parent_id )->get_children();
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $children[0] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $children[0] );

		$this->assertCount( 2, self::variations_of( $parent_id ), 'The memo answers until flushed.' );

		Child_Loader::flush( $parent_id + 1000 );

		$this->assertCount( 2, self::variations_of( $parent_id ), 'Flushing another parent keeps this one.' );

		Child_Loader::flush( $parent_id );

		$this->assertSame( array( $children[1] ), self::ids( self::variations_of( $parent_id ) ) );

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $children[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $children[1] );

		Child_Loader::flush();

		$this->assertSame( array(), self::variations_of( $parent_id ) );
	}

	/**
	 * Priming a page seeds the variation ids, so no row reads `get_children()` (R9), and
	 * gives the same variations as the single-product path.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_prime_seeds_the_variation_ids(): void {
		$parent_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array(
					'regular_price' => '',
					'status'        => 'private',
				),
				array( 'regular_price' => '12' ),
			)
		);
		$empty_id  = $this->variable_product( array() );
		$simple_id = $this->passing_product();

		$expected = self::ids( self::variations_of( $parent_id ) );
		$this->assertCount( 2, $expected );

		$reads   = 0;
		$counter = static function ( $children ) use ( &$reads ) {
			++$reads;

			return $children;
		};
		add_filter( 'woocommerce_get_children', $counter );

		Child_Loader::flush();
		Child_Loader::prime( array( $parent_id, $empty_id, $simple_id, 0, -3 ) );

		$this->assertSame( $expected, self::ids( self::variations_of( $parent_id ) ) );
		$this->assertSame( array(), self::variations_of( $empty_id ) );
		$this->assertSame( 0, $reads, 'A primed page never reads get_children().' );

		Child_Loader::flush( $parent_id );
		self::variations_of( $parent_id );

		remove_filter( 'woocommerce_get_children', $counter );

		$this->assertSame( 1, $reads, 'Flushing a parent forgets its seeded ids too.' );
	}

	/**
	 * Priming a page also primes the linked products of its grouped rows.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_prime_primes_grouped_children(): void {
		global $wpdb;

		$children   = array( $this->passing_product( 'publish' ), $this->passing_product( 'draft' ) );
		$grouped_id = $this->grouped_product( $children );

		foreach ( array_merge( $children, array( $grouped_id ) ) as $id ) {
			clean_post_cache( $id );
		}

		Child_Loader::prime( array( $grouped_id ) );

		$before = $wpdb->num_queries;
		$result = Child_Loader::grouped( $children );

		$this->assertSame( $before, $wpdb->num_queries, 'The linked products were already primed.' );
		$this->assertSame(
			array(
				'ids'       => $children,
				'published' => 1,
			),
			$result
		);
	}

	/**
	 * Extra ids, such as the page's images, are primed in the same pass as the children:
	 * one posts, one meta and at most one terms query in all, not a second pass (D11).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_prime_primes_extra_ids_in_the_same_pass(): void {
		global $wpdb;

		$parent_id = $this->variable_product( array( array( 'regular_price' => '10' ) ) );
		$images    = array( $this->image(), $this->image() );
		$children  = wc_get_product( $parent_id )->get_children();

		foreach ( array_merge( $images, $children ) as $id ) {
			clean_post_cache( $id );
		}

		// The parent's own caches stay warm, as the list screen's main query leaves them.
		get_post_meta( $parent_id );

		$before = $wpdb->num_queries;
		Child_Loader::prime( array( $parent_id ), $images );
		$primed = $wpdb->num_queries - $before;

		// The id query, then posts, meta and terms once for the variation and the images.
		$this->assertLessThanOrEqual( 4, $primed );

		$before = $wpdb->num_queries;

		foreach ( $images as $image_id ) {
			get_post( $image_id );
			get_post_meta( $image_id, '_wp_attached_file', true );
		}

		$this->assertSame( $before, $wpdb->num_queries, 'The extra ids were primed with their meta.' );
	}
}
