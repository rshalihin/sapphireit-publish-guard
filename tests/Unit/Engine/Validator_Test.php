<?php
/**
 * Tests for the rule runner.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use ProductPublishGuard\Tests\Stubs\Path_Aware_Rule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the severity matrix, rule isolation and the skip-and-disable paths.
 *
 * @since 1.0.0
 */
final class Validator_Test extends TestCase {

	/**
	 * A settings double.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $enabled    Enabled rule identifiers, or null for "all enabled".
	 * @param string $severity   Severity returned for every rule.
	 * @param string $hash       Settings hash.
	 * @return Settings
	 */
	private function settings( ?array $enabled = null, string $severity = Severity::REQUIRED, string $hash = 'hash' ): Settings {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'rule_is_enabled' )->willReturnCallback(
			static function ( $rule_id ) use ( $enabled ) {
				return null === $enabled || in_array( $rule_id, $enabled, true );
			}
		);

		$settings->method( 'rule_severity' )->willReturn( $severity );
		$settings->method( 'get_hash' )->willReturn( $hash );

		return $settings;
	}

	/**
	 * Build a validator over the given rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $rules    Rule objects.
	 * @param Settings $settings Settings double.
	 * @return Validator
	 */
	private function validator( array $rules, Settings $settings ): Validator {
		$registry = new Rule_Registry();

		foreach ( $rules as $rule ) {
			$registry->register( $rule );
		}

		return new Validator( $registry, $settings );
	}

	/**
	 * An empty context for a simple product.
	 *
	 * @since 1.0.0
	 *
	 * @return Product_Context
	 */
	private function context(): Product_Context {
		return Product_Context::from_array(
			array(
				'product_id'   => 42,
				'product_type' => 'simple',
			)
		);
	}

	/**
	 * Map results to `rule_id => status`.
	 *
	 * @since 1.0.0
	 *
	 * @param array $results Rule results.
	 * @return array
	 */
	private function statuses( array $results ): array {
		$map = array();

		foreach ( $results as $result ) {
			$map[ $result->get_rule_id() ] = $result->get_status();
		}

		return $map;
	}

	/**
	 * Under `required` severity: pass stays pass, fail stays fail, warn stays warn,
	 * skip stays skipped.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_severity_matrix_under_required() {
		$validator = $this->validator(
			array(
				new Fake_Rule( 'passing', 'pass', 10 ),
				new Fake_Rule( 'failing', 'fail', 20 ),
				new Fake_Rule( 'warning', 'warn', 30 ),
				new Fake_Rule( 'skipping', 'skip', 40 ),
			),
			$this->settings( null, Severity::REQUIRED )
		);

		$this->assertSame(
			array(
				'passing'  => Status::PASS,
				'failing'  => Status::FAIL,
				'warning'  => Status::WARNING,
				'skipping' => Status::SKIPPED,
			),
			$this->statuses( $validator->validate( $this->context() )->get_results() )
		);
	}

	/**
	 * Under `warning` severity a failure is demoted and everything else is unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_severity_matrix_under_warning() {
		$validator = $this->validator(
			array(
				new Fake_Rule( 'passing', 'pass', 10 ),
				new Fake_Rule( 'failing', 'fail', 20 ),
				new Fake_Rule( 'warning', 'warn', 30 ),
				new Fake_Rule( 'skipping', 'skip', 40 ),
			),
			$this->settings( null, Severity::WARNING )
		);

		$result = $validator->validate( $this->context() );

		$this->assertSame(
			array(
				'passing'  => Status::PASS,
				'failing'  => Status::WARNING,
				'warning'  => Status::WARNING,
				'skipping' => Status::SKIPPED,
			),
			$this->statuses( $result->get_results() )
		);

		$this->assertTrue( $result->is_ready(), 'A demoted failure must not block publishing.' );
		$this->assertSame( array(), $result->get_required_failures() );
	}

	/**
	 * A disabled rule is not merely hidden: it is never invoked.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_disabled_rule_is_never_run() {
		$enabled  = new Fake_Rule( 'enabled', 'pass', 10 );
		$disabled = new Fake_Rule( 'disabled', 'fail', 20 );

		$validator = $this->validator( array( $enabled, $disabled ), $this->settings( array( 'enabled' ) ) );
		$result    = $validator->validate( $this->context() );

		$this->assertSame( 1, $enabled->get_check_count() );
		$this->assertSame( 0, $disabled->get_check_count() );
		$this->assertCount( 1, $result->get_results() );
	}

	/**
	 * A rule that does not support the product is never invoked.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unsupported_rule_is_never_run() {
		$supported   = new Fake_Rule( 'supported', 'pass', 10 );
		$unsupported = new Fake_Rule( 'unsupported', 'fail', 20, false );

		$validator = $this->validator( array( $supported, $unsupported ), $this->settings() );
		$result    = $validator->validate( $this->context() );

		$this->assertSame( 0, $unsupported->get_check_count() );
		$this->assertSame( array( 'supported' ), array_keys( $this->statuses( $result->get_results() ) ) );
	}

	/**
	 * One rule throwing costs its own row and nothing else.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_throwing_rule_does_not_break_the_run() {
		$validator = $this->validator(
			array(
				new Fake_Rule( 'before', 'pass', 10 ),
				new Fake_Rule( 'broken', 'pass', 20, true, true ),
				new Fake_Rule( 'after', 'fail', 30 ),
			),
			$this->settings()
		);

		$result = $validator->validate( $this->context() );

		$this->assertSame(
			array(
				'before' => Status::PASS,
				'after'  => Status::FAIL,
			),
			$this->statuses( $result->get_results() )
		);

		$this->assertSame( array( 'after' ), $result->get_required_failures() );
	}

	/**
	 * Results keep the registry's display order.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_results_are_in_display_order() {
		$validator = $this->validator(
			array(
				new Fake_Rule( 'third', 'pass', 30 ),
				new Fake_Rule( 'first', 'pass', 10 ),
				new Fake_Rule( 'second', 'pass', 20 ),
			),
			$this->settings()
		);

		$this->assertSame(
			array( 'first', 'second', 'third' ),
			array_keys( $this->statuses( $validator->validate( $this->context() )->get_results() ) )
		);
	}

	/**
	 * The aggregate carries the product identity and the settings hash of the run.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_result_carries_product_identity_and_settings_hash() {
		$validator = $this->validator( array( new Fake_Rule( 'title' ) ), $this->settings( null, Severity::REQUIRED, 'abc123' ) );
		$result    = $validator->validate( $this->context() );

		$this->assertSame( 42, $result->get_product_id() );
		$this->assertSame( 'simple', $result->get_product_type() );
		$this->assertSame( 'abc123', $result->get_settings_hash() );
	}

	/**
	 * A registry with no rules produces an empty, ready result.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_rules_produces_a_ready_result() {
		$result = $this->validator( array(), $this->settings() )->validate( $this->context() );

		$this->assertTrue( $result->is_ready() );
		$this->assertSame( array(), $result->get_results() );
	}

	/**
	 * A rule can relax or tighten itself by the route the product is saved through.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_sees_the_save_path() {
		$validator = $this->validator( array( new Path_Aware_Rule() ), $this->settings() );

		$this->assertSame( array( 'path_aware' ), $validator->validate( $this->context()->with_save_path( Save_Path::REST ) )->get_required_failures() );
		$this->assertSame( array(), $validator->validate( $this->context()->with_save_path( Save_Path::CLASSIC ) )->get_required_failures() );
	}
}
