<?php
/**
 * Tests for the variations and grouped products rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Rules\Child_Products_Rule;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the empty variable product, the empty or unpublished group, and the skips.
 *
 * @since 1.1.0
 */
final class Child_Products_Rule_Test extends TestCase {

	/**
	 * The fix target of a grouped failure.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private const GROUPED_FIX = array(
		'selector' => '#grouped_products',
		'label'    => 'Add products to the group',
		'panel'    => 'linked_product',
	);

	/**
	 * Run the rule.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Context values.
	 * @return Rule_Result
	 */
	private function check( array $data ): Rule_Result {
		return ( new Child_Products_Rule() )->check( Product_Context::from_array( $data ), $this->createMock( Settings::class ) );
	}

	/**
	 * A grouped product context.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $child_ids Existing linked products.
	 * @param int   $published How many of them are published.
	 * @return array
	 */
	private function grouped( array $child_ids, int $published ): array {
		return array(
			'product_type'          => 'grouped',
			'child_ids'             => $child_ids,
			'published_child_count' => $published,
		);
	}

	/**
	 * Identity, placement and the shipped severity (D7).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_metadata() {
		$rule = new Child_Products_Rule();

		$this->assertSame( 'child_products', $rule->get_id() );
		$this->assertSame( 'Variations and grouped products', $rule->get_label() );
		$this->assertSame( 'organization', $rule->get_group() );
		$this->assertSame( 120, $rule->get_priority() );
		$this->assertSame( Severity::WARNING, $rule->get_default_severity() );
		$this->assertTrue( $rule->is_enabled_by_default() );
		$this->assertSame(
			'Checks that a variable product has at least one enabled variation, and that a grouped product contains at least one existing product that is published.',
			$rule->get_description()
		);
	}

	/**
	 * Simple and external products have nothing to contain.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_simple_and_external_skip() {
		foreach ( array( 'simple', 'external', 'subscription' ) as $type ) {
			$result = $this->check( array( 'product_type' => $type ) );

			$this->assertSame( Status::SKIPPED, $result->get_status(), $type );
			$this->assertSame( 'Only variable and grouped products have variations or linked products.', $result->get_message(), $type );
		}
	}

	/**
	 * One enabled variation is enough.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_with_enabled_variation_passes() {
		$result = $this->check(
			array(
				'product_type' => 'variable',
				'variations'   => array( array( 'id' => 3 ) ),
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A variable product with no enabled variation has nothing to sell.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_without_enabled_variation_fails() {
		foreach ( array( 'variable', 'variable-subscription' ) as $type ) {
			$result = $this->check( array( 'product_type' => $type ) );

			$this->assertSame( Status::FAIL, $result->get_status(), $type );
			$this->assertSame( 'This variable product has no enabled variations — add or enable one in Product data → Variations.', $result->get_message(), $type );
			$this->assertSame( array( 'enabled' => 0 ), $result->get_data(), $type );
			$this->assertSame( Variation_Fix::target(), $result->get_fix(), $type );
		}
	}

	/**
	 * A group with no existing products in it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_without_children_fails() {
		$result = $this->check( $this->grouped( array(), 0 ) );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'This grouped product contains no products — add some in Product data → Linked Products → Grouped products.', $result->get_message() );
		$this->assertSame(
			array(
				'linked'    => 0,
				'published' => 0,
			),
			$result->get_data()
		);
		$this->assertSame( self::GROUPED_FIX, $result->get_fix() );
	}

	/**
	 * A group whose products are all unpublished, in the singular and the plural.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_with_no_published_child_fails() {
		$single = $this->check( $this->grouped( array( 11 ), 0 ) );

		$this->assertSame( Status::FAIL, $single->get_status() );
		$this->assertSame( 'None of the 1 product in this group is published — publish at least one, or the group has nothing to sell.', $single->get_message() );
		$this->assertSame( self::GROUPED_FIX, $single->get_fix() );

		$plural = $this->check( $this->grouped( array( 11, 12, 13 ), 0 ) );

		$this->assertSame( 'None of the 3 products in this group is published — publish at least one, or the group has nothing to sell.', $plural->get_message() );
		$this->assertSame(
			array(
				'linked'    => 3,
				'published' => 0,
			),
			$plural->get_data()
		);
	}

	/**
	 * One published product makes the group sellable.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_with_one_published_child_passes() {
		$this->assertSame( Status::PASS, $this->check( $this->grouped( array( 11, 12 ), 1 ) )->get_status() );
	}

	/**
	 * Under the shipped defaults the failure warns rather than blocks, so REST and CSV
	 * imports that create the parent before its children are never refused.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_failure_is_a_warning_under_default_severity() {
		unset( $GLOBALS['sit_wcpg_test_options'] );

		$registry = new Rule_Registry();
		$registry->register( new Child_Products_Rule() );

		$result = ( new Validator( $registry, new Settings( $registry ) ) )->validate(
			Product_Context::from_array( array( 'product_type' => 'variable' ) )
		);

		$results = $result->get_results();

		$this->assertCount( 1, $results );
		$this->assertSame( Status::FAIL, $results[0]->get_outcome() );
		$this->assertSame( Status::WARNING, $results[0]->get_status() );
		$this->assertSame( Severity::WARNING, $results[0]->get_severity() );
		$this->assertSame( array(), $result->get_required_failures() );
		$this->assertTrue( $result->is_ready() );
	}
}
