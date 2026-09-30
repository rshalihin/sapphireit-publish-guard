<?php
/**
 * The featured image rule.
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
 * Requires a featured image that actually exists.
 *
 * The two failures are separated because they need different fixes: one product has no
 * image, the other points at an attachment that has since been deleted or replaced by a
 * non-image file, which looks fine in the editor until the catalogue renders.
 *
 * @since 1.0.0
 */
final class Featured_Image_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'featured_image';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Featured image', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a featured image and that the image is still in the media library.', 'sapphireit-publish-guard' );
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'media';
	}

	/**
	 * Display order.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 40;
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
			'selector' => '#set-post-thumbnail',
			'label'    => __( 'Set a featured image', 'sapphireit-publish-guard' ),
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

		// The context has already normalized core's `-1` "no image" sentinel to zero.
		$image_id = $context->get_featured_image_id();

		$data = array(
			'image_count' => $context->get_image_count(),
		);

		if ( $image_id <= 0 ) {
			return $this->fail( __( 'This product has no featured image.', 'sapphireit-publish-guard' ), $data );
		}

		if ( ! $context->has_valid_featured_image() ) {
			return $this->fail( __( 'The featured image is missing from the media library.', 'sapphireit-publish-guard' ), $data );
		}

		return $this->pass( '', $data );
	}
}
