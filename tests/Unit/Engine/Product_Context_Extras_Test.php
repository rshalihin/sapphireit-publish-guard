<?php
/**
 * Tests for context extras.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Save_Path;
use stdClass;
use WC_Product;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Extras carry product data the plugin does not model. A seeded value wins, anything else is
 * asked of `sit_wcpg_context_extra` once, and nothing unsafe is ever stored.
 *
 * @since 1.2.0
 */
final class Product_Context_Extras_Test extends TestCase {

	/**
	 * How many times the resolver filter ran, by key.
	 *
	 * @since 1.2.0
	 * @var array<string, int>
	 */
	private array $calls = array();

	/**
	 * Start with nothing on the filter.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		$this->calls = array();

		unset( $GLOBALS['sit_wcpg_test_filters']['sit_wcpg_context_extra'] );
	}

	/**
	 * Leave nothing on the filter.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_filters']['sit_wcpg_context_extra'] );

		parent::tear_down();
	}

	/**
	 * Resolve extras from a map, counting each call.
	 *
	 * @since 1.2.0
	 *
	 * @param array $map Values by key.
	 * @return void
	 */
	private function resolve_from( array $map ): void {
		add_filter(
			'sit_wcpg_context_extra',
			function ( $value, $key ) use ( $map ) {
				$this->calls[ $key ] = ( $this->calls[ $key ] ?? 0 ) + 1;

				return array_key_exists( $key, $map ) ? $map[ $key ] : $value;
			},
			10,
			3
		);
	}

	/**
	 * `from_array()` takes extras under their own key.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_from_array_seeds_extras(): void {
		$context = Product_Context::from_array( array( 'extras' => array( 'pro_gtin' => '123' ) ) );

		$this->assertSame( '123', $context->get_extra( 'pro_gtin' ) );
	}

	/**
	 * A seeded value is never replaced by what storage says.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_seeded_extra_wins_over_the_resolver(): void {
		$this->resolve_from( array( 'pro_gtin' => 'stored' ) );

		$context = Product_Context::from_array( array( 'extras' => array( 'pro_gtin' => 'typed' ) ) );

		$this->assertSame( 'typed', $context->get_extra( 'pro_gtin' ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * The filter is asked once for a key, however often it is read.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_resolver_runs_once_per_key(): void {
		$this->resolve_from( array( 'pro_gtin' => 'stored' ) );

		$context = Product_Context::from_array( array() );

		$this->assertSame( 'stored', $context->get_extra( 'pro_gtin' ) );
		$this->assertSame( 'stored', $context->get_extra( 'pro_gtin' ) );
		$this->assertSame( 1, $this->calls['pro_gtin'] );
	}

	/**
	 * Nothing resolved means the caller's default, and the miss is remembered too.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_null_from_resolver_returns_default(): void {
		$this->resolve_from( array() );

		$context = Product_Context::from_array( array() );

		$this->assertSame( 'fallback', $context->get_extra( 'pro_x', 'fallback' ) );
		$this->assertNull( $context->get_extra( 'pro_x' ) );
		$this->assertSame( 1, $this->calls['pro_x'] );
	}

	/**
	 * A key outside the pattern is never looked up.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_invalid_key_returns_default_without_calling_the_filter(): void {
		$this->resolve_from( array() );

		$context = Product_Context::from_array( array() );

		foreach ( array( 'Pro', '', 'a-b', str_repeat( 'a', 41 ) ) as $key ) {
			$this->assertSame( 'fallback', $context->get_extra( $key, 'fallback' ) );
		}

		$this->assertSame( array(), $this->calls );
		$this->assertNull( $context->get_extra( str_repeat( 'a', 40 ) ), 'Forty characters is still a key.' );
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * Seeding makes a new context and leaves the old one alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_with_extras_returns_a_clone(): void {
		$product  = new WC_Product(
			array(
				'id'   => 7,
				'name' => 'Stored title',
			)
		);
		$original = Product_Context::from_product_with_overrides( $product, array( 'title' => 'Typed title' ) )->with_save_path( Save_Path::CLASSIC );
		$first    = $original->with_extras( array( 'pro_gtin' => 'one' ) );
		$second   = $first->with_extras(
			array(
				'pro_gtin' => 'two',
				'pro_mpn'  => 'm',
			)
		);

		$this->assertNotSame( $original, $first );
		$this->assertNull( $original->get_extra( 'pro_gtin' ) );
		$this->assertSame( 'one', $first->get_extra( 'pro_gtin' ) );
		$this->assertSame( 'two', $second->get_extra( 'pro_gtin' ) );
		$this->assertSame( 'm', $second->get_extra( 'pro_mpn' ) );
		$this->assertSame( Save_Path::CLASSIC, $second->get_save_path() );
		$this->assertSame( 'Typed title', $second->get_title() );
		$this->assertSame( $product, $second->get_product() );
	}

	/**
	 * Only scalars, null and shallow arrays of those are kept.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unsafe_values_are_dropped(): void {
		$context = Product_Context::from_array(
			array(
				'extras' => array(
					'an_object'  => new stdClass(),
					'a_closure'  => static function () {
						return 1;
					},
					'four_deep'  => array( array( array( array( 'x' ) ) ) ),
					'holds_one'  => array( 'fine', new stdClass() ),
					'three_deep' => array( 'a' => array( 'b' => array( 1, 'two', true, null, 1.5 ) ) ),
					'Bad Key'    => 'x',
					'a_bool'     => false,
				),
			)
		);

		$this->assertNull( $context->get_extra( 'an_object' ) );
		$this->assertNull( $context->get_extra( 'a_closure' ) );
		$this->assertNull( $context->get_extra( 'four_deep' ) );
		$this->assertNull( $context->get_extra( 'holds_one' ) );
		$this->assertSame( array( 'a' => array( 'b' => array( 1, 'two', true, null, 1.5 ) ) ), $context->get_extra( 'three_deep' ) );
		$this->assertFalse( $context->get_extra( 'a_bool', 'fallback' ) );
	}

	/**
	 * What the resolver returns goes through the same rules.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unsafe_resolved_value_returns_default(): void {
		$this->resolve_from( array( 'pro_gtin' => new stdClass() ) );

		$this->assertSame( 'fallback', Product_Context::from_array( array() )->get_extra( 'pro_gtin', 'fallback' ) );
	}

	/**
	 * Extras are a property: no override array can set them.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_override_array_cannot_set_extras(): void {
		$overrides = array( 'extras' => array( 'pro_gtin' => '123' ) );
		$product   = new WC_Product( array( 'id' => 7 ) );

		$this->assertNull( Product_Context::from_product_with_overrides( $product, $overrides )->get_extra( 'pro_gtin' ) );
		$this->assertNull( Product_Context::from_save_request( $product, $overrides )->get_extra( 'pro_gtin' ) );
	}
}
