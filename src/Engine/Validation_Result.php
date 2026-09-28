<?php
/**
 * The aggregate outcome of a full validation run.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable aggregate over the rule results for one product.
 *
 * Every downstream consumer — the editor panel, the REST response, the products list
 * column and the publish guard — reads readiness from this object, so the counting and
 * readiness rules live here exactly once.
 *
 * @since 1.0.0
 */
final class Validation_Result {

	/**
	 * Product this result describes. Zero for an unsaved product.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $product_id;

	/**
	 * WooCommerce product type.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $product_type;

	/**
	 * Rule results in display order.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $results;

	/**
	 * Result counts: evaluated, passed, warnings, failed, skipped.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $counts;

	/**
	 * Identifiers of the required rules that failed.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $required_failure_ids;

	/**
	 * Unix timestamp of the run.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $generated_at;

	/**
	 * Hash of the settings this run was made under.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $settings_hash;

	/**
	 * Build the aggregate. Use `from_results()` instead.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $product_id    Product identifier.
	 * @param string $product_type  WooCommerce product type.
	 * @param array  $results       Rule_Result objects in display order.
	 * @param string $settings_hash Hash of the settings used for the run.
	 * @param int    $generated_at  Unix timestamp of the run.
	 */
	private function __construct( int $product_id, string $product_type, array $results, string $settings_hash, int $generated_at ) {
		$this->product_id    = $product_id;
		$this->product_type  = $product_type;
		$this->settings_hash = $settings_hash;
		$this->generated_at  = $generated_at;

		$this->results              = array_values(
			array_filter(
				$results,
				static function ( $result ) {
					return $result instanceof Rule_Result;
				}
			)
		);
		$this->counts               = $this->count_results();
		$this->required_failure_ids = $this->collect_required_failures();
	}

	/**
	 * Aggregate a set of rule results for a product context.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_Context $context       The context the rules were run against.
	 * @param array           $results       Rule_Result objects in display order.
	 * @param string          $settings_hash Hash of the settings used for the run.
	 * @return Validation_Result
	 */
	public static function from_results( Product_Context $context, array $results, string $settings_hash = '' ): Validation_Result {
		return new self(
			$context->get_product_id(),
			$context->get_product_type(),
			$results,
			$settings_hash,
			time()
		);
	}

	/**
	 * Count the results by status.
	 *
	 * Skipped rules are deliberately excluded from `evaluated` so a product is not shown
	 * as permanently incomplete because a rule does not apply to its product type.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function count_results(): array {
		$counts = array(
			'evaluated' => 0,
			'passed'    => 0,
			'warnings'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		foreach ( $this->results as $result ) {
			switch ( $result->get_status() ) {
				case Status::PASS:
					++$counts['passed'];
					break;
				case Status::WARNING:
					++$counts['warnings'];
					break;
				case Status::FAIL:
					++$counts['failed'];
					break;
				default:
					++$counts['skipped'];
					break;
			}
		}

		$counts['evaluated'] = $counts['passed'] + $counts['warnings'] + $counts['failed'];

		return $counts;
	}

	/**
	 * Collect the identifiers of required rules that failed.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function collect_required_failures(): array {
		$ids = array();

		foreach ( $this->results as $result ) {
			if ( Status::FAIL === $result->get_status() && Severity::REQUIRED === $result->get_severity() ) {
				$ids[] = $result->get_rule_id();
			}
		}

		return $ids;
	}

	/**
	 * Product identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return $this->product_id;
	}

	/**
	 * WooCommerce product type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_product_type(): string {
		return $this->product_type;
	}

	/**
	 * Rule results in display order.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_results(): array {
		return $this->results;
	}

	/**
	 * Result counts.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_counts(): array {
		return $this->counts;
	}

	/**
	 * Whether the product is ready: no result has the `fail` status.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return 0 === $this->counts['failed'];
	}

	/**
	 * Identifiers of the required rules that failed.
	 *
	 * Publishing is blocked only when this is non-empty and enforcement is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_required_failures(): array {
		return $this->required_failure_ids;
	}

	/**
	 * Unix timestamp of the run.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_generated_at(): int {
		return $this->generated_at;
	}

	/**
	 * Hash of the settings this run was made under.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_settings_hash(): string {
		return $this->settings_hash;
	}

	/**
	 * Translated readiness summary, for example "6 of 9 checks passed".
	 *
	 * Translated server-side because it is data travelling to the browser alongside the
	 * rule labels and messages, not UI chrome.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_summary_label(): string {
		return sprintf(
			/* translators: 1: number of checks that passed, 2: number of checks evaluated. */
			_n(
				'%1$d of %2$d check passed',
				'%1$d of %2$d checks passed',
				$this->counts['evaluated'],
				'sapphireit-publish-guard'
			),
			$this->counts['passed'],
			$this->counts['evaluated']
		);
	}

	/**
	 * JSON-safe representation, in the shape defined in coding-plan.md section 5.4.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		$results = array();

		foreach ( $this->results as $result ) {
			$results[] = $result->to_array();
		}

		return array(
			'product_id'           => $this->product_id,
			'product_type'         => $this->product_type,
			'results'              => $results,
			'counts'               => $this->counts,
			'is_ready'             => $this->is_ready(),
			'required_failure_ids' => $this->required_failure_ids,
			'summary_label'        => $this->get_summary_label(),
			'generated_at'         => $this->generated_at,
			'settings_hash'        => $this->settings_hash,
		);
	}
}
