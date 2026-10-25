<?php
/**
 * New products are reported under their own id.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Plugin;
use WC_Product_Simple;

/**
 * A product created as published is judged before it has an id. The guard holds its notice
 * until the save gives it one, so several new products refused in one request (an import, a
 * REST batch) each keep an entry, instead of overwriting each other under key 0 (§6.1).
 *
 * @since 1.1.0
 */
final class Publish_Guard_New_Product_Notice_Test extends Publish_Guard_Test_Case {

	/**
	 * Create an incomplete product as published through CRUD.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Product name.
	 * @return int The created product id.
	 */
	private static function crud_create( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'   => $name,
				'status' => 'publish',
			)
		);

		return $product->save();
	}

	/**
	 * Insert an incomplete product as published through `wp_insert_post()`.
	 *
	 * @since 1.1.0
	 *
	 * @param string $title Product title.
	 * @return int The inserted product id.
	 */
	private static function insert( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * Assert each product has a blocked entry of its own and nothing was left under key 0.
	 *
	 * @since 1.1.0
	 *
	 * @param int[]  $ids  Product ids.
	 * @param string $kind Notices::KIND_* value.
	 * @return void
	 */
	private function assertEachHasItsOwnEntry( array $ids, string $kind = Notices::KIND_BLOCKED ): void {
		$this->assertArrayNotHasKey( 0, $this->pending_notices(), 'An entry was left under key 0.' );

		foreach ( $ids as $id ) {
			$this->assertGreaterThan( 0, $id );
			$this->assertNoticeQueued( $id, $kind );
		}

		$this->assertCount( count( $ids ), $this->pending_notices() );
	}

	/**
	 * Two products created as published through CRUD in one request are both reported.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_new_crud_products_each_get_their_own_entry(): void {
		$first  = self::crud_create( 'Created first' );
		$second = self::crud_create( 'Created second' );

		$this->assertSame( 'draft', $this->status_of( $first ) );
		$this->assertSame( 'draft', $this->status_of( $second ) );
		$this->assertEachHasItsOwnEntry( array( $first, $second ) );
		$this->assertNotNull( Plugin::instance()->publish_guard()->get_refused_result( $first ) );
	}

	/**
	 * Two products inserted as published in one request are both reported.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_new_inserted_products_each_get_their_own_entry(): void {
		$first  = self::insert( 'Inserted first' );
		$second = self::insert( 'Inserted second' );

		$this->assertSame( 'draft', $this->status_of( $first ) );
		$this->assertSame( 'draft', $this->status_of( $second ) );
		$this->assertEachHasItsOwnEntry( array( $first, $second ) );
		$this->assertNotNull( Plugin::instance()->publish_guard()->get_refused_result( $second ) );
	}

	/**
	 * Inserts that finish inside a refused insert, a page and a draft product, do not take
	 * its entry.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_nested_insert_does_not_take_the_entry(): void {
		$nested = array();
		$hook   = static function ( $post_id, $post, $update ) use ( &$nested ) {
			if ( $update || 'Inserted outer' !== $post->post_title ) {
				return;
			}

			$nested[] = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Nested page',
				)
			);
			$nested[] = wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'draft',
					'post_title'  => 'Nested draft product',
				)
			);
		};

		add_action( 'save_post', $hook, 10, 3 );
		$outer = self::insert( 'Inserted outer' );
		remove_action( 'save_post', $hook, 10 );

		$this->assertCount( 2, $nested );
		$this->assertSame( 'draft', $this->status_of( $outer ) );
		$this->assertEachHasItsOwnEntry( array( $outer ) );
	}

	/**
	 * An override on a new product is reported under its id too.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_override_on_a_new_product_is_reported_under_its_id(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$created  = self::crud_create( 'Created past the guard' );
		$inserted = self::insert( 'Inserted past the guard' );

		$this->assertSame( 'publish', $this->status_of( $created ) );
		$this->assertSame( 'publish', $this->status_of( $inserted ) );
		$this->assertEachHasItsOwnEntry( array( $created, $inserted ), Notices::KIND_OVERRIDE );
	}
}
