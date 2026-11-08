<?php
/**
 * Tests for the readiness store's pure parts.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Support;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Readiness_Store;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The status mapping, the filters' meta queries and the signature (coding-plan.md D7, D8).
 *
 * @since 1.2.0
 */
final class Readiness_Store_Status_Test extends TestCase {

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
	 * A settings double with a known hash.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hash What `get_hash()` returns.
	 * @return Settings
	 */
	private function settings_with_hash( string $hash ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get_hash' )->willReturn( $hash );

		return $settings;
	}

	/**
	 * A required failure outranks everything else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_required_failure_is_fail(): void {
		$result = $this->result(
			Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED ),
			Rule_Result::warn( 'sku', 'SKU', 'inventory', 'Warn.' )->with_severity( Severity::WARNING ),
			Rule_Result::fail( 'price', 'Price', 'pricing', 'Fail.' )->with_severity( Severity::REQUIRED )
		);

		$this->assertSame( Status::FAIL, Readiness_Store::status_for( $result ) );
	}

	/**
	 * A failure the merchant configured as a warning never reads as a failure.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_warning_severity_failure_is_warning(): void {
		$result = $this->result(
			Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED ),
			Rule_Result::fail( 'tags', 'Tags', 'organization', 'Fail.' )->with_severity( Severity::WARNING )
		);

		$this->assertSame( Status::WARNING, Readiness_Store::status_for( $result ) );
	}

	/**
	 * A warn outcome is a warning.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_warn_result_is_warning(): void {
		$result = $this->result( Rule_Result::warn( 'sku', 'SKU', 'inventory', 'Warn.' )->with_severity( Severity::REQUIRED ) );

		$this->assertSame( Status::WARNING, Readiness_Store::status_for( $result ) );
	}

	/**
	 * Nothing evaluated reads as skipped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_all_skipped_is_skipped(): void {
		$result = $this->result( Rule_Result::skip( 'price', 'Price', 'pricing', 'Skip.' )->with_severity( Severity::REQUIRED ) );

		$this->assertSame( Status::SKIPPED, Readiness_Store::status_for( $result ) );
	}

	/**
	 * Every rule passing reads as pass.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_all_pass_is_pass(): void {
		$result = $this->result(
			Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED ),
			Rule_Result::skip( 'price', 'Price', 'pricing', 'Skip.' )->with_severity( Severity::REQUIRED )
		);

		$this->assertSame( Status::PASS, Readiness_Store::status_for( $result ) );
	}

	/**
	 * Each filter maps to its meta query, and anything else to none.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_meta_query_per_filter(): void {
		$this->assertSame(
			array(
				'key'     => '_sit_wcpg_readiness',
				'value'   => array( 'pass', 'skipped' ),
				'compare' => 'IN',
			),
			Readiness_Store::meta_query( Readiness_Store::FILTER_READY )
		);
		$this->assertSame(
			array(
				'key'     => '_sit_wcpg_readiness',
				'value'   => 'warning',
				'compare' => '=',
			),
			Readiness_Store::meta_query( Readiness_Store::FILTER_WARNING )
		);
		$this->assertSame(
			array(
				'key'     => '_sit_wcpg_readiness',
				'value'   => 'fail',
				'compare' => '=',
			),
			Readiness_Store::meta_query( Readiness_Store::FILTER_FAIL )
		);
		$this->assertSame(
			array(
				'key'     => '_sit_wcpg_readiness',
				'compare' => 'NOT EXISTS',
			),
			Readiness_Store::meta_query( Readiness_Store::FILTER_UNCHECKED )
		);
		$this->assertSame( array(), Readiness_Store::meta_query( 'bogus' ) );
	}

	/**
	 * The filters come in display order.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filters_order(): void {
		$this->assertSame( array( 'ready', 'warning', 'fail', 'unchecked' ), Readiness_Store::filters() );
	}

	/**
	 * The three keys are the ones uninstall deletes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_meta_keys(): void {
		$this->assertSame(
			array( '_sit_wcpg_readiness', '_sit_wcpg_readiness_sig', '_sit_wcpg_readiness_data' ),
			Readiness_Store::meta_keys()
		);
	}

	/**
	 * A settings change makes a different signature, of the documented form.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_signature_changes_with_settings_hash_and_version(): void {
		$first  = Readiness_Store::signature( $this->settings_with_hash( 'aaa' ) );
		$second = Readiness_Store::signature( $this->settings_with_hash( 'bbb' ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $first );
		$this->assertNotSame( $first, $second );
		$this->assertSame( md5( 'aaa|' . Checklist_Service::CACHE_VERSION . '|' . SIT_WCPG_VERSION ), $first );
	}
}
