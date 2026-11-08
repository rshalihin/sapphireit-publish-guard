<?php
/**
 * Whether a product can be published right now, in one decision.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Support;

use ProductPublishGuard\Engine\Validation_Result;

defined( 'ABSPATH' ) || exit;

/**
 * What the merchant reads before clicking Publish.
 *
 * PHP decides and JavaScript only words the decision, so the statement can never disagree
 * with what the guard will do. The decision holds a kind and two integers, and nothing a
 * product supplied.
 *
 * @since 1.2.0
 */
final class Preflight {

	/**
	 * Nothing stands in the way.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_READY = 'ready';

	/**
	 * Publishable, with advisory findings.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_WARNINGS = 'warnings';

	/**
	 * A publish would be refused.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_BLOCKED = 'blocked';

	/**
	 * A publish would be refused, but this user may go past it.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_OVERRIDE = 'override';

	/**
	 * Required checks fail, but this store does not block publishing.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_ADVISORY = 'advisory';

	/**
	 * The product is already published.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const KIND_LIVE = 'live';

	/**
	 * Decide what to tell the merchant.
	 *
	 * @since 1.2.0
	 *
	 * @param Validation_Result $result            The product's result.
	 * @param bool              $blocks_publishing Whether the store blocks publishing.
	 * @param bool              $can_override      Whether this user may publish past a failure.
	 * @param string            $post_status       The product's stored status.
	 * @return array{kind: string, required: int, warnings: int}
	 */
	public static function for_result( Validation_Result $result, bool $blocks_publishing, bool $can_override, string $post_status ): array {
		$counts   = $result->get_counts();
		$required = count( $result->get_required_failures() );
		$warnings = (int) $counts['failed'] + (int) $counts['warnings'] - $required;

		return array(
			'kind'     => self::kind( $required, $warnings, $blocks_publishing, $can_override, $post_status ),
			'required' => $required,
			'warnings' => $warnings,
		);
	}

	/**
	 * The statement for a decision.
	 *
	 * @since 1.2.0
	 *
	 * @param array $preflight A decision from `for_result()`.
	 * @return string Empty for an unknown kind.
	 */
	public static function message( array $preflight ): string {
		$required = (int) ( $preflight['required'] ?? 0 );
		$warnings = (int) ( $preflight['warnings'] ?? 0 );

		switch ( $preflight['kind'] ?? '' ) {
			case self::KIND_READY:
				return __( 'This product can be published now.', 'sapphireit-publish-guard' );

			case self::KIND_WARNINGS:
				return sprintf(
					/* translators: %d: number of advisory warnings. */
					_n(
						"This product can be published now. %d warning is advisory and won't stop it.",
						"This product can be published now. %d warnings are advisory and won't stop it.",
						$warnings,
						'sapphireit-publish-guard'
					),
					$warnings
				);

			case self::KIND_BLOCKED:
				return sprintf(
					/* translators: %d: number of required checks that fail. */
					_n(
						"This product can't be published yet — %d required check fails. Publishing now saves it as a draft.",
						"This product can't be published yet — %d required checks fail. Publishing now saves it as a draft.",
						$required,
						'sapphireit-publish-guard'
					),
					$required
				);

			case self::KIND_OVERRIDE:
				return sprintf(
					/* translators: %d: number of required checks that fail. */
					_n(
						'%d required check fails. You can publish anyway, and the override will be recorded.',
						'%d required checks fail. You can publish anyway, and the override will be recorded.',
						$required,
						'sapphireit-publish-guard'
					),
					$required
				);

			case self::KIND_ADVISORY:
				return sprintf(
					/* translators: %d: number of required checks that fail. */
					_n(
						"%d required check fails, but this store doesn't block publishing, so it can still go live.",
						"%d required checks fail, but this store doesn't block publishing, so it can still go live.",
						$required,
						'sapphireit-publish-guard'
					),
					$required
				);

			case self::KIND_LIVE:
				if ( 0 === $required ) {
					return __( 'This product is live and passes every required check.', 'sapphireit-publish-guard' );
				}

				return sprintf(
					/* translators: %d: number of required checks that fail. */
					_n(
						'This product is live, but %d required check fails. Publish Guard never takes a live product offline.',
						'This product is live, but %d required checks fail. Publish Guard never takes a live product offline.',
						$required,
						'sapphireit-publish-guard'
					),
					$required
				);

			default:
				return '';
		}
	}

	/**
	 * The first kind that applies.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $required          Required failures.
	 * @param int    $warnings          Advisory findings.
	 * @param bool   $blocks_publishing Whether the store blocks publishing.
	 * @param bool   $can_override      Whether this user may publish past a failure.
	 * @param string $post_status       The product's stored status.
	 * @return string One of the KIND_* constants.
	 */
	private static function kind( int $required, int $warnings, bool $blocks_publishing, bool $can_override, string $post_status ): string {
		if ( 'publish' === $post_status ) {
			return self::KIND_LIVE;
		}

		if ( $required > 0 ) {
			if ( ! $blocks_publishing ) {
				return self::KIND_ADVISORY;
			}

			return $can_override ? self::KIND_OVERRIDE : self::KIND_BLOCKED;
		}

		return $warnings > 0 ? self::KIND_WARNINGS : self::KIND_READY;
	}
}
