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
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Engine\Variation_Data;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Requires a usable regular price on the product, or on every enabled variation.
 *
 * A price of exactly zero passes. Free products are legitimate, and a rule that treated
 * them as unfinished would make the checklist wrong for an entire category of store.
 *
 * @since 1.0.0
 */
final class Price_Rule extends Abstract_Rule {

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
		return __( 'Checks that the product has a valid regular price. On a variable product, every enabled variation needs one; grouped products are skipped.', 'sapphireit-publish-guard' );
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

		$family = $context->get_type_family();

		if ( Type_Family::GROUPED === $family ) {
			return $this->skip( __( 'A grouped product has no price of its own — its products carry their own prices.', 'sapphireit-publish-guard' ) );
		}

		if ( Type_Family::VARIABLE === $family ) {
			return $this->check_variations( $context->get_variations() );
		}

		$price = trim( $context->get_regular_price() );

		if ( '' === $price ) {
			return $this->fail( __( 'The regular price is empty — enter it in Product data → General → Regular price.', 'sapphireit-publish-guard' ) );
		}

		if ( ! self::is_valid_price( $price ) ) {
			return $this->fail( __( 'The regular price is not a valid amount — enter a number of zero or more in Product data → General → Regular price.', 'sapphireit-publish-guard' ) );
		}

		// Zero is a price, not a missing price.
		return $this->pass();
	}

	/**
	 * Every enabled variation needs a valid price of its own.
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

		$failing  = 0;
		$first_id = 0;

		foreach ( $variations as $variation ) {
			if ( self::is_valid_price( trim( $variation->get_regular_price() ) ) ) {
				continue;
			}

			++$failing;

			if ( 0 === $first_id ) {
				$first_id = $variation->get_id();
			}
		}

		if ( 0 === $failing ) {
			return $this->pass();
		}

		$enabled = count( $variations );

		return $this->fail(
			sprintf(
				/* translators: 1: number of enabled variations without a valid price, 2: number of enabled variations, 3: the first such variation's id. */
				_n(
					'%1$d of %2$d enabled variations has no valid regular price (first: variation #%3$d) — enter one in Product data → Variations → Regular price.',
					'%1$d of %2$d enabled variations have no valid regular price (first: variation #%3$d) — enter one in Product data → Variations → Regular price.',
					$failing,
					'sapphireit-publish-guard'
				),
				$failing,
				$enabled,
				$first_id
			),
			array(
				'failing'            => $failing,
				'enabled'            => $enabled,
				'first_variation_id' => $first_id,
			)
		)->with_fix( Variation_Fix::target() );
	}

	/**
	 * Whether a trimmed price is a usable amount: non-empty, numeric and not negative.
	 *
	 * @since 1.1.0
	 *
	 * @param string $price Trimmed price, as stored.
	 * @return bool
	 */
	private static function is_valid_price( string $price ): bool {
		return '' !== $price && is_numeric( $price ) && (float) $price >= 0;
	}
}
