<?php
/**
 * The contract every checklist rule implements.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One checklist rule.
 *
 * Rules are pure functions of a Product_Context plus the settings. They must not read
 * WordPress or WooCommerce data directly — Product_Context is the only data boundary —
 * and they must not depend on each other or on execution order.
 *
 * @since 1.0.0
 * @api
 */
interface Rule_Interface {

	/**
	 * Stable, snake_case identifier. Used as the settings key and in the JSON payload.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Short translated label shown in the checklist row.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Translated help text shown on the settings screen.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Rule group: content, media, pricing, organization or inventory.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string
	 */
	public function get_group(): string;

	/**
	 * Display order, in steps of ten. Display order only: rules never depend on it.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return int
	 */
	public function get_priority(): int;

	/**
	 * Severity applied until the merchant changes it.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string One of the Severity constants.
	 */
	public function get_default_severity(): string;

	/**
	 * Whether the rule is enabled on a site that has never saved the settings.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool;

	/**
	 * Whether the rule applies to this product at all.
	 *
	 * An unsupported rule is not run and does not appear in the checklist.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param Product_Context $context The product being validated.
	 * @return bool
	 */
	public function supports( Product_Context $context ): bool;

	/**
	 * Where the merchant should go to fix a failure.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return array Keys `selector`, `label` and `panel`, or an empty array.
	 */
	public function get_fix_target(): array;

	/**
	 * Evaluate the rule.
	 *
	 * Must return a Rule_Result and must never throw for ordinary product data; the
	 * validator catches throwables, but a rule that throws is a bug.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result;
}
