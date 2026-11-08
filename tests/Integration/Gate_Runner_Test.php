<?php
/**
 * Tests for the core of the WP-CLI commands.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Cli\Gate_Runner;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;

/**
 * The gate over a catalog: what it reports, and that it reports nothing a shop wrote.
 *
 * @since 1.2.0
 */
final class Gate_Runner_Test extends Publish_Guard_Test_Case {

	/**
	 * Start with no recorded rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * The runner over the plugin's own services.
	 *
	 * @since 1.2.0
	 *
	 * @return Gate_Runner
	 */
	private function runner(): Gate_Runner {
		$plugin = Plugin::instance();

		return new Gate_Runner( $plugin->settings(), $plugin->checklist(), $plugin->readiness_indexer() );
	}

	/**
	 * Every row of a run.
	 *
	 * @since 1.2.0
	 *
	 * @param Gate_Runner $runner    The runner.
	 * @param array       $ids       Product ids.
	 * @param string      $status    Status filter.
	 * @param bool        $fail_only Whether to keep only failures.
	 * @return array
	 */
	private function rows( Gate_Runner $runner, array $ids = array(), string $status = 'any', bool $fail_only = false ): array {
		return iterator_to_array( $runner->check( $ids, $status, $fail_only ), false );
	}

	/**
	 * Many bare products, quickly.
	 *
	 * @since 1.2.0
	 *
	 * @param int $count How many.
	 * @return int[]
	 */
	private function bare_products( int $count ): array {
		return self::factory()->post->create_many(
			$count,
			array(
				'post_type'   => 'product',
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * One row per product, with the documented shape.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_check_yields_one_row_per_product(): void {
		$passing = $this->spotless_product( 'publish' );
		$failing = $this->failing_product( 'publish' );

		$rows = $this->rows( $this->runner() );

		$this->assertCount( 2, $rows );

		$by_id = array_column( $rows, null, 'id' );

		$this->assertSame(
			array(
				'id'                => $passing,
				'status'            => Status::PASS,
				'required_failures' => '',
				'warnings'          => 0,
			),
			$by_id[ $passing ]
		);
		$this->assertSame( Status::FAIL, $by_id[ $failing ]['status'] );
		$this->assertSame( 'price', $by_id[ $failing ]['required_failures'] );
	}

	/**
	 * Advisory problems are counted, without the required failures among them.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_warnings_exclude_required_failures(): void {
		$id  = $this->failing_product();
		$row = $this->rows( $this->runner(), array( $id ) )[0];

		$result = Plugin::instance()->checklist()->validate_post( $id );
		$counts = $result->get_counts();

		$this->assertSame( (int) $counts['failed'] + (int) $counts['warnings'] - 1, $row['warnings'] );
		$this->assertGreaterThan( 0, $row['warnings'], 'The fixture has no tag and only one image.' );
	}

	/**
	 * Rules can tell a CLI run from a save.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_check_uses_cli_save_path(): void {
		$id    = $this->passing_product();
		$paths = array();

		$record = static function ( $result, $context ) use ( &$paths ) {
			if ( $context instanceof Product_Context ) {
				$paths[] = $context->get_save_path();
			}

			return $result;
		};

		add_filter( 'sit_wcpg_validation_result', $record, 10, 2 );
		$this->rows( $this->runner(), array( $id ) );
		remove_filter( 'sit_wcpg_validation_result', $record, 10 );

		$this->assertSame( array( 'cli' ), $paths );
	}

	/**
	 * Only failures survive `$fail_only`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_fail_only_filters_rows(): void {
		$this->spotless_product( 'publish' );
		$failing = $this->failing_product( 'publish' );

		$rows = $this->rows( $this->runner(), array(), 'any', true );

		$this->assertSame( array( $failing ), array_column( $rows, 'id' ) );
	}

	/**
	 * The summary covers everything checked, including rows `$fail_only` dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_summary_counts_everything_checked(): void {
		$this->spotless_product( 'publish' );
		$this->failing_product( 'publish' );

		$runner = $this->runner();

		$this->rows( $runner, array(), 'any', true );

		$this->assertSame(
			array(
				'checked'  => 2,
				'failed'   => 1,
				'warnings' => 1,
			),
			$runner->summary()
		);
	}

	/**
	 * A status filter narrows the walk.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_status_filter(): void {
		$published = $this->passing_product( 'publish' );
		$this->passing_product( 'draft' );

		$this->assertSame( array( $published ), array_column( $this->rows( $this->runner(), array(), 'publish' ), 'id' ) );
	}

	/**
	 * Trashed products are not part of the catalog.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_any_excludes_the_trash(): void {
		$kept = $this->passing_product( 'draft' );

		wp_trash_post( $this->passing_product( 'draft' ) );

		$this->assertSame( array( $kept ), array_column( $this->rows( $this->runner() ), 'id' ) );
	}

	/**
	 * Ids that are not products vanish without an error.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_explicit_ids_skip_non_products(): void {
		$product   = $this->passing_product();
		$other     = $this->passing_product();
		$page      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$variation = get_post( $this->variable_product( array( array( 'regular_price' => '5' ) ) ) );
		$children  = wc_get_product( $variation->ID )->get_children();

		$rows = $this->rows( $this->runner(), array( $product, $page, 999999, $children[0], -3 ) );

		$this->assertSame( array( $product ), array_column( $rows, 'id' ) );
		$this->assertNotContains( $other, array_column( $rows, 'id' ) );
	}

	/**
	 * No row carries anything a merchant or an importer typed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rows_hold_no_product_text(): void {
		$id = $this->passing_product(
			'draft',
			array(
				'name'          => '<b>x</b>',
				'sku'           => 'evil,sku',
				'regular_price' => '',
			)
		);

		$row = $this->rows( $this->runner(), array( $id ) )[0];

		foreach ( $row as $value ) {
			if ( is_string( $value ) ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9_,]*$/', $value );
			} else {
				$this->assertIsInt( $value );
			}
		}

		$this->assertStringNotContainsString( 'evil', (string) wp_json_encode( $row ) );
	}

	/**
	 * More than one batch is walked, and each product once.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_check_pages_through_more_than_one_batch(): void {
		$ids = $this->bare_products( Gate_Runner::BATCH + 20 );

		$seen = array_column( $this->rows( $this->runner() ), 'id' );

		sort( $seen );
		sort( $ids );

		$this->assertSame( $ids, $seen );
	}

	/**
	 * Explicit ids beyond one batch all come back too.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_explicit_ids_page_through_more_than_one_batch(): void {
		$ids = $this->bare_products( Gate_Runner::BATCH + 5 );

		$this->assertCount( Gate_Runner::BATCH + 5, $this->rows( $this->runner(), $ids ) );
	}

	/**
	 * A reindex writes every flag and reports each product.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_reindex_writes_store_and_ticks(): void {
		$ids   = $this->bare_products( 5 );
		$ticks = 0;

		$count = $this->runner()->reindex(
			static function () use ( &$ticks ) {
				++$ticks;
			}
		);

		$this->assertSame( 5, $count );
		$this->assertSame( 5, $ticks );

		foreach ( $ids as $id ) {
			$this->assertTrue( Status::is_valid( (string) get_post_meta( $id, Readiness_Store::META_STATUS, true ) ) );
		}
	}

	/**
	 * After a reindex the screens stop asking for one.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_reindex_records_the_index_as_current(): void {
		$this->bare_products( 3 );

		$this->runner()->reindex();

		$status = Plugin::instance()->readiness_rebuild()->status();

		$this->assertTrue( $status['is_current'] );
		$this->assertSame( 3, $status['total'] );
		$this->assertSame( 3, $status['done'] );
	}

	/**
	 * A batch queued before the reindex has nothing left to do.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_reindex_drops_queued_rebuild_batches(): void {
		$this->bare_products( 1 );

		Plugin::instance()->readiness_rebuild()->schedule();

		$this->runner()->reindex();

		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => Readiness_Rebuild::HOOK,
					'status' => 'pending',
				),
				'ids'
			)
		);
	}

	/**
	 * While the checklist is off, nothing is written (D9).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_reindex_writes_nothing_while_the_checklist_is_off(): void {
		$ids = $this->bare_products( 2 );

		update_option( Settings::OPTION_NAME, array( 'enabled' => false ) );
		Plugin::instance()->settings()->refresh();

		$runner = $this->runner();

		$this->assertFalse( $runner->is_checklist_enabled() );
		$this->assertSame( 0, $runner->reindex() );
		$this->assertSame( '', get_post_meta( $ids[0], Readiness_Store::META_STATUS, true ) );
	}

	/**
	 * A check reads and validates, and never touches the store.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_check_does_not_write_the_store(): void {
		$id = $this->passing_product();

		$this->rows( $this->runner(), array( $id ) );
		$this->rows( $this->runner() );

		$this->assertSame( '', get_post_meta( $id, Readiness_Store::META_STATUS, true ) );
	}

	/**
	 * The status choices the command offers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_statuses(): void {
		$this->assertSame( array( 'any', 'publish', 'draft', 'pending', 'future', 'private' ), Gate_Runner::statuses() );
	}

	/**
	 * The total the progress bar counts toward is the one the walk covers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_catalog_size_counts_the_indexed_statuses(): void {
		$this->passing_product( 'draft' );
		$this->passing_product( 'publish' );

		wp_trash_post( $this->passing_product( 'draft' ) );

		$this->assertSame( 2, Gate_Runner::catalog_size() );
	}
}
