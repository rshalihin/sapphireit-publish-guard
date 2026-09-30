<?php
/**
 * The product category rule.
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
 * Requires a category, and notices when the only one is the store's fallback.
 *
 * WooCommerce assigns the default category automatically, so "has a category" on its own
 * is not much of a check: the useful signal is whether anyone chose one.
 *
 * @since 1.0.0
 */
final class Category_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'category';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Product category', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product is in at least one category, and warns when the only category is the store default.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'organization';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 80;
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
			'selector' => '#product_catdiv',
			'label'    => __( 'Choose a category', 'sapphireit-publish-guard' ),
			'panel'    => '',
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

		$count = count( $context->get_category_ids() );
		$data  = array( 'category_count' => $count );

		if ( 0 === $count ) {
			return $this->fail( __( 'Assign at least one product category.', 'sapphireit-publish-guard' ), $data );
		}

		if ( $context->is_only_default_category() ) {
			return $this->warn( __( 'This product only uses the default category.', 'sapphireit-publish-guard' ), $data );
		}

		return $this->pass( '', $data );
	}
}
