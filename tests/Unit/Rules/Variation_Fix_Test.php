<?php
/**
 * Tests for the shared variation fix target and skip reason.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Rules\Variation_Fix;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pins the copy in coding-plan.md sections 5.1 and 5.2.
 *
 * @since 1.1.0
 */
final class Variation_Fix_Test extends TestCase {

	/**
	 * Every per-variation failure points at the Variations panel.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_target_points_at_the_variations_panel() {
		$this->assertSame(
			array(
				'selector' => '#variable_product_options',
				'label'    => 'Open the Variations tab',
				'panel'    => 'variations',
			),
			Variation_Fix::target()
		);
	}

	/**
	 * The skip reason for a variable product with nothing enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_reason_is_the_exact_copy() {
		$this->assertSame(
			'This variable product has no enabled variations yet — see the "Variations and grouped products" check.',
			Variation_Fix::zero_variations_reason()
		);
	}

	/**
	 * The class holds two static operations and cannot be instantiated.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_is_not_instantiable() {
		$reflection = new \ReflectionClass( Variation_Fix::class );

		$this->assertTrue( $reflection->isFinal() );
		$this->assertTrue( $reflection->getConstructor()->isPrivate() );
	}
}
