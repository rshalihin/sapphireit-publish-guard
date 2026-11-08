<?php
/**
 * Tests for reading and writing the readiness flag.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Support\Readiness_Store;

/**
 * The flag round-trips through post meta, leaves the product's own row alone, and reads
 * back safely whatever is in the database (D7, §3.2).
 *
 * @since 1.2.0
 */
final class Readiness_Store_Test extends Publish_Guard_Test_Case {

	/**
	 * The store under test.
	 *
	 * @since 1.2.0
	 * @var Readiness_Store
	 */
	private Readiness_Store $store;

	/**
	 * Build the store.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->store = new Readiness_Store();
	}

	/**
	 * A result with one required failure.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id Product id.
	 * @return Validation_Result
	 */
	private function failing_result( int $product_id ): Validation_Result {
		return Validation_Result::from_results(
			Product_Context::from_array(
				array(
					'product_id'   => $product_id,
					'product_type' => 'simple',
				)
			),
			array( Rule_Result::fail( 'price', 'Price', 'pricing', 'Fail.' )->with_severity( Severity::REQUIRED ) ),
			'hash'
		);
	}

	/**
	 * What is written is what is read.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_write_then_read_round_trips(): void {
		$id     = $this->failing_product();
		$before = time();

		$this->store->write( $id, $this->failing_result( $id ), 'signature' );

		$read = $this->store->read( $id );

		$this->assertSame( 'fail', $read['status'] );
		$this->assertSame( 'signature', $read['signature'] );
		$this->assertSame( array( 'price' ), $read['required_failures'] );
		$this->assertGreaterThanOrEqual( $before, $read['checked_at'] );
	}

	/**
	 * Writing the flag is not an edit of the product.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_write_does_not_touch_post_modified(): void {
		$id = $this->failing_product();

		global $wpdb;

		// Backdated, so a write that bumped it could not hide inside the same second.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wp_update_post() would stamp the time itself.
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2020-01-01 00:00:00' ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		$this->store->write( $id, $this->failing_result( $id ), 'signature' );
		clean_post_cache( $id );

		$this->assertSame( '2020-01-01 00:00:00', get_post( $id )->post_modified_gmt );
	}

	/**
	 * Clearing removes all three keys.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_clear_removes_all_three_keys(): void {
		$id = $this->failing_product();

		$this->store->write( $id, $this->failing_result( $id ), 'signature' );
		$this->store->clear( $id );

		foreach ( Readiness_Store::meta_keys() as $key ) {
			$this->assertFalse( metadata_exists( 'post', $id, $key ), $key . ' was left behind.' );
		}
	}

	/**
	 * A product that was never checked has no flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_read_of_unchecked_product_is_null(): void {
		$this->assertNull( $this->store->read( $this->failing_product() ) );
	}

	/**
	 * Anything odd in the database reads as safe values.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_read_normalizes_tampered_meta(): void {
		$id = $this->failing_product();

		update_post_meta( $id, Readiness_Store::META_STATUS, 'unknown' );
		$this->assertNull( $this->store->read( $id ) );

		update_post_meta( $id, Readiness_Store::META_STATUS, 'fail' );
		update_post_meta( $id, Readiness_Store::META_DATA, 'not an array' );

		$read = $this->store->read( $id );

		$this->assertSame( array(), $read['required_failures'] );
		$this->assertSame( 0, $read['checked_at'] );

		update_post_meta(
			$id,
			Readiness_Store::META_DATA,
			array(
				'required_failures' => array( 'price', '<b>x</b>', array( 'nested' ), 'price' ),
				'checked_at'        => 'soon',
			)
		);

		$read = $this->store->read( $id );

		$this->assertSame( array( 'price', 'bxb' ), $read['required_failures'] );
		$this->assertSame( 0, $read['checked_at'] );
	}

	/**
	 * The keys stay out of the custom-fields box.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_meta_keys_are_protected(): void {
		foreach ( Readiness_Store::meta_keys() as $key ) {
			$this->assertTrue( is_protected_meta( $key, 'post' ), $key . ' is not protected.' );
		}
	}
}
