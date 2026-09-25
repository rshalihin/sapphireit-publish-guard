<?php
/**
 * Layer A through Quick Edit.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ReflectionClass;
use WC_Admin_Post_Types;

/**
 * Quick Edit posts price, SKU and status but no description (section 12.2), and
 * WooCommerce applies its fields on `save_post` — after Layer A has already decided.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Quick_Edit_Test extends Publish_Guard_Test_Case {

	/**
	 * Forget the simulated request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * A Quick Edit payload for a product, as `edit_post()` hands it to core.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Product id.
	 * @param array $fields  Fields to add or replace.
	 * @return array
	 */
	private function payload( int $post_id, array $fields = array() ): array {
		return array_merge(
			array(
				'action'                       => 'inline-save',
				'post_ID'                      => $post_id,
				'woocommerce_quick_edit'       => '1',
				'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
				'_regular_price'               => '19.99',
				'_sale_price'                  => '',
				'_sku'                         => 'SIT-WCPG-QUICK-1',
				'_stock_status'                => 'instock',
			),
			$fields
		);
	}

	/**
	 * No content field in the payload is not an empty description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_absent_description_is_not_a_failure(): void {
		$id = $this->passing_product();

		$this->publish( $id, $this->payload( $id ) );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices() );
	}

	/**
	 * A price typed into Quick Edit counts before WooCommerce saves it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_quick_edit_price_is_read_before_it_is_saved(): void {
		$id = $this->failing_product();

		$this->publish( $id, $this->payload( $id, array( '_regular_price' => '8.00' ) ) );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * Clearing the price in Quick Edit is refused, and WooCommerce still keeps the other
	 * edits made in the same Quick Edit (section 6.3.1).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_refused_quick_edit_still_keeps_the_field_edits(): void {
		$id      = $this->passing_product();
		$payload = $this->payload(
			$id,
			array(
				'_regular_price' => '',
				'_sku'           => 'SIT-WCPG-QUICK-EDITED',
			)
		);

		$this->publish( $id, $payload );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id );

		// Run WooCommerce's own save_post handler, which reads the request itself.
		if ( ! class_exists( 'WC_Admin_Post_Types' ) ) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-post-types.php';
		}

		$_REQUEST = $payload;
		$handler  = ( new ReflectionClass( WC_Admin_Post_Types::class ) )->newInstanceWithoutConstructor();
		$handler->bulk_and_quick_edit_save_post( $id, get_post( $id ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'SIT-WCPG-QUICK-EDITED', $product->get_sku() );
		$this->assertSame( '', $product->get_regular_price() );
		$this->assertSame( 'draft', $this->status_of( $id ) );
	}
}
