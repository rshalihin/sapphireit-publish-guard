<?php
/**
 * A rule written against the v1.0 API only.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Stubs;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Settings\Settings;

/**
 * What a third-party rule built for v1.0 looks like: it overrides only v1.0 methods and
 * calls the protected factories with their v1.0 signatures. It must keep working (D1).
 *
 * Do not modernize this class: its value is that it uses nothing added after v1.0.
 *
 * @since 1.1.0
 */
final class Legacy_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const ID = 'legacy_rule';

	/**
	 * Rule identifier.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Rule label.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Legacy rule';
	}

	/**
	 * Class-level fix target, the only kind v1.0 had.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_fix_target(): array {
		return array(
			'selector' => '#legacy_field',
			'label'    => 'Fix legacy field',
			'panel'    => 'general_product_data',
		);
	}

	/**
	 * Always fail, through the v1.0 factory.
	 *
	 * @since 1.1.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $context, $settings );

		return $this->fail( 'Legacy.', array( 'n' => 1 ) );
	}
}
