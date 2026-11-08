<?php
/**
 * Tests for the background readiness rebuild.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Stubs\No_Scheduler_Rebuild;
use WC_Product_Simple;
use WP_Query;

/**
 * After a settings change or a plugin update every flag is brought up to date in batches,
 * with no recurring schedule (D11, D12). Jobs are run by calling their handlers.
 *
 * @since 1.2.0
 */
final class Readiness_Rebuild_Test extends Publish_Guard_Test_Case {

	/**
	 * The plugin's own rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Rebuild
	 */
	private function rebuild(): Readiness_Rebuild {
		return Plugin::instance()->readiness_rebuild();
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
	 * The args of each pending action on a hook.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook Action hook.
	 * @return array[]
	 */
	private function pending( string $hook = Readiness_Rebuild::HOOK ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Readiness_Rebuild::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			)
		);

		return array_values(
			array_map(
				static function ( $action ) {
					return $action->get_args();
				},
				$actions
			)
		);
	}

	/**
	 * Saved products with nothing but a name, which is all a batch needs.
	 *
	 * @since 1.2.0
	 *
	 * @param int $count How many.
	 * @return int[]
	 */
	private function products( int $count ): array {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Product ' . $i );
			$ids[] = $this->save_unguarded( $product );
		}

		return $ids;
	}

	/**
	 * How many products carry a flag.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private function flags(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture count.
				'meta_key'       => Readiness_Store::META_STATUS,
			)
		);

		return count( $query->posts );
	}

	/**
	 * Store settings with one rule's severity changed.
	 *
	 * @since 1.2.0
	 *
	 * @param string $severity The severity to give the SKU rule.
	 * @return void
	 */
	private function change_settings( string $severity = 'required' ): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['rules']['sku']['severity'] = $severity;

		update_option( Settings::OPTION_NAME, $stored );
	}

	/**
	 * Start with no index and an empty queue.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Readiness_Rebuild::OPTION );
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
	}

	/**
	 * A settings change queues one rebuild and says so.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_saving_changed_settings_queues_one_rebuild(): void {
		$this->change_settings();

		$pending = $this->pending();

		$this->assertCount( 1, $pending );
		$this->assertSame( 1, $pending[0]['page'] );
		$this->assertSame( $this->signature(), $pending[0]['signature'] );

		$index = get_option( Readiness_Rebuild::OPTION );

		$this->assertSame( 'building', $index['state'] );
		$this->assertSame( SIT_WCPG_VERSION, $index['version'] );
		$this->assertSame( $this->signature(), $index['signature'] );
		$this->assertSame( 0, $index['done'] );
	}

	/**
	 * The index option stays out of autoload.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_index_option_is_not_autoloaded(): void {
		$this->change_settings();

		$this->assertArrayNotHasKey( Readiness_Rebuild::OPTION, wp_load_alloptions() );
	}

	/**
	 * Saving the form unchanged queues nothing (R10).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_saving_identical_settings_queues_nothing(): void {
		$this->change_settings();
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );

		$this->change_settings();

		$this->assertSame( array(), $this->pending() );
	}

	/**
	 * A second change replaces the first rebuild rather than queueing behind it (R18).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_second_change_replaces_the_pending_rebuild(): void {
		$this->change_settings( 'required' );
		$first = $this->signature();

		$this->change_settings( 'warning' );

		$pending = $this->pending();

		$this->assertCount( 1, $pending );
		$this->assertNotSame( $first, $this->signature() );
		$this->assertSame( $this->signature(), $pending[0]['signature'] );
	}

	/**
	 * Rebuilds wait for no recurring schedule (D12).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_recurring_action_is_ever_scheduled(): void {
		$this->change_settings();

		$actions = as_get_scheduled_actions(
			array(
				'group'    => Readiness_Rebuild::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			)
		);

		$this->assertNotEmpty( $actions );

		foreach ( $actions as $action ) {
			$this->assertFalse( $action->get_schedule()->is_recurring() );
		}
	}

	/**
	 * A plugin update queues a rebuild on the next admin request.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_version_change_on_admin_init_schedules(): void {
		$this->assertNotFalse( has_action( 'admin_init', array( $this->rebuild(), 'maybe_schedule_for_version' ) ) );

		update_option( Readiness_Rebuild::OPTION, array( 'version' => 'an-older-version' ), false );

		$this->rebuild()->maybe_schedule_for_version();

		$this->assertCount( 1, $this->pending() );
	}

	/**
	 * Settings that count every read.
	 *
	 * @since 1.2.0
	 *
	 * @return Settings
	 */
	private function counting_settings(): Settings {
		return new class() extends Settings {

			/**
			 * Reads so far.
			 *
			 * @since 1.2.0
			 * @var int
			 */
			public int $reads = 0;

			/**
			 * Count the read.
			 *
			 * @since 1.2.0
			 *
			 * @return array
			 */
			public function get_all(): array {
				++$this->reads;

				return parent::get_all();
			}
		};
	}

	/**
	 * On an ordinary admin request the version check is a string compare and no more.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_same_version_on_admin_init_does_not_build_registry(): void {
		$settings = $this->counting_settings();
		$rebuild  = new Readiness_Rebuild( $settings, Plugin::instance()->readiness_indexer() );

		update_option( Readiness_Rebuild::OPTION, array( 'version' => SIT_WCPG_VERSION ), false );

		$rebuild->maybe_schedule_for_version();

		$this->assertSame( 0, $settings->reads );
		$this->assertSame( array(), $this->pending() );
	}

	/**
	 * A switched-off checklist never records a version, so it must not pay for the check
	 * on every admin request instead.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disabled_checklist_on_admin_init_does_not_build_registry(): void {
		$settings = $this->counting_settings();
		$rebuild  = new Readiness_Rebuild( $settings, Plugin::instance()->readiness_indexer() );

		update_option( Settings::OPTION_NAME, array( 'enabled' => false ) );
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );

		$rebuild->maybe_schedule_for_version();

		$this->assertSame( 0, $settings->reads );
		$this->assertSame( array(), $this->pending() );
	}

	/**
	 * Fifty, then the rest, then done.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_batch_indexes_50_and_queues_next_page(): void {
		$this->products( 60 );

		$this->rebuild()->schedule();
		$signature = $this->signature();

		$this->rebuild()->run_batch( 1, $signature );

		$this->assertSame( 50, $this->flags() );
		$this->assertContains(
			array(
				'page'      => 2,
				'signature' => $signature,
			),
			$this->pending()
		);
		$this->assertSame( 'building', get_option( Readiness_Rebuild::OPTION )['state'] );

		$this->rebuild()->run_batch( 2, $signature );

		$index = get_option( Readiness_Rebuild::OPTION );

		$this->assertSame( 60, $this->flags() );
		$this->assertSame( 'complete', $index['state'] );
		$this->assertSame( 60, $index['done'] );
		$this->assertSame( 60, $index['total'] );
	}

	/**
	 * A catalog of exactly one full page finishes on the empty page after it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_an_exact_page_finishes_on_the_next_empty_page(): void {
		$this->products( Readiness_Rebuild::BATCH );

		$this->rebuild()->schedule();

		$this->rebuild()->run_batch( 1, $this->signature() );
		$this->rebuild()->run_batch( 2, $this->signature() );

		$this->assertSame( 'complete', get_option( Readiness_Rebuild::OPTION )['state'] );
		$this->assertSame( Readiness_Rebuild::BATCH, get_option( Readiness_Rebuild::OPTION )['done'] );
	}

	/**
	 * A job from before a settings change does nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_stale_job_does_nothing(): void {
		$this->products( 3 );

		$this->change_settings( 'required' );
		$old = $this->signature();

		$this->change_settings( 'warning' );

		$this->rebuild()->run_batch( 1, $old );

		$this->assertSame( 0, $this->flags() );
		$this->assertSame( 0, get_option( Readiness_Rebuild::OPTION )['done'] );
	}

	/**
	 * With the checklist off, no rebuild is scheduled and no job writes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disabled_checklist_schedules_and_writes_nothing(): void {
		$this->products( 2 );

		$signature = $this->signature();
		$settings  = Plugin::instance()->settings();
		$stored    = $settings->get_defaults();

		$stored['enabled'] = false;

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$this->assertFalse( $this->rebuild()->schedule() );
		$this->assertSame( array(), $this->pending() );

		$this->rebuild()->run_batch( 1, $signature );
		$this->rebuild()->run_batch( 1, Readiness_Store::signature( $settings ) );

		$this->assertSame( 0, $this->flags() );
	}

	/**
	 * Ids are split into chunks, with duplicates dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_enqueue_products_chunks_and_dedupes(): void {
		$ids = array_merge( range( 1, 150 ), array( 1, 2, 3, 0, -4 ) );

		$this->rebuild()->enqueue_products( $ids );

		$pending = $this->pending( Readiness_Rebuild::PRODUCTS_HOOK );

		$this->assertCount( 2, $pending );
		$this->assertCount( 100, $pending[0]['ids'] );
		$this->assertCount( 50, $pending[1]['ids'] );
	}

	/**
	 * A set already waiting is not queued twice, and a different set still is (R17).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_only_an_identical_pending_chunk_is_skipped(): void {
		$this->rebuild()->enqueue_products( array( 7, 8 ) );
		$this->rebuild()->enqueue_products( array( 8, 7 ) );

		$this->assertCount( 1, $this->pending( Readiness_Rebuild::PRODUCTS_HOOK ) );

		$this->rebuild()->enqueue_products( array( 9 ) );

		$this->assertCount( 2, $this->pending( Readiness_Rebuild::PRODUCTS_HOOK ) );
	}

	/**
	 * The product job indexes exactly the ids it was given.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_run_products_indexes_given_ids(): void {
		$ids = $this->products( 3 );

		$this->rebuild()->run_products( array( $ids[0], $ids[2] ) );

		$store = Plugin::instance()->readiness_store();

		$this->assertNotNull( $store->read( $ids[0] ) );
		$this->assertNull( $store->read( $ids[1] ) );
		$this->assertNotNull( $store->read( $ids[2] ) );
	}

	/**
	 * Status reports the run and whether the flags can be trusted.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_status_reports_progress_and_currency(): void {
		$signature = $this->signature();

		$this->assertFalse( $this->rebuild()->status()['is_current'] );

		update_option(
			Readiness_Rebuild::OPTION,
			array(
				'version'    => SIT_WCPG_VERSION,
				'signature'  => $signature,
				'state'      => 'building',
				'total'      => 40,
				'done'       => 10,
				'started_at' => time(),
			),
			false
		);

		$this->assertSame(
			array(
				'state'      => 'building',
				'total'      => 40,
				'done'       => 10,
				'is_current' => false,
				'scheduler'  => true,
			),
			$this->rebuild()->status()
		);

		$index          = get_option( Readiness_Rebuild::OPTION );
		$index['state'] = 'complete';
		update_option( Readiness_Rebuild::OPTION, $index, false );

		$this->assertTrue( $this->rebuild()->status()['is_current'] );

		$index['signature'] = 'something-older';
		update_option( Readiness_Rebuild::OPTION, $index, false );

		$this->assertFalse( $this->rebuild()->status()['is_current'] );
	}

	/**
	 * A stale index is rescheduled once, and a current or building one is left alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_ensure_current_schedules_only_when_stale(): void {
		update_option( Readiness_Rebuild::OPTION, array( 'signature' => 'stale' ), false );

		$this->rebuild()->ensure_current();
		$this->assertCount( 1, $this->pending() );

		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );

		$this->rebuild()->ensure_current();
		$this->assertSame( array(), $this->pending(), 'A rebuild of this signature is already recorded.' );
	}

	/**
	 * Without Action Scheduler nothing is written, and the screens can tell.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_without_scheduler_schedule_returns_false(): void {
		$rebuild = new No_Scheduler_Rebuild( Plugin::instance()->settings(), Plugin::instance()->readiness_indexer() );

		$this->assertFalse( $rebuild->schedule() );
		$this->assertFalse( get_option( Readiness_Rebuild::OPTION ) );
		$this->assertFalse( $rebuild->status()['scheduler'] );

		$rebuild->enqueue_products( array( 1 ) );
		$this->assertSame( array(), $this->pending( Readiness_Rebuild::PRODUCTS_HOOK ) );
	}

	/**
	 * A rebuild that could not be queued is not recorded as running, or the banner would
	 * promise one forever and the screens would never retry.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_failed_enqueue_leaves_no_building_state(): void {
		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		$this->assertFalse( $this->rebuild()->schedule() );
		$this->assertFalse( get_option( Readiness_Rebuild::OPTION ) );
	}
}
