<?php
/**
 * The short description rule.
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
 * The same shape as the description rule, against its own threshold.
 *
 * It ships as a warning rather than a requirement: plenty of stores never use the short
 * description, and a merchant who does care can promote it on the settings screen.
 *
 * @since 1.0.0
 */
final class Short_Description_Rule extends Abstract_Rule {

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'short_description';
	}

	/**
	 * Rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Short description', 'sapphireit-publish-guard' );
	}

	/**
	 * Settings-screen help text.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the product has a short description, and warns when it is shorter than the minimum length.', 'sapphireit-publish-guard' );
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
		return 30;
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
			'selector' => '#excerpt',
			'label'    => __( 'Edit the short description', 'sapphireit-publish-guard' ),
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
		$length  = $context->get_short_description_length();
		$minimum = max( 0, $settings->threshold( 'min_short_description_chars' ) );

		$data = array(
			'length'  => $length,
			'minimum' => $minimum,
		);

		if ( 0 === $length ) {
			return $this->fail( __( 'The short description is empty.', 'sapphireit-publish-guard' ), $data );
		}

		// A threshold of zero switches the advisory branch off entirely.
		if ( $minimum > 0 && $length < $minimum ) {
			return $this->warn(
				sprintf(
					/* translators: 1: current short description length in characters, 2: recommended minimum length in characters. */
					_n(
						'The short description is %1$d character; at least %2$d are recommended.',
						'The short description is %1$d characters; at least %2$d are recommended.',
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
