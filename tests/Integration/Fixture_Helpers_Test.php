<?php
/**
 * The product-type fixtures later phases build on.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use WC_Product_External;
use WC_Product_Grouped;
use WC_Product_Variable;

/**
 * `variable_product()`, `grouped_product()` and `external_product()` store what they are
 * asked for.
 *
 * @since 1.1.0
 */
class Fixture_Helpers_Test extends Publish_Guard_Test_Case {

	/**
	 * Two enabled variations and one disabled: all three are children, two are enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_product_has_the_requested_variations(): void {
		$product_id = $this->variable_product(
			array(
				array( 'regular_price' => '10' ),
				array( 'regular_price' => '12' ),
				array(
					'regular_price' => '14',
					'status'        => 'private',
				),
			)
		);

		$product = wc_get_product( $product_id );

		$this->assertInstanceOf( WC_Product_Variable::class, $product );
		$this->assertSame( 'draft', $product->get_status( 'edit' ) );
		$this->assertSame( '', $product->get_regular_price( 'edit' ) );

		$children = $product->get_children();
		$enabled  = array_filter(
			$children,
			static fn ( $child_id ) => 'publish' === wc_get_product( $child_id )->get_status( 'edit' )
		);

		$this->assertCount( 3, $children );
		$this->assertCount( 2, $enabled );
		$this->assertSame( '14', wc_get_product( $children[2] )->get_regular_price( 'edit' ) );
	}

	/**
	 * A grouped product stores the children it was given, in order.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_grouped_product_links_its_children(): void {
		$first  = $this->passing_product( 'publish' );
		$second = $this->passing_product( 'publish' );

		$product = wc_get_product( $this->grouped_product( array( $first, $second ) ) );

		$this->assertInstanceOf( WC_Product_Grouped::class, $product );
		$this->assertSame( 'draft', $product->get_status( 'edit' ) );
		$this->assertSame( array( $first, $second ), $product->get_children( 'edit' ) );
	}

	/**
	 * An external product has a product URL and the passing fixture's price.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_external_product_has_url_and_price(): void {
		$product = wc_get_product( $this->external_product( 'publish', array( 'regular_price' => '42.00' ) ) );

		$this->assertInstanceOf( WC_Product_External::class, $product );
		$this->assertSame( 'publish', $product->get_status( 'edit' ) );
		$this->assertNotSame( '', $product->get_product_url( 'edit' ) );
		$this->assertSame( '42.00', $product->get_regular_price( 'edit' ) );
	}
}
