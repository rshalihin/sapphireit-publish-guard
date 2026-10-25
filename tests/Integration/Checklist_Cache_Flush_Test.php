<?php
/**
 * Tests that a variation write refreshes its parent's checklist.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Child_Loader;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Data_Store;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Covers D12: the parent's cached result and the child-loader memo are flushed when a
 * variation is updated, deleted, trashed or has its stock changed.
 *
 * Every write here goes through WooCommerce directly, never `save_unguarded()`: that
 * helper empties the memo itself, which would hide a missing flush.
 *
 * @since 1.1.0
 */
class Checklist_Cache_Flush_Test extends Publish_Guard_Test_Case {

	/**
	 * Start every test with an empty child-loader memo.
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
	 * Each rule's status in the parent's checklist, read through the public facade.
	 *
	 * @since 1.1.0
	 *
	 * @param int $parent_id Parent product id.
	 * @return array<string, string>
	 */
	private static function statuses( int $parent_id ): array {
		$result   = Plugin::instance()->checklist()->validate_post( $parent_id );
		$statuses = array();

		foreach ( $result->get_results() as $row ) {
			$statuses[ $row->get_rule_id() ] = $row->get_status();
		}

		return $statuses;
	}

	/**
	 * The variation ids of a parent, in menu order.
	 *
	 * @since 1.1.0
	 *
	 * @param int $parent_id Parent product id.
	 * @return int[]
	 */
	private static function variation_ids( int $parent_id ): array {
		$parent = wc_get_product( $parent_id );

		return $parent instanceof WC_Product_Variable ? array_map( 'intval', $parent->get_children() ) : array();
	}

	/**
	 * A variable product whose first variation is priced and whose second is not.
	 *
	 * @since 1.1.0
	 *
	 * @return int Parent product id.
	 */
	private function half_priced_parent(): int {
		return $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array( 'regular_price' => '' ),
			)
		);
	}

	/**
	 * The service hooks every variation write R6 confirmed, on the shared instance.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_flush_hooks_are_registered(): void {
		$callback = array( Plugin::instance()->checklist(), 'flush_parent_of' );

		foreach (
			array(
				'woocommerce_new_product_variation',
				'woocommerce_update_product_variation',
				'woocommerce_before_delete_product_variation',
				'woocommerce_trash_product_variation',
				'woocommerce_variation_set_stock',
				'woocommerce_variation_set_stock_status',
			) as $hook
		) {
			$this->assertNotFalse( has_action( $hook, $callback ), $hook );
		}
	}

	/**
	 * Clearing a variation's price is seen at once, not after the cache expires.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variation_price_change_refreshes_the_parent_result(): void {
		$parent_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array( 'regular_price' => '12' ),
			)
		);

		$this->assertSame( Status::PASS, self::statuses( $parent_id )['price'] );

		$variation = wc_get_product( self::variation_ids( $parent_id )[1] );
		$variation->set_regular_price( '' );
		$variation->save();

		$this->assertSame( Status::FAIL, self::statuses( $parent_id )['price'] );
	}

	/**
	 * A new variation is seen at once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_new_variation_refreshes_the_parent_result(): void {
		$parent_id = $this->variable_product( array( array( 'regular_price' => '10' ) ) );

		$this->assertSame( Status::PASS, self::statuses( $parent_id )['price'] );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_props(
			array(
				'status'        => 'publish',
				'regular_price' => '',
			)
		);
		$variation->save();

		$this->assertSame( Status::FAIL, self::statuses( $parent_id )['price'] );
	}

	/**
	 * Deleting or trashing the failing variation, singly or in bulk, clears the failure.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variation_delete_and_trash_flush_the_parent(): void {
		// Single permanent delete, as the "Remove" button does.
		$parent_id = $this->half_priced_parent();
		$this->assertSame( Status::FAIL, self::statuses( $parent_id )['price'] );

		wc_get_product( self::variation_ids( $parent_id )[1] )->delete( true );
		$this->assertSame( Status::PASS, self::statuses( $parent_id )['price'] );

		// Single trash.
		$parent_id = $this->half_priced_parent();
		$this->assertSame( Status::FAIL, self::statuses( $parent_id )['price'] );

		wc_get_product( self::variation_ids( $parent_id )[1] )->delete();
		$this->assertSame( Status::PASS, self::statuses( $parent_id )['price'] );

		// Bulk, through the parent: every variation goes, so the parent has none left.
		foreach ( array( true, false ) as $force ) {
			$parent_id = $this->half_priced_parent();
			$before    = self::statuses( $parent_id );
			$this->assertSame( Status::FAIL, $before['price'] );
			$this->assertSame( Status::PASS, $before['child_products'] );

			WC_Data_Store::load( 'product-variable' )->delete_variations( $parent_id, $force );

			$after = self::statuses( $parent_id );
			$this->assertNotSame( Status::FAIL, $after['price'], $force ? 'bulk delete' : 'bulk trash' );
			// `child_products` ships at warning severity (D7), so its failure shows as a warning.
			$this->assertSame( Status::WARNING, $after['child_products'], $force ? 'bulk delete' : 'bulk trash' );
		}
	}

	/**
	 * A stock change made the way orders make it is seen at once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variation_stock_change_flushes_the_parent(): void {
		$parent_id = $this->variable_product(
			array(
				array(
					'regular_price'  => '10',
					'manage_stock'   => true,
					'stock_quantity' => 5,
					'stock_status'   => 'instock',
				),
			)
		);

		$this->assertSame( Status::PASS, self::statuses( $parent_id )['stock_status'] );

		wc_update_product_stock( wc_get_product( self::variation_ids( $parent_id )[0] ), 0, 'set' );

		$this->assertSame( Status::WARNING, self::statuses( $parent_id )['stock_status'] );
	}

	/**
	 * The cache-key schema moved with the variation-aware result shape.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_cache_version_is_v2(): void {
		$this->assertSame( 'v2', Checklist_Service::CACHE_VERSION );
	}
}
