<?php
/**
 * Tests for the variation value object.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Variation_Data;
use WC_Product_Variation;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the array factory's defaults and coercion, and what is read from a variation.
 *
 * @since 1.1.0
 */
final class Variation_Data_Test extends TestCase {

	/**
	 * Absent keys take safe defaults rather than null.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_from_array_defaults() {
		$variation = Variation_Data::from_array( array() );

		$this->assertSame( 0, $variation->get_id() );
		$this->assertSame( '', $variation->get_regular_price() );
		$this->assertSame( '', $variation->get_sale_price() );
		$this->assertSame( '', $variation->get_sale_from() );
		$this->assertSame( '', $variation->get_sale_to() );
		$this->assertSame( '', $variation->get_sku() );
		$this->assertSame( '', $variation->get_stock_status() );
		$this->assertSame( Variation_Data::MANAGE_NO, $variation->get_manage_stock() );
		$this->assertNull( $variation->get_stock_quantity() );
		$this->assertSame( 0, $variation->get_image_id() );
		$this->assertSame( array(), $variation->get_gallery_image_ids() );
	}

	/**
	 * Ids follow the Product_Context rules, and quantity keeps the null / int distinction.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_from_array_coerces_ids_and_quantity() {
		$variation = Variation_Data::from_array(
			array(
				'id'                => '12',
				'image_id'          => '-1',
				'gallery_image_ids' => '4,4,0,5',
				'stock_quantity'    => '7',
				'regular_price'     => 10,
			)
		);

		$this->assertSame( 12, $variation->get_id() );
		$this->assertSame( 0, $variation->get_image_id() );
		$this->assertSame( array( 4, 5 ), $variation->get_gallery_image_ids() );
		$this->assertSame( 7, $variation->get_stock_quantity() );
		$this->assertSame( '10', $variation->get_regular_price() );

		$negative = Variation_Data::from_array(
			array(
				'id'                => -3,
				'image_id'          => 8,
				'gallery_image_ids' => array( '9', -2, 9 ),
				'stock_quantity'    => '',
				'sku'               => array( 'not', 'scalar' ),
			)
		);

		$this->assertSame( 0, $negative->get_id() );
		$this->assertSame( 8, $negative->get_image_id() );
		$this->assertSame( array( 9 ), $negative->get_gallery_image_ids() );
		$this->assertNull( $negative->get_stock_quantity() );
		$this->assertSame( '', $negative->get_sku() );
		$this->assertSame( 0, Variation_Data::from_array( array( 'stock_quantity' => '0' ) )->get_stock_quantity() );
	}

	/**
	 * A manage-stock value outside the three known ones is read as "not managed".
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_invalid_manage_stock_becomes_no() {
		$this->assertSame( Variation_Data::MANAGE_YES, Variation_Data::from_array( array( 'manage_stock' => 'yes' ) )->get_manage_stock() );
		$this->assertSame( Variation_Data::MANAGE_PARENT, Variation_Data::from_array( array( 'manage_stock' => 'parent' ) )->get_manage_stock() );
		$this->assertSame( Variation_Data::MANAGE_NO, Variation_Data::from_array( array( 'manage_stock' => 'YES' ) )->get_manage_stock() );
		$this->assertSame( Variation_Data::MANAGE_NO, Variation_Data::from_array( array( 'manage_stock' => true ) )->get_manage_stock() );
		$this->assertSame( Variation_Data::MANAGE_NO, Variation_Data::from_array( array( 'manage_stock' => 'maybe' ) )->get_manage_stock() );
	}

	/**
	 * The raw values WooCommerce returns from `get_manage_stock( 'view' )`.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function manage_stock_values(): array {
		return array(
			'managed'        => array( true, Variation_Data::MANAGE_YES ),
			'not managed'    => array( false, Variation_Data::MANAGE_NO ),
			'parent managed' => array( 'parent', Variation_Data::MANAGE_PARENT ),
		);
	}

	/**
	 * WooCommerce's three manage-stock answers map to the three constants.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider manage_stock_values
	 *
	 * @param mixed  $raw      What the variation returns.
	 * @param string $expected Expected constant.
	 * @return void
	 */
	public function test_from_product_maps_manage_stock( $raw, string $expected ) {
		$variation = Variation_Data::from_product( new WC_Product_Variation( array( 'manage_stock' => $raw ) ) );

		$this->assertSame( $expected, $variation->get_manage_stock() );
	}

	/**
	 * A variation with no SKU of its own reports '', not the SKU it inherits from the parent.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_from_product_reads_own_sku_only() {
		$inheriting = Variation_Data::from_product(
			new WC_Product_Variation(
				array(
					'sku'        => '',
					'parent_sku' => 'PARENT-1',
				)
			)
		);

		$own = Variation_Data::from_product(
			new WC_Product_Variation(
				array(
					'sku'        => 'VAR-1',
					'parent_sku' => 'PARENT-1',
				)
			)
		);

		$this->assertSame( '', $inheriting->get_sku() );
		$this->assertSame( 'VAR-1', $own->get_sku() );
	}

	/**
	 * Sale dates are exposed as comparable `Y-m-d H:i:s` strings, like the parent's.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_from_product_formats_sale_dates() {
		$variation = Variation_Data::from_product(
			new WC_Product_Variation(
				array(
					'id'                => 31,
					'regular_price'     => '10',
					'sale_price'        => '8',
					'date_on_sale_from' => new \DateTimeImmutable( '2026-01-02 03:04:05' ),
					'date_on_sale_to'   => null,
					'stock_status'      => 'outofstock',
					'image_id'          => 4,
					'gallery_image_ids' => array( 5, 5, 6 ),
				)
			)
		);

		$this->assertSame( '2026-01-02 03:04:05', $variation->get_sale_from() );
		$this->assertSame( '', $variation->get_sale_to() );
		$this->assertSame( 31, $variation->get_id() );
		$this->assertSame( '10', $variation->get_regular_price() );
		$this->assertSame( '8', $variation->get_sale_price() );
		$this->assertSame( 'outofstock', $variation->get_stock_status() );
		$this->assertSame( 4, $variation->get_image_id() );
		$this->assertSame( array( 5, 6 ), $variation->get_gallery_image_ids() );
	}

	/**
	 * Under parent-managed stock, the quantity is the parent's ('view' context).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_from_product_reads_the_parent_quantity_when_parent_managed() {
		$variation = Variation_Data::from_product(
			new WC_Product_Variation(
				array(
					'manage_stock'          => 'parent',
					'stock_quantity'        => null,
					'parent_stock_quantity' => 5,
				)
			)
		);

		$this->assertSame( 5, $variation->get_stock_quantity() );
	}
}
