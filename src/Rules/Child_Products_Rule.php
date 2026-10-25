<?php
/**
 * The variations and grouped products rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rules;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a variable or grouped product that has nothing in it to sell.
 *
 * A variable product needs at least one enabled variation, and a grouped product at least
 * one linked product that still exists and is published. This rule carries the failure
 * for an empty variable product, so the four variation-aware rules skip it instead of
 * reporting it four times.
 *
 * It ships as a warning (D7): REST and CSV imports create the parent before its children,
 * so a requirement would refuse them by default. A merchant can raise it to required.
 *
 * @since 1.1.0
 */
final class Child_Products_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'child_products';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Variations and grouped products', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that a variable product has at least one enabled variation, and that a grouped product contains at least one existing product that is published.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'organization';
	}

	/**
	 * Display order: after every v1.0 rule.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 120;
	}

	/**
	 * Severity this rule ships with.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_default_severity(): string {
		return Severity::WARNING;
	}

	/**
	 * Where to fix a failure on a variable product. Grouped failures carry their own.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_fix_target(): array {
		return Variation_Fix::target();
	}

	/**
	 * Evaluate the rule.
	 *
	 * @since 1.1.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		$family = $context->get_type_family();

		if ( Type_Family::VARIABLE === $family ) {
			return $this->check_variable( $context );
		}

		if ( Type_Family::GROUPED === $family ) {
			return $this->check_grouped( $context );
		}

		return $this->skip( __( 'Only variable and grouped products have variations or linked products.', 'sapphireit-publish-guard' ) );
	}

	/**
	 * A variable product needs at least one enabled variation.
	 *
	 * @since 1.1.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return Rule_Result
	 */
	private function check_variable( Product_Context $context ): Rule_Result {
		$data = array( 'enabled' => count( $context->get_variations() ) );

		if ( $data['enabled'] > 0 ) {
			return $this->pass( '', $data );
		}

		return $this->fail( __( 'This variable product has no enabled variations — add or enable one in Product data → Variations.', 'sapphireit-publish-guard' ), $data );
	}

	/**
	 * A grouped product needs at least one existing linked product, and one of those published.
	 *
	 * @since 1.1.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return Rule_Result
	 */
	private function check_grouped( Product_Context $context ): Rule_Result {
		$linked    = count( $context->get_child_ids() );
		$published = $context->get_published_child_count();
		$data      = array(
			'linked'    => $linked,
			'published' => $published,
		);

		if ( $published > 0 ) {
			return $this->pass( '', $data );
		}

		if ( 0 === $linked ) {
			$message = __( 'This grouped product contains no products — add some in Product data → Linked Products → Grouped products.', 'sapphireit-publish-guard' );
		} else {
			$message = sprintf(
				/* translators: %d: number of products in the group. */
				_n(
					'None of the %d product in this group is published — publish at least one, or the group has nothing to sell.',
					'None of the %d products in this group is published — publish at least one, or the group has nothing to sell.',
					$linked,
					'sapphireit-publish-guard'
				),
				$linked
			);
		}

		return $this->fail( $message, $data )->with_fix( self::grouped_fix() );
	}

	/**
	 * Where to fix a grouped product's contents.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private static function grouped_fix(): array {
		return array(
			'selector' => '#grouped_products',
			'label'    => __( 'Add products to the group', 'sapphireit-publish-guard' ),
			'panel'    => 'linked_product',
		);
	}
}
