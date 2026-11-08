<?php
/**
 * The stored readiness flag of a product.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What the last validation of a product said, in a form a query can filter on.
 *
 * Three protected meta keys, written with `update_post_meta()`, so neither `post_modified`
 * nor the checklist cache key moves when a flag is written. The flag is a consumer of
 * validation results and never a source of them: the editor, the guard and the readiness
 * column all keep validating live.
 *
 * @since 1.2.0
 */
final class Readiness_Store {

	/**
	 * Meta key of the status, one of the Status constants. Queryable.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const META_STATUS = '_sit_wcpg_readiness';

	/**
	 * Meta key of the signature the status was computed under. Queryable.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const META_SIGNATURE = '_sit_wcpg_readiness_sig';

	/**
	 * Meta key of the array of `required_failures` and `checked_at`.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const META_DATA = '_sit_wcpg_readiness_data';

	/**
	 * Filter value: every required check passes and nothing warns.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const FILTER_READY = 'ready';

	/**
	 * Filter value: no required failure, but at least one warning.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const FILTER_WARNING = 'warning';

	/**
	 * Filter value: at least one required check fails.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const FILTER_FAIL = 'fail';

	/**
	 * Filter value: the product has no flag yet.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const FILTER_UNCHECKED = 'unchecked';

	/**
	 * What every stored flag is valid for.
	 *
	 * A settings change, a newly registered third-party rule or a plugin release each
	 * produce a different signature, which makes every stored flag stale at once.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings $settings The merchant's configuration.
	 * @return string 32 hexadecimal characters.
	 */
	public static function signature( Settings $settings ): string {
		return md5( $settings->get_hash() . '|' . Checklist_Service::CACHE_VERSION . '|' . SIT_WCPG_VERSION );
	}

	/**
	 * The status a validation result is stored under.
	 *
	 * @since 1.2.0
	 *
	 * @param Validation_Result $result The result.
	 * @return string One of the Status constants.
	 */
	public static function status_for( Validation_Result $result ): string {
		$counts = $result->get_counts();

		if ( array() !== $result->get_required_failures() ) {
			return Status::FAIL;
		}

		if ( (int) $counts['failed'] + (int) $counts['warnings'] > 0 ) {
			return Status::WARNING;
		}

		return 0 === (int) $counts['evaluated'] ? Status::SKIPPED : Status::PASS;
	}

	/**
	 * Every filter value, in display order.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	public static function filters(): array {
		return array( self::FILTER_READY, self::FILTER_WARNING, self::FILTER_FAIL, self::FILTER_UNCHECKED );
	}

	/**
	 * The meta-query clause for a filter value.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filter One of the FILTER_* values.
	 * @return array Empty for an unknown filter.
	 */
	public static function meta_query( string $filter ): array {
		switch ( $filter ) {
			case self::FILTER_READY:
				return array(
					'key'     => self::META_STATUS,
					'value'   => array( Status::PASS, Status::SKIPPED ),
					'compare' => 'IN',
				);
			case self::FILTER_WARNING:
				return array(
					'key'     => self::META_STATUS,
					'value'   => Status::WARNING,
					'compare' => '=',
				);
			case self::FILTER_FAIL:
				return array(
					'key'     => self::META_STATUS,
					'value'   => Status::FAIL,
					'compare' => '=',
				);
			case self::FILTER_UNCHECKED:
				return array(
					'key'     => self::META_STATUS,
					'compare' => 'NOT EXISTS',
				);
			default:
				return array();
		}
	}

	/**
	 * The three meta keys.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array( self::META_STATUS, self::META_SIGNATURE, self::META_DATA );
	}

	/**
	 * Store a product's flag.
	 *
	 * @since 1.2.0
	 *
	 * @param int               $product_id The product.
	 * @param Validation_Result $result     Its validation result.
	 * @param string            $signature  The signature the result was computed under.
	 * @return void
	 */
	public function write( int $product_id, Validation_Result $result, string $signature ): void {
		update_post_meta( $product_id, self::META_STATUS, self::status_for( $result ) );
		update_post_meta( $product_id, self::META_SIGNATURE, $signature );
		update_post_meta(
			$product_id,
			self::META_DATA,
			array(
				'required_failures' => array_values( $result->get_required_failures() ),
				'checked_at'        => time(),
			)
		);
	}

	/**
	 * Remove a product's flag.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id The product.
	 * @return void
	 */
	public function clear( int $product_id ): void {
		foreach ( self::meta_keys() as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}

	/**
	 * A product's flag, normalized.
	 *
	 * Post meta can be edited by anything, so nothing here is taken on trust.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id The product.
	 * @return array{status: string, signature: string, required_failures: string[], checked_at: int}|null
	 *               Null when the product has no valid flag.
	 */
	public function read( int $product_id ): ?array {
		$status = get_post_meta( $product_id, self::META_STATUS, true );

		if ( ! is_string( $status ) || ! Status::is_valid( $status ) ) {
			return null;
		}

		$signature = get_post_meta( $product_id, self::META_SIGNATURE, true );
		$data      = get_post_meta( $product_id, self::META_DATA, true );
		$data      = is_array( $data ) ? $data : array();

		return array(
			'status'            => $status,
			'signature'         => is_string( $signature ) ? $signature : '',
			'required_failures' => self::rule_ids( $data['required_failures'] ?? array() ),
			'checked_at'        => isset( $data['checked_at'] ) && is_numeric( $data['checked_at'] ) ? max( 0, (int) $data['checked_at'] ) : 0,
		);
	}

	/**
	 * Rule ids as sanitized keys: non-empty and unique.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $rule_ids Stored value.
	 * @return string[]
	 */
	private static function rule_ids( $rule_ids ): array {
		$sanitized = array();

		foreach ( is_array( $rule_ids ) ? $rule_ids : array() as $rule_id ) {
			if ( is_scalar( $rule_id ) ) {
				$sanitized[] = sanitize_key( (string) $rule_id );
			}
		}

		return array_values( array_unique( array_filter( $sanitized, 'strlen' ) ) );
	}
}
