<?php
/**
 * Tests for the product category rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Category_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the missing, default-only and properly categorized branches.
 *
 * @since 1.0.0
 */
final class Category_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array $categories Category term identifiers.
	 * @param int   $default_id The store's default category term identifier.
	 * @return Rule_Result
	 */
	private function check( array $categories, int $default_id = 15 ): Rule_Result {
		return ( new Category_Rule() )->check(
			Product_Context::from_array(
				array(
					'category_ids'        => $categories,
					'default_category_id' => $default_id,
				)
			),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No category at all is an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_category_fails() {
		$result = $this->check( array() );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The product is not in any category — tick one in the Product categories box.', $result->get_message() );
		$this->assertSame( array( 'category_count' => 0 ), $result->get_data() );
	}

	/**
	 * WooCommerce assigns the default category by itself, so a product that has only
	 * that one has not actually been categorized — but it is still shippable, so the
	 * rule advises rather than blocks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_only_the_default_category_warns() {
		$result = $this->check( array( 15 ) );

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 'The product is only in the store\'s default category — choose a more specific one in the Product categories box.', $result->get_message() );
	}

	/**
	 * A category someone chose passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_real_category_passes() {
		$result = $this->check( array( 22 ) );

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( '', $result->get_message() );
	}

	/**
	 * The default alongside a real category is a deliberate choice, so it passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_default_plus_a_real_category_passes() {
		$result = $this->check( array( 15, 22 ) );

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( array( 'category_count' => 2 ), $result->get_data() );
	}

	/**
	 * A store with no default category configured cannot have a default-only product.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_configured_default_means_no_default_only_warning() {
		$this->assertSame( Status::PASS, $this->check( array( 15 ), 0 )->get_status() );
	}
}
