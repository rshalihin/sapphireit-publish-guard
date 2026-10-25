<?php
/**
 * The stock status rule.
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
 * Checks that the stock status is set, and that it agrees with the managed quantity.
 *
 * The last branch is the one that catches real mistakes: a product marked in stock while
 * stock management reports nothing left will take orders it cannot fulfil.
 *
 * @since 1.0.0
 */
final class Stock_Status_Rule extends Abstract_Rule {

	/**
	 * The stock statuses WooCommerce ships with.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const VALID_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'stock_status';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Stock status', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a stock status and that a managed quantity agrees with it. On a variable product, each enabled variation is checked; grouped and external products are skipped.', 'sapphireit-publish-guard' );
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
		return 110;
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
			'selector' => '#_stock_status',
			'label'    => __( 'Review the stock status', 'sapphireit-publish-guard' ),
			'panel'    => 'inventory',
		);
	}

	/**
	 * Evaluate the rule.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Per variation on a variable product; grouped and external skip.
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		$family = $context->get_type_family();

		if ( Type_Family::GROUPED === $family ) {
			return $this->skip( __( 'A grouped product has no stock of its own — its products carry their own.', 'sapphireit-publish-guard' ) );
		}

		if ( Type_Family::EXTERNAL === $family ) {
			return $this->skip( __( 'External products have no stock — the store they link to sells them.', 'sapphireit-publish-guard' ) );
		}

		if ( Type_Family::VARIABLE === $family ) {
			return $this->check_variations( $context->get_variations() )->with_fix( Variation_Fix::target() );
		}

		$status = trim( $context->get_stock_status() );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return $this->fail( __( 'No stock status is selected — choose one in Product data → Inventory → Stock status.', 'sapphireit-publish-guard' ) );
		}

		if ( 'outofstock' === $status ) {
			return $this->warn( __( 'This product is marked out of stock — change it in Product data → Inventory → Stock status if that is wrong.', 'sapphireit-publish-guard' ) );
		}

		$quantity = $context->get_stock_quantity();

		if ( 'instock' === $status && $context->get_manage_stock() && null !== $quantity && $quantity <= 0 ) {
			return $this->warn(
				sprintf(
					/* translators: %d is the managed stock quantity. */
					__( 'Stock management is on but the quantity is %d — update it in Product data → Inventory → Quantity.', 'sapphireit-publish-guard' ),
					$quantity
				),
				array( 'stock_quantity' => $quantity )
			);
		}

		return $this->pass();
	}

	/**
	 * Each enabled variation is checked: a missing status first, then a managed quantity
	 * that contradicts "in stock", then out of stock.
	 *
	 * The quantity comes from the view context, so a variation whose stock the parent
	 * manages reports the parent's quantity.
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

		$invalid  = array();
		$quantity = array();
		$out      = array();

		foreach ( $variations as $variation ) {
			$status = trim( $variation->get_stock_status() );

			if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
				$invalid[] = $variation->get_id();
			} elseif ( 'outofstock' === $status ) {
				$out[] = $variation->get_id();
			} elseif ( 'instock' === $status && Variation_Data::MANAGE_NO !== $variation->get_manage_stock() && null !== $variation->get_stock_quantity() && $variation->get_stock_quantity() <= 0 ) {
				$quantity[] = $variation->get_id();
			}
		}

		$enabled = count( $variations );

		if ( array() !== $invalid ) {
			return $this->fail(
				sprintf(
					/* translators: 1: number of enabled variations without a stock status, 2: number of enabled variations, 3: the first such variation's id. */
					_n(
						'%1$d of %2$d enabled variations has no stock status (first: variation #%3$d) — choose one in Product data → Variations → Stock status.',
						'%1$d of %2$d enabled variations have no stock status (first: variation #%3$d) — choose one in Product data → Variations → Stock status.',
						count( $invalid ),
						'sapphireit-publish-guard'
					),
					count( $invalid ),
					$enabled,
					$invalid[0]
				),
				self::counts( $invalid, $enabled )
			);
		}

		if ( array() !== $quantity ) {
			return $this->warn(
				sprintf(
					/* translators: 1: number of enabled variations in stock with a managed quantity of zero or less, 2: number of enabled variations, 3: the first such variation's id. */
					_n(
						'%1$d of %2$d enabled variations is in stock with a quantity of zero or less (first: variation #%3$d) — update the quantity in Product data → Variations, or in Product data → Inventory when the product manages stock.',
						'%1$d of %2$d enabled variations are in stock with a quantity of zero or less (first: variation #%3$d) — update the quantity in Product data → Variations, or in Product data → Inventory when the product manages stock.',
						count( $quantity ),
						'sapphireit-publish-guard'
					),
					count( $quantity ),
					$enabled,
					$quantity[0]
				),
				self::counts( $quantity, $enabled )
			);
		}

		if ( array() !== $out ) {
			return $this->warn(
				sprintf(
					/* translators: 1: number of enabled variations marked out of stock, 2: number of enabled variations, 3: the first such variation's id. */
					_n(
						'%1$d of %2$d enabled variations is marked out of stock (first: variation #%3$d) — check Product data → Variations → Stock status.',
						'%1$d of %2$d enabled variations are marked out of stock (first: variation #%3$d) — check Product data → Variations → Stock status.',
						count( $out ),
						'sapphireit-publish-guard'
					),
					count( $out ),
					$enabled,
					$out[0]
				),
				self::counts( $out, $enabled )
			);
		}

		return $this->pass();
	}

	/**
	 * The data payload for a per-variation result.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $ids     The flagged variation ids, in display order.
	 * @param int   $enabled Number of enabled variations.
	 * @return array
	 */
	private static function counts( array $ids, int $enabled ): array {
		return array(
			'failing'            => count( $ids ),
			'enabled'            => $enabled,
			'first_variation_id' => $ids[0],
		);
	}
}
