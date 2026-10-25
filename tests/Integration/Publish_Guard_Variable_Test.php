<?php
/**
 * Every guard layer with variable, grouped and external products.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;

/**
 * The v1.0 layer tests ran on simple products only. These run the same layers on the
 * families v1.1.0 makes the gate understand (coding-plan.md §7.3).
 *
 * @since 1.1.0
 */
final class Publish_Guard_Variable_Test extends Publish_Guard_Test_Case {

	/**
	 * A variable product with one priced and one unpriced variation.
	 *
	 * @since 1.1.0
	 *
	 * @param string $unpriced_status Status of the unpriced variation: `publish` (enabled) or `private` (disabled).
	 * @return int Parent id.
	 */
	private function half_priced_variable( string $unpriced_status ): int {
		return $this->variable_product(
			array(
				array(
					'regular_price' => '10',
					'sku'           => 'SIT-WCPG-' . wp_generate_password( 8, false ),
				),
				array(
					'regular_price' => '',
					'sku'           => 'SIT-WCPG-' . wp_generate_password( 8, false ),
					'status'        => $unpriced_status,
				),
			)
		);
	}

	/**
	 * An enabled variation with no price keeps the parent from publishing, and the notice
	 * names the price check.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer Guard layer.
	 * @return void
	 */
	public function test_variable_with_an_unpriced_enabled_variation_is_refused( string $layer ): void {
		$id = $this->half_priced_variable( 'publish' );

		$user_id = $this->publish_through( $layer, $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id, Notices::KIND_BLOCKED, $user_id );
	}

	/**
	 * The same variation, disabled, is ignored (D4).
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer Guard layer.
	 * @return void
	 */
	public function test_variable_with_the_unpriced_variation_disabled_is_published( string $layer ): void {
		$id = $this->half_priced_variable( 'private' );

		$user_id = $this->publish_through( $layer, $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $user_id ) );
	}

	/**
	 * A grouped product with a published child publishes.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer Guard layer.
	 * @return void
	 */
	public function test_grouped_with_a_published_child_is_published( string $layer ): void {
		$id = $this->grouped_product( array( $this->passing_product( 'publish' ) ) );

		$user_id = $this->publish_through( $layer, $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $user_id ) );
	}

	/**
	 * A complete external product publishes, and its stock status is not judged.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer Guard layer.
	 * @return void
	 */
	public function test_complete_external_product_is_published_and_stock_is_skipped( string $layer ): void {
		$id = $this->external_product( 'draft', array( 'regular_price' => '25' ) );

		$user_id = $this->publish_through( $layer, $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $user_id ) );

		$this->assertNotEmpty( $this->judged_contexts, 'The layer judged nothing.' );

		foreach ( $this->judged_results() as $result ) {
			$this->assertSame( Status::SKIPPED, self::rule_status( $result, 'stock_status' ) );
		}

		$this->assertSame( Status::SKIPPED, self::rule_status( Plugin::instance()->checklist()->validate_post( $id ), 'stock_status' ) );
	}
}
