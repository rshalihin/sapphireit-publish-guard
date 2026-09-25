<?php
/**
 * Tests for the product data boundary.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Product_Context;
use WC_Product;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the override merge semantics, length normalization and image de-duplication.
 *
 * @since 1.0.0
 */
final class Product_Context_Test extends TestCase {

	/**
	 * Reset the globals the WordPress function doubles read.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function set_up() {
		$GLOBALS['sit_wcpg_test_terms']     = array();
		$GLOBALS['sit_wcpg_test_options']   = array();
		$GLOBALS['sit_wcpg_test_image_ids'] = array();
	}

	/**
	 * A saved product with known values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Overrides for the defaults below.
	 * @return WC_Product
	 */
	private function stored_product( array $values = array() ): WC_Product {
		return new WC_Product(
			array_merge(
				array(
					'id'                => 55,
					'type'              => 'simple',
					'status'            => 'draft',
					'name'              => 'Stored name',
					'description'       => 'Stored description',
					'short_description' => 'Stored excerpt',
					'image_id'          => 9,
					'gallery_image_ids' => array( 10, 11 ),
					'regular_price'     => '19.99',
					'sale_price'        => '',
					'sku'               => 'STORED-1',
					'stock_status'      => 'instock',
					'manage_stock'      => false,
					'stock_quantity'    => null,
				),
				$values
			)
		);
	}

	/**
	 * An array context defaults every accessor rather than returning null.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_from_array_defaults_every_accessor() {
		$context = Product_Context::from_array( array() );

		$this->assertNull( $context->get_product() );
		$this->assertSame( 0, $context->get_product_id() );
		$this->assertSame( '', $context->get_product_type() );
		$this->assertSame( '', $context->get_title() );
		$this->assertSame( 0, $context->get_description_length() );
		$this->assertSame( 0, $context->get_featured_image_id() );
		$this->assertFalse( $context->has_valid_featured_image() );
		$this->assertSame( array(), $context->get_gallery_image_ids() );
		$this->assertSame( 0, $context->get_image_count() );
		$this->assertSame( '', $context->get_regular_price() );
		$this->assertNull( $context->get_stock_quantity() );
		$this->assertSame( array(), $context->get_category_ids() );
		$this->assertFalse( $context->is_only_default_category() );
	}

	/**
	 * An override that is present wins over the stored product.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_present_override_wins() {
		$context = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array(
				'title'         => 'Draft name',
				'sku'           => 'DRAFT-1',
				'regular_price' => '5.00',
			)
		);

		$this->assertSame( 'Draft name', $context->get_title() );
		$this->assertSame( 'DRAFT-1', $context->get_sku() );
		$this->assertSame( '5.00', $context->get_regular_price() );
	}

	/**
	 * An absent override falls back to the stored product.
	 *
	 * This is what makes Quick Edit, which posts a price but no description, correct
	 * without any special-casing inside the rules.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_absent_override_falls_back_to_the_stored_product() {
		$context = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array( 'regular_price' => '5.00' )
		);

		$this->assertSame( 'Stored name', $context->get_title() );
		$this->assertSame( 'Stored description', $context->get_description() );
		$this->assertSame( 'STORED-1', $context->get_sku() );
		$this->assertSame( 55, $context->get_product_id() );
		$this->assertSame( 'draft', $context->get_post_status() );
	}

	/**
	 * A save request merges exactly like a draft snapshot.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_save_request_merges_over_the_stored_product() {
		$context = Product_Context::from_save_request(
			$this->stored_product(),
			array(
				'regular_price' => '',
				'post_status'   => 'publish',
			)
		);

		$this->assertSame( '', $context->get_regular_price() );
		$this->assertSame( 'publish', $context->get_post_status() );
		$this->assertSame( 'Stored description', $context->get_description() );
	}

	/**
	 * A save request for a product that was never stored falls back to empty values.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_save_request_without_a_stored_product_defaults_to_empty() {
		$context = Product_Context::from_save_request( null, array( 'title' => 'Brand new' ) );

		$this->assertSame( 'Brand new', $context->get_title() );
		$this->assertSame( 0, $context->get_product_id() );
		$this->assertSame( '', $context->get_description() );
		$this->assertSame( '', $context->get_regular_price() );
		$this->assertSame( array(), $context->get_category_ids() );
		$this->assertNull( $context->get_product() );
	}

	/**
	 * An override present but empty still wins: clearing a field is a real edit.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_override_still_wins() {
		$context = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array(
				'title' => '',
				'sku'   => '',
			)
		);

		$this->assertSame( '', $context->get_title() );
		$this->assertSame( '', $context->get_sku() );
	}

	/**
	 * The editor's field names are accepted as aliases for the engine's.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_draft_payload_aliases_are_resolved() {
		$context = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array(
				'content' => 'Draft body',
				'excerpt' => 'Draft excerpt',
			)
		);

		$this->assertSame( 'Draft body', $context->get_description() );
		$this->assertSame( 'Draft excerpt', $context->get_short_description() );
	}

	/**
	 * Unknown keys in an override array are ignored.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_unknown_override_keys_are_ignored() {
		$context = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array(
				'post_password' => 'secret',
				'title'         => 'Draft name',
			)
		);

		$this->assertSame( 'Draft name', $context->get_title() );
		$this->assertSame( 'Stored description', $context->get_description() );
	}

	/**
	 * Markup, shortcodes and whitespace runs are removed before counting characters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_length_ignores_markup_shortcodes_and_whitespace() {
		$context = Product_Context::from_array(
			array( 'description' => "<p>Hello</p>\n\n  <strong>world</strong> [gallery ids=\"1,2\"]" ),
		);

		// "Hello world" once the markup, the shortcode and the whitespace runs are gone.
		$this->assertSame( 11, $context->get_description_length() );
	}

	/**
	 * Multibyte characters count as one character each, not as their byte length.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_length_counts_multibyte_characters_once() {
		$context = Product_Context::from_array( array( 'short_description' => 'héllo' ) );

		$this->assertSame( 5, $context->get_short_description_length() );
	}

	/**
	 * An empty field has length zero, and so does a field of pure markup.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_markup_only_content_has_zero_length() {
		$context = Product_Context::from_array( array( 'description' => "<p></p>\n<br />" ) );

		$this->assertSame( 0, $context->get_description_length() );
	}

	/**
	 * The featured image is counted once even when it is also in the gallery.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_image_count_deduplicates_the_featured_image() {
		$context = Product_Context::from_array(
			array(
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 10, 11, 12 ),
			)
		);

		$this->assertSame( 3, $context->get_image_count() );
	}

	/**
	 * A featured image outside the gallery adds to the count.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_image_count_adds_a_featured_image_outside_the_gallery() {
		$context = Product_Context::from_array(
			array(
				'featured_image_id' => 9,
				'gallery_image_ids' => array( 10, 11 ),
			)
		);

		$this->assertSame( 3, $context->get_image_count() );
	}

	/**
	 * Core writes -1 into the featured image field when there is no image.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_no_image_sentinel_normalizes_to_zero() {
		$context = Product_Context::from_array( array( 'featured_image_id' => '-1' ) );

		$this->assertSame( 0, $context->get_featured_image_id() );
		$this->assertFalse( $context->has_valid_featured_image() );
		$this->assertSame( 0, $context->get_image_count() );
	}

	/**
	 * A gallery arrives from the DOM as a comma-separated string.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_comma_separated_gallery_is_parsed() {
		$context = Product_Context::from_array( array( 'gallery_image_ids' => '10,11,11,0' ) );

		$this->assertSame( array( 10, 11 ), $context->get_gallery_image_ids() );
	}

	/**
	 * An explicit validity flag beats the "id is set" inference.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_featured_image_validity_can_be_stated() {
		$context = Product_Context::from_array(
			array(
				'featured_image_id'        => 7,
				'has_valid_featured_image' => false,
			)
		);

		$this->assertSame( 7, $context->get_featured_image_id() );
		$this->assertFalse( $context->has_valid_featured_image() );
	}

	/**
	 * With a stored product, featured-image validity is checked against the library.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_featured_image_validity_is_checked_against_the_media_library() {
		$GLOBALS['sit_wcpg_test_image_ids'] = array( 9 );

		$valid   = Product_Context::from_product( $this->stored_product() );
		$missing = Product_Context::from_product( $this->stored_product( array( 'image_id' => 404 ) ) );

		$this->assertTrue( $valid->has_valid_featured_image() );
		$this->assertFalse( $missing->has_valid_featured_image() );
	}

	/**
	 * Terms are read for a stored product and overridden when the draft supplies them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_term_ids_come_from_the_product_or_the_override() {
		$GLOBALS['sit_wcpg_test_terms'] = array(
			'product_cat' => array( 3, 4 ),
			'product_tag' => array( 7 ),
		);

		$stored = Product_Context::from_product( $this->stored_product() );

		$this->assertSame( array( 3, 4 ), $stored->get_category_ids() );
		$this->assertSame( array( 7 ), $stored->get_tag_ids() );

		$drafted = Product_Context::from_product_with_overrides(
			$this->stored_product(),
			array( 'category_ids' => array( 5 ) )
		);

		$this->assertSame( array( 5 ), $drafted->get_category_ids() );
		$this->assertSame( array( 7 ), $drafted->get_tag_ids() );
	}

	/**
	 * A product carrying only the store default category is recognised as such.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_only_default_category_is_detected() {
		$only_default = Product_Context::from_array(
			array(
				'category_ids'        => array( 15 ),
				'default_category_id' => 15,
			)
		);

		$with_real = Product_Context::from_array(
			array(
				'category_ids'        => array( 15, 22 ),
				'default_category_id' => 15,
			)
		);

		$no_default = Product_Context::from_array(
			array(
				'category_ids'        => array( 22 ),
				'default_category_id' => 15,
			)
		);

		$this->assertTrue( $only_default->is_only_default_category() );
		$this->assertFalse( $with_real->is_only_default_category() );
		$this->assertFalse( $no_default->is_only_default_category() );
	}

	/**
	 * The store default category is read from the options table when not supplied.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_default_category_comes_from_the_option() {
		$GLOBALS['sit_wcpg_test_options'] = array( 'default_product_cat' => '15' );

		$context = Product_Context::from_product( $this->stored_product() );

		$this->assertSame( 15, $context->get_default_category_id() );
	}

	/**
	 * Sale dates are exposed as comparable `Y-m-d H:i:s` strings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_sale_dates_are_formatted_for_comparison() {
		$product = $this->stored_product(
			array(
				'sale_price'        => '9.99',
				'date_on_sale_from' => new \DateTimeImmutable( '2026-01-02 03:04:05' ),
				'date_on_sale_to'   => null,
			)
		);

		$context = Product_Context::from_product( $product );

		$this->assertSame( '2026-01-02 03:04:05', $context->get_sale_from() );
		$this->assertSame( '', $context->get_sale_to() );
	}

	/**
	 * Stock quantity keeps the null / integer distinction through an override.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_stock_quantity_preserves_null() {
		$unset   = Product_Context::from_array( array( 'stock_quantity' => '' ) );
		$zero    = Product_Context::from_array( array( 'stock_quantity' => '0' ) );
		$stocked = Product_Context::from_array( array( 'stock_quantity' => '12' ) );

		$this->assertNull( $unset->get_stock_quantity() );
		$this->assertSame( 0, $zero->get_stock_quantity() );
		$this->assertSame( 12, $stocked->get_stock_quantity() );
	}

	/**
	 * A context reads each product value once; repeated access is memoized.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_values_are_memoized() {
		$GLOBALS['sit_wcpg_test_terms'] = array( 'product_cat' => array( 3 ) );

		$context = Product_Context::from_product( $this->stored_product() );

		$this->assertSame( array( 3 ), $context->get_category_ids() );

		// Changing the source after the first read must not change the answer.
		$GLOBALS['sit_wcpg_test_terms'] = array( 'product_cat' => array( 99 ) );

		$this->assertSame( array( 3 ), $context->get_category_ids() );
	}
}
