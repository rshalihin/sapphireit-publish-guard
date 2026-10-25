<?php
/**
 * Tests for the regular price rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Price_Rule;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the missing, malformed, free and skipped branches, and the per-variation check.
 *
 * @since 1.0.0
 */
final class Price_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $price Regular price, as stored.
	 * @param string $type  WooCommerce product type.
	 * @return Rule_Result
	 */
	private function check( string $price, string $type = 'simple' ): Rule_Result {
		return ( new Price_Rule() )->check(
			Product_Context::from_array(
				array(
					'regular_price' => $price,
					'product_type'  => $type,
				)
			),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No price at all is an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_price_fails() {
		$result = $this->check( '' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The regular price is empty — enter it in Product data → General → Regular price.', $result->get_message() );
	}

	/**
	 * Whitespace is not a price.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_whitespace_price_fails_as_empty() {
		$this->assertSame( 'The regular price is empty — enter it in Product data → General → Regular price.', $this->check( '   ' )->get_message() );
	}

	/**
	 * Something that is not a number gets its own message.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_non_numeric_price_fails() {
		$result = $this->check( 'nineteen' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The regular price is not a valid amount — enter a number of zero or more in Product data → General → Regular price.', $result->get_message() );
	}

	/**
	 * A negative price is not a price.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_negative_price_fails() {
		$result = $this->check( '-5' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The regular price is not a valid amount — enter a number of zero or more in Product data → General → Regular price.', $result->get_message() );
	}

	/**
	 * Free products are legitimate, so zero passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_price_of_zero_passes() {
		$this->assertSame( Status::PASS, $this->check( '0' )->get_status() );
		$this->assertSame( Status::PASS, $this->check( '0.00' )->get_status() );
	}

	/**
	 * An ordinary price passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_ordinary_price_passes() {
		$this->assertSame( Status::PASS, $this->check( '19.99' )->get_status() );
	}

	/**
	 * An external product does carry its own price, so it is checked like a simple one.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_external_is_checked_like_simple() {
		$this->assertSame( 'The regular price is empty — enter it in Product data → General → Regular price.', $this->check( '', 'external' )->get_message() );
		$this->assertSame( Status::FAIL, $this->check( 'abc', 'external' )->get_status() );
		$this->assertSame( Status::PASS, $this->check( '25', 'external' )->get_status() );
	}

	/**
	 * Run the rule on a product with variations.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $prices Regular price by variation id, in variation order.
	 * @param string $type   WooCommerce product type.
	 * @return Rule_Result
	 */
	private function check_variations( array $prices, string $type = 'variable' ): Rule_Result {
		$variations = array();

		foreach ( $prices as $id => $price ) {
			$variations[] = array(
				'id'            => $id,
				'regular_price' => $price,
			);
		}

		return ( new Price_Rule() )->check(
			Product_Context::from_array(
				array(
					'product_type'  => $type,
					'regular_price' => '',
					'variations'    => $variations,
				)
			),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * The parent's own empty price is irrelevant when every enabled variation is priced.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_passes_when_every_enabled_variation_is_priced() {
		$result = $this->check_variations(
			array(
				3 => '10',
				5 => '12.50',
				7 => '99',
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A free variation is as legitimate as a free product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_zero_price_variation_passes() {
		$this->assertSame(
			Status::PASS,
			$this->check_variations(
				array(
					3 => '0',
					5 => '0.00',
				)
			)->get_status()
		);
	}

	/**
	 * One unpriced variation fails the product, says which one, and points at the Variations tab.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_fails_on_one_unpriced_variation() {
		$result = $this->check_variations(
			array(
				3 => '10',
				5 => '',
				7 => '10',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 3 enabled variations has no valid regular price (first: variation #5) — enter one in Product data → Variations → Regular price.', $result->get_message() );
		$this->assertSame(
			array(
				'failing'            => 1,
				'enabled'            => 3,
				'first_variation_id' => 5,
			),
			$result->get_data()
		);
		$this->assertSame( Variation_Fix::target(), $result->get_fix() );
	}

	/**
	 * Two or more failing variations take the plural copy.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_plural_copy() {
		$result = $this->check_variations(
			array(
				3 => '',
				5 => '10',
				7 => '',
			)
		);

		$this->assertSame( '2 of 3 enabled variations have no valid regular price (first: variation #3) — enter one in Product data → Variations → Regular price.', $result->get_message() );
		$this->assertSame( 2, $result->get_data()['failing'] );
	}

	/**
	 * "Valid" keeps its v1.0 meaning on a variation: numeric and not negative.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_invalid_price_counts_as_failing() {
		$result = $this->check_variations(
			array(
				3 => 'abc',
				5 => '-1',
				7 => '10',
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 2, $result->get_data()['failing'] );
		$this->assertSame( 3, $result->get_data()['enabled'] );
	}

	/**
	 * The first failing id is the first in variation order, not the lowest id.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_first_failing_id_follows_variation_order() {
		$result = $this->check_variations(
			array(
				9 => '10',
				8 => '',
				2 => '',
			)
		);

		$this->assertSame( 8, $result->get_data()['first_variation_id'] );
		$this->assertStringContainsString( '(first: variation #8)', $result->get_message() );
	}

	/**
	 * A variable product with nothing in it is left to the child_products check.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_without_variations_skips_with_shared_reason() {
		$result = $this->check_variations( array() );

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( Variation_Fix::zero_variations_reason(), $result->get_message() );
	}

	/**
	 * A grouped product has no price of its own.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_skips_with_reason() {
		$result = $this->check( '', 'grouped' );

		$this->assertSame( Status::SKIPPED, $result->get_status() );
		$this->assertSame( 'A grouped product has no price of its own — its products carry their own prices.', $result->get_message() );
	}

	/**
	 * A variable subscription is checked per variation, like any variable product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_subscription_is_variable_family() {
		$result = $this->check_variations( array( 4 => '' ), 'variable-subscription' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( '1 of 1 enabled variations has no valid regular price (first: variation #4) — enter one in Product data → Variations → Regular price.', $result->get_message() );
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
			'Checks that the product has a valid regular price. On a variable product, every enabled variation needs one; grouped products are skipped.',
			( new Price_Rule() )->get_description()
		);
	}
}
