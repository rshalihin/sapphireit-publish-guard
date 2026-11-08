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
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Engine\Variation_Data;
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
		return __( 'Checks that the product has a SKU. On a variable product, a SKU on the product covers its variations; otherwise every enabled variation needs its own.', 'sapphireit-publish-guard' );
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

		if ( '' !== trim( $context->get_sku() ) ) {
			// On a variable product, WooCommerce passes the parent SKU down to every variation without one.
			return $this->pass();
		}

		if ( Type_Family::VARIABLE === $context->get_type_family() ) {
			return $this->check_variations( $context->get_variations() );
		}

		return $this->fail( __( 'The product has no SKU — enter one in Product data → Inventory → SKU.', 'sapphireit-publish-guard' ) );
	}

	/**
	 * With no parent SKU to inherit, every enabled variation needs its own.
	 *
	 * The failure keeps the rule's own fix target: one parent SKU is quicker than one per
	 * variation.
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
			if ( '' !== trim( $variation->get_sku() ) ) {
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
				/* translators: 1: number of enabled variations without a SKU, 2: number of enabled variations, 3: the first such variation's id. */
				_n(
					'%1$d of %2$d enabled variations has no SKU and the product has none to pass down (first: variation #%3$d) — add one in Product data → Inventory → SKU, or on each variation.',
					'%1$d of %2$d enabled variations have no SKU and the product has none to pass down (first: variation #%3$d) — add one in Product data → Inventory → SKU, or on each variation.',
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
		);
	}
}
