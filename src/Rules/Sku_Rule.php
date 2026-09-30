<?php
/**
 * The SKU rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rules;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Asks for a SKU.
 *
 * Presence only. Uniqueness is WooCommerce's own job and it already enforces it on save,
 * so repeating that check here would only produce a second opinion about the same fact.
 *
 * @since 1.0.0
 */
final class Sku_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'sku';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'SKU', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a SKU.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'inventory';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 100;
	}

	/**
	 * Severity this rule ships with.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_default_severity(): string {
		return Severity::WARNING;
	}

	/**
	 * Where to fix a failure.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fix_target(): array {
		return array(
			'selector' => '#_sku',
			'label'    => __( 'Add a SKU', 'sapphireit-publish-guard' ),
			'panel'    => 'inventory',
		);
	}

	/**
	 * Evaluate the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		if ( '' === trim( $context->get_sku() ) ) {
			return $this->fail( __( 'Add a SKU for this product.', 'sapphireit-publish-guard' ) );
		}

		return $this->pass();
	}
}
