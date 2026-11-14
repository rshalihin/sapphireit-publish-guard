<?php
/**
 * The probe add-on's rule.
 *
 * Loaded from `Sit_Wcpg_Probe::boot()`, once the plugin's classes exist.
 *
 * @package ProductPublishGuard
 */

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Managed_Rule_Interface;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Probe code": a managed rule, so the add-on decides where it applies and how strictly.
 *
 * @since 1.2.0
 */
final class Sit_Wcpg_Probe_Rule extends Abstract_Rule implements Managed_Rule_Interface {

	/**
	 * The rule's identifier.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const ID = 'probe_code';

	/**
	 * Rule identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Rule label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Probe code';
	}

	/**
	 * The add-on's own group.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return Sit_Wcpg_Probe::GROUP;
	}

	/**
	 * After every shipped rule.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 500;
	}

	/**
	 * Every product, except those in the exempt category.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return bool
	 */
	public function supports( Product_Context $context ): bool {
		if ( ! Sit_Wcpg_Probe::$enabled ) {
			return false;
		}

		$exempt = get_term_by( 'slug', Sit_Wcpg_Probe::EXEMPT_CATEGORY, 'product_cat' );

		return ! $exempt instanceof WP_Term || ! in_array( (int) $exempt->term_id, $context->get_category_ids(), true );
	}

	/**
	 * A warning for external products, required for everything else.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return string
	 */
	public function get_severity_for( Product_Context $context ): string {
		return Type_Family::EXTERNAL === $context->get_type_family() ? Severity::WARNING : Severity::REQUIRED;
	}

	/**
	 * Pass when the product has a probe code.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		$code = $context->get_extra( Sit_Wcpg_Probe::EXTRA, '' );

		return is_string( $code ) && '' !== trim( $code ) ? $this->pass() : $this->fail( 'This product has no probe code.' );
	}
}
