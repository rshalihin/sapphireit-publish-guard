<?php
/**
 * Configured rule severities.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * How strictly a merchant wants a rule to be treated.
 *
 * Severity is a *setting*, not a rule property: the rule reports an outcome and the
 * merchant's severity decides whether an unmet requirement blocks publishing.
 *
 * A class of constants rather than a PHP enum: the declared floor is PHP 8.0.
 *
 * @since 1.0.0
 */
final class Severity {

	/**
	 * An unmet requirement blocks publishing (when enforcement is on).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const REQUIRED = 'required';

	/**
	 * An unmet requirement is advisory only.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const WARNING = 'warning';

	/**
	 * Every valid severity.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::REQUIRED, self::WARNING );
	}

	/**
	 * Whether a value is one of the two severities.
	 *
	 * @since 1.0.0
	 *
	 * @param string $severity Candidate severity.
	 * @return bool
	 */
	public static function is_valid( string $severity ): bool {
		return in_array( $severity, self::all(), true );
	}

	/**
	 * Translated label for the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $severity Severity to label.
	 * @return string Empty string for an unknown severity.
	 */
	public static function label( string $severity ): string {
		switch ( $severity ) {
			case self::REQUIRED:
				return __( 'Required', 'sapphireit-publish-guard' );
			case self::WARNING:
				return __( 'Warning', 'sapphireit-publish-guard' );
			default:
				return '';
		}
	}
}
