<?php
/**
 * The image count rule.
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
 * Advises when a product carries fewer images than the merchant wants.
 *
 * The count comes from the context, which de-duplicates the featured image against the
 * gallery, so an image used in both places is counted once. A threshold of one or less
 * makes the rule redundant with the featured-image rule, so it skips itself rather than
 * showing the merchant a row that can never say anything new.
 *
 * @since 1.0.0
 */
final class Image_Count_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'image_count';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Product images', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks the total number of images on the product, counting the featured image and the gallery together.', 'sapphireit-publish-guard' );
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
		return 50;
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
			'selector' => '#product_images_container',
			'label'    => __( 'Add product images', 'sapphireit-publish-guard' ),
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
		$minimum = max( 0, $settings->threshold( 'min_images' ) );
		$count   = $context->get_image_count();

		$data = array(
			'image_count' => $count,
			'minimum'     => $minimum,
		);

		if ( $minimum <= 1 ) {
			return $this->skip( __( 'No minimum number of images is configured.', 'sapphireit-publish-guard' ), $data );
		}

		if ( $count < $minimum ) {
			return $this->fail(
				sprintf(
					/* translators: 1: number of images on the product, 2: recommended minimum number of images. */
					_n(
						'This product has %1$d image; at least %2$d are recommended.',
						'This product has %1$d images; at least %2$d are recommended.',
						$count,
						'sapphireit-publish-guard'
					),
					$count,
					$minimum
				),
				$data
			);
		}

		return $this->pass( '', $data );
	}
}
