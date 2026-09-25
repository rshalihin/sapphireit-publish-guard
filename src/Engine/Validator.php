<?php
/**
 * Runs the active rules against a product context.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

use ProductPublishGuard\Settings\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The rule runner.
 *
 * Two responsibilities beyond looping: it applies the merchant's configured severity to
 * each rule outcome (the matrix in coding-plan.md section 5.5), and it isolates every
 * rule inside a try/catch so one broken rule — including a third-party one — degrades to
 * a missing row rather than a fatal admin screen.
 *
 * @since 1.0.0
 */
final class Validator {

	/**
	 * The rules available to this request.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry
	 */
	private Rule_Registry $registry;

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the validator.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry $registry The rules available to this request.
	 * @param Settings      $settings The merchant's configuration.
	 */
	public function __construct( Rule_Registry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Validate a product context.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_Context $context The product to validate.
	 * @return Validation_Result
	 */
	public function validate( Product_Context $context ): Validation_Result {
		$results = array();

		foreach ( $this->registry->get_active( $this->settings, $context ) as $rule ) {
			$result = $this->run_rule( $rule, $context );

			if ( $result instanceof Rule_Result ) {
				$results[] = $result;
			}
		}

		$validation = Validation_Result::from_results( $context, $results, $this->settings->get_hash() );

		/**
		 * Filters the completed validation result.
		 *
		 * @since 1.0.0
		 *
		 * @param Validation_Result $validation The aggregated result.
		 * @param Product_Context   $context    The context that was validated.
		 */
		$filtered = apply_filters( 'sit_wcpg_validation_result', $validation, $context );

		return $filtered instanceof Validation_Result ? $filtered : $validation;
	}

	/**
	 * Run one rule and stamp the configured severity onto its outcome.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Interface  $rule    The rule to run.
	 * @param Product_Context $context The product to validate.
	 * @return Rule_Result|null Null when the rule threw or returned nothing usable.
	 */
	private function run_rule( Rule_Interface $rule, Product_Context $context ): ?Rule_Result {
		try {
			$result = $rule->check( $context, $this->settings );
		} catch ( Throwable $error ) {
			$this->log_failure( $rule->get_id(), $error );

			return null;
		}

		if ( ! $result instanceof Rule_Result ) {
			return null;
		}

		return $result->with_severity( $this->settings->rule_severity( $rule->get_id() ) );
	}

	/**
	 * Record a rule that threw, so the bug is findable without breaking the screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $rule_id Identifier of the rule that threw.
	 * @param Throwable $error   The throwable.
	 * @return void
	 */
	private function log_failure( string $rule_id, Throwable $error ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Developer-facing diagnostics only; the merchant sees a missing row, not this.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Product Publish Guard: rule "%1$s" threw %2$s: %3$s',
				$rule_id,
				get_class( $error ),
				$error->getMessage()
			)
		);
	}
}
