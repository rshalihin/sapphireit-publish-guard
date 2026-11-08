<?php
/**
 * The fix target and skip reason shared by the variation-aware rules.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Where a per-variation failure sends the merchant, and what an empty variable product says.
 *
 * Four rules point at the Variations tab and share one skip reason, so both are defined
 * here once rather than retyped in each rule.
 *
 * @since 1.1.0
 */
final class Variation_Fix {

	/**
	 * Not instantiable: the class holds two static operations.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {}

	/**
	 * The fix target for a failure that lives on a variation.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public static function target(): array {
		return array(
			'selector' => '#variable_product_options',
			'label'    => __( 'Open the Variations tab', 'sapphireit-publish-guard' ),
			'panel'    => 'variations',
		);
	}

	/**
	 * Why a variation-aware rule skips a variable product that has no enabled variations.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public static function zero_variations_reason(): string {
		return __( 'This variable product has no enabled variations yet — see the "Variations and grouped products" check.', 'sapphireit-publish-guard' );
	}
}
