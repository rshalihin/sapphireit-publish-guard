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
		return __( 'Checks that the product has a stock status, and that a managed quantity agrees with it.', 'sapphireit-publish-guard' );
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
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		$status = trim( $context->get_stock_status() );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return $this->fail( __( 'Select a stock status.', 'sapphireit-publish-guard' ) );
		}

		if ( 'outofstock' === $status ) {
			return $this->warn( __( 'This product is marked out of stock.', 'sapphireit-publish-guard' ) );
		}

		$quantity = $context->get_stock_quantity();

		if ( 'instock' === $status && $context->get_manage_stock() && null !== $quantity && $quantity <= 0 ) {
			return $this->warn(
				sprintf(
					/* translators: %d is the managed stock quantity. */
					__( 'Stock management is on but the quantity is %d.', 'sapphireit-publish-guard' ),
					$quantity
				),
				array( 'stock_quantity' => $quantity )
			);
		}

		return $this->pass();
	}
}
