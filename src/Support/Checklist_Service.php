<?php
/**
 * The public facade for checklist results.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Settings\Settings;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Every consumer outside the engine asks this class for a result, never the validator.
 *
 * It owns the two memoization layers described in coding-plan.md section 11.3:
 *
 * 1. A static per-request array, so the meta box, the asset payload and any other caller
 *    in the same request validate a product exactly once.
 * 2. The object cache, keyed on the product's modification time and the settings hash, so
 *    a change to either produces a different key rather than an invalidation path that can
 *    be got wrong. Without a persistent object cache this degrades to layer 1.
 *
 * Validations that carry overrides — the live editor checks and the publish guard — are
 * never memoized and never cached: they describe in-flight data that exists only for the
 * length of one request.
 *
 * @since 1.0.0
 */
final class Checklist_Service {

	/**
	 * Object-cache group.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CACHE_GROUP = 'sit_wcpg';

	/**
	 * Object-cache lifetime in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const CACHE_TTL = 300;

	/**
	 * Cache-key schema version, bumped when the stored shape changes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CACHE_VERSION = 'v1';

	/**
	 * Results already computed in this request, keyed by product id and settings hash.
	 *
	 * Static rather than per-instance so two services built from the same container still
	 * share the work. Nothing outside this class writes to it.
	 *
	 * @since 1.0.0
	 * @var array<string, Validation_Result>
	 */
	private static array $memo = array();

	/**
	 * The engine this service runs.
	 *
	 * @since 1.0.0
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * The merchant's configuration, read for the cache key.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the service.
	 *
	 * @since 1.0.0
	 *
	 * @param Validator $validator The rule engine.
	 * @param Settings  $settings  The merchant's configuration.
	 */
	public function __construct( Validator $validator, Settings $settings ) {
		$this->validator = $validator;
		$this->settings  = $settings;
	}

	/**
	 * Validate a stored product by post id.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id   Product post identifier.
	 * @param array $overrides Unsaved field values in `Product_Context` override shape. A
	 *                         non-empty array disables both memoization layers.
	 * @return Validation_Result|null Null when the id does not resolve to a product.
	 */
	public function validate_post( int $post_id, array $overrides = array() ): ?Validation_Result {
		$post_id = absint( $post_id );

		if ( 0 === $post_id ) {
			return null;
		}

		$is_cacheable = array() === $overrides;
		$memo_key     = '';

		if ( $is_cacheable ) {
			$memo_key = $this->memo_key( $post_id );

			if ( isset( self::$memo[ $memo_key ] ) ) {
				return self::$memo[ $memo_key ];
			}

			$cached = wp_cache_get( $this->cache_key( $post_id ), self::CACHE_GROUP );

			if ( $cached instanceof Validation_Result ) {
				self::$memo[ $memo_key ] = $cached;

				return $cached;
			}
		}

		$product = $this->get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$result = $this->validate_product( $product, $overrides );

		if ( $is_cacheable ) {
			self::$memo[ $memo_key ] = $result;

			wp_cache_set( $this->cache_key( $post_id ), $result, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Validate a product object the caller already holds.
	 *
	 * Neither memoized nor cached: the caller owns the object and may have mutated it, so
	 * this class cannot reason about how fresh it is.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Product $product   The product to validate.
	 * @param array      $overrides Unsaved field values in `Product_Context` override shape.
	 * @return Validation_Result
	 */
	public function validate_product( WC_Product $product, array $overrides = array() ): Validation_Result {
		$context = array() === $overrides
			? Product_Context::from_product( $product )
			: Product_Context::from_product_with_overrides( $product, $overrides );

		return $this->validator->validate( $context );
	}

	/**
	 * Validate a context the caller has already assembled.
	 *
	 * The publish guard's entry point: its context describes a write that is still in
	 * flight, and may have no stored product behind it at all, so it is never memoized
	 * or cached.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_Context $context The product as it is about to be saved.
	 * @return Validation_Result
	 */
	public function validate_context( Product_Context $context ): Validation_Result {
		return $this->validator->validate( $context );
	}

	/**
	 * The compact readiness summary for one product.
	 *
	 * This is the **only** read path for consumers outside the editor — the products list
	 * column above all (coding-plan.md section 14.4). A persisted readiness store can be
	 * introduced behind this one method without touching a single caller.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post identifier.
	 * @return array|null Null when the id does not resolve to a product. Otherwise
	 *                    `product_id` int, `status` string, `is_ready` bool, `counts` array
	 *                    and `summary_label` string.
	 */
	public function get_summary_for_post_id( int $post_id ): ?array {
		$result = $this->validate_post( $post_id );

		if ( null === $result ) {
			return null;
		}

		$counts = $result->get_counts();

		return array(
			'product_id'    => $result->get_product_id(),
			'status'        => self::worst_status( $counts ),
			'is_ready'      => $result->is_ready(),
			'counts'        => $counts,
			'summary_label' => $result->get_summary_label(),
		);
	}

	/**
	 * Drop the cached result for one product.
	 *
	 * Best-effort by design. The object-cache key embeds the product's modification time
	 * and the settings hash, so a stale entry can never be served even if this misses —
	 * the key simply stops being asked for. The method exists so a caller that has just
	 * written a product in this request does not read its own pre-write memo back.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post identifier.
	 * @return void
	 */
	public function flush_post( int $post_id ): void {
		$post_id = absint( $post_id );

		if ( 0 === $post_id ) {
			return;
		}

		unset( self::$memo[ $this->memo_key( $post_id ) ] );

		wp_cache_delete( $this->cache_key( $post_id ), self::CACHE_GROUP );
	}

	/**
	 * Empty the per-request memo.
	 *
	 * Used by the test suite, which runs many "requests" inside one PHP process.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function flush_memo(): void {
		self::$memo = array();
	}

	/**
	 * The worst status present in a set of counts.
	 *
	 * @since 1.0.0
	 *
	 * @param array $counts Counts from a `Validation_Result`.
	 * @return string One of the Status constants.
	 */
	private static function worst_status( array $counts ): string {
		if ( (int) ( $counts['failed'] ?? 0 ) > 0 ) {
			return Status::FAIL;
		}

		if ( (int) ( $counts['warnings'] ?? 0 ) > 0 ) {
			return Status::WARNING;
		}

		if ( (int) ( $counts['evaluated'] ?? 0 ) > 0 ) {
			return Status::PASS;
		}

		return Status::SKIPPED;
	}

	/**
	 * Resolve a post id to a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post identifier.
	 * @return WC_Product|null
	 */
	private function get_product( int $post_id ): ?WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $post_id );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Per-request memo key.
	 *
	 * The settings hash is part of it because the settings screen can save new values in
	 * the same request that later renders a checklist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post identifier.
	 * @return string
	 */
	private function memo_key( int $post_id ): string {
		return $post_id . ':' . $this->settings->get_hash();
	}

	/**
	 * Object-cache key.
	 *
	 * Four parts, each of which must change the key when it changes: the schema version,
	 * the product, its modification time and the settings hash. The locale is included too
	 * — rule labels and messages are translated before they are stored, so an entry written
	 * for one administrator's language must not be served to another's.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post identifier.
	 * @return string
	 */
	private function cache_key( int $post_id ): string {
		$post     = get_post( $post_id );
		$modified = $post instanceof WP_Post ? $post->post_modified_gmt : '';

		return implode(
			':',
			array(
				self::CACHE_VERSION,
				(string) $post_id,
				(string) $modified,
				$this->settings->get_hash(),
				determine_locale(),
			)
		);
	}
}
