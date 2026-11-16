<?php
/**
 * Tests for the readiness statistics the Dashboard shows.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Stats;
use ProductPublishGuard\Support\Readiness_Store;
use ReflectionProperty;

/**
 * The numbers come from the stored flags, are cached, and are dropped whenever they may be
 * wrong (U14, V-U4, V-U5).
 *
 * @since 1.2.0
 */
final class Readiness_Stats_Test extends Publish_Guard_Test_Case {

	/**
	 * No cached numbers and no index from an earlier test.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_transient( Readiness_Stats::TRANSIENT );
		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * Leave no cached numbers behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_transient( Readiness_Stats::TRANSIENT );

		parent::tear_down();
	}

	/**
	 * The statistics under test.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Stats
	 */
	private function stats(): Readiness_Stats {
		return new Readiness_Stats( Plugin::instance()->settings() );
	}

	/**
	 * A product post carrying a flag written through the store.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $state       'pass', 'skipped', 'warning', 'fail', or '' for no flag.
	 * @param string[] $failures    Required failures, for 'fail'.
	 * @param string   $post_status The post status.
	 * @return int Product id.
	 */
	private function flagged( string $state, array $failures = array( 'price' ), string $post_status = 'draft' ): int {
		$args = array(
			'post_type'   => 'product',
			'post_status' => $post_status,
		);

		if ( 'future' === $post_status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}

		// The guard would hold an empty product back from `publish`.
		add_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );
		$id = self::factory()->post->create( $args );
		remove_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );

		$this->assertSame( $post_status, get_post_status( $id ) );

		if ( '' === $state ) {
			return $id;
		}

		switch ( $state ) {
			case 'fail':
				$results = array();

				foreach ( $failures as $rule_id ) {
					$results[] = Rule_Result::fail( $rule_id, $rule_id, 'content', 'Fail.' )->with_severity( Severity::REQUIRED );
				}
				break;
			case 'warning':
				$results = array( Rule_Result::warn( 'tags', 'Tags', 'organization', 'Warn.' )->with_severity( Severity::WARNING ) );
				break;
			case 'skipped':
				$results = array();
				break;
			default:
				$results = array( Rule_Result::pass( 'title', 'Title', 'content' )->with_severity( Severity::REQUIRED ) );
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
	 * Switch the checklist off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function disable_checklist(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
	}

	/**
	 * Every state is counted over the statuses a rebuild covers, and nothing else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_counts_each_state_over_non_trashed_products(): void {
		$this->flagged( 'pass', array(), 'publish' );
		$this->flagged( 'skipped', array(), 'private' );
		$this->flagged( 'warning', array(), 'pending' );
		$this->flagged( 'fail', array( 'price' ), 'draft' );
		$this->flagged( 'fail', array( 'price' ), 'future' );
		$this->flagged( '', array(), 'draft' );
		$this->flagged( 'fail', array( 'price' ), 'trash' );
		$this->flagged( 'pass', array(), 'auto-draft' );

		$stats = $this->stats()->get();

		$this->assertSame( 2, $stats['ready'] );
		$this->assertSame( 1, $stats['warning'] );
		$this->assertSame( 2, $stats['fail'] );
		$this->assertSame( 1, $stats['unchecked'] );
		$this->assertSame( 6, $stats['total'] );
		$this->assertFalse( $stats['capped'] );
		$this->assertGreaterThan( 0, $stats['computed_at'] );
	}

	/**
	 * "Not checked yet" is a product with no flag at all (V-U4).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unchecked_counts_products_without_a_flag(): void {
		$this->flagged( '' );
		$this->flagged( '' );

		$stale = $this->flagged( 'fail' );

		// A stale signature still counts under its old status.
		update_post_meta( $stale, Readiness_Store::META_SIGNATURE, 'old' );

		$stats = $this->stats()->get();

		$this->assertSame( 2, $stats['unchecked'] );
		$this->assertSame( 1, $stats['fail'] );
	}

	/**
	 * "Live, would fail today" is published and not ready, nothing else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_live_fail_counts_published_not_ready_only(): void {
		$this->flagged( 'fail', array( 'price' ), 'publish' );
		$this->flagged( 'fail', array( 'price' ), 'publish' );
		$this->flagged( 'fail', array( 'price' ), 'draft' );
		$this->flagged( 'warning', array(), 'publish' );
		$this->flagged( 'pass', array(), 'publish' );

		$this->assertSame( 2, $this->stats()->get()['live_fail'] );
	}

	/**
	 * The five most frequent required failures, ties broken by the registry's order.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_top_failures_are_sorted_by_count_then_rule_order_and_limited_to_five(): void {
		$this->flagged( 'fail', array( 'price', 'title', 'category' ) );
		$this->flagged( 'fail', array( 'price', 'description', 'category' ) );
		$this->flagged( 'fail', array( 'price', 'description' ) );
		$this->flagged( 'fail', array( 'featured_image', 'sku', 'tags' ) );

		$order = array_map(
			static function ( $rule ) {
				return $rule->get_id();
			},
			Plugin::instance()->registry()->all()
		);

		$singles = array( 'title', 'featured_image', 'sku', 'tags' );
		usort(
			$singles,
			static function ( $a, $b ) use ( $order ) {
				return array_search( $a, $order, true ) <=> array_search( $b, $order, true );
			}
		);

		$pairs = array( 'description', 'category' );
		usort(
			$pairs,
			static function ( $a, $b ) use ( $order ) {
				return array_search( $a, $order, true ) <=> array_search( $b, $order, true );
			}
		);

		$expected = array( 'price' => 3 );

		foreach ( $pairs as $rule_id ) {
			$expected[ $rule_id ] = 2;
		}

		foreach ( array_slice( $singles, 0, 2 ) as $rule_id ) {
			$expected[ $rule_id ] = 1;
		}

		$this->assertSame( $expected, $this->stats()->get()['top_failures'] );
	}

	/**
	 * A rule id that is no longer registered is not reported.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unregistered_rule_ids_are_skipped(): void {
		$this->flagged( 'fail', array( 'sit_wcpg_gone_rule', 'price' ) );
		$this->flagged( 'fail', array( 'sit_wcpg_gone_rule' ) );

		$this->assertSame( array( 'price' => 1 ), $this->stats()->get()['top_failures'] );
	}

	/**
	 * The scan reads at most the limit, and says when it stopped early.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_scan_stops_at_limit_and_reports_capped(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->flagged( 'fail', array( 'price' ) );
		}

		$stats = $this->stats();
		$limit = new ReflectionProperty( Readiness_Stats::class, 'scan_limit' );
		$limit->setAccessible( true );
		$limit->setValue( $stats, 3 );

		$result = $stats->get();

		$this->assertTrue( $result['capped'] );
		$this->assertSame( 5, $result['fail'] );
		$this->assertSame( array( 'price' => 3 ), $result['top_failures'] );
	}

	/**
	 * The numbers are cached, and a settings save drops them.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_result_is_cached_and_flushed_on_settings_update(): void {
		$this->flagged( 'fail' );

		$this->assertSame( 1, $this->stats()->get()['fail'] );
		$this->assertIsArray( get_transient( Readiness_Stats::TRANSIENT ) );

		$this->flagged( 'fail' );

		$this->assertSame( 1, $this->stats()->get()['fail'], 'Served from the cache.' );

		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		// The first save adds the option, the second updates it: both must flush.
		update_option( Settings::OPTION_NAME, $stored );
		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ), 'Flushed when the option is added.' );

		$this->stats()->get();
		update_option( Settings::OPTION_NAME, array_merge( $stored, array( 'enabled' => false ) ) );
		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ), 'Flushed when the option is updated.' );

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
		delete_transient( Readiness_Stats::TRANSIENT );
		$this->stats()->get();
		$this->assertIsArray( get_transient( Readiness_Stats::TRANSIENT ) );
		$this->assertSame( 2, $this->stats()->get()['fail'] );
	}

	/**
	 * An add-on announcing changed rules drops the numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_flushed_on_rules_changed_action(): void {
		$this->stats()->get();

		do_action( 'sit_wcpg_rules_changed' );

		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ) );
	}

	/**
	 * The numbers are dropped when a rebuild turns complete, and not while it runs (V-U5).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_flushed_when_rebuild_finishes(): void {
		$index = array(
			'version'    => SIT_WCPG_VERSION,
			'signature'  => 'sig',
			'state'      => Readiness_Rebuild::STATE_BUILDING,
			'total'      => 100,
			'done'       => 0,
			'started_at' => time(),
		);

		update_option( Readiness_Rebuild::OPTION, $index, false );
		$this->stats()->get();

		update_option( Readiness_Rebuild::OPTION, array_merge( $index, array( 'done' => 50 ) ), false );
		$this->assertIsArray( get_transient( Readiness_Stats::TRANSIENT ), 'A batch in the middle keeps the cache.' );

		update_option(
			Readiness_Rebuild::OPTION,
			array_merge(
				$index,
				array(
					'done'  => 100,
					'state' => Readiness_Rebuild::STATE_COMPLETE,
				)
			),
			false
		);
		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ) );
	}

	/**
	 * A rebuild recorded complete in one write (WP-CLI, no earlier index) drops the numbers too.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_flushed_when_rebuild_is_recorded_complete_from_nothing(): void {
		$this->stats()->get();

		Plugin::instance()->readiness_rebuild()->mark_complete( 3 );

		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ) );
	}

	/**
	 * With the checklist off nothing is queried, cached or reported.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_checklist_disabled_returns_zeroes_without_querying(): void {
		global $wpdb;

		$this->flagged( 'fail' );
		$this->disable_checklist();
		delete_transient( Readiness_Stats::TRANSIENT );

		$stats  = $this->stats();
		$before = $wpdb->num_queries;
		$result = $stats->get();

		$this->assertSame( $before, $wpdb->num_queries );
		$this->assertSame( 0, $result['fail'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['top_failures'] );
		$this->assertFalse( $result['capped'] );
		$this->assertFalse( get_transient( Readiness_Stats::TRANSIENT ) );
	}

	/**
	 * Every number comes through WP_Query and the store, never raw SQL (U14).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_wpdb_queries_issued(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a source file.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/Readiness_Stats.php' );

		$this->assertStringNotContainsString( '$wpdb', $source );
		$this->assertStringNotContainsString( Readiness_Store::META_STATUS, $source, 'Meta keys come from the store.' );
		$this->assertStringNotContainsString( Readiness_Store::META_DATA, $source, 'Meta keys come from the store.' );
	}
}
