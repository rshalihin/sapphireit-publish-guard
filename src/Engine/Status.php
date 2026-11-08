<?php
/**
 * Rule result statuses.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The four statuses a checklist row can have.
 *
 * A class of constants rather than a PHP enum: enums stay out of the `@api` types.
 *
 * @since 1.0.0
 * @api
 */
final class Status {

	/**
	 * The rule's requirement is met.
	 *
	 * @since 1.0.0
	 * @api
	 * @var string
	 */
	public const PASS = 'pass';

	/**
	 * The rule's requirement is not met, but publishing is not blocked by it.
	 *
	 * @since 1.0.0
	 * @api
	 * @var string
	 */
	public const WARNING = 'warning';

	/**
	 * The rule's requirement is not met and the rule is configured as required.
	 *
	 * @since 1.0.0
	 * @api
	 * @var string
	 */
	public const FAIL = 'fail';

	/**
	 * The rule does not apply to this product, so it is excluded from the counts.
	 *
	 * @since 1.0.0
	 * @api
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Sort weights, worst first.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	private const WEIGHTS = array(
		self::FAIL    => 0,
		self::WARNING => 10,
		self::PASS    => 20,
		self::SKIPPED => 30,
	);

	/**
	 * Every valid status.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PASS, self::WARNING, self::FAIL, self::SKIPPED );
	}

	/**
	 * Whether a value is one of the four statuses.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return isset( self::WEIGHTS[ $status ] );
	}

	/**
	 * Sort weight for a status: fail < warning < pass < skipped.
	 *
	 * Used wherever rows are ordered by severity rather than by rule priority.
	 * An unknown status sorts last so a bad value can never hide a real failure.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $status Status to weigh.
	 * @return int
	 */
	public static function weight( string $status ): int {
		return self::WEIGHTS[ $status ] ?? 99;
	}
}
