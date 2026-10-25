<?php
/**
 * Regression test for third-party rules written against v1.0.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Tests\Stubs\Legacy_Rule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A rule that uses only the v1.0 API still runs, unchanged, through the v1.1 engine (D1).
 *
 * @since 1.1.0
 */
final class Legacy_Rule_Compat_Test extends TestCase {

	/**
	 * The legacy rule's result keeps its id, message, data and class-level fix target.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_legacy_rule_runs_through_the_validator() {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'rule_is_enabled' )->willReturn( true );
		$settings->method( 'rule_severity' )->willReturn( Severity::REQUIRED );
		$settings->method( 'get_hash' )->willReturn( 'hash' );

		$rule     = new Legacy_Rule();
		$registry = new Rule_Registry();

		$registry->register( $rule );

		$results = ( new Validator( $registry, $settings ) )
			->validate( Product_Context::from_array( array( 'product_type' => 'variable' ) ) )
			->get_results();

		$this->assertCount( 1, $results );

		$result = $results[0];

		$this->assertSame( Legacy_Rule::ID, $result->get_rule_id() );
		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'Legacy.', $result->get_message() );
		$this->assertSame( array( 'n' => 1 ), $result->get_data() );
		$this->assertSame( $rule->get_fix_target(), $result->get_fix() );
	}
}
