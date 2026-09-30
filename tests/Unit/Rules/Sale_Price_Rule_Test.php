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
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the skip, the four failing branches, the severity mapping and the passing case.
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
		$this->assertSame( 'The sale price is not a valid number.', $result->get_message() );
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
		$this->assertSame( 'The sale price cannot be negative.', $result->get_message() );
	}

	/**
	 * A sale that is not a discount fails, whether it is higher or merely equal.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_sale_price_at_or_above_the_regular_price_fails() {
		$expected = 'The sale price is not lower than the regular price.';

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
		$this->assertSame( 'The sale end date is earlier than the sale start date.', $result->get_message() );
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
}
