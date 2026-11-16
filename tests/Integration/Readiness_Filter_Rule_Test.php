<?php
/**
 * Tests for the products-list filter by failing check.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WP_Query;

/**
 * `sit_wcpg_rule=<rule id>` narrows the products list to the not-ready products failing that
 * required check (U14), which the Dashboard's "Most common issues" links to.
 *
 * @since 1.2.0
 */
final class Readiness_Filter_Rule_Test extends Publish_Guard_Test_Case {

	/**
	 * The main query before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var WP_Query|null
	 */
	private ?WP_Query $main_query = null;

	/**
	 * The products list, unfiltered, with no index.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->main_query = $GLOBALS['wp_the_query'] ?? null;

		delete_option( Readiness_Rebuild::OPTION );
		set_current_screen( 'edit-product' );
	}

	/**
	 * Leave no filter, screen or main query behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET[ Readiness_Filter::QUERY_VAR ], $_GET[ Readiness_Filter::RULE_QUERY_VAR ], $GLOBALS['current_screen'] );

		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores what set_up() saved.

		parent::tear_down();
	}

	/**
	 * A product post whose stored flag fails the given required checks.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $failures Required failures; empty for a ready product.
	 * @return int Product id.
	 */
	private function flagged( array $failures ): int {
		$id      = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$results = array( Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED ) );

		foreach ( $failures as $rule_id ) {
			$results[] = Rule_Result::fail( $rule_id, $rule_id, 'content', 'Fail.' )->with_severity( Severity::REQUIRED );
		}

		$result = Validation_Result::from_results(
			Product_Context::from_array(
				array(
					'product_id'   => $id,
					'product_type' => 'simple',
				)
			),
			$results,
			'hash'
		);

		( new Readiness_Store() )->write( $id, $result, 'signature' );

		return $id;
	}

	/**
	 * A filter with its hooks registered.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Filter
	 */
	private function filter(): Readiness_Filter {
		$filter = new Readiness_Filter( Plugin::instance()->settings() );

		$filter->register();

		return $filter;
	}

	/**
	 * The ids the list's main query returns.
	 *
	 * @since 1.2.0
	 *
	 * @return int[]
	 */
	private function run_query(): array {
		$query = new WP_Query();

		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The query under test is the main one.

		$ids = $query->query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		sort( $ids );

		return array_map( 'intval', $ids );
	}

	/**
	 * What a call prints.
	 *
	 * @since 1.2.0
	 *
	 * @param callable $callback What to run.
	 * @return string
	 */
	private function output_of( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	/**
	 * With "Not ready" chosen, the rule keeps only the products failing it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_param_narrows_not_ready_list_to_that_rule(): void {
		$this->flagged( array() );
		$this->flagged( array( 'price' ) );

		$wanted = $this->flagged( array( 'featured_image', 'price' ) );

		$this->filter();
		$_GET[ Readiness_Filter::QUERY_VAR ]      = Readiness_Store::FILTER_FAIL;
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'featured_image';

		$this->assertSame( array( $wanted ), $this->run_query() );
	}

	/**
	 * The rule on its own means "not ready, failing this check".
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_param_alone_implies_not_ready(): void {
		$this->flagged( array() );
		$this->flagged( array( 'price' ) );

		$wanted = $this->flagged( array( 'featured_image' ) );

		$filter = $this->filter();

		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'featured_image';

		$this->assertSame( 'featured_image', $filter->current_rule() );
		$this->assertSame( array( $wanted ), $this->run_query() );
	}

	/**
	 * Another state chosen in the select drops the rule: a ready product fails nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_param_is_dropped_when_another_state_is_chosen(): void {
		$ready = $this->flagged( array() );

		$this->flagged( array( 'featured_image' ) );

		$filter = $this->filter();

		$_GET[ Readiness_Filter::QUERY_VAR ]      = Readiness_Store::FILTER_READY;
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'featured_image';

		$this->assertSame( '', $filter->current_rule() );
		$this->assertSame( array( $ready ), $this->run_query() );
	}

	/**
	 * A rule id nobody registered changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unknown_rule_id_is_ignored(): void {
		$first  = $this->flagged( array() );
		$second = $this->flagged( array( 'price' ) );

		$filter = $this->filter();

		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'sit_wcpg_no_such_rule';

		$this->assertSame( '', $filter->current_rule() );
		$this->assertSame( array( $first, $second ), $this->run_query() );
	}

	/**
	 * `price` must not find a product that fails only `sale_price`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_id_is_matched_exactly_not_as_substring(): void {
		$this->flagged( array( 'sale_price' ) );

		$wanted = $this->flagged( array( 'price' ) );

		$this->filter();
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'price';

		$this->assertSame( array( $wanted ), $this->run_query() );
	}

	/**
	 * Changing the state keeps the rule, through a hidden field, and shows "Not ready".
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_dropdown_keeps_rule_param_in_a_hidden_field(): void {
		$filter = $this->filter();

		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'featured_image';

		$html = $this->output_of(
			static function () use ( $filter ) {
				$filter->render( 'product', 'top' );
			}
		);

		$this->assertStringContainsString( '<input type="hidden" name="sit_wcpg_rule" value="featured_image" />', $html );
		$this->assertStringContainsString( "<option value=\"fail\" selected='selected'>Not ready</option>", $html );
	}

	/**
	 * No hidden field without an active rule.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_hidden_field_without_a_rule(): void {
		$filter = $this->filter();

		$html = $this->output_of(
			static function () use ( $filter ) {
				$filter->render( 'product', 'top' );
			}
		);

		$this->assertStringNotContainsString( 'sit_wcpg_rule', $html );
	}

	/**
	 * The active rule is named above the list, with a link that removes it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_active_rule_shows_a_removable_chip_above_the_list(): void {
		$filter = $this->filter();

		$_SERVER['REQUEST_URI']                   = '/wp-admin/edit.php?post_type=product&sit_wcpg_rule=featured_image&paged=2';
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'featured_image';

		$html = $this->output_of(
			static function () use ( $filter ) {
				$filter->render_rule_chip( 'top' );
			}
		);

		$this->assertMatchesRegularExpression( '#class="[^"]*\bsit-wcpg-rule-chip\b[^"]*"#', $html );
		$this->assertStringContainsString( 'Failing: Featured image', $html );
		$this->assertStringContainsString( '&times;', $html );
		$this->assertStringContainsString( 'aria-label="Remove the filter: Featured image"', $html );
		$this->assertMatchesRegularExpression( '#href="[^"]*post_type=product[^"]*"#', $html );
		$this->assertMatchesRegularExpression( '#href="[^"]*sit_wcpg_readiness=fail[^"]*"#', $html );
		$this->assertStringNotContainsString( 'sit_wcpg_rule=', $html );
		$this->assertStringNotContainsString( 'paged=', $html );

		$this->assertSame(
			'',
			$this->output_of(
				static function () use ( $filter ) {
					$filter->render_rule_chip( 'bottom' );
				}
			),
			'Once, above the list.'
		);
	}

	/**
	 * No chip without an active rule.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_chip_without_a_rule(): void {
		$filter = $this->filter();

		$this->assertSame(
			'',
			$this->output_of(
				static function () use ( $filter ) {
					$filter->render_rule_chip( 'top' );
				}
			)
		);
	}
}
