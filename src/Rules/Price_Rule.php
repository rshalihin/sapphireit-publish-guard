<?php
/**
 * The regular price rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rules;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Requires a usable regular price on the product types that carry one.
 *
 * A price of exactly zero passes. Free products are legitimate, and a rule that treated
 * them as unfinished would make the checklist wrong for an entire category of store.
 *
 * @since 1.0.0
 */
final class Price_Rule extends Abstract_Rule {

	/**
	 * Product types whose price does not live on the parent product.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const UNPRICED_TYPES = array( 'variable', 'grouped' );

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'price';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Regular price', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a valid regular price. Variable and grouped products are skipped.', 'sapphireit-publish-guard' );
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
		return 60;
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
			'selector' => '#_regular_price',
			'label'    => __( 'Set the regular price', 'sapphireit-publish-guard' ),
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

		$type = $context->get_product_type();

		if ( in_array( $type, self::UNPRICED_TYPES, true ) ) {
			return $this->skip( $this->skip_reason( $type ) );
		}

		$price = trim( $context->get_regular_price() );

		if ( '' === $price ) {
			return $this->fail( __( 'Set a regular price.', 'sapphireit-publish-guard' ) );
		}

		if ( ! is_numeric( $price ) || (float) $price < 0 ) {
			return $this->fail( __( 'The regular price is not a valid number.', 'sapphireit-publish-guard' ) );
		}

		// Zero is a price, not a missing price.
		return $this->pass();
	}

	/**
	 * Why this product type has no price of its own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type WooCommerce product type.
	 * @return string
	 */
	private function skip_reason( string $type ): string {
		if ( 'grouped' === $type ) {
			return __( 'A grouped product takes its price from the products it contains.', 'sapphireit-publish-guard' );
		}

		return __( 'Variable product pricing is validated per variation, which is not part of this version.', 'sapphireit-publish-guard' );
	}
}
