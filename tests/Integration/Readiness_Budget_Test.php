<?php
/**
 * The query budgets of the readiness store (plan section 5.3).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Catalog_List_Table;
use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WP_Query;

/**
 * Every number here is binding. If one fails, fix the cause: missing priming or duplicate
 * marking. Never raise it without amending the plan.
 *
 * @since 1.2.0
 */
final class Readiness_Budget_Test extends Publish_Guard_Test_Case {

	/**
	 * Rows on one page of the budgets.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const ROWS = 20;

	/**
	 * The main query before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var WP_Query|null
	 */
	private ?WP_Query $main_query = null;

	/**
	 * The request URI before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $request_uri = '';

	/**
	 * Admin code loaded, an empty queue, and a list-table URL.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->main_query       = $GLOBALS['wp_the_query'] ?? null;
		$this->request_uri      = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=product';

		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * Leave no filter, screen, queue or main query behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET[ Readiness_Filter::QUERY_VAR ], $GLOBALS['current_screen'] );

		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores what set_up() saved.
		$_SERVER['REQUEST_URI']  = $this->request_uri;

		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
		delete_option( Readiness_Rebuild::OPTION );

		parent::tear_down();
	}

	/**
	 * The current signature.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function signature(): string {
		return Readiness_Store::signature( Plugin::instance()->settings() );
	}

	/**
	 * Record the index as current.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function index_is_current(): void {
		update_option(
			Readiness_Rebuild::OPTION,
			array(
				'version'    => SIT_WCPG_VERSION,
				'signature'  => $this->signature(),
				'state'      => Readiness_Rebuild::STATE_COMPLETE,
				'total'      => self::ROWS,
				'done'       => self::ROWS,
				'started_at' => time(),
			),
			false
		);
	}

	/**
	 * Published products with a stored failure.
	 *
	 * @since 1.2.0
	 *
	 * @param int $count How many.
	 * @return int[]
	 */
	private function flagged_products( int $count ): array {
		// A bare product fails the required checks, and the guard would hold it as a draft.
		add_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );

		$ids = self::factory()->post->create_many(
			$count,
			array(
				'post_type'   => Screen::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		remove_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );

		foreach ( $ids as $id ) {
			update_post_meta( $id, Readiness_Store::META_STATUS, 'fail' );
			update_post_meta( $id, Readiness_Store::META_SIGNATURE, $this->signature() );
			update_post_meta(
				$id,
				Readiness_Store::META_DATA,
				array(
					'required_failures' => array( 'price' ),
					'checked_at'        => time(),
				)
			);
		}

		return $ids;
	}

	/**
	 * Count `sit_wcpg_validation_result` while something runs.
	 *
	 * @since 1.2.0
	 *
	 * @param callable $callback What to run.
	 * @return int
	 */
	private function validations_during( callable $callback ): int {
		$count   = 0;
		$counter = static function ( $result ) use ( &$count ) {
			++$count;

			return $result;
		};

		add_filter( 'sit_wcpg_validation_result', $counter );
		$callback();
		remove_filter( 'sit_wcpg_validation_result', $counter );

		return $count;
	}

	/**
	 * What one view of the products list costs.
	 *
	 * @since 1.2.0
	 *
	 * @param int[]  $ids   Products on the page.
	 * @param string $value The chosen filter, or '' for none.
	 * @return int Queries.
	 */
	private function list_cost( array $ids, string $value ): int {
		global $wpdb;

		if ( '' === $value ) {
			unset( $_GET[ Readiness_Filter::QUERY_VAR ] );
		} else {
			$_GET[ Readiness_Filter::QUERY_VAR ] = $value;
		}

		array_map( 'clean_post_cache', $ids );
		wp_cache_delete( Readiness_Rebuild::OPTION, 'options' );

		$filter = new Readiness_Filter( Plugin::instance()->settings() );

		add_action( 'pre_get_posts', array( $filter, 'apply' ) );

		$before = $wpdb->num_queries;

		$filter->on_load();

		$query = new WP_Query();

		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Emulating the list screen's main query.

		$query->query(
			array(
				'post_type'      => Screen::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::ROWS,
			)
		);

		ob_start();
		$filter->render_notice();
		ob_end_clean();

		$cost = $wpdb->num_queries - $before;

		remove_action( 'pre_get_posts', array( $filter, 'apply' ) );

		$this->assertCount( self::ROWS, $query->posts );

		return $cost;
	}

	/**
	 * The active filter is a join on a query that was running anyway.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_adds_at_most_one_query(): void {
		set_current_screen( 'edit-product' );

		$ids = $this->flagged_products( self::ROWS );

		$this->index_is_current();

		$this->list_cost( $ids, '' ); // Warm what the two measured views share.

		$unfiltered = $this->list_cost( $ids, '' );
		$filtered   = $this->list_cost( $ids, Readiness_Store::FILTER_FAIL );

		$this->assertLessThanOrEqual( 1, $filtered - $unfiltered, sprintf( 'Filtered %1$d, unfiltered %2$d.', $filtered, $unfiltered ) );
	}

	/**
	 * The catalog reads what is stored and runs no rule.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_catalog_page_query_budget_and_zero_validations(): void {
		global $wpdb;

		set_current_screen( Screen::CATALOG_HOOK );

		$ids = $this->flagged_products( self::ROWS );

		$this->index_is_current();

		array_map( 'clean_post_cache', $ids );

		$table = new Catalog_List_Table( $this->signature(), array( 'price' => 'Regular price' ) );
		$cost  = 0;

		$validations = $this->validations_during(
			static function () use ( $wpdb, $table, &$cost ) {
				$before = $wpdb->num_queries;

				$table->prepare_items();

				ob_start();
				$table->display();
				ob_end_clean();

				$cost = $wpdb->num_queries - $before;
			}
		);

		$this->assertCount( self::ROWS, $table->items );
		$this->assertLessThanOrEqual( 6, $cost, sprintf( 'The list table took %d queries.', $cost ) );
		$this->assertSame( 0, $validations );
	}

	/**
	 * The editor's double save is one validation at shutdown.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_classic_save_validates_once_for_index(): void {
		set_current_screen( 'product' );

		$id = $this->passing_product();

		Readiness_Indexer::reset();
		Checklist_Service::flush_memo();

		$indexer = Plugin::instance()->readiness_indexer();

		// Draft to draft, so the guard's own validation is out of the count.
		$validations = $this->validations_during(
			function () use ( $id, $indexer ) {
				$this->publish( $id, $this->classic_form( $id ), 'draft' );
				$indexer->flush_pending();
			}
		);

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertSame( 1, $validations );
	}

	/**
	 * One full batch of real products.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rebuild_batch_budget(): void {
		global $wpdb;

		for ( $made = 0; $made < Readiness_Rebuild::BATCH; $made++ ) {
			$this->passing_product();
		}

		Readiness_Indexer::reset();
		Checklist_Service::flush_memo();
		wp_cache_flush();

		$rebuild   = Plugin::instance()->readiness_rebuild();
		$signature = $this->signature();
		$writes    = 0;
		$cost      = 0;

		// Core spends two queries on a meta write: its existence check, then the write.
		$write_queries = 0;
		$write_pattern = '/^\s*(?:INSERT INTO|UPDATE|SELECT meta_id FROM)\s+`?' . preg_quote( $wpdb->postmeta, '/' ) . '\b/i';
		$count_query   = static function ( $sql ) use ( &$write_queries, $write_pattern ) {
			if ( 1 === preg_match( $write_pattern, (string) $sql ) ) {
				++$write_queries;
			}

			return $sql;
		};

		$count_write = static function ( $meta_id, $object_id, $meta_key ) use ( &$writes ) {
			unset( $meta_id, $object_id );

			if ( in_array( $meta_key, Readiness_Store::meta_keys(), true ) ) {
				++$writes;
			}
		};

		add_filter( 'query', $count_query );
		add_action( 'added_post_meta', $count_write, 10, 3 );
		add_action( 'updated_post_meta', $count_write, 10, 3 );

		$validations = $this->validations_during(
			static function () use ( $wpdb, $rebuild, $signature, &$cost ) {
				$before = $wpdb->num_queries;

				$rebuild->run_batch( 1, $signature );

				$cost = $wpdb->num_queries - $before;
			}
		);

		remove_filter( 'query', $count_query );
		remove_action( 'added_post_meta', $count_write, 10 );
		remove_action( 'updated_post_meta', $count_write, 10 );

		$this->assertLessThanOrEqual( Readiness_Rebuild::BATCH, $validations );
		$this->assertSame( 3 * Readiness_Rebuild::BATCH, $writes, 'Three meta writes per product.' );
		$this->assertLessThanOrEqual(
			15 * Readiness_Rebuild::BATCH,
			$cost - $write_queries,
			sprintf( '%1$d queries besides the meta writes, for %2$d products.', $cost - $write_queries, Readiness_Rebuild::BATCH )
		);
	}
}
