<?php
/**
 * What the WP-CLI commands actually do.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Cli;

use Generator;
use ProductPublishGuard\Engine\Child_Loader;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use Throwable;
use WC_Product;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the gate over a catalog, separately from the `WP_CLI` class so it can be tested.
 *
 * Rows hold ids, statuses and rule ids only. Nothing a merchant or an importer typed ever
 * reaches the output (D17).
 *
 * @since 1.2.0
 */
final class Gate_Runner {

	/**
	 * Products in one page of the walk.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const BATCH = 100;

	/**
	 * The status choice that means every status a rebuild covers.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const ANY = 'any';

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Where validations run.
	 *
	 * @since 1.2.0
	 * @var Checklist_Service
	 */
	private Checklist_Service $checklist;

	/**
	 * What writes each flag.
	 *
	 * @since 1.2.0
	 * @var Readiness_Indexer
	 */
	private Readiness_Indexer $indexer;

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Readiness_Rebuild|null
	 */
	private ?Readiness_Rebuild $rebuild;

	/**
	 * Totals of the last `check()`.
	 *
	 * @since 1.2.0
	 * @var array{checked: int, failed: int, warnings: int}
	 */
	private array $summary = array(
		'checked'  => 0,
		'failed'   => 0,
		'warnings' => 0,
	);

	/**
	 * Construct the runner.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings               $settings  The merchant's configuration.
	 * @param Checklist_Service      $checklist Where validations run.
	 * @param Readiness_Indexer      $indexer   What writes each flag.
	 * @param Readiness_Rebuild|null $rebuild   The rebuild. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, Checklist_Service $checklist, Readiness_Indexer $indexer, ?Readiness_Rebuild $rebuild = null ) {
		$this->settings  = $settings;
		$this->checklist = $checklist;
		$this->indexer   = $indexer;
		$this->rebuild   = $rebuild;
	}

	/**
	 * The status choices of `check`.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::ANY, 'publish', 'draft', 'pending', 'future', 'private' );
	}

	/**
	 * How many products a reindex covers.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public static function catalog_size(): int {
		return Readiness_Rebuild::catalog_size();
	}

	/**
	 * Whether the checklist is switched on.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_checklist_enabled(): bool {
		return $this->settings->is_enabled();
	}

	/**
	 * Validate products live, one row each. Writes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $ids       Product ids. Empty walks the catalog.
	 * @param string $status    One of `statuses()`. Applies only when walking the catalog.
	 * @param bool   $fail_only Whether to yield only products failing a required check.
	 * @return Generator<int, array{id: int, status: string, required_failures: string, warnings: int}>
	 */
	public function check( array $ids = array(), string $status = self::ANY, bool $fail_only = false ): Generator {
		$this->summary = array(
			'checked'  => 0,
			'failed'   => 0,
			'warnings' => 0,
		);

		$batches = array() === $ids
			? self::catalog_pages( $status )
			: array_chunk( Product_Context::coerce_ids( $ids ), self::BATCH );

		foreach ( $batches as $batch ) {
			Child_Loader::prime( $batch, array() );

			foreach ( $batch as $id ) {
				$row = $this->row( $id );

				if ( null === $row ) {
					continue;
				}

				$this->tally( $row );

				if ( ! $fail_only || Status::FAIL === $row['status'] ) {
					yield $row;
				}
			}

			// A long run should not keep every product it has already finished with.
			Checklist_Service::flush_memo();
		}
	}

	/**
	 * Totals of the last `check()`, including rows `$fail_only` dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return array{checked: int, failed: int, warnings: int}
	 */
	public function summary(): array {
		return $this->summary;
	}

	/**
	 * Bring every stored flag up to date, without Action Scheduler.
	 *
	 * @since 1.2.0
	 *
	 * @param callable|null $tick Called once per product.
	 * @return int How many products were rechecked.
	 */
	public function reindex( ?callable $tick = null ): int {
		if ( ! $this->settings->is_enabled() ) {
			return 0;
		}

		$signature = Readiness_Store::signature( $this->settings );
		$count     = 0;

		foreach ( self::catalog_pages( self::ANY ) as $batch ) {
			Child_Loader::prime( $batch, array() );

			foreach ( $batch as $id ) {
				try {
					$this->indexer->index( $id, $signature );
					++$count;
				} catch ( Throwable $error ) {
					Readiness_Indexer::log_failure( $error );
				}

				if ( null !== $tick ) {
					$tick();
				}
			}

			Checklist_Service::flush_memo();
		}

		$this->rebuild()->mark_complete( $count );

		return $count;
	}

	/**
	 * Add one product to the totals.
	 *
	 * @since 1.2.0
	 *
	 * @param array{id: int, status: string, required_failures: string, warnings: int} $row The product's row.
	 * @return void
	 */
	private function tally( array $row ): void {
		++$this->summary['checked'];

		if ( Status::FAIL === $row['status'] ) {
			++$this->summary['failed'];
		}

		if ( $row['warnings'] > 0 ) {
			++$this->summary['warnings'];
		}
	}

	/**
	 * Validate one product.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Post id.
	 * @return array{id: int, status: string, required_failures: string, warnings: int}|null Null when it is not a product.
	 */
	private function row( int $id ): ?array {
		if ( 'product' !== get_post_type( $id ) ) {
			return null;
		}

		$product = wc_get_product( $id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$result   = $this->checklist->validate_context( Product_Context::from_product( $product )->with_save_path( Save_Path::CLI ) );
		$required = $result->get_required_failures();
		$counts   = $result->get_counts();

		return array(
			'id'                => $id,
			'status'            => Readiness_Store::status_for( $result ),
			'required_failures' => implode( ',', $required ),
			'warnings'          => (int) $counts['failed'] + (int) $counts['warnings'] - count( $required ),
		);
	}

	/**
	 * The catalog, a page at a time, oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status One of `statuses()`.
	 * @return Generator<int, int[]>
	 */
	private static function catalog_pages( string $status ): Generator {
		$statuses = self::ANY !== $status && in_array( $status, Readiness_Rebuild::STATUSES, true )
			? array( $status )
			: Readiness_Rebuild::STATUSES;

		$page = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => $statuses,
					'fields'         => 'ids',
					'posts_per_page' => self::BATCH,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			$ids     = array_map( 'intval', $query->posts );
			$fetched = count( $ids );

			if ( $fetched > 0 ) {
				yield $ids;
			}

			++$page;
		} while ( self::BATCH === $fetched );
	}

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Rebuild
	 */
	private function rebuild(): Readiness_Rebuild {
		if ( null === $this->rebuild ) {
			$this->rebuild = Plugin::instance()->readiness_rebuild();
		}

		return $this->rebuild;
	}
}
