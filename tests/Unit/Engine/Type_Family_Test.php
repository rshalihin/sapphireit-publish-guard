<?php
/**
 * Tests for the product type families.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Type_Family;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the D2 type → family mapping and its fallback.
 *
 * @since 1.1.0
 */
final class Type_Family_Test extends TestCase {

	/**
	 * Every known type and the family it belongs to (coding-plan.md D2).
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function known_types(): array {
		return array(
			'simple'                => array( 'simple', Type_Family::SIMPLE ),
			'subscription'          => array( 'subscription', Type_Family::SIMPLE ),
			'variable'              => array( 'variable', Type_Family::VARIABLE ),
			'variable-subscription' => array( 'variable-subscription', Type_Family::VARIABLE ),
			'grouped'               => array( 'grouped', Type_Family::GROUPED ),
			'external'              => array( 'external', Type_Family::EXTERNAL ),
		);
	}

	/**
	 * Each known type maps to its family.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider known_types
	 *
	 * @param string $type   WooCommerce product type.
	 * @param string $family Expected family.
	 * @return void
	 */
	public function test_maps_each_known_type( string $type, string $family ) {
		$this->assertSame( $family, Type_Family::from_type( $type ) );
	}

	/**
	 * An unknown type, or no type at all, keeps the v1.0 behaviour: simple.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_unknown_and_empty_types_are_simple() {
		$this->assertSame( Type_Family::SIMPLE, Type_Family::from_type( '' ) );
		$this->assertSame( Type_Family::SIMPLE, Type_Family::from_type( 'bundle' ) );
		$this->assertSame( Type_Family::SIMPLE, Type_Family::from_type( 'variation' ) );
		$this->assertSame( Type_Family::SIMPLE, Type_Family::from_type( 'Variable' ) );
	}

	/**
	 * Only the four families are valid, and `all()` lists them in order.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_only_the_four_families() {
		$this->assertSame( array( 'simple', 'variable', 'grouped', 'external' ), Type_Family::all() );

		foreach ( Type_Family::all() as $family ) {
			$this->assertTrue( Type_Family::is_valid( $family ) );
		}

		$this->assertFalse( Type_Family::is_valid( '' ) );
		$this->assertFalse( Type_Family::is_valid( 'subscription' ) );
		$this->assertFalse( Type_Family::is_valid( 'variable-subscription' ) );
		$this->assertFalse( Type_Family::is_valid( 'SIMPLE' ) );
	}
}
