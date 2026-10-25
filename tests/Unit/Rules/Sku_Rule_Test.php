<?php
/**
 * Tests for the SKU rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Sku_Rule;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers both branches of the SKU rule, and WooCommerce's SKU inheritance on variations.
 *
 * @since 1.0.0
 */
final class Sku_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sku Product SKU.
	 * @return Rule_Result
	 */
	private function check( string $sku ): Rule_Result {
		return ( new Sku_Rule() )->check(
			Product_Context::from_array( array( 'sku' => $sku ) ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No SKU reports an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_sku_fails() {
		$result = $this->check( '' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The product has no SKU — enter one in Product data → Inventory → SKU.', $result->get_message() );
	}

	/**
	 * Whitespace is not a SKU.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_whitespace_only_sku_fails() {
		$this->assertSame( Status::FAIL, $this->check( "  \t " )->get_status() );
	}

	/**
	 * Any real SKU passes; uniqueness is WooCommerce's job, not this rule's.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_set_sku_passes() {
		$this->assertSame( Status::PASS, $this->check( 'MUG-001' )->get_status() );
		$this->assertSame( Status::PASS, $this->check( '0' )->get_status() );
	}

	/**
	 * The rule points the merchant at the inventory panel.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_fix_target_is_the_inventory_panel() {
		$fix = $this->check( '' )->get_fix();

		$this->assertSame( '#_sku', $fix['selector'] );
		$this->assertSame( 'inventory', $fix['panel'] );
	}

	/**
	 * Run the rule on a product with variations.
	 *
	 * @since 1.1.0
	 *
	 * @param string $parent_sku The parent's own SKU.
	 * @param array  $skus       Each variation's own SKU by id, in variation order.
	 * @return Rule_Result
	 */
	private function check_variations( string $parent_sku, array $skus ): Rule_Result {
		$variations = array();

		foreach ( $skus as $id => $sku ) {
			$variations[] = array(
				'id'  => $id,
				'sku' => $sku,
			);
		}

		return ( new Sku_Rule() )->check(
			Product_Context::from_array(
				array(
					'product_type' => 'variable',
					'sku'          => $parent_sku,
					'variations'   => $variations,
				)
			),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * WooCommerce passes the parent SKU down, so it covers every variation (D6).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_parent_sku_passes_even_when_variations_have_none() {
		$result = $this->check_variations(
			'TEE',
			array(
				3 => '',
				5 => '',
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( Status::PASS, $this->check_variations( 'TEE', array() )->get_status() );
	}

	/**
	 * Without a parent SKU, a variation without its own fails, and the fix points at the
	 * parent SKU, the quicker fix.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_without_parent_sku_needs_every_variation_sku() {
		$result = $this->check_variations(
			'',
			array(
				3 => 'TEE-S',
				5 => '',
				7 => 'TEE-L',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 3 enabled variations has no SKU and the product has none to pass down (first: variation #5) — add one in Product data → Inventory → SKU, or on each variation.', $result->get_message() );
		$this->assertSame(
			array(
				'failing'            => 1,
				'enabled'            => 3,
				'first_variation_id' => 5,
			),
			$result->get_data()
		);
		$this->assertSame( '#_sku', $result->get_fix()['selector'] );
		$this->assertSame( 'inventory', $result->get_fix()['panel'] );

		$plural = $this->check_variations(
			'',
			array(
				9 => 'TEE-S',
				8 => '',
				2 => '',
			)
		);

		$this->assertSame( '2 of 3 enabled variations have no SKU and the product has none to pass down (first: variation #8) — add one in Product data → Inventory → SKU, or on each variation.', $plural->get_message() );
	}

	/**
	 * Every variation carrying its own SKU is as good as a parent SKU.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_without_parent_sku_all_variations_have_sku_passes() {
		$result = $this->check_variations(
			'',
			array(
				3 => 'TEE-S',
				5 => '0',
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A variable product with nothing in it is left to the child_products check.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_without_parent_sku_and_no_variations_skips() {
		$result = $this->check_variations( '', array() );

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( Variation_Fix::zero_variations_reason(), $result->get_message() );
	}

	/**
	 * Whitespace is no SKU on the parent or on a variation.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_whitespace_only_sku_counts_as_missing() {
		$result = $this->check_variations(
			"  \t ",
			array(
				3 => '   ',
				5 => 'TEE-M',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 1, $result->get_data()['failing'] );
		$this->assertSame( 3, $result->get_data()['first_variation_id'] );
	}

	/**
	 * Grouped and external products have no variations to inherit from, so they need a
	 * SKU of their own.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_and_external_need_their_own() {
		foreach ( array( 'grouped', 'external' ) as $type ) {
			$missing = ( new Sku_Rule() )->check(
				Product_Context::from_array(
					array(
						'product_type' => $type,
						'sku'          => '',
						'child_ids'    => array( 11 ),
					)
				),
				$this->createMock( Settings::class )
			);

			$this->assertSame( Status::FAIL, $missing->get_status(), $type );
			$this->assertSame( 'The product has no SKU — enter one in Product data → Inventory → SKU.', $missing->get_message(), $type );

			$set = ( new Sku_Rule() )->check(
				Product_Context::from_array(
					array(
						'product_type' => $type,
						'sku'          => 'SET-1',
					)
				),
				$this->createMock( Settings::class )
			);

			$this->assertSame( Status::PASS, $set->get_status(), $type );
		}
	}

	/**
	 * The settings screen describes the inheritance.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_description_text() {
		$this->assertSame(
			'Checks that the product has a SKU. On a variable product, a SKU on the product covers its variations; otherwise every enabled variation needs its own.',
			( new Sku_Rule() )->get_description()
		);
	}
}
