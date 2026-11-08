<?php
/**
 * Brings every stored readiness flag up to date in the background.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Child_Loader;
use ProductPublishGuard\Settings\Settings;
use Throwable;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Batches of products, through WooCommerce's Action Scheduler, after something has made
 * the stored flags stale.
 *
 * It runs only after a settings change, a plugin update or a stale signature found on a
 * screen. There is no recurring schedule (D12). Without Action Scheduler nothing is queued,
 * and the screens say so.
 *
 * Not final: the suite can only simulate a site without Action Scheduler by overriding
 * `scheduler_available()`.
 *
 * @since 1.2.0
 */
class Readiness_Rebuild {

	/**
	 * The option describing the last rebuild.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const OPTION = 'sit_wcpg_readiness_index';

	/**
	 * Action Scheduler hook of one catalog batch. Internal, not part of the rule API.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const HOOK = 'sit_wcpg_rebuild_readiness';

	/**
	 * Action Scheduler hook of a list of products. Internal, not part of the rule API.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const PRODUCTS_HOOK = 'sit_wcpg_reindex_products';

	/**
	 * Action Scheduler group of every job this plugin queues.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const GROUP = 'sit-wcpg';

	/**
	 * Products in one catalog batch.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const BATCH = 50;

	/**
	 * The most ids one product job carries.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_IDS = 100;

	/**
	 * Index state while jobs are still working through the catalog.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const STATE_BUILDING = 'building';

	/**
	 * Index state once the last batch has run.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const STATE_COMPLETE = 'complete';

	/**
	 * Post statuses a rebuild covers.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * What writes each flag.
	 *
	 * @since 1.2.0
	 * @var Readiness_Indexer
	 */
	private Readiness_Indexer $indexer;

	/**
	 * Construct the rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings          $settings The merchant's configuration.
	 * @param Readiness_Indexer $indexer  What writes each flag.
	 */
	public function __construct( Settings $settings, Readiness_Indexer $indexer ) {
		$this->settings = $settings;
		$this->indexer  = $indexer;
	}

	/**
	 * Listen for what makes flags stale, and for the jobs.
	 *
	 * On every request, because Action Scheduler runs its jobs in cron and async requests.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'on_settings_changed' ), 10, 0 );
		add_action( 'add_option_' . Settings::OPTION_NAME, array( $this, 'on_settings_changed' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'maybe_schedule_for_version' ), 10, 0 );
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 2 );
		add_action( self::PRODUCTS_HOOK, array( $this, 'run_products' ), 10, 1 );
	}

	/**
	 * Whether Action Scheduler is here to run the jobs.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function scheduler_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queue a rebuild of the whole catalog, replacing any still waiting.
	 *
	 * @since 1.2.0
	 *
	 * @return bool False when nothing could be queued.
	 */
	public function schedule(): bool {
		if ( ! static::scheduler_available() || ! $this->settings->is_enabled() ) {
			return false;
		}

		try {
			// By hook alone: with a group as well, the call only matches actions with no args (R18).
			as_unschedule_all_actions( self::HOOK );

			$signature = Readiness_Store::signature( $this->settings );

			update_option(
				self::OPTION,
				array(
					'version'    => SIT_WCPG_VERSION,
					'signature'  => $signature,
					'state'      => self::STATE_BUILDING,
					'total'      => self::catalog_size(),
					'done'       => 0,
					'started_at' => time(),
				),
				false
			);

			$queued = $this->enqueue_page( 1, $signature );
		} catch ( Throwable $error ) {
			Readiness_Indexer::log_failure( $error );

			$queued = false;
		}

		if ( ! $queued ) {
			// A rebuild recorded as running would promise a result forever, and never be retried.
			delete_option( self::OPTION );
		}

		return $queued;
	}

	/**
	 * Record that the whole catalog has just been indexed in one go, as `wp sit-wcpg reindex` does.
	 *
	 * Without this the screens would go on reporting stale flags, and on a site with no
	 * Action Scheduler they would ask for the very command that has just run.
	 *
	 * @since 1.2.0
	 *
	 * @param int $total Products indexed.
	 * @return void
	 */
	public function mark_complete( int $total ): void {
		if ( static::scheduler_available() ) {
			as_unschedule_all_actions( self::HOOK );
		}

		update_option(
			self::OPTION,
			array(
				'version'    => SIT_WCPG_VERSION,
				'signature'  => Readiness_Store::signature( $this->settings ),
				'state'      => self::STATE_COMPLETE,
				'total'      => $total,
				'done'       => $total,
				'started_at' => time(),
			),
			false
		);
	}

	/**
	 * Queue a rebuild for the settings just saved.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function on_settings_changed(): void {
		$this->settings->refresh();
		$this->schedule();
	}

	/**
	 * Queue a rebuild the first time an admin request runs a new plugin version.
	 *
	 * Runs on every admin request, so everything before `schedule()` must stay free of the
	 * rule registry. A switched-off checklist is read straight from the option for that
	 * reason: it is never rebuilt, so its stored version never catches up.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function maybe_schedule_for_version(): void {
		if ( SIT_WCPG_VERSION === $this->index()['version'] || ! static::scheduler_available() ) {
			return;
		}

		$stored = get_option( Settings::OPTION_NAME, array() );

		if ( is_array( $stored ) && array_key_exists( 'enabled', $stored ) && ! $stored['enabled'] ) {
			return;
		}

		$this->schedule();
	}

	/**
	 * Queue a rebuild when the recorded one is for different settings.
	 *
	 * For the screens that show flags, which build the registry anyway.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function ensure_current(): void {
		if ( Readiness_Store::signature( $this->settings ) !== $this->index()['signature'] ) {
			$this->schedule();
		}
	}

	/**
	 * Run one catalog batch. Action Scheduler's callback.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $page      Page number, from 1.
	 * @param mixed $signature The signature the job was queued for.
	 * @return void
	 */
	public function run_batch( $page, $signature ): void {
		$page = is_numeric( $page ) ? max( 1, (int) $page ) : 1;

		if ( ! $this->settings->is_enabled() || Readiness_Store::signature( $this->settings ) !== $signature ) {
			return;
		}

		$ids = self::page_of_products( $page );

		Child_Loader::prime( $ids, array() );

		$this->index_each( $ids, (string) $signature );

		$index          = $this->index();
		$index['done'] += count( $ids );

		if ( self::BATCH === count( $ids ) ) {
			update_option( self::OPTION, $index, false );
			$this->enqueue_page( $page + 1, (string) $signature );

			return;
		}

		$index['state'] = self::STATE_COMPLETE;

		update_option( self::OPTION, $index, false );
	}

	/**
	 * Queue background indexing of some products.
	 *
	 * Skips a chunk already waiting with the same ids. `$unique` cannot do this: on the
	 * Action Scheduler of WooCommerce 9.0 it compares only hook and group, so it would drop
	 * every chunk after the first (R17).
	 *
	 * @since 1.2.0
	 *
	 * @param array $ids Product ids.
	 * @return void
	 */
	public function enqueue_products( array $ids ): void {
		if ( ! static::scheduler_available() ) {
			return;
		}

		$ids = self::positive_ids( $ids );

		foreach ( array_chunk( $ids, self::MAX_IDS ) as $chunk ) {
			$args = array( 'ids' => $chunk );

			if ( ! $this->is_pending( $args ) ) {
				as_enqueue_async_action( self::PRODUCTS_HOOK, $args, self::GROUP );
			}
		}
	}

	/**
	 * Index some products. Action Scheduler's callback.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $ids Product ids.
	 * @return void
	 */
	public function run_products( $ids ): void {
		if ( ! is_array( $ids ) || ! $this->settings->is_enabled() ) {
			return;
		}

		$this->index_each( self::positive_ids( $ids ), Readiness_Store::signature( $this->settings ) );
	}

	/**
	 * How far the flags can be trusted.
	 *
	 * @since 1.2.0
	 *
	 * @return array{state: string, total: int, done: int, is_current: bool, scheduler: bool}
	 */
	public function status(): array {
		$index = $this->index();

		return array(
			'state'      => $index['state'],
			'total'      => $index['total'],
			'done'       => $index['done'],
			'is_current' => self::STATE_COMPLETE === $index['state'] && Readiness_Store::signature( $this->settings ) === $index['signature'],
			'scheduler'  => static::scheduler_available(),
		);
	}

	/**
	 * The stored index, normalized.
	 *
	 * @since 1.2.0
	 *
	 * @return array{version: string, signature: string, state: string, total: int, done: int, started_at: int}
	 */
	private function index(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'version'    => is_string( $stored['version'] ?? null ) ? $stored['version'] : '',
			'signature'  => is_string( $stored['signature'] ?? null ) ? $stored['signature'] : '',
			'state'      => self::STATE_BUILDING === ( $stored['state'] ?? '' ) ? self::STATE_BUILDING : self::STATE_COMPLETE,
			'total'      => is_numeric( $stored['total'] ?? null ) ? max( 0, (int) $stored['total'] ) : 0,
			'done'       => is_numeric( $stored['done'] ?? null ) ? max( 0, (int) $stored['done'] ) : 0,
			'started_at' => is_numeric( $stored['started_at'] ?? null ) ? max( 0, (int) $stored['started_at'] ) : 0,
		);
	}

	/**
	 * Queue one page of the catalog.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $page      Page number.
	 * @param string $signature The signature being built.
	 * @return bool Whether it was queued.
	 */
	private function enqueue_page( int $page, string $signature ): bool {
		return 0 < (int) as_enqueue_async_action(
			self::HOOK,
			array(
				'page'      => $page,
				'signature' => $signature,
			),
			self::GROUP
		);
	}

	/**
	 * Whether a product job with these args is still waiting.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Action args.
	 * @return bool
	 */
	private function is_pending( array $args ): bool {
		return array() !== as_get_scheduled_actions(
			array(
				'hook'     => self::PRODUCTS_HOOK,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Index products one by one, so one failure costs one flag.
	 *
	 * @since 1.2.0
	 *
	 * @param int[]  $ids       Product ids.
	 * @param string $signature The current signature.
	 * @return void
	 */
	private function index_each( array $ids, string $signature ): void {
		foreach ( $ids as $id ) {
			try {
				$this->indexer->index( $id, $signature );
			} catch ( Throwable $error ) {
				Readiness_Indexer::log_failure( $error );
			}
		}
	}

	/**
	 * How many products a rebuild covers.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public static function catalog_size(): int {
		$counts = (array) wp_count_posts( 'product' );
		$total  = 0;

		foreach ( self::STATUSES as $status ) {
			$total += (int) ( $counts[ $status ] ?? 0 );
		}

		return $total;
	}

	/**
	 * One page of the catalog, oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @param int $page Page number.
	 * @return int[]
	 */
	private static function page_of_products( int $page ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => self::STATUSES,
				'fields'         => 'ids',
				'posts_per_page' => self::BATCH,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Positive integers, unique and sorted, so one set always makes the same job.
	 *
	 * @since 1.2.0
	 *
	 * @param array $ids Raw ids.
	 * @return int[]
	 */
	private static function positive_ids( array $ids ): array {
		$positive = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$positive[ (int) $id ] = (int) $id;
			}
		}

		sort( $positive );

		return $positive;
	}
}
