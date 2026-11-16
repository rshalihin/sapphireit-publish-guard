<?php
/**
 * The catalog numbers the Dashboard shows.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Counts per readiness state and the most common required failures, read from the stored
 * flags (U14).
 *
 * Nothing new is stored per product: the counts are `WP_Query` counts over the store's own
 * meta-query clauses, and the failures are read through `Readiness_Store::read()`. The
 * result is cached for a short while and dropped whenever it may be wrong: a settings save,
 * an add-on announcing changed rules, a rebuild that has just finished. A recheck of single
 * products on save does not drop it; the short lifetime covers that.
 *
 * The numbers are only as current as the flags. While a rebuild is running a product still
 * counts under its old status, so the screen showing them must say so (V-U4).
 *
 * @since 1.2.0
 */
final class Readiness_Stats {

	/**
	 * Transient holding the last result.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const TRANSIENT = 'sit_wcpg_readiness_stats';

	/**
	 * How long a result is served from the cache, in seconds.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Not-ready products read per query when tallying failures.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const BATCH = 200;

	/**
	 * The most not-ready products read when tallying failures.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const SCAN_LIMIT = 5000;

	/**
	 * How many failing checks are reported.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const TOP = 5;

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry;

	/**
	 * The readiness store.
	 *
	 * @since 1.2.0
	 * @var Readiness_Store
	 */
	private Readiness_Store $store;

	/**
	 * The most not-ready products read. A property, not a hook, so the suite can lower it
	 * and nobody else can.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private int $scan_limit = self::SCAN_LIMIT;

	/**
	 * Construct the statistics.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings             $settings The merchant's configuration.
	 * @param Rule_Registry|null   $registry The rule registry. Null resolves the plugin's own on first use.
	 * @param Readiness_Store|null $store    The readiness store. Null builds one.
	 */
	public function __construct( Settings $settings, ?Rule_Registry $registry = null, ?Readiness_Store $store = null ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->store    = $store ?? new Readiness_Store();
	}

	/**
	 * Listen for what makes the cached numbers wrong.
	 *
	 * On every request: settings can be saved over REST or WP-CLI, and a rebuild finishes in
	 * cron. Registering adds hooks and reads nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'flush' ), 10, 0 );
		add_action( 'add_option_' . Settings::OPTION_NAME, array( $this, 'flush' ), 10, 0 );
		add_action( 'sit_wcpg_rules_changed', array( $this, 'flush' ), 10, 0 );

		/*
		 * `Readiness_Rebuild` fires nothing when it finishes; it writes its option with the
		 * state `complete` (V-U5). `update_option()` on a missing option adds it instead, so
		 * both hooks are needed.
		 */
		add_action( 'update_option_' . Readiness_Rebuild::OPTION, array( $this, 'on_index_updated' ), 10, 2 );
		add_action( 'add_option_' . Readiness_Rebuild::OPTION, array( $this, 'on_index_added' ), 10, 2 );
	}

	/**
	 * The numbers, from the cache when it has them.
	 *
	 * @since 1.2.0
	 *
	 * @return array{ready:int, warning:int, fail:int, unchecked:int, total:int, live_fail:int,
	 *               top_failures: array<string,int>, capped: bool, computed_at: int}
	 */
	public function get(): array {
		if ( ! $this->settings->is_enabled() ) {
			return self::empty_result();
		}

		$cached = get_transient( self::TRANSIENT );

		if ( self::is_valid_result( $cached ) ) {
			return $cached;
		}

		$result = $this->compute();

		set_transient( self::TRANSIENT, $result, self::TTL );

		return $result;
	}

	/**
	 * Drop the cached numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Drop the numbers when a rebuild has just turned complete.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $old_value The index before.
	 * @param mixed $value     The index now.
	 * @return void
	 */
	public function on_index_updated( $old_value, $value ): void {
		if ( self::is_complete( $value ) && ! self::is_complete( $old_value ) ) {
			$this->flush();
		}
	}

	/**
	 * Drop the numbers when the index is first written as complete.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The index.
	 * @return void
	 */
	public function on_index_added( $option, $value ): void {
		unset( $option );

		if ( self::is_complete( $value ) ) {
			$this->flush();
		}
	}

	/**
	 * Compute the numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	private function compute(): array {
		$fail = self::count( Readiness_Store::FILTER_FAIL );

		$result = array(
			'ready'        => self::count( Readiness_Store::FILTER_READY ),
			'warning'      => self::count( Readiness_Store::FILTER_WARNING ),
			'fail'         => $fail,
			'unchecked'    => self::count( Readiness_Store::FILTER_UNCHECKED ),
			'total'        => self::count( '' ),
			'live_fail'    => 0 === $fail ? 0 : self::count( Readiness_Store::FILTER_FAIL, array( 'publish' ) ),
			'top_failures' => array(),
			'capped'       => $fail > $this->scan_limit,
			'computed_at'  => time(),
		);

		if ( $fail > 0 ) {
			$result['top_failures'] = $this->top_failures();
		}

		return $result;
	}

	/**
	 * Products in a readiness state.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $filter   A Readiness_Store filter, or '' for every product.
	 * @param string[] $statuses Post statuses; defaults to the ones a rebuild covers.
	 * @return int
	 */
	private static function count( string $filter, array $statuses = Readiness_Rebuild::STATUSES ): int {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => $statuses,
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);

		if ( '' !== $filter ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The store's own indexed clause.
			$args['meta_query'] = array( Readiness_Store::meta_query( $filter ) );
		}

		$query = new WP_Query( $args );

		return max( 0, (int) $query->found_posts );
	}

	/**
	 * The required checks that fail on the most products.
	 *
	 * Reads not-ready products in batches, oldest first, up to the scan limit, with the
	 * meta cache primed once per batch. Rule ids no longer registered are left out.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, int> Rule id to products, most first, then in the registry's order.
	 */
	private function top_failures(): array {
		$tally   = array();
		$scanned = 0;
		$page    = 1;

		while ( $scanned < $this->scan_limit ) {
			$size = min( self::BATCH, $this->scan_limit - $scanned );
			$ids  = self::failing_ids( $page, $size );

			if ( array() === $ids ) {
				break;
			}

			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$flag = $this->store->read( $id );

				foreach ( null === $flag ? array() : $flag['required_failures'] as $rule_id ) {
					$tally[ $rule_id ] = ( $tally[ $rule_id ] ?? 0 ) + 1;
				}
			}

			$scanned += count( $ids );

			if ( count( $ids ) < $size ) {
				break;
			}

			++$page;
		}

		return $this->rank( $tally );
	}

	/**
	 * One page of not-ready product ids.
	 *
	 * Pages are counted in `$size`, which only shrinks on the last page, so the offset
	 * stays right.
	 *
	 * @since 1.2.0
	 *
	 * @param int $page Page number, from 1.
	 * @param int $size Products on the page.
	 * @return int[]
	 */
	private static function failing_ids( int $page, int $size ): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => Readiness_Rebuild::STATUSES,
				'fields'                 => 'ids',
				'posts_per_page'         => $size,
				'offset'                 => ( $page - 1 ) * self::BATCH,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The store's own indexed clause.
				'meta_query'             => array( Readiness_Store::meta_query( Readiness_Store::FILTER_FAIL ) ),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Registered rules only, most failures first, ties in the registry's order, top five.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, int> $tally Rule id to products.
	 * @return array<string, int>
	 */
	private function rank( array $tally ): array {
		$rows     = array();
		$position = 0;

		foreach ( $this->registry()->all() as $rule ) {
			$rule_id = $rule->get_id();

			if ( isset( $tally[ $rule_id ] ) ) {
				$rows[] = array( $rule_id, $tally[ $rule_id ], $position );
			}

			++$position;
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return 0 !== ( $b[1] <=> $a[1] ) ? $b[1] <=> $a[1] : $a[2] <=> $b[2];
			}
		);

		$ranked = array();

		foreach ( array_slice( $rows, 0, self::TOP ) as $row ) {
			$ranked[ $row[0] ] = $row[1];
		}

		return $ranked;
	}

	/**
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		if ( null === $this->registry ) {
			$this->registry = Plugin::instance()->registry();
		}

		return $this->registry;
	}

	/**
	 * Whether an index value records a finished rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $index Stored index.
	 * @return bool
	 */
	private static function is_complete( $index ): bool {
		return is_array( $index ) && Readiness_Rebuild::STATE_COMPLETE === ( $index['state'] ?? '' );
	}

	/**
	 * The result while the checklist is off: nothing is being checked.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	private static function empty_result(): array {
		return array(
			'ready'        => 0,
			'warning'      => 0,
			'fail'         => 0,
			'unchecked'    => 0,
			'total'        => 0,
			'live_fail'    => 0,
			'top_failures' => array(),
			'capped'       => false,
			'computed_at'  => 0,
		);
	}

	/**
	 * Whether a cached value has the result's shape. A transient can be edited by anything.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Cached value.
	 * @return bool
	 */
	private static function is_valid_result( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( array( 'ready', 'warning', 'fail', 'unchecked', 'total', 'live_fail', 'computed_at' ) as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_int( $value[ $key ] ) || $value[ $key ] < 0 ) {
				return false;
			}
		}

		if ( ! isset( $value['capped'] ) || ! is_bool( $value['capped'] ) || ! isset( $value['top_failures'] ) || ! is_array( $value['top_failures'] ) ) {
			return false;
		}

		foreach ( $value['top_failures'] as $rule_id => $count ) {
			if ( ! is_string( $rule_id ) || ! is_int( $count ) ) {
				return false;
			}
		}

		return true;
	}
}
