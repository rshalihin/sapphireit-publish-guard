<?php
/**
 * Tests for rules an add-on manages itself.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use ProductPublishGuard\Tests\Stubs\Managed_Fake_Rule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A managed rule runs whenever it supports the product, carries its own severity, and never
 * reaches the settings. A rule whose `supports()` throws is skipped, managed or not.
 *
 * @since 1.3.0
 */
final class Managed_Rule_Test extends TestCase {

	/**
	 * Start from a site that has never saved the settings.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		unset( $GLOBALS['sit_wcpg_test_options'] );
	}

	/**
	 * A registry over the given rules.
	 *
	 * @since 1.3.0
	 *
	 * @param array $rules Rule objects.
	 * @return Rule_Registry
	 */
	private function registry( array $rules ): Rule_Registry {
		$registry = new Rule_Registry();

		foreach ( $rules as $rule ) {
			$registry->register( $rule );
		}

		return $registry;
	}

	/**
	 * A simple product's context.
	 *
	 * @since 1.3.0
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
	 * The ids of the rules that would run.
	 *
	 * @since 1.3.0
	 *
	 * @param Rule_Registry $registry The rules.
	 * @return string[]
	 */
	private function active_ids( Rule_Registry $registry ): array {
		$ids = array();

		foreach ( $registry->get_active( new Settings( $registry ), $this->context() ) as $rule ) {
			$ids[] = $rule->get_id();
		}

		return $ids;
	}

	/**
	 * Validate over the given rules and key the results by rule id.
	 *
	 * @since 1.3.0
	 *
	 * @param array $rules Rule objects.
	 * @return array<string, Rule_Result>
	 */
	private function results( array $rules ): array {
		$registry  = $this->registry( $rules );
		$validator = new Validator( $registry, new Settings( $registry ) );
		$results   = array();

		foreach ( $validator->validate( $this->context() )->get_results() as $result ) {
			$results[ $result->get_rule_id() ] = $result;
		}

		return $results;
	}

	/**
	 * A managed rule runs although the settings have no entry that enables it.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_managed_rule_is_active_without_a_settings_entry(): void {
		$registry = $this->registry( array( new Managed_Fake_Rule( 'managed' ) ) );
		$settings = new Settings( $registry );

		$this->assertFalse( $settings->rule_is_enabled( 'managed' ) );
		$this->assertSame( array( 'managed' ), $this->active_ids( $registry ) );
	}

	/**
	 * The rule itself still decides whether it applies.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_managed_rule_inactive_when_supports_is_false(): void {
		$this->assertSame( array(), $this->active_ids( $this->registry( array( new Managed_Fake_Rule( 'managed', false ) ) ) ) );
	}

	/**
	 * The stamped severity is the rule's answer for this product, not its default.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_managed_severity_comes_from_the_rule(): void {
		$results = $this->results( array( new Managed_Fake_Rule( 'managed', true, Severity::WARNING, Severity::REQUIRED ) ) );

		$this->assertSame( Severity::WARNING, $results['managed']->get_severity() );
		$this->assertSame( Status::WARNING, $results['managed']->get_status() );
	}

	/**
	 * A severity that is not one falls back to the rule's default.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_invalid_managed_severity_falls_back_to_default(): void {
		$results = $this->results( array( new Managed_Fake_Rule( 'managed', true, 'loud', Severity::WARNING ) ) );

		$this->assertSame( Severity::WARNING, $results['managed']->get_severity() );

		$results = $this->results( array( new Managed_Fake_Rule( 'managed', true, 'loud', 'louder' ) ) );

		$this->assertSame( Severity::REQUIRED, $results['managed']->get_severity() );
	}

	/**
	 * A rule whose severity lookup throws leaves no row, and takes nothing else with it.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_throwing_severity_drops_only_that_rule(): void {
		$results = $this->results(
			array(
				new Managed_Fake_Rule( 'broken', true, Managed_Fake_Rule::THROWS ),
				new Fake_Rule( 'ordinary', 'fail' ),
			)
		);

		$this->assertSame( array( 'ordinary' ), array_keys( $results ) );
	}

	/**
	 * A throwing `supports()` skips that rule, managed or ordinary, and nothing escapes.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_throwing_supports_is_skipped(): void {
		$ordinary = new class() extends \ProductPublishGuard\Engine\Abstract_Rule {

			/**
			 * Rule identifier.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'ordinary_broken';
			}

			/**
			 * Rule label.
			 *
			 * @return string
			 */
			public function get_label(): string {
				return 'Ordinary broken';
			}

			/**
			 * Throw, as a buggy third-party rule would.
			 *
			 * @param Product_Context $context The product being validated.
			 * @return bool
			 * @throws \RuntimeException For every saved product.
			 */
			public function supports( Product_Context $context ): bool {
				if ( $context->get_product_id() > 0 ) {
					throw new \RuntimeException( 'Ordinary supports failure.' );
				}

				return false;
			}

			/**
			 * Never reached.
			 *
			 * @param Product_Context $context  The product being validated.
			 * @param Settings        $settings The merchant's configuration.
			 * @return Rule_Result
			 */
			public function check( Product_Context $context, Settings $settings ): Rule_Result {
				unset( $context, $settings );

				return $this->pass();
			}
		};

		$registry = $this->registry(
			array(
				$ordinary,
				new Managed_Fake_Rule( 'managed_broken', Managed_Fake_Rule::THROWS ),
				new Fake_Rule( 'healthy', 'pass' ),
			)
		);

		$this->assertSame( array( 'healthy' ), $this->active_ids( $registry ) );
	}

	/**
	 * The settings never learn about a managed rule.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_managed_rule_absent_from_defaults(): void {
		$registry = $this->registry( array( new Managed_Fake_Rule( 'managed' ), new Fake_Rule( 'ordinary' ) ) );
		$rules    = ( new Settings( $registry ) )->get_defaults()['rules'];

		$this->assertArrayNotHasKey( 'managed', $rules );
		$this->assertArrayHasKey( 'ordinary', $rules );
	}

	/**
	 * Registering a managed rule does not invalidate a stored signature or cache key.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_hash_is_the_same_with_and_without_a_managed_rule(): void {
		$without = new Settings( $this->registry( array( new Fake_Rule( 'ordinary' ) ) ) );
		$with    = new Settings( $this->registry( array( new Fake_Rule( 'ordinary' ), new Managed_Fake_Rule( 'managed' ) ) ) );

		$this->assertSame( $without->get_hash(), $with->get_hash() );
	}

	/**
	 * An ordinary rule still obeys the merchant's switch.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_ordinary_rule_disabled_in_settings_stays_inactive(): void {
		$registry = $this->registry(
			array(
				new Fake_Rule( 'off', 'pass', 10, true, false, Severity::REQUIRED, false ),
				new Fake_Rule( 'on', 'pass', 20 ),
			)
		);

		$this->assertSame( array( 'on' ), $this->active_ids( $registry ) );
	}
}
