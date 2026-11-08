<?php
/**
 * Record of publishes that went past failing required checks.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Save_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Who published past which required rule, and when.
 *
 * A capped, self-pruning, non-autoloaded option, stored oldest first. It holds identifiers
 * and rule ids only, never product text. Two overrides in the same instant can lose one
 * write, because the option is read and rewritten without a lock: a documented limitation
 * of an option-backed log.
 *
 * @since 1.2.0
 */
final class Override_Log {

	/**
	 * The option holding the log.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const OPTION = 'sit_wcpg_override_log';

	/**
	 * The most entries kept.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * The oldest an entry may be, in seconds, when the log is next written.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_AGE = 90 * DAY_IN_SECONDS;

	/**
	 * The most rule ids kept on one entry.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_RULES = 50;

	/**
	 * Listen for overrides and user deletions.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'sit_wcpg_publish_overridden', array( $this, 'record' ), 10, 4 );
		add_action( 'deleted_user', array( $this, 'forget_user' ), 10, 1 );
	}

	/**
	 * Add an override to the log.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $product_id The product.
	 * @param mixed $user_id    The user who overrode.
	 * @param mixed $rule_ids   The required rules that failed.
	 * @param mixed $save_path  One of the Save_Path constants.
	 * @return void
	 */
	public function record( $product_id, $user_id, $rule_ids, $save_path ): void {
		$entry = self::normalize(
			array(
				'time'       => time(),
				'user_id'    => $user_id,
				'product_id' => $product_id,
				'rule_ids'   => $rule_ids,
				'save_path'  => $save_path,
			)
		);

		if ( null === $entry ) {
			return;
		}

		$entries   = $this->stored();
		$entries[] = $entry;

		$this->save( $entries );
	}

	/**
	 * The log, normalized and newest first.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{time: int, user_id: int, product_id: int, rule_ids: string[], save_path: string}>
	 */
	public function entries(): array {
		return array_reverse( $this->stored() );
	}

	/**
	 * Anonymize a deleted user's entries, which stay in the log under user 0.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $user_id The deleted user.
	 * @return void
	 */
	public function forget_user( $user_id ): void {
		$user_id = self::positive_int( $user_id );

		if ( 0 === $user_id ) {
			return;
		}

		$entries = $this->stored();
		$changed = false;

		foreach ( $entries as $index => $entry ) {
			if ( $user_id === $entry['user_id'] ) {
				$entries[ $index ]['user_id'] = 0;
				$changed                      = true;
			}
		}

		if ( $changed ) {
			$this->save( $entries );
		}
	}

	/**
	 * The stored entries, normalized and oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return array_values( array_filter( array_map( array( __CLASS__, 'normalize' ), is_array( $stored ) ? $stored : array() ) ) );
	}

	/**
	 * Drop what has aged out or overflowed, and write the log.
	 *
	 * @since 1.2.0
	 *
	 * @param array $entries Normalized entries, oldest first.
	 * @return void
	 */
	private function save( array $entries ): void {
		$oldest  = time() - self::MAX_AGE;
		$current = array_filter(
			$entries,
			static function ( array $entry ) use ( $oldest ): bool {
				return $entry['time'] >= $oldest;
			}
		);

		update_option( self::OPTION, array_slice( array_values( $current ), -1 * self::MAX_ENTRIES ), false );
	}

	/**
	 * One entry in its stored shape.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $entry A candidate entry.
	 * @return array|null Null when the entry has no usable product id or time.
	 */
	private static function normalize( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$time       = self::positive_int( $entry['time'] ?? 0 );
		$product_id = self::positive_int( $entry['product_id'] ?? 0 );

		if ( 0 === $time || 0 === $product_id ) {
			return null;
		}

		$save_path = $entry['save_path'] ?? '';

		return array(
			'time'       => $time,
			'user_id'    => self::positive_int( $entry['user_id'] ?? 0 ),
			'product_id' => $product_id,
			'rule_ids'   => self::rule_ids( $entry['rule_ids'] ?? array() ),
			'save_path'  => is_string( $save_path ) && Save_Path::is_valid( $save_path ) ? $save_path : Save_Path::NONE,
		);
	}

	/**
	 * A numeric value as a positive integer.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @return int Zero for anything that is not a positive number.
	 */
	private static function positive_int( $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Rule ids as sanitized keys: non-empty, unique and capped.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $rule_ids Raw rule ids.
	 * @return string[]
	 */
	private static function rule_ids( $rule_ids ): array {
		$sanitized = array();

		foreach ( is_array( $rule_ids ) ? $rule_ids : array() as $rule_id ) {
			if ( is_scalar( $rule_id ) ) {
				$sanitized[] = sanitize_key( (string) $rule_id );
			}
		}

		return array_slice( array_values( array_unique( array_filter( $sanitized, 'strlen' ) ) ), 0, self::MAX_RULES );
	}
}
