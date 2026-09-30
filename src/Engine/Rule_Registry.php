<?php
/**
 * The set of rules known to this request.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Holds rules keyed by identifier and hands them out in a deterministic order.
 *
 * Ordering is by priority, then by identifier, so the checklist UI and the test
 * assertions are stable however the rules were registered.
 *
 * @since 1.0.0
 */
final class Rule_Registry {

	/**
	 * Registered rules, keyed by identifier.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $rules = array();

	/**
	 * Cached sorted rule list, or null when it needs rebuilding.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private ?array $sorted = null;

	/**
	 * Register a rule.
	 *
	 * Duplicate identifiers are rejected rather than overwritten: silently replacing a
	 * rule would make the settings for the original identifier apply to different logic.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Interface $rule The rule to register.
	 * @return bool True when the rule was registered.
	 */
	public function register( Rule_Interface $rule ): bool {
		$id = $rule->get_id();

		if ( '' === $id ) {
			$this->notify_misuse( __METHOD__, __( 'A rule must have a non-empty id.', 'sapphireit-publish-guard' ) );

			return false;
		}

		if ( isset( $this->rules[ $id ] ) ) {
			$this->notify_misuse(
				__METHOD__,
				sprintf(
					/* translators: %s is the duplicate rule identifier. */
					__( 'A rule with the id "%s" is already registered.', 'sapphireit-publish-guard' ),
					$id
				)
			);

			return false;
		}

		$this->rules[ $id ] = $rule;
		$this->sorted       = null;

		return true;
	}

	/**
	 * Whether a rule identifier is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Rule identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->rules[ $id ] );
	}

	/**
	 * Get one rule by identifier.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Rule identifier.
	 * @return Rule_Interface|null
	 */
	public function get( string $id ): ?Rule_Interface {
		return $this->rules[ $id ] ?? null;
	}

	/**
	 * Every registered rule, sorted by priority and then by identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->sorted ) {
			$sorted = array_values( $this->rules );

			usort(
				$sorted,
				static function ( Rule_Interface $a, Rule_Interface $b ) {
					$by_priority = $a->get_priority() <=> $b->get_priority();

					return 0 !== $by_priority ? $by_priority : strcmp( $a->get_id(), $b->get_id() );
				}
			);

			$this->sorted = $sorted;
		}

		return $this->sorted;
	}

	/**
	 * The rules that should actually run for this product.
	 *
	 * A rule runs when the merchant has it enabled and it supports the product.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings        $settings The merchant's configuration.
	 * @param Product_Context $context  The product being validated.
	 * @return array
	 */
	public function get_active( Settings $settings, Product_Context $context ): array {
		$active = array();

		foreach ( $this->all() as $rule ) {
			if ( ! $settings->rule_is_enabled( $rule->get_id() ) ) {
				continue;
			}

			if ( ! $rule->supports( $context ) ) {
				continue;
			}

			$active[] = $rule;
		}

		return $active;
	}

	/**
	 * How many rules are registered.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->rules );
	}

	/**
	 * Report a registration mistake to the developer, only when debugging.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method  Method that was misused.
	 * @param string $message Translated explanation.
	 * @return void
	 */
	private function notify_misuse( string $method, string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		_doing_it_wrong( esc_html( $method ), esc_html( $message ), '1.0.0' );
	}
}
