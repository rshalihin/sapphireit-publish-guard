<?php
/**
 * A rule double that branches on the save path.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Stubs;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Settings\Settings;

/**
 * Fails on a REST save and passes on every other route.
 *
 * @since 1.2.0
 */
final class Path_Aware_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'path_aware';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Path aware';
	}

	/**
	 * Decide by the route the product is being saved through.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		return Save_Path::REST === $context->get_save_path() ? $this->fail( 'Not over REST.' ) : $this->pass();
	}
}
