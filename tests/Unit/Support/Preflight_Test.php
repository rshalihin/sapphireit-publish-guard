<?php
/**
 * Tests for the pre-flight decision.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Support;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Support\Preflight;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which of six statements a product gets, and the exact words of each (D15, §3.5, §4.2).
 *
 * @since 1.2.0
 */
final class Preflight_Test extends TestCase {

	/**
	 * Aggregate rule results for a saved simple product.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Result ...$results Rule results.
	 * @return Validation_Result
	 */
	private function result( Rule_Result ...$results ): Validation_Result {
		return Validation_Result::from_results(
			Product_Context::from_array(
				array(
					'product_id'   => 12,
					'product_type' => 'simple',
				)
			),
			$results,
			'hash'
		);
	}

	/**
	 * A passing row.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Result
	 */
	private function pass(): Rule_Result {
		return Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED );
	}

	/**
	 * A required failure.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Rule id.
	 * @return Rule_Result
	 */
	private function required_failure( string $id = 'price' ): Rule_Result {
		return Rule_Result::fail( $id, 'Rule', 'pricing', 'Fail.' )->with_severity( Severity::REQUIRED );
	}

	/**
	 * Nothing wrong.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_ready(): void {
		$this->assertSame(
			array(
				'kind'     => 'ready',
				'required' => 0,
				'warnings' => 0,
			),
			Preflight::for_result( $this->result( $this->pass() ), true, false, 'draft' )
		);
	}

	/**
	 * A failure at warning severity and a warn outcome are both warnings.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_warnings_counts_warning_severity_failures_and_warns(): void {
		$result = $this->result(
			Rule_Result::fail( 'tags', 'Tags', 'organization', 'Fail.' )->with_severity( Severity::WARNING ),
			Rule_Result::warn( 'sku', 'SKU', 'inventory', 'Warn.' )->with_severity( Severity::REQUIRED )
		);

		$this->assertSame(
			array(
				'kind'     => 'warnings',
				'required' => 0,
				'warnings' => 2,
			),
			Preflight::for_result( $result, true, false, 'draft' )
		);
	}

	/**
	 * Required failures, enforcement on and no way round it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_blocked(): void {
		$result = $this->result( $this->required_failure( 'price' ), $this->required_failure( 'category' ) );

		$this->assertSame(
			array(
				'kind'     => 'blocked',
				'required' => 2,
				'warnings' => 0,
			),
			Preflight::for_result( $result, true, false, 'draft' )
		);
	}

	/**
	 * A user who may override is told so.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_override(): void {
		$this->assertSame( 'override', Preflight::for_result( $this->result( $this->required_failure() ), true, true, 'draft' )['kind'] );
	}

	/**
	 * With blocking off there is nothing to override.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_advisory_wins_over_override(): void {
		$this->assertSame( 'advisory', Preflight::for_result( $this->result( $this->required_failure() ), false, true, 'draft' )['kind'] );
	}

	/**
	 * A live product is never told it is about to be blocked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_live_wins_over_everything(): void {
		$result = $this->result( $this->required_failure( 'price' ), $this->required_failure( 'category' ) );

		$this->assertSame(
			array(
				'kind'     => 'live',
				'required' => 2,
				'warnings' => 0,
			),
			Preflight::for_result( $result, true, true, 'publish' )
		);
	}

	/**
	 * Scheduled and pending products have not gone live yet.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_not_live_statuses
	 *
	 * @param string $status Post status.
	 * @return void
	 */
	public function test_future_and_pending_are_not_live( string $status ): void {
		$this->assertSame( 'blocked', Preflight::for_result( $this->result( $this->required_failure() ), true, false, $status )['kind'] );
	}

	/**
	 * Statuses that are not live.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_not_live_statuses(): array {
		return array(
			'future'  => array( 'future' ),
			'pending' => array( 'pending' ),
		);
	}

	/**
	 * The exact words of every statement.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_messages
	 *
	 * @param string $kind     Preflight kind.
	 * @param int    $required Required failures.
	 * @param int    $warnings Warnings.
	 * @param string $expected The statement.
	 * @return void
	 */
	public function test_message_copy_per_kind( string $kind, int $required, int $warnings, string $expected ): void {
		$this->assertSame(
			$expected,
			Preflight::message(
				array(
					'kind'     => $kind,
					'required' => $required,
					'warnings' => $warnings,
				)
			)
		);
	}

	/**
	 * Every row of §4.2.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string, int, int, string}>
	 */
	public function data_messages(): array {
		return array(
			'ready'             => array( 'ready', 0, 0, 'This product can be published now.' ),
			'warnings, one'     => array( 'warnings', 0, 1, "This product can be published now. 1 warning is advisory and won't stop it." ),
			'warnings, many'    => array( 'warnings', 0, 3, "This product can be published now. 3 warnings are advisory and won't stop it." ),
			'blocked, one'      => array( 'blocked', 1, 0, "This product can't be published yet — 1 required check fails. Publishing now saves it as a draft." ),
			'blocked, many'     => array( 'blocked', 2, 0, "This product can't be published yet — 2 required checks fail. Publishing now saves it as a draft." ),
			'override, one'     => array( 'override', 1, 0, '1 required check fails. You can publish anyway, and the override will be recorded.' ),
			'override, many'    => array( 'override', 4, 0, '4 required checks fail. You can publish anyway, and the override will be recorded.' ),
			'advisory, one'     => array( 'advisory', 1, 0, "1 required check fails, but this store doesn't block publishing, so it can still go live." ),
			'advisory, many'    => array( 'advisory', 2, 0, "2 required checks fail, but this store doesn't block publishing, so it can still go live." ),
			'live, passing'     => array( 'live', 0, 0, 'This product is live and passes every required check.' ),
			'live, one failure' => array( 'live', 1, 0, 'This product is live, but 1 required check fails. Publish Guard never takes a live product offline.' ),
			'live, failures'    => array( 'live', 3, 0, 'This product is live, but 3 required checks fail. Publish Guard never takes a live product offline.' ),
		);
	}

	/**
	 * An unknown kind has nothing to say.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unknown_kind_is_empty(): void {
		$this->assertSame( '', Preflight::message( array( 'kind' => 'bogus' ) ) );
		$this->assertSame( '', Preflight::message( array() ) );
	}
}
