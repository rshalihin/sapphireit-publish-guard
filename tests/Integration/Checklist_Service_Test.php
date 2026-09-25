<?php
/**
 * Tests for the checklist facade against real products.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * Covers the result for a real product, both memoization layers, and the summary shape.
 *
 * @since 1.0.0
 */
final class Checklist_Service_Test extends WP_UnitTestCase {

	/**
	 * Start every test from an unsaved option and an empty memo.
	 *
	 * The memo is static and this process runs many tests, so leaving it populated would
	 * make one test's result answer another test's question.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		Checklist_Service::flush_memo();
		wp_cache_flush();
	}

	/**
	 * Leave no memo behind for the next test class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * A service running exactly the rules the test dictates.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry $registry Rules to run.
	 * @return Checklist_Service
	 */
	private function service( Rule_Registry $registry ): Checklist_Service {
		$settings = new Settings( $registry );

		return new Checklist_Service( new Validator( $registry, $settings ), $settings );
	}

	/**
	 * A saved simple product with everything the shipped rules ask for except an image.
	 *
	 * @since 1.0.0
	 *
	 * @return WC_Product_Simple
	 */
	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'A perfectly ordinary product' );
		$product->set_description( str_repeat( 'Sentence about the product. ', 20 ) );
		$product->set_short_description( str_repeat( 'Short blurb. ', 10 ) );
		$product->set_regular_price( '19.99' );
		$product->set_sku( 'SIT-WCPG-TEST-1' );
		$product->set_stock_status( 'instock' );
		$product->save();

		return $product;
	}

	/**
	 * A real product produces a result whose rows match its data.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_real_product_produces_the_expected_result(): void {
		/*
		 * The test library deletes every term after each test class, WooCommerce's default
		 * category included, so the store default is recreated for this test.
		 */
		$default_category = self::factory()->term->create( array( 'taxonomy' => 'product_cat' ) );
		update_option( 'default_product_cat', $default_category );

		$product = $this->product();
		$result  = Plugin::instance()->checklist()->validate_post( $product->get_id() );

		$this->assertInstanceOf( Validation_Result::class, $result );
		$this->assertSame( $product->get_id(), $result->get_product_id() );
		$this->assertSame( 'simple', $result->get_product_type() );

		$statuses = array();

		foreach ( $result->get_results() as $row ) {
			$statuses[ $row->get_rule_id() ] = $row->get_status();
		}

		$this->assertSame( Status::PASS, $statuses['title'] );
		$this->assertSame( Status::PASS, $statuses['description'] );
		$this->assertSame( Status::PASS, $statuses['price'] );
		$this->assertSame( Status::PASS, $statuses['sku'] );

		// No featured image: a required rule, so a failure.
		$this->assertSame( Status::FAIL, $statuses['featured_image'] );

		/*
		 * No category was set, but WooCommerce's data store assigns the default one on save
		 * (`WC_Product_Data_Store_CPT::update_terms()`), so the rule sees the default term
		 * alone: a warning, never a block.
		 */
		$this->assertSame(
			array( $default_category ),
			wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) )
		);
		$this->assertSame( Status::WARNING, $statuses['category'] );

		$this->assertFalse( $result->is_ready() );
		$this->assertSame( array( 'featured_image' ), $result->get_required_failures() );
	}

	/**
	 * The counts add up the way section 5.4 says they do.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_counts_exclude_skipped_rules_from_the_denominator(): void {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'alpha', 'pass', 10 ) );
		$registry->register( new Fake_Rule( 'beta', 'fail', 20 ) );
		$registry->register( new Fake_Rule( 'gamma', 'skip', 30 ) );

		$result = $this->service( $registry )->validate_post( $this->product()->get_id() );
		$counts = $result->get_counts();

		$this->assertSame( 2, $counts['evaluated'] );
		$this->assertSame( 1, $counts['passed'] );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 1, $counts['skipped'] );
	}

	/**
	 * Manual rows 3 and 4: on a real variable product, with or without variations, the
	 * price rule is not applicable and leaves the counts, while the other rules still run.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider data_variation_counts
	 *
	 * @param int $variations Priced variations to create.
	 * @return void
	 */
	public function test_a_variable_product_skips_only_the_price_rule( int $variations ): void {
		$product = new WC_Product_Variable();
		$product->set_name( 'A variable product' );
		$product->set_description( str_repeat( 'Sentence about the product. ', 20 ) );
		$product->save();

		for ( $i = 0; $i < $variations; $i++ ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_regular_price( (string) ( 10 + $i ) );
			$variation->save();
		}

		WC_Product_Variable::sync( $product->get_id() );

		$result   = Plugin::instance()->checklist()->validate_post( $product->get_id() );
		$statuses = array();

		foreach ( $result->get_results() as $row ) {
			$statuses[ $row->get_rule_id() ] = $row->get_status();
		}

		$this->assertSame( 'variable', $result->get_product_type() );
		$this->assertSame( Status::SKIPPED, $statuses['price'] );
		$this->assertSame( Status::PASS, $statuses['title'] );
		$this->assertSame( Status::PASS, $statuses['description'] );
		$this->assertNotContains( 'price', $result->get_required_failures() );

		$skipped = count( array_keys( $statuses, Status::SKIPPED, true ) );
		$counts  = $result->get_counts();

		$this->assertSame( $skipped, $counts['skipped'] );
		$this->assertSame( count( $statuses ) - $skipped, $counts['evaluated'] );
	}

	/**
	 * Variable products with and without variations.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{int}>
	 */
	public function data_variation_counts(): array {
		return array(
			'priced variations' => array( 2 ),
			'no variations'     => array( 0 ),
		);
	}

	/**
	 * A second call in the same request reuses the first result instead of re-running.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_second_call_in_the_same_request_does_not_rerun_the_engine(): void {
		$rule     = new Fake_Rule( 'alpha', 'pass', 10 );
		$registry = new Rule_Registry();
		$registry->register( $rule );

		$service = $this->service( $registry );
		$post_id = $this->product()->get_id();

		$first  = $service->validate_post( $post_id );
		$second = $service->validate_post( $post_id );

		$this->assertSame( 1, $rule->get_check_count() );
		$this->assertSame( $first, $second );
	}

	/**
	 * The memo is shared: a second service instance still does not re-run the engine.
	 *
	 * That is what makes the meta box and the asset payload cost one validation between
	 * them rather than one each.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_memo_is_shared_between_service_instances(): void {
		$rule     = new Fake_Rule( 'alpha', 'pass', 10 );
		$registry = new Rule_Registry();
		$registry->register( $rule );

		$post_id = $this->product()->get_id();

		$this->service( $registry )->validate_post( $post_id );
		$this->service( $registry )->validate_post( $post_id );

		$this->assertSame( 1, $rule->get_check_count() );
	}

	/**
	 * A validation carrying overrides is never memoized: it describes in-flight data.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_overridden_validations_are_never_memoized(): void {
		$rule     = new Fake_Rule( 'alpha', 'pass', 10 );
		$registry = new Rule_Registry();
		$registry->register( $rule );

		$service = $this->service( $registry );
		$post_id = $this->product()->get_id();

		$service->validate_post( $post_id, array( 'title' => 'Draft title' ) );
		$service->validate_post( $post_id, array( 'title' => 'Draft title' ) );

		$this->assertSame( 2, $rule->get_check_count() );
	}

	/**
	 * Overrides reach the rules, rather than the stored values.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_overrides_replace_the_stored_values(): void {
		$product = $this->product();
		$service = Plugin::instance()->checklist();

		$stored = $service->validate_post( $product->get_id() );
		$draft  = $service->validate_post( $product->get_id(), array( 'title' => '   ' ) );

		$this->assertSame( Status::PASS, $this->status_of( $stored, 'title' ) );
		$this->assertSame( Status::FAIL, $this->status_of( $draft, 'title' ) );
	}

	/**
	 * Flushing a product drops its memo, so the next call recomputes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_flush_post_forces_a_recomputation(): void {
		$rule     = new Fake_Rule( 'alpha', 'pass', 10 );
		$registry = new Rule_Registry();
		$registry->register( $rule );

		$service = $this->service( $registry );
		$post_id = $this->product()->get_id();

		$service->validate_post( $post_id );
		$service->flush_post( $post_id );
		$service->validate_post( $post_id );

		$this->assertSame( 2, $rule->get_check_count() );
	}

	/**
	 * The summary is the shape the products-list column will read.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_summary_reports_the_worst_status_present(): void {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'alpha', 'pass', 10 ) );
		$registry->register( new Fake_Rule( 'beta', 'warn', 20 ) );

		$summary = $this->service( $registry )->get_summary_for_post_id( $this->product()->get_id() );

		$this->assertSame(
			array( 'product_id', 'status', 'is_ready', 'counts', 'summary_label' ),
			array_keys( $summary )
		);
		$this->assertSame( Status::WARNING, $summary['status'] );
		$this->assertTrue( $summary['is_ready'] );
	}

	/**
	 * A failing rule outranks a warning in the summary status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_failure_outranks_a_warning_in_the_summary(): void {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'alpha', 'warn', 10 ) );
		$registry->register( new Fake_Rule( 'beta', 'fail', 20 ) );

		$summary = $this->service( $registry )->get_summary_for_post_id( $this->product()->get_id() );

		$this->assertSame( Status::FAIL, $summary['status'] );
		$this->assertFalse( $summary['is_ready'] );
	}

	/**
	 * An id that is not a product yields null rather than an empty result.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_non_product_id_yields_null(): void {
		$service = Plugin::instance()->checklist();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertNull( $service->validate_post( 0 ) );
		$this->assertNull( $service->validate_post( $page_id ) );
		$this->assertNull( $service->get_summary_for_post_id( $page_id ) );
	}

	/**
	 * The result carries the hash of the settings it was produced under.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_result_carries_the_settings_hash(): void {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'alpha', 'pass', 10 ) );

		$settings = new Settings( $registry );
		$service  = new Checklist_Service( new Validator( $registry, $settings ), $settings );

		$result = $service->validate_post( $this->product()->get_id() );

		$this->assertSame( $settings->get_hash(), $result->get_settings_hash() );
	}

	/**
	 * The status a named rule reported in a result.
	 *
	 * @since 1.0.0
	 *
	 * @param Validation_Result $result  The result to read.
	 * @param string            $rule_id Rule identifier.
	 * @return string
	 */
	private function status_of( Validation_Result $result, string $rule_id ): string {
		foreach ( $result->get_results() as $row ) {
			if ( $rule_id === $row->get_rule_id() ) {
				return $row->get_status();
			}
		}

		return '';
	}
}
