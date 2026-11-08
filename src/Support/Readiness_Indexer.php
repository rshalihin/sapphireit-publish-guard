<?php
/**
 * Keeps each product's readiness flag in step with its saves.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use Throwable;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Marks products dirty as they are written, and indexes them once, at `shutdown`.
 *
 * Deferring to `shutdown` absorbs the several writes one classic-editor save makes, and
 * runs after the redirect. A shopper's request never validates: its ids go to Action
 * Scheduler instead. Indexing fails open, so it can never break a save (D9).
 *
 * @since 1.2.0
 */
final class Readiness_Indexer {

	/**
	 * Post statuses that never carry a flag.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const CLEARED_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * Products waiting to be indexed, as id => true.
	 *
	 * Static, like `Checklist_Service::$memo`: it describes the request, whichever instance
	 * marked the product.
	 *
	 * @since 1.2.0
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Where flags are stored.
	 *
	 * @since 1.2.0
	 * @var Readiness_Store
	 */
	private Readiness_Store $store;

	/**
	 * The result source, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist;

	/**
	 * Construct the indexer.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings               $settings  The merchant's configuration.
	 * @param Readiness_Store        $store     Where flags are stored.
	 * @param Checklist_Service|null $checklist Result source. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, Readiness_Store $store, ?Checklist_Service $checklist = null ) {
		$this->settings  = $settings;
		$this->store     = $store;
		$this->checklist = $checklist;
	}

	/**
	 * Listen for product writes.
	 *
	 * Registered on every request, since a product can be written from anywhere. Marking
	 * only adds an id to a list.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_insert_post', array( $this, 'mark_post' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'mark_product' ), PHP_INT_MAX, 1 );

		foreach ( Checklist_Service::VARIATION_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'mark_parent_of' ), 10, 1 );
		}

		add_action( 'shutdown', array( $this, 'flush_pending' ), 1, 0 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_meta' ), 10, 1 );
	}

	/**
	 * Mark a product dirty.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id The product.
	 * @return void
	 */
	public function mark( int $product_id ): void {
		if ( $product_id > 0 ) {
			self::$pending[ $product_id ] = true;
		}
	}

	/**
	 * Mark a post dirty, when it is a product.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $post_id The post.
	 * @param mixed $post    The post object.
	 * @return void
	 */
	public function mark_post( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );

		if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
			$this->mark( (int) $post->ID );
		}
	}

	/**
	 * Mark a saved product dirty. A variation marks its parent instead.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $product The product that was saved.
	 * @return void
	 */
	public function mark_product( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$this->mark( 'variation' === $product->get_type() ? (int) $product->get_parent_id() : (int) $product->get_id() );
	}

	/**
	 * Mark a variation's parent dirty.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $variation_or_id The variation, or its post id.
	 * @return void
	 */
	public function mark_parent_of( $variation_or_id ): void {
		if ( $variation_or_id instanceof WC_Product ) {
			$this->mark( (int) $variation_or_id->get_parent_id() );
		} elseif ( is_numeric( $variation_or_id ) ) {
			$this->mark( (int) wp_get_post_parent_id( (int) $variation_or_id ) );
		}
	}

	/**
	 * Index everything marked in this request.
	 *
	 * @since 1.2.0
	 *
	 * @return int How many products were indexed or cleared.
	 */
	public function flush_pending(): int {
		if ( array() === self::$pending ) {
			return 0;
		}

		$ids           = array_keys( self::$pending );
		self::$pending = array();
		$flushed       = 0;

		try {
			if ( ! $this->settings->is_enabled() ) {
				return 0;
			}

			if ( ! $this->can_validate_now() ) {
				Plugin::instance()->readiness_rebuild()->enqueue_products( $ids );

				return 0;
			}

			$signature = Readiness_Store::signature( $this->settings );
		} catch ( Throwable $error ) {
			self::log_failure( $error );

			return 0;
		}

		foreach ( $ids as $id ) {
			try {
				$this->index( $id, $signature );
				++$flushed;
			} catch ( Throwable $error ) {
				self::log_failure( $error );
			}
		}

		return $flushed;
	}

	/**
	 * Bring one product's flag up to date.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $product_id The product.
	 * @param string $signature  The current signature.
	 * @return void
	 */
	public function index( int $product_id, string $signature ): void {
		$post = get_post( $product_id );

		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || in_array( $post->post_status, self::CLEARED_STATUSES, true ) ) {
			$this->store->clear( $product_id );

			return;
		}

		$checklist = $this->checklist();

		$checklist->flush_post( $product_id );

		$result = $checklist->validate_post( $product_id );

		if ( null !== $result ) {
			$this->store->write( $product_id, $result, $signature );
		}
	}

	/**
	 * Keep the flag off a duplicate, which has not been checked yet.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $keys Meta keys WooCommerce will not copy.
	 * @return array
	 */
	public function exclude_meta( $keys ): array {
		return array_merge( is_array( $keys ) ? $keys : array(), Readiness_Store::meta_keys() );
	}

	/**
	 * The products waiting to be indexed.
	 *
	 * @since 1.2.0
	 * @internal Test helper.
	 *
	 * @return int[]
	 */
	public static function pending(): array {
		return array_keys( self::$pending );
	}

	/**
	 * Forget everything marked so far.
	 *
	 * @since 1.2.0
	 * @internal Test helper: the suite runs many "requests" inside one PHP process.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending = array();
	}

	/**
	 * Whether this request may spend time validating.
	 *
	 * A REST request counts only for a user who can edit products: the block checkout is
	 * REST too, and a shopper's request never validates (R16).
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function can_validate_now(): bool {
		return is_admin()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( wp_is_rest_endpoint() && current_user_can( 'edit_products' ) );
	}

	/**
	 * The checklist service, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Checklist_Service
	 */
	private function checklist(): Checklist_Service {
		if ( null === $this->checklist ) {
			$this->checklist = Plugin::instance()->checklist();
		}

		return $this->checklist;
	}

	/**
	 * Record an indexing failure, after which the save has gone ahead regardless.
	 *
	 * @since 1.2.0
	 * @internal Shared with the rebuild jobs.
	 *
	 * @param Throwable $error The throwable.
	 * @return void
	 */
	public static function log_failure( Throwable $error ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Developer-facing diagnostics only; the merchant's save is never blocked by this.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'SapphireIT Publish Guard: readiness indexing failed open after %1$s: %2$s',
				get_class( $error ),
				$error->getMessage()
			)
		);
	}
}
