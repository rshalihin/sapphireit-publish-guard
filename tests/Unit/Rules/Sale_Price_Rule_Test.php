<?php
/**
 * Tests for the sale price rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Sale_Price_Rule;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the skip, the four failing branches, the severity mapping, the passing case and
 * the per-variation check.
 *
 * @since 1.0.0
 */
final class Sale_Price_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Context values.
	 * @return Rule_Result
	 */
	private function check( array $data ): Rule_Result {
		return ( new Sale_Price_Rule() )->check(
			Product_Context::from_array( $data ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * A product that is not on sale has nothing to check.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_sale_price_skips() {
		$this->assertSame( Status::SKIPPED, $this->check( array( 'regular_price' => '20' ) )->get_status() );
		$this->assertSame(
			Status::SKIPPED,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '   ',
				)
			)->get_status()
		);
	}

	/**
	 * A sale price that is not a number fails.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_non_numeric_sale_price_fails() {
		$result = $this->check(
			array(
				'regular_price' => '20',
				'sale_price'    => 'half price',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The sale price is not a valid number — correct it in Product data → General → Sale price.', $result->get_message() );
	}

	/**
	 * A negative sale price gets its own message.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_negative_sale_price_fails() {
		$result = $this->check(
			array(
				'regular_price' => '20',
				'sale_price'    => '-1',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The sale price is negative — correct it in Product data → General → Sale price.', $result->get_message() );
	}

	/**
	 * A sale that is not a discount fails, whether it is higher or merely equal.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_sale_price_at_or_above_the_regular_price_fails() {
		$expected = 'The sale price is not lower than the regular price — lower it in Product data → General → Sale price.';

		$this->assertSame(
			$expected,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '25',
				)
			)->get_message()
		);

		$this->assertSame(
			$expected,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '20',
				)
			)->get_message()
		);
	}

	/**
	 * A sale window that ends before it starts fails.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_sale_ending_before_it_starts_fails() {
		$result = $this->check(
			array(
				'regular_price' => '20',
				'sale_price'    => '15',
				'sale_from'     => '2026-05-10 00:00:00',
				'sale_to'       => '2026-05-01 00:00:00',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The sale ends before it starts — fix the dates in Product data → General → Schedule.', $result->get_message() );
	}

	/**
	 * The merchant's severity decides the status: a warning by default, a block when required.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_severity_decides_whether_a_bad_sale_blocks() {
		$result = $this->check(
			array(
				'regular_price' => '20',
				'sale_price'    => '25',
			)
		);

		$this->assertSame( Status::WARNING, $result->with_severity( Severity::WARNING )->get_status() );
		$this->assertSame( Status::FAIL, $result->with_severity( Severity::REQUIRED )->get_status() );
	}

	/**
	 * A valid sale passes, with or without dates.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_valid_sale_passes() {
		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '15',
				)
			)->get_status()
		);

		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '15',
					'sale_from'     => '2026-05-01 00:00:00',
					'sale_to'       => '2026-05-10 00:00:00',
				)
			)->get_status()
		);
	}

	/**
	 * With only one of the two dates set there is no window to contradict.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_single_sale_date_is_not_a_contradiction() {
		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'regular_price' => '20',
					'sale_price'    => '15',
					'sale_to'       => '2026-05-01 00:00:00',
				)
			)->get_status()
		);
	}

	/**
	 * With no usable regular price to compare against, the comparison is skipped rather
	 * than guessed at — the price rule is the one that reports that problem.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unusable_regular_price_does_not_produce_a_comparison_warning() {
		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'regular_price' => '',
					'sale_price'    => '15',
				)
			)->get_status()
		);
	}

	/**
	 * Run the rule on a product with variations.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $variations Variation data, in variation order.
	 * @param string $type       WooCommerce product type.
	 * @return Rule_Result
	 */
	private function check_variations( array $variations, string $type = 'variable' ): Rule_Result {
		return $this->check(
			array(
				'product_type'  => $type,
				'regular_price' => '',
				'sale_price'    => '',
				'variations'    => $variations,
			)
		);
	}

	/**
	 * Variations with no sale price have nothing to check.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_no_variation_on_sale_skips() {
		$result = $this->check_variations(
			array(
				array(
					'id'            => 3,
					'regular_price' => '20',
				),
				array(
					'id'            => 5,
					'regular_price' => '20',
					'sale_price'    => '  ',
				),
			)
		);

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( 'No enabled variation is on sale.', $result->get_message() );
	}

	/**
	 * Valid sales on every variation on sale pass, with or without dates.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_valid_sales_pass() {
		$result = $this->check_variations(
			array(
				array(
					'id'            => 3,
					'regular_price' => '20',
					'sale_price'    => '15',
				),
				array(
					'id'            => 5,
					'regular_price' => '30',
					'sale_price'    => '25',
					'sale_from'     => '2026-05-01 00:00:00',
					'sale_to'       => '2026-05-10 00:00:00',
				),
				array(
					'id'            => 7,
					'regular_price' => '30',
				),
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * Each variation is compared with its own regular price, never the parent's.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_sale_not_lower_than_its_own_regular_fails() {
		$result = $this->check(
			array(
				'product_type'  => 'variable',
				'regular_price' => '100',
				'variations'    => array(
					array(
						'id'            => 3,
						'regular_price' => '20',
						'sale_price'    => '15',
					),
					array(
						'id'            => 5,
						'regular_price' => '20',
						'sale_price'    => '25',
					),
				),
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 2 variations on sale has a sale price problem (first: variation #5) — check its sale price and dates in Product data → Variations.', $result->get_message() );
		$this->assertSame( Variation_Fix::target(), $result->get_fix() );

		// A parent regular price below the sale must not fail a variation that is discounted from its own.
		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'product_type'  => 'variable',
					'regular_price' => '1',
					'variations'    => array(
						array(
							'id'            => 3,
							'regular_price' => '20',
							'sale_price'    => '15',
						),
					),
				)
			)->get_status()
		);
	}

	/**
	 * A variation whose sale ends before it starts fails.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_reversed_dates_fail() {
		$result = $this->check_variations(
			array(
				array(
					'id'            => 4,
					'regular_price' => '20',
					'sale_price'    => '15',
					'sale_from'     => '2026-05-10 00:00:00',
					'sale_to'       => '2026-05-01 00:00:00',
				),
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 1 variations on sale has a sale price problem (first: variation #4) — check its sale price and dates in Product data → Variations.', $result->get_message() );
	}

	/**
	 * The denominator is the variations on sale, not every enabled variation.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_counts_only_variations_on_sale() {
		$result = $this->check_variations(
			array(
				array(
					'id'            => 2,
					'regular_price' => '20',
				),
				array(
					'id'            => 9,
					'regular_price' => '20',
					'sale_price'    => 'half',
				),
				array(
					'id'            => 4,
					'regular_price' => '20',
					'sale_price'    => '15',
				),
				array(
					'id'            => 6,
					'regular_price' => '20',
					'sale_price'    => '-1',
				),
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '2 of 3 variations on sale have a sale price problem (first: variation #9) — check its sale price and dates in Product data → Variations.', $result->get_message() );
		$this->assertSame(
			array(
				'failing'            => 2,
				'on_sale'            => 3,
				'first_variation_id' => 9,
			),
			$result->get_data()
		);
	}

	/**
	 * A variable product with nothing in it is left to the child_products check.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_zero_enabled_skips_shared_reason() {
		$result = $this->check_variations( array() );

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( Variation_Fix::zero_variations_reason(), $result->get_message() );
	}

	/**
	 * A grouped product has no sale price of its own, whatever its stored meta says.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_skips() {
		$result = $this->check(
			array(
				'product_type'  => 'grouped',
				'regular_price' => '20',
				'sale_price'    => '25',
			)
		);

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( 'A grouped product has no sale price of its own.', $result->get_message() );
	}

	/**
	 * An external product carries its own sale price, so it is checked like a simple one.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_external_checked_like_simple() {
		$this->assertSame(
			'The sale price is not lower than the regular price — lower it in Product data → General → Sale price.',
			$this->check(
				array(
					'product_type'  => 'external',
					'regular_price' => '20',
					'sale_price'    => '25',
				)
			)->get_message()
		);

		$this->assertSame(
			Status::PASS,
			$this->check(
				array(
					'product_type'  => 'external',
					'regular_price' => '20',
					'sale_price'    => '15',
				)
			)->get_status()
		);
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
			'Checks that a sale price, when one is set, is valid, lower than the regular price and has a sensible date range. On a variable product, each enabled variation on sale is checked.',
			( new Sale_Price_Rule() )->get_description()
		);
	}
}
