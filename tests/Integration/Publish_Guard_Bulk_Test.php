<?php
/**
 * Layer A through Bulk Edit.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;

/**
 * Bulk Edit is judged per product: the failing ones stay drafts, the passing ones in the
 * same batch still publish (section 12.2, manual row 18).
 *
 * @since 1.0.0
 */
final class Publish_Guard_Bulk_Test extends Publish_Guard_Test_Case {

	/**
	 * Load core's bulk-edit function.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	/**
	 * Run core's Bulk Edit over some products.
	 *
	 * @since 1.0.0
	 *
	 * @param int[]  $ids    Products.
	 * @param string $status `_status` value; `-1` is "No change".
	 * @return void
	 */
	private function bulk_edit( array $ids, string $status ): void {
		bulk_edit_posts(
			array(
				'post_type'                    => 'product',
				'bulk_edit'                    => 'Update',
				'post'                         => array_map( 'strval', $ids ),
				'_status'                      => $status,
				'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
				'change_regular_price'         => '',
				'_regular_price'               => '',
			)
		);
	}

	/**
	 * Five products, two failing: three publish, two stay drafts, and one notice names
	 * exactly the two.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_failing_products_are_refused_one_by_one(): void {
		$passing = array( $this->passing_product(), $this->passing_product(), $this->passing_product() );
		$failing = array( $this->failing_product(), $this->failing_product() );

		$this->bulk_edit( array_merge( $passing, $failing ), 'publish' );

		foreach ( $passing as $id ) {
			$this->assertSame( 'publish', $this->status_of( $id ) );
		}

		foreach ( $failing as $id ) {
			$this->assertSame( 'draft', $this->status_of( $id ) );
			$this->assertNoticeQueued( $id );
		}

		$this->assertEqualsCanonicalizing( $failing, array_keys( $this->pending_notices() ) );

		set_current_screen( 'edit-product' );

		ob_start();
		Plugin::instance()->notices()->render();
		$html = (string) ob_get_clean();

		set_current_screen( 'front' );

		$this->assertStringContainsString( '2 products were not published', $html );
		$this->assertSame( 2, substr_count( $html, '<li>' ) );
		$this->assertSame( array(), $this->pending_notices() );
	}

	/**
	 * "No change" to the status is no publish attempt, whatever the products hold.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_change_to_the_status_is_not_judged(): void {
		$id = $this->failing_product();

		$this->bulk_edit( array( $id ), '-1' );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices() );
	}
}
