<?php
/**
 * Tests for the save-request reader.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Save_Request_Reader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers each admin payload shape (coding-plan.md section 12.1): the classic editor,
 * Quick Edit (an absent field must not become empty) and Bulk Edit ("no change"
 * sentinels ignored), plus unslashing and the bypass-relevant refusals.
 *
 * The stubbed `wp_verify_nonce()` accepts `valid-{action}`.
 *
 * @since 1.0.0
 */
final class Save_Request_Reader_Test extends TestCase {

	/**
	 * Forget any denied capability.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_denied_caps'] );

		parent::tear_down();
	}

	/**
	 * A classic-editor payload for product 12.
	 *
	 * @since 1.0.0
	 *
	 * @param array $extra Fields to add or replace.
	 * @return array
	 */
	private static function classic( array $extra = array() ): array {
		return array_merge(
			array(
				'action'                 => 'editpost',
				'post_ID'                => '12',
				'woocommerce_meta_nonce' => 'valid-woocommerce_save_data',
				'product-type'           => 'simple',
				'_regular_price'         => '19.99',
				'_sale_price'            => '',
				'_sale_price_dates_from' => '2026-04-01',
				'_sale_price_dates_to'   => '',
				'_sku'                   => 'SIT-WCPG-READER-1',
				'_stock_status'          => 'instock',
				'_thumbnail_id'          => '-1',
				'product_image_gallery'  => '7,8,8,0',
				'tax_input'              => array(
					'product_cat' => array( '0', '3', '5' ),
					'product_tag' => 'red, blue,,Red',
				),
			),
			$extra
		);
	}

	/**
	 * A Quick Edit payload for product 12.
	 *
	 * @since 1.0.0
	 *
	 * @param array $extra Fields to add or replace.
	 * @return array
	 */
	private static function quick( array $extra = array() ): array {
		return array_merge(
			array(
				'action'                       => 'inline-save',
				'post_ID'                      => '12',
				'_status'                      => 'publish',
				'woocommerce_quick_edit'       => '1',
				'woocommerce_quick_edit_nonce' => 'valid-woocommerce_quick_edit_nonce',
				'_regular_price'               => '10',
				'_sku'                         => 'Q-1',
				'_stock_status'                => '',
			),
			$extra
		);
	}

	/**
	 * A Bulk Edit payload covering products 12 and 13.
	 *
	 * @since 1.0.0
	 *
	 * @param array $extra Fields to add or replace.
	 * @return array
	 */
	private static function bulk( array $extra = array() ): array {
		return array_merge(
			array(
				'bulk_edit'                    => 'Update',
				'post'                         => array( '12', '13' ),
				'_status'                      => '-1',
				'woocommerce_quick_edit_nonce' => 'valid-woocommerce_quick_edit_nonce',
				'change_regular_price'         => '',
				'_regular_price'               => '',
				'change_sale_price'            => '',
				'_sale_price'                  => '',
				'_stock_status'                => '',
				'_manage_stock'                => '',
				'change_stock'                 => '',
				'_stock'                       => '',
			),
			$extra
		);
	}

	/**
	 * Each payload is recognised, and only for the post it targets.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_sources_are_detected_for_the_targeted_post_only(): void {
		$this->assertSame( Save_Request_Reader::SOURCE_CLASSIC, Save_Request_Reader::detect_source( self::classic(), 12 ) );
		$this->assertSame( Save_Request_Reader::SOURCE_QUICK_EDIT, Save_Request_Reader::detect_source( self::quick(), 12 ) );
		$this->assertSame( Save_Request_Reader::SOURCE_BULK_EDIT, Save_Request_Reader::detect_source( self::bulk(), 13 ) );

		// Another plugin updating product 99 from inside this save is not the form.
		$this->assertSame( Save_Request_Reader::SOURCE_PROGRAMMATIC, Save_Request_Reader::detect_source( self::classic(), 99 ) );
		$this->assertSame( Save_Request_Reader::SOURCE_PROGRAMMATIC, Save_Request_Reader::detect_source( self::bulk(), 99 ) );
		$this->assertSame( Save_Request_Reader::SOURCE_PROGRAMMATIC, Save_Request_Reader::detect_source( array(), 12 ) );
	}

	/**
	 * The classic editor contributes every field, normalized.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_classic_payload_is_read_and_normalized(): void {
		$overrides = Save_Request_Reader::read(
			self::classic(),
			array(
				'post_title'   => 'A title',
				'post_content' => 'Body',
				'post_excerpt' => '',
				'post_status'  => 'publish',
			),
			12
		);

		$this->assertSame( 12, $overrides['product_id'] );
		$this->assertSame( 'A title', $overrides['title'] );
		$this->assertSame( 'Body', $overrides['description'] );
		$this->assertSame( '', $overrides['short_description'] );
		$this->assertSame( 'publish', $overrides['post_status'] );
		$this->assertSame( 'simple', $overrides['product_type'] );
		$this->assertSame( '19.99', $overrides['regular_price'] );
		$this->assertSame( '', $overrides['sale_price'] );
		$this->assertSame( '2026-04-01 00:00:00', $overrides['sale_from'] );
		$this->assertSame( '', $overrides['sale_to'] );
		$this->assertSame( 'SIT-WCPG-READER-1', $overrides['sku'] );
		$this->assertSame( 'instock', $overrides['stock_status'] );
		$this->assertFalse( $overrides['manage_stock'] );
		$this->assertArrayNotHasKey( 'stock_quantity', $overrides );
		$this->assertSame( array( 7, 8 ), $overrides['gallery_image_ids'] );
		$this->assertSame( array( 3, 5 ), $overrides['category_ids'] );

		// "red", "blue" and "Red" are two distinct tags; they are counted, never resolved.
		$this->assertSame( array( 1, 2 ), $overrides['tag_ids'] );
	}

	/**
	 * Core's `-1` featured-image sentinel means "no image".
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_featured_image_sentinel_means_no_image(): void {
		$this->assertSame( 0, Save_Request_Reader::read( self::classic(), array(), 12 )['featured_image_id'] );
		$this->assertSame( 44, Save_Request_Reader::read( self::classic( array( '_thumbnail_id' => '44' ) ), array(), 12 )['featured_image_id'] );
	}

	/**
	 * Managed stock is read only when the box is ticked.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_classic_stock_follows_the_manage_stock_checkbox(): void {
		$overrides = Save_Request_Reader::read(
			self::classic(
				array(
					'_manage_stock' => 'yes',
					'_stock'        => '7',
				)
			),
			array(),
			12
		);

		$this->assertTrue( $overrides['manage_stock'] );
		$this->assertSame( 7, $overrides['stock_quantity'] );
	}

	/**
	 * Both inputs arrive slashed and are measured unslashed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_slashed_input_is_unslashed(): void {
		$overrides = Save_Request_Reader::read(
			self::classic( array( '_sku' => 'O\\\'Brien' ) ),
			array( 'post_title' => 'Rock \\"n\\" roll' ),
			12
		);

		$this->assertSame( "O'Brien", $overrides['sku'] );
		$this->assertSame( 'Rock "n" roll', $overrides['title'] );
	}

	/**
	 * A Quick Edit carries no description, and that must not read as an empty one.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_quick_edit_does_not_invent_absent_fields(): void {
		$overrides = Save_Request_Reader::read( self::quick(), array( 'post_status' => 'publish' ), 12 );

		$this->assertArrayNotHasKey( 'description', $overrides );
		$this->assertArrayNotHasKey( 'short_description', $overrides );
		$this->assertArrayNotHasKey( 'featured_image_id', $overrides );
		$this->assertArrayNotHasKey( 'gallery_image_ids', $overrides );
		$this->assertArrayNotHasKey( 'product_type', $overrides );
		$this->assertArrayNotHasKey( 'category_ids', $overrides );

		$this->assertSame( '10', $overrides['regular_price'] );
		$this->assertSame( 'Q-1', $overrides['sku'] );

		// WooCommerce ignores an empty stock status in Quick Edit rather than clearing it.
		$this->assertArrayNotHasKey( 'stock_status', $overrides );
	}

	/**
	 * Bulk Edit's "no change" values change nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_bulk_edit_no_change_sentinels_are_ignored(): void {
		$overrides = Save_Request_Reader::read( self::bulk(), array(), 13 );

		$this->assertSame( array( 'product_id' => 13 ), $overrides );
	}

	/**
	 * Bulk Edit prices are read only in "set to" mode, with a value.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_bulk_edit_reads_prices_only_when_set(): void {
		$set = Save_Request_Reader::read(
			self::bulk(
				array(
					'change_regular_price' => '1',
					'_regular_price'       => '12.50',
					'_stock_status'        => 'outofstock',
				)
			),
			array(),
			12
		);

		$this->assertSame( '12.50', $set['regular_price'] );
		$this->assertSame( 'outofstock', $set['stock_status'] );

		$relative = Save_Request_Reader::read(
			self::bulk(
				array(
					'change_regular_price' => '2',
					'_regular_price'       => '10%',
				)
			),
			array(),
			12
		);

		$this->assertArrayNotHasKey( 'regular_price', $relative );
	}

	/**
	 * Bulk Edit terms arrive already merged by core and are read as they are.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_bulk_edit_reads_the_merged_terms(): void {
		$overrides = Save_Request_Reader::read(
			self::bulk(
				array(
					'tax_input' => array(
						'product_cat' => array( 4, 9 ),
						'product_tag' => array( 'old', 'new', 'old' ),
					),
				)
			),
			array(),
			12
		);

		$this->assertSame( array( 4, 9 ), $overrides['category_ids'] );
		$this->assertSame( array( 1, 2 ), $overrides['tag_ids'] );
	}

	/**
	 * WooCommerce fields are not believed without the nonce WooCommerce itself checks.
	 *
	 * Otherwise a crafted request could pass the guard on a price WooCommerce is about to
	 * ignore.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_woocommerce_fields_need_woocommerces_nonce(): void {
		$classic = Save_Request_Reader::read( self::classic( array( 'woocommerce_meta_nonce' => 'forged' ) ), array(), 12 );
		$quick   = Save_Request_Reader::read( self::quick( array( 'woocommerce_quick_edit_nonce' => '' ) ), array(), 12 );

		$this->assertArrayNotHasKey( 'regular_price', $classic );
		$this->assertArrayNotHasKey( 'gallery_image_ids', $classic );
		$this->assertArrayNotHasKey( 'regular_price', $quick );
		$this->assertArrayNotHasKey( 'sku', $quick );

		// Core applies these itself, so they stand without WooCommerce's nonce.
		$this->assertSame( 0, $classic['featured_image_id'] );
		$this->assertSame( array( 3, 5 ), $classic['category_ids'] );
	}

	/**
	 * A payload for another post contributes nothing but the row.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_payload_for_another_post_contributes_no_woocommerce_fields(): void {
		$overrides = Save_Request_Reader::read( self::classic(), array( 'post_status' => 'publish' ), 99 );

		$this->assertArrayNotHasKey( 'regular_price', $overrides );
		$this->assertArrayNotHasKey( 'sku', $overrides );
	}

	/**
	 * Terms the user may not assign are not read, because core will not save them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_terms_the_user_cannot_assign_are_ignored(): void {
		$GLOBALS['sit_wcpg_test_denied_caps'] = array( 'assign_product_cat' );

		$overrides = Save_Request_Reader::read( self::classic(), array(), 12 );

		$this->assertArrayNotHasKey( 'category_ids', $overrides );
		$this->assertArrayHasKey( 'tag_ids', $overrides );
	}

	/**
	 * Oversized identifier lists are capped before any per-item work.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_identifier_lists_are_capped(): void {
		$overrides = Save_Request_Reader::read(
			self::classic(
				array(
					'product_image_gallery' => implode( ',', range( 1, 5000 ) ),
					'tax_input'             => array(
						'product_cat' => range( 1, 5000 ),
						'product_tag' => implode( ',', range( 1, 5000 ) ),
					),
				)
			),
			array(),
			12
		);

		$this->assertCount( 100, $overrides['gallery_image_ids'] );
		$this->assertCount( 100, $overrides['category_ids'] );
		$this->assertCount( 200, $overrides['tag_ids'] );
	}
}
