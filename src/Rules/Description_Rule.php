<?php
/**
 * The long description rule.
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
 * Requires a description, and advises when it is shorter than the merchant's threshold.
 *
 * The two outcomes are deliberately different in kind. An empty description is a missing
 * requirement, so it fails and the merchant's severity setting decides whether that
 * blocks publishing. A short description is advisory, so it only ever warns: raising the
 * threshold must never turn an entire catalogue unpublishable overnight.
 *
 * @since 1.0.0
 */
final class Description_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'description';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Description', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a description, and warns when it is shorter than the minimum length.', 'sapphireit-publish-guard' );
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
		return 20;
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
			'selector' => '#content',
			'label'    => __( 'Edit the description', 'sapphireit-publish-guard' ),
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
		$length  = $context->get_description_length();
		$minimum = max( 0, $settings->threshold( 'min_description_chars' ) );

		$data = array(
			'length'  => $length,
			'minimum' => $minimum,
		);

		if ( 0 === $length ) {
			return $this->fail( __( 'The product description is empty.', 'sapphireit-publish-guard' ), $data );
		}

		// A threshold of zero switches the advisory branch off entirely.
		if ( $minimum > 0 && $length < $minimum ) {
			return $this->warn(
				sprintf(
					/* translators: 1: current description length in characters, 2: recommended minimum length in characters. */
					_n(
						'The product description is %1$d character; at least %2$d are recommended.',
						'The product description is %1$d characters; at least %2$d are recommended.',
						$length,
						'sapphireit-publish-guard'
					),
					$length,
					$minimum
				),
				$data
			);
		}

		return $this->pass( '', $data );
	}
}
