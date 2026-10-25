<?php
/**
 * Tests for the stock status rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Stock_Status_Rule;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the missing, invalid, out-of-stock, contradictory and healthy branches, on the
 * product and per variation.
 *
 * @since 1.0.0
 */
final class Stock_Status_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Context values.
	 * @return Rule_Result
	 */
	private function check( array $data ): Rule_Result {
		return ( new Stock_Status_Rule() )->check(
			Product_Context::from_array( $data ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No stock status reports an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_status_fails() {
		$result = $this->check( array( 'stock_status' => '' ) );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'No stock status is selected — choose one in Product data → Inventory → Stock status.', $result->get_message() );
	}

	/**
	 * A value WooCommerce does not recognise fails the same way.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unknown_status_fails() {
		$result = $this->check( array( 'stock_status' => 'maybe' ) );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'No stock status is selected — choose one in Product data → Inventory → Stock status.', $result->get_message() );
	}

	/**
	 * Publishing something nobody can buy is worth mentioning, but it is the merchant's
	 * decision, so it is advisory.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_out_of_stock_warns() {
		$result = $this->check( array( 'stock_status' => 'outofstock' ) );

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 'This product is marked out of stock — change it in Product data → Inventory → Stock status if that is wrong.', $result->get_message() );
	}

	/**
	 * In stock with nothing left is a contradiction that will take orders it cannot
	 * fulfil.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_managed_stock_at_zero_while_in_stock_warns() {
		$result = $this->check(
			array(
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => 0,
			)
		);

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 'Stock management is on but the quantity is 0 — update it in Product data → Inventory → Quantity.', $result->get_message() );
		$this->assertSame( array( 'stock_quantity' => 0 ), $result->get_data() );
	}

	/**
	 * A negative managed quantity is the same contradiction.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_negative_managed_quantity_warns() {
		$result = $this->check(
			array(
				'stock_status'   => 'instock',
				'manage_stock'   => true,
				'stock_quantity' => -3,
			)
		);

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( array( 'stock_quantity' => -3 ), $result->get_data() );
	}

	/**
	 * Without stock management there is no quantity to contradict the status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unmanaged_product_is_not_second_guessed() {
		$result = $this->check(
			array(
				'stock_status'   => 'instock',
				'manage_stock'   => false,
				'stock_quantity' => 0,
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A healthy product passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_in_stock_passes() {
		$this->assertSame( Status::PASS, $this->check( array( 'stock_status' => 'instock' ) )->get_status() );
		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'stock_status'   => 'instock',
					'manage_stock'   => true,
					'stock_quantity' => 12,
				)
			)->get_status()
		);
	}

	/**
	 * Backorders are a deliberate arrangement, not a mistake.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_on_backorder_passes() {
		$this->assertSame( Status::PASS, $this->check( array( 'stock_status' => 'onbackorder' ) )->get_status() );
	}

	/**
	 * Run the rule on a variable product.
	 *
	 * The parent's own stock fields are deliberately healthy, so any result comes from
	 * the variations.
	 *
	 * @since 1.1.0
	 *
	 * @param array $variations Variation data, in variation order.
	 * @return Rule_Result
	 */
	private function check_variations( array $variations ): Rule_Result {
		return $this->check(
			array(
				'product_type' => 'variable',
				'stock_status' => 'instock',
				'variations'   => $variations,
			)
		);
	}

	/**
	 * A variation without a valid stock status fails the product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_invalid_status_fails() {
		$result = $this->check_variations(
			array(
				array(
					'id'           => 3,
					'stock_status' => 'instock',
				),
				array(
					'id'           => 5,
					'stock_status' => '',
				),
				array(
					'id'           => 7,
					'stock_status' => 'outofstock',
				),
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 3 enabled variations has no stock status (first: variation #5) — choose one in Product data → Variations → Stock status.', $result->get_message() );
		$this->assertSame(
			array(
				'failing'            => 1,
				'enabled'            => 3,
				'first_variation_id' => 5,
			),
			$result->get_data()
		);

		$plural = $this->check_variations(
			array(
				array(
					'id'           => 9,
					'stock_status' => 'maybe',
				),
				array(
					'id'           => 4,
					'stock_status' => '',
				),
			)
		);

		$this->assertSame( '2 of 2 enabled variations have no stock status (first: variation #9) — choose one in Product data → Variations → Stock status.', $plural->get_message() );
	}

	/**
	 * The quantity contradiction outranks the out-of-stock notice when both are present.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_quantity_warning_before_out_of_stock_warning() {
		$result = $this->check_variations(
			array(
				array(
					'id'           => 3,
					'stock_status' => 'outofstock',
				),
				array(
					'id'             => 5,
					'stock_status'   => 'instock',
					'manage_stock'   => 'yes',
					'stock_quantity' => 0,
				),
				array(
					'id'             => 7,
					'stock_status'   => 'instock',
					'manage_stock'   => 'yes',
					'stock_quantity' => -2,
				),
			)
		);

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( '2 of 3 enabled variations are in stock with a quantity of zero or less (first: variation #5) — update the quantity in Product data → Variations, or in Product data → Inventory when the product manages stock.', $result->get_message() );
		$this->assertSame( 2, $result->get_data()['failing'] );

		// An invalid status outranks both warnings.
		$this->assertSame(
			Status::FAIL,
			$this->check_variations(
				array(
					array(
						'id'             => 5,
						'stock_status'   => 'instock',
						'manage_stock'   => 'yes',
						'stock_quantity' => 0,
					),
					array(
						'id'           => 6,
						'stock_status' => '',
					),
				)
			)->get_status()
		);
	}

	/**
	 * Out-of-stock variations are worth mentioning, and only advisory.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_out_of_stock_warns() {
		$result = $this->check_variations(
			array(
				array(
					'id'           => 3,
					'stock_status' => 'instock',
				),
				array(
					'id'           => 8,
					'stock_status' => 'outofstock',
				),
			)
		);

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( '1 of 2 enabled variations is marked out of stock (first: variation #8) — check Product data → Variations → Stock status.', $result->get_message() );

		$plural = $this->check_variations(
			array(
				array(
					'id'           => 3,
					'stock_status' => 'outofstock',
				),
				array(
					'id'           => 8,
					'stock_status' => 'outofstock',
				),
			)
		);

		$this->assertSame( '2 of 2 enabled variations are marked out of stock (first: variation #3) — check Product data → Variations → Stock status.', $plural->get_message() );
	}

	/**
	 * A variation whose stock the parent manages reports the parent's quantity, and a
	 * quantity of zero while in stock is the same contradiction.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_parent_managed_variation_uses_parent_quantity() {
		$result = $this->check_variations(
			array(
				array(
					'id'             => 3,
					'stock_status'   => 'instock',
					'manage_stock'   => 'parent',
					'stock_quantity' => 0,
				),
			)
		);

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( '1 of 1 enabled variations is in stock with a quantity of zero or less (first: variation #3) — update the quantity in Product data → Variations, or in Product data → Inventory when the product manages stock.', $result->get_message() );
	}

	/**
	 * A parent-managed variation with stock left is healthy.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_parent_managed_variation_with_stock_passes() {
		$result = $this->check_variations(
			array(
				array(
					'id'             => 3,
					'stock_status'   => 'instock',
					'manage_stock'   => 'parent',
					'stock_quantity' => 12,
				),
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * Without stock management, a variation's quantity contradicts nothing.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_unmanaged_variation_ignores_quantity() {
		$result = $this->check_variations(
			array(
				array(
					'id'             => 3,
					'stock_status'   => 'instock',
					'manage_stock'   => 'no',
					'stock_quantity' => 0,
				),
				array(
					'id'           => 5,
					'stock_status' => 'onbackorder',
				),
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A variable product with nothing in it is left to the child_products check, whatever
	 * the parent's own stock fields say.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_zero_enabled_skips() {
		$result = $this->check(
			array(
				'product_type' => 'variable',
				'stock_status' => '',
			)
		);

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( Variation_Fix::zero_variations_reason(), $result->get_message() );
	}

	/**
	 * Every variable-family outcome points at the Variations tab.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_all_variable_results_carry_the_variations_fix() {
		$cases = array(
			'fail'         => array(
				array(
					'id'           => 3,
					'stock_status' => '',
				),
			),
			'quantity'     => array(
				array(
					'id'             => 3,
					'stock_status'   => 'instock',
					'manage_stock'   => 'yes',
					'stock_quantity' => 0,
				),
			),
			'out of stock' => array(
				array(
					'id'           => 3,
					'stock_status' => 'outofstock',
				),
			),
			'pass'         => array(
				array(
					'id'           => 3,
					'stock_status' => 'instock',
				),
			),
			'zero enabled' => array(),
		);

		foreach ( $cases as $label => $variations ) {
			$this->assertSame( Variation_Fix::target(), $this->check_variations( $variations )->get_fix(), $label );
		}
	}

	/**
	 * A grouped product's stock lives on its products, so its own empty status never
	 * blocks it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_skips_with_reason() {
		$result = $this->check(
			array(
				'product_type' => 'grouped',
				'stock_status' => '',
			)
		);

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( 'A grouped product has no stock of its own — its products carry their own.', $result->get_message() );
	}

	/**
	 * An external product is sold elsewhere, so it has no stock to check.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_external_skips_with_reason() {
		$result = $this->check(
			array(
				'product_type' => 'external',
				'stock_status' => 'outofstock',
			)
		);

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( 'External products have no stock — the store they link to sells them.', $result->get_message() );
	}

	/**
	 * The settings screen describes the variation-aware behaviour.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_description_text() {
		$this->assertSame(
			'Checks that the product has a stock status and that a managed quantity agrees with it. On a variable product, each enabled variation is checked; grouped and external products are skipped.',
			( new Stock_Status_Rule() )->get_description()
		);
	}
}
