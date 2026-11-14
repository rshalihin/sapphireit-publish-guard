<?php
/**
 * The contract of a rule whose owner, not the settings screen, decides when it runs.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * A rule an add-on manages itself.
 *
 * An ordinary rule is switched on and given a severity on the settings screen. A managed
 * rule answers both questions itself, for each product. For a managed rule the plugin
 * guarantees three things:
 *
 * - It runs whenever `supports()` returns true. `Settings::rule_is_enabled()` is not asked.
 * - Its result carries `get_severity_for()` when that is a valid `Severity`, otherwise
 *   `get_default_severity()` when that is valid, otherwise `required`.
 * - It is left out of the settings: it is never stored, sanitized, hashed or listed on the
 *   settings screen.
 *
 * `get_severity_for()` runs inside the validator's isolation, like `check()`: a rule that
 * throws there leaves no row and breaks nothing else.
 *
 * @since 1.3.0
 * @api
 */
interface Managed_Rule_Interface extends Rule_Interface {

	/**
	 * The severity of this rule for one product.
	 *
	 * @since 1.3.0
	 * @api
	 *
	 * @param Product_Context $context The product being validated.
	 * @return string One of the Severity constants.
	 */
	public function get_severity_for( Product_Context $context ): string;
}
