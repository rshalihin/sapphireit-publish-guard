<?php
/**
 * Shared defaults and result factories for rules.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Base class implementing everything a rule can reasonably default.
 *
 * A concrete rule therefore only has to provide `get_id()`, `get_label()` and `check()`,
 * plus whatever metadata differs from the defaults below. The four protected factories
 * stamp the rule identity onto each result so no rule repeats it.
 *
 * @since 1.0.0
 * @api
 */
abstract class Abstract_Rule implements Rule_Interface {

	/**
	 * Stable, snake_case identifier.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Short translated label shown in the checklist row.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	abstract public function get_label(): string;

	/**
	 * Translated help text shown on the settings screen.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'content';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 50;
	}

	/**
	 * Severity applied until the merchant changes it.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_default_severity(): string {
		return Severity::REQUIRED;
	}

	/**
	 * Whether the rule is enabled before the settings are ever saved.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool {
		return true;
	}

	/**
	 * Whether the rule applies to this product.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param Product_Context $context The product being validated.
	 * @return bool
	 */
	public function supports( Product_Context $context ): bool {
		return true;
	}

	/**
	 * Where the merchant should go to fix a failure.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return array
	 */
	public function get_fix_target(): array {
		return array();
	}

	/**
	 * Build a passing result for this rule.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $message Optional translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @return Rule_Result
	 */
	protected function pass( string $message = '', array $data = array() ): Rule_Result {
		return Rule_Result::pass( $this->get_id(), $this->get_label(), $this->get_group(), $message, $data, $this->get_fix_target() );
	}

	/**
	 * Build an unmet-requirement result for this rule.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $message Translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @return Rule_Result
	 */
	protected function fail( string $message, array $data = array() ): Rule_Result {
		return Rule_Result::fail( $this->get_id(), $this->get_label(), $this->get_group(), $message, $data, $this->get_fix_target() );
	}

	/**
	 * Build an advisory result for this rule. Never escalated to a failure.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $message Translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @return Rule_Result
	 */
	protected function warn( string $message, array $data = array() ): Rule_Result {
		return Rule_Result::warn( $this->get_id(), $this->get_label(), $this->get_group(), $message, $data, $this->get_fix_target() );
	}

	/**
	 * Build a not-applicable result for this rule.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param string $message Translated reason.
	 * @param array  $data    Optional numeric and boolean context.
	 * @return Rule_Result
	 */
	protected function skip( string $message = '', array $data = array() ): Rule_Result {
		return Rule_Result::skip( $this->get_id(), $this->get_label(), $this->get_group(), $message, $data, $this->get_fix_target() );
	}
}
