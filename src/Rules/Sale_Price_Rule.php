<?php
/**
 * The sale price rule.
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
 * Checks a sale price against the regular price and its own date window.
 *
 * Each problem is reported as a failure, so the merchant's severity decides whether it
 * blocks. The rule ships as a warning: a sale that reads oddly is a merchandising mistake,
 * not an incomplete product, and WooCommerce will happily sell the product either way.
 *
 * @since 1.0.0
 */
final class Sale_Price_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'sale_price';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Sale price', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that a sale price, when one is set, is valid, lower than the regular price and has a sensible date range.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'pricing';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 70;
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
			'selector' => '#_sale_price',
			'label'    => __( 'Review the sale price', 'sapphireit-publish-guard' ),
			'panel'    => 'general',
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

		$sale = trim( $context->get_sale_price() );

		if ( '' === $sale ) {
			return $this->skip( __( 'This product is not on sale.', 'sapphireit-publish-guard' ) );
		}

		if ( ! is_numeric( $sale ) ) {
			return $this->fail( __( 'The sale price is not a valid number.', 'product-publish-guard' ) );
		}

		if ( (float) $sale < 0 ) {
			return $this->fail( __( 'The sale price cannot be negative.', 'product-publish-guard' ) );
		}

		$regular = trim( $context->get_regular_price() );

		if ( is_numeric( $regular ) && (float) $sale >= (float) $regular ) {
			return $this->fail( __( 'The sale price is not lower than the regular price.', 'product-publish-guard' ) );
		}

		$from = $context->get_sale_from();
		$to   = $context->get_sale_to();

		// Both dates are normalized to `Y-m-d H:i:s`, so a string comparison is a date comparison.
		if ( '' !== $from && '' !== $to && $to < $from ) {
			return $this->fail( __( 'The sale end date is earlier than the sale start date.', 'product-publish-guard' ) );
		}

		return $this->pass();
	}
}
