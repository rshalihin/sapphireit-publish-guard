<?php
/**
 * Tests for the products-list readiness filter.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Stubs\No_Scheduler_Rebuild;
use WP_Query;

/**
 * The merchant can find not-ready products across the catalog, and is told when the answer
 * is still being rebuilt (D13, Review Focus 1).
 *
 * @since 1.2.0
 */
final class Readiness_Filter_Test extends Publish_Guard_Test_Case {

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
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
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
		unset( $_GET[ Readiness_Filter::QUERY_VAR ], $GLOBALS['current_screen'] );

		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores what set_up() saved.

		parent::tear_down();
	}

	/**
	 * Choose a filter, as the form would.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value The submitted value.
	 * @return void
	 */
	private function choose( string $value ): void {
		$_GET[ Readiness_Filter::QUERY_VAR ] = $value;
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
	 * Index a product under the current signature.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Product id.
	 * @return int The same id.
	 */
	private function indexed( int $id ): int {
		Plugin::instance()->readiness_indexer()->index( $id, Readiness_Store::signature( Plugin::instance()->settings() ) );

		return $id;
	}

	/**
	 * The ids a query returns.
	 *
	 * @since 1.2.0
	 *
	 * @param bool  $main  Whether it is the main query.
	 * @param array $extra Query arguments to add.
	 * @return int[]
	 */
	private function run_query( bool $main = true, array $extra = array() ): array {
		$query = new WP_Query();

		if ( $main ) {
			$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The query under test is the main one.
		}

		$ids = $query->query(
			array_merge(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				),
				$extra
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
	 * Store the index option.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Fields to set.
	 * @return void
	 */
	private function set_index( array $values ): void {
		update_option(
			Readiness_Rebuild::OPTION,
			array_merge(
				array(
					'version'    => SIT_WCPG_VERSION,
					'signature'  => Readiness_Store::signature( Plugin::instance()->settings() ),
					'state'      => 'complete',
					'total'      => 40,
					'done'       => 40,
					'started_at' => time(),
				),
				$values
			),
			false
		);
	}

	/**
	 * "Not ready" finds the failing products and nothing else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_fail_filter_returns_only_failing_products(): void {
		$this->indexed( $this->spotless_product() );
		$this->indexed( $this->passing_product() );

		$failing = $this->indexed( $this->failing_product() );

		$this->filter();
		$this->choose( 'fail' );

		$this->assertSame( array( $failing ), $this->run_query() );
	}

	/**
	 * "Warnings" finds a product that is publishable but imperfect.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_warning_filter_returns_only_warning_products(): void {
		$this->indexed( $this->spotless_product() );
		$this->indexed( $this->failing_product() );

		$warning = $this->indexed( $this->passing_product() );

		$this->filter();
		$this->choose( 'warning' );

		$this->assertSame( array( $warning ), $this->run_query() );
	}

	/**
	 * "Ready" covers both a pass and a product no check applies to.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_ready_filter_includes_skipped(): void {
		$pass    = $this->indexed( $this->spotless_product() );
		$skipped = $this->failing_product();

		update_post_meta( $skipped, Readiness_Store::META_STATUS, 'skipped' );
		$this->indexed( $this->failing_product() );

		$this->filter();
		$this->choose( 'ready' );

		$expected = array( $pass, $skipped );
		sort( $expected );

		$this->assertSame( $expected, $this->run_query() );
	}

	/**
	 * "Not checked yet" finds products without a flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unchecked_filter_returns_products_without_meta(): void {
		$this->indexed( $this->failing_product() );

		$unchecked = $this->passing_product();

		$this->filter();
		$this->choose( 'unchecked' );

		$this->assertSame( array( $unchecked ), $this->run_query() );
	}

	/**
	 * Only whitelisted values do anything.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_unknown_values
	 *
	 * @param string $value The submitted value.
	 * @return void
	 */
	public function test_unknown_value_is_ignored( string $value ): void {
		$first  = $this->indexed( $this->failing_product() );
		$second = $this->passing_product();

		$this->filter();
		$this->choose( $value );

		$this->assertSame( '', Readiness_Filter::current_filter() );
		$this->assertSame( array( $first, $second ), $this->run_query() );
	}

	/**
	 * Values outside the whitelist.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_unknown_values(): array {
		return array(
			'unknown'   => array( 'bogus' ),
			'injection' => array( "fail' OR 1=1" ),
			'empty'     => array( '' ),
		);
	}

	/**
	 * A meta query already on the list is kept.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_existing_meta_query_is_preserved(): void {
		$kept = $this->indexed( $this->failing_product() );

		$this->indexed( $this->failing_product() );
		update_post_meta( $kept, '_sit_wcpg_test_marker', 'yes' );

		$this->filter();
		$this->choose( 'fail' );

		$found = $this->run_query(
			true,
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test fixture.
				'meta_query' => array(
					array(
						'key'   => '_sit_wcpg_test_marker',
						'value' => 'yes',
					),
				),
			)
		);

		$this->assertSame( array( $kept ), $found );
	}

	/**
	 * Only the list's own query is filtered.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_secondary_queries_are_untouched(): void {
		$first  = $this->indexed( $this->failing_product() );
		$second = $this->indexed( $this->spotless_product() );

		$this->filter();
		$this->choose( 'fail' );

		$this->assertSame( array( $first, $second ), $this->run_query( false ) );
	}

	/**
	 * Other post types' lists are untouched.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_other_list_screens_are_untouched(): void {
		$first  = $this->indexed( $this->failing_product() );
		$second = $this->indexed( $this->spotless_product() );

		set_current_screen( 'edit-post' );
		$this->filter();
		$this->choose( 'fail' );

		$this->assertSame( array( $first, $second ), $this->run_query() );
	}

	/**
	 * Someone who cannot edit products gets no filtering.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_user_without_edit_products_is_not_filtered(): void {
		$first  = $this->indexed( $this->failing_product() );
		$second = $this->indexed( $this->spotless_product() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->filter();
		$this->choose( 'fail' );

		$this->assertSame( array( $first, $second ), $this->run_query() );
	}

	/**
	 * With the checklist off, no flags are being kept, so no filtering either.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disabled_checklist_is_not_filtered(): void {
		$first  = $this->indexed( $this->failing_product() );
		$second = $this->indexed( $this->spotless_product() );

		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$filter = $this->filter();
		$this->choose( 'fail' );

		$this->assertSame( array( $first, $second ), $this->run_query() );
		$this->assertSame(
			'',
			$this->output_of(
				static function () use ( $filter ) {
					$filter->render( 'product', 'top' );
				}
			)
		);
	}

	/**
	 * The select carries every option, the choice, and a label.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_select_renders_with_selected_option_and_escaped_values(): void {
		$filter = $this->filter();

		$this->choose( 'warning' );

		$html = $this->output_of(
			static function () use ( $filter ) {
				$filter->render( 'product', 'top' );
			}
		);

		$this->assertStringContainsString( '<label class="screen-reader-text" for="sit-wcpg-readiness-filter">Filter by readiness</label>', $html );
		$this->assertStringContainsString( '<select id="sit-wcpg-readiness-filter" name="sit_wcpg_readiness"', $html );
		$this->assertStringContainsString( '<option value="">All readiness states</option>', $html );
		$this->assertStringContainsString( '<option value="ready">Ready</option>', $html );
		$this->assertStringContainsString( "<option value=\"warning\" selected='selected'>Warnings</option>", $html );
		$this->assertStringContainsString( '<option value="fail">Not ready</option>', $html );
		$this->assertStringContainsString( '<option value="unchecked">Not checked yet</option>', $html );
	}

	/**
	 * A hostile value never reaches the markup.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_select_does_not_echo_a_hostile_value(): void {
		$filter = $this->filter();

		$this->choose( '"><script>alert(1)</script>' );

		$html = $this->output_of(
			static function () use ( $filter ) {
				$filter->render( 'product', 'top' );
			}
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( "<option value=\"\" selected='selected'>All readiness states</option>", $html );
	}

	/**
	 * Nothing appears on other post types, or below the table.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_select_not_rendered_on_other_post_types(): void {
		$filter = $this->filter();

		$this->assertSame(
			'',
			$this->output_of(
				static function () use ( $filter ) {
					$filter->render( 'post', 'top' );
					$filter->render( 'product', 'bottom' );
				}
			)
		);
	}

	/**
	 * Someone who cannot edit products is not offered the filter.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_select_not_rendered_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$filter = $this->filter();

		$this->assertSame(
			'',
			$this->output_of(
				static function () use ( $filter ) {
					$filter->render( 'product', 'top' );
				}
			)
		);
	}

	/**
	 * Review Focus 1: stale flags are never presented as current.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_stale_index_shows_rechecking_banner_when_filter_active(): void {
		$filter = $this->filter();

		$this->set_index(
			array(
				'state' => 'building',
				'done'  => 10,
				'total' => 40,
			)
		);
		$this->choose( 'fail' );

		$html = $this->output_of( array( $filter, 'render_notice' ) );

		$this->assertStringContainsString( 'notice notice-info', $html );
		$this->assertStringContainsString(
			'Rechecking products against your current settings: 10 of 40 done. This filtered list will be complete when it finishes.',
			$html
		);
	}

	/**
	 * No filter, no banner.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_banner_without_an_active_filter(): void {
		$filter = $this->filter();

		$this->set_index( array( 'state' => 'building' ) );

		$this->assertSame( '', $this->output_of( array( $filter, 'render_notice' ) ) );
	}

	/**
	 * Current flags need no warning.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_banner_when_index_current(): void {
		$filter = $this->filter();

		$this->set_index( array() );
		$this->choose( 'fail' );

		$this->assertSame( '', $this->output_of( array( $filter, 'render_notice' ) ) );
	}

	/**
	 * Without Action Scheduler the banner points at the CLI.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_scheduler_banner(): void {
		$rebuild = new No_Scheduler_Rebuild( Plugin::instance()->settings(), Plugin::instance()->readiness_indexer() );
		$filter  = new Readiness_Filter( Plugin::instance()->settings(), $rebuild );

		$this->set_index( array( 'signature' => 'stale' ) );
		$this->choose( 'fail' );

		$html = $this->output_of( array( $filter, 'render_notice' ) );

		$this->assertStringContainsString( 'notice notice-info', $html );
		$this->assertStringContainsString(
			esc_html( 'This filtered list may be out of date: background rechecks need WooCommerce\'s Action Scheduler, which isn\'t available. Run "wp sit-wcpg reindex" to update it.' ),
			$html
		);
	}

	/**
	 * Opening the list starts a rebuild when the flags are stale.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_load_triggers_ensure_current(): void {
		$this->set_index( array( 'signature' => 'stale' ) );

		$this->filter()->on_load();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Readiness_Rebuild::HOOK,
				'group'  => Readiness_Rebuild::GROUP,
				'status' => 'pending',
			),
			'ids'
		);

		$this->assertCount( 1, $actions );
	}

	/**
	 * Opening another post type's list leaves the flags alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_load_on_another_list_does_nothing(): void {
		$this->set_index( array( 'signature' => 'stale' ) );

		set_current_screen( 'edit-post' );
		$this->filter()->on_load();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Readiness_Rebuild::HOOK,
				'group'  => Readiness_Rebuild::GROUP,
				'status' => 'pending',
			),
			'ids'
		);

		$this->assertSame( array(), $actions );
	}
}
