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
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Engine\Variation_Data;
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
		return __( 'Checks that a sale price, when one is set, is valid, lower than the regular price and has a sensible date range. On a variable product, each enabled variation on sale is checked.', 'sapphireit-publish-guard' );
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

		$family = $context->get_type_family();

		if ( Type_Family::GROUPED === $family ) {
			return $this->skip( __( 'A grouped product has no sale price of its own.', 'sapphireit-publish-guard' ) );
		}

		if ( Type_Family::VARIABLE === $family ) {
			return $this->check_variations( $context->get_variations() );
		}

		$sale = trim( $context->get_sale_price() );

		if ( '' === $sale ) {
			return $this->skip( __( 'This product is not on sale.', 'sapphireit-publish-guard' ) );
		}

		$problem = $this->problem( $sale, $context->get_regular_price(), $context->get_sale_from(), $context->get_sale_to() );

		return '' === $problem ? $this->pass() : $this->fail( $problem );
	}

	/**
	 * Every enabled variation on sale is checked against its own regular price and dates.
	 *
	 * @since 1.1.0
	 *
	 * @param Variation_Data[] $variations The enabled variations, in display order.
	 * @return Rule_Result
	 */
	private function check_variations( array $variations ): Rule_Result {
		if ( array() === $variations ) {
			return $this->skip( Variation_Fix::zero_variations_reason() );
		}

		$on_sale  = 0;
		$failing  = 0;
		$first_id = 0;

		foreach ( $variations as $variation ) {
			$sale = trim( $variation->get_sale_price() );

			if ( '' === $sale ) {
				continue;
			}

			++$on_sale;

			if ( '' === $this->problem( $sale, $variation->get_regular_price(), $variation->get_sale_from(), $variation->get_sale_to() ) ) {
				continue;
			}

			++$failing;

			if ( 0 === $first_id ) {
				$first_id = $variation->get_id();
			}
		}

		if ( 0 === $on_sale ) {
			return $this->skip( __( 'No enabled variation is on sale.', 'sapphireit-publish-guard' ) );
		}

		if ( 0 === $failing ) {
			return $this->pass();
		}

		return $this->fail(
			sprintf(
				/* translators: 1: number of variations on sale with a sale price problem, 2: number of enabled variations on sale, 3: the first such variation's id. */
				_n(
					'%1$d of %2$d variations on sale has a sale price problem (first: variation #%3$d) — check its sale price and dates in Product data → Variations.',
					'%1$d of %2$d variations on sale have a sale price problem (first: variation #%3$d) — check its sale price and dates in Product data → Variations.',
					$failing,
					'sapphireit-publish-guard'
				),
				$failing,
				$on_sale,
				$first_id
			),
			array(
				'failing'            => $failing,
				'on_sale'            => $on_sale,
				'first_variation_id' => $first_id,
			)
		)->with_fix( Variation_Fix::target() );
	}

	/**
	 * What is wrong with one sale, or '' when nothing is.
	 *
	 * Shared by the single-product path and the per-variation path, so both apply the same
	 * checks in the same order.
	 *
	 * @since 1.1.0
	 *
	 * @param string $sale    Trimmed, non-empty sale price.
	 * @param string $regular Regular price, as stored.
	 * @param string $from    Sale start, `Y-m-d H:i:s` or ''.
	 * @param string $to      Sale end, `Y-m-d H:i:s` or ''.
	 * @return string The problem's message, or '' when the sale is sound.
	 */
	private function problem( string $sale, string $regular, string $from, string $to ): string {
		if ( ! is_numeric( $sale ) ) {
			return __( 'The sale price is not a valid number — correct it in Product data → General → Sale price.', 'sapphireit-publish-guard' );
		}

		if ( (float) $sale < 0 ) {
			return __( 'The sale price is negative — correct it in Product data → General → Sale price.', 'sapphireit-publish-guard' );
		}

		$regular = trim( $regular );

		if ( is_numeric( $regular ) && (float) $sale >= (float) $regular ) {
			return __( 'The sale price is not lower than the regular price — lower it in Product data → General → Sale price.', 'sapphireit-publish-guard' );
		}

		// Both dates are normalized to `Y-m-d H:i:s`, so a string comparison is a date comparison.
		if ( '' !== $from && '' !== $to && $to < $from ) {
			return __( 'The sale ends before it starts — fix the dates in Product data → General → Schedule.', 'sapphireit-publish-guard' );
		}

		return '';
	}
}
