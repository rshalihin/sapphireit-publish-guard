<?php
/**
 * Tests for the product tags rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Tags_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers both branches, and the severity that decides how the failure surfaces.
 *
 * @since 1.0.0
 */
final class Tags_Rule_Test extends TestCase {

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tags Tag term identifiers.
	 * @return Rule_Result
	 */
	private function check( array $tags ): Rule_Result {
		return ( new Tags_Rule() )->check(
			Product_Context::from_array( array( 'tag_ids' => $tags ) ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No tags reports an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_tags_fails() {
		$result = $this->check( array() );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The product has no tags — add one in the Product tags box.', $result->get_message() );
		$this->assertSame( array( 'tag_count' => 0 ), $result->get_data() );
	}

	/**
	 * One tag is enough.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_one_tag_passes() {
		$result = $this->check( array( 31 ) );

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( array( 'tag_count' => 1 ), $result->get_data() );
	}

	/**
	 * It ships at warning severity, so the failure surfaces as a warning until a
	 * merchant who cares about tags promotes it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_it_ships_as_a_warning() {
		$rule = new Tags_Rule();

		$this->assertSame( Severity::WARNING, $rule->get_default_severity() );
		$this->assertSame( Status::WARNING, $this->check( array() )->with_severity( Severity::WARNING )->get_status() );
	}
}
