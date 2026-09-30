<?php
/**
 * The product title rule.
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
 * Requires a real product title.
 *
 * "Real" excludes the placeholder WordPress and WooCommerce write into a freshly created auto-draft,
 * which would otherwise make an untouched new product look as though it had a title.
 *
 * @since 1.0.0
 */
final class Title_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'title';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Product title', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a title of its own.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'content';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 10;
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
			'selector' => '#title',
			'label'    => __( 'Edit the title', 'sapphireit-publish-guard' ),
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

		$title = trim( $context->get_title() );

		if ( '' === $title || $this->is_placeholder( $title ) ) {
			return $this->fail( __( 'Add a product title.', 'sapphireit-publish-guard' ) );
		}

		return $this->pass();
	}

	/**
	 * Whether a title is the one a brand-new auto-draft is given.
	 *
	 * Core writes `Auto Draft` through the default text domain, so the localized form has
	 * to be matched as well as the English one. WooCommerce then overwrites every product
	 * auto-draft's title with the untranslated literal `AUTO-DRAFT`
	 * (`WC_Post_Data::wp_insert_post_data()`), which is the form a new product actually has.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Trimmed product title.
	 * @return bool
	 */
	private function is_placeholder( string $title ): bool {
		// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Matching core's own string, which is in the default domain.
		$placeholders = array( 'Auto Draft', __( 'Auto Draft' ), 'AUTO-DRAFT' );

		foreach ( $placeholders as $placeholder ) {
			if ( 0 === strcasecmp( $title, $placeholder ) ) {
				return true;
			}
		}

		return false;
	}
}
