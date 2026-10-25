<?php
/**
 * Tests for the image count rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Image_Count_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the threshold branches and the de-duplication the count relies on.
 *
 * @since 1.0.0
 */
final class Image_Count_Rule_Test extends TestCase {

	/**
	 * A settings double that answers the image threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param int $minimum Value returned for `min_images`.
	 * @return Settings
	 */
	private function settings( int $minimum ): Settings {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'threshold' )->willReturnCallback(
			static function ( $key ) use ( $minimum ) {
				return 'min_images' === $key ? $minimum : 0;
			}
		);

		return $settings;
	}

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data    Context values.
	 * @param int   $minimum Configured threshold.
	 * @return Rule_Result
	 */
	private function check( array $data, int $minimum ): Rule_Result {
		return ( new Image_Count_Rule() )->check( Product_Context::from_array( $data ), $this->settings( $minimum ) );
	}

	/**
	 * A single image takes the singular form.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_single_image_uses_the_singular() {
		$this->assertSame(
			'This product has 1 image; at least 3 are recommended — add more in the Product gallery box.',
			$this->check( array( 'featured_image_id' => 10 ), 3 )->get_message()
		);
	}

	/**
	 * Fewer images than the merchant asked for reports an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_below_the_minimum_fails() {
		$result = $this->check(
			array(
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 11 ),
			),
			3
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'This product has 2 images; at least 3 are recommended — add more in the Product gallery box.', $result->get_message() );
		$this->assertSame(
			array(
				'image_count' => 2,
				'minimum'     => 3,
			),
			$result->get_data()
		);
	}

	/**
	 * The threshold is inclusive.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_exactly_the_minimum_passes() {
		$result = $this->check(
			array(
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 11 ),
			),
			2
		);

		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A featured image that is also in the gallery is one image, not two.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_featured_image_is_counted_once() {
		$result = $this->check(
			array(
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 10, 11 ),
			),
			2
		);

		$this->assertSame( 2, $result->get_data()['image_count'] );
		$this->assertSame( Status::PASS, $result->get_status() );
	}

	/**
	 * A minimum of one or less would only repeat the featured image rule, so the row
	 * takes itself out of the checklist instead.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_minimum_of_one_or_less_skips() {
		$this->assertSame( Status::SKIPPED, $this->check( array(), 1 )->get_status() );
		$this->assertSame( Status::SKIPPED, $this->check( array(), 0 )->get_status() );
		$this->assertSame(
			Status::SKIPPED,
			$this->check( array( 'featured_image_id' => 10 ), 1 )->get_status(),
			'A skip must not depend on the product having no images.'
		);
	}

	/**
	 * A product with no images at all fails rather than dividing by nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_images_at_all_fails() {
		$result = $this->check( array(), 2 );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 0, $result->get_data()['image_count'] );
	}

	/**
	 * A variable product's count covers its enabled variations' images and galleries.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_counts_variation_images_and_galleries() {
		$result = $this->check(
			array(
				'product_type'      => 'variable',
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 11 ),
				'variations'        => array(
					array(
						'id'                => 101,
						'image_id'          => 20,
						'gallery_image_ids' => array( 21 ),
					),
					array(
						'id'       => 102,
						'image_id' => 30,
					),
				),
			),
			5
		);

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( 5, $result->get_data()['image_count'] );
	}

	/**
	 * An image used on the parent and on a variation is one image.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_counts_shared_images_once() {
		$result = $this->check(
			array(
				'product_type'      => 'variable',
				'featured_image_id' => 10,
				'gallery_image_ids' => array( 11 ),
				'variations'        => array(
					array(
						'id'                => 101,
						'image_id'          => 10,
						'gallery_image_ids' => array( 11, 12 ),
					),
					array(
						'id'       => 102,
						'image_id' => 12,
					),
				),
			),
			4
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 3, $result->get_data()['image_count'] );
	}

	/**
	 * Below the minimum, a variable product is told the variations count too.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variable_copy_when_below_minimum() {
		$singular = $this->check(
			array(
				'product_type'      => 'variable',
				'featured_image_id' => 10,
				'variations'        => array(
					array(
						'id'       => 101,
						'image_id' => 10,
					),
				),
			),
			3
		);

		$this->assertSame( Status::FAIL, $singular->get_status() );
		$this->assertSame(
			'This product and its variations have 1 image; at least 3 are recommended — add more in the Product gallery box or on the variations.',
			$singular->get_message()
		);

		$plural = $this->check(
			array(
				'product_type'      => 'variable',
				'featured_image_id' => 10,
				'variations'        => array(
					array(
						'id'       => 101,
						'image_id' => 20,
					),
				),
			),
			3
		);

		$this->assertSame(
			'This product and its variations have 2 images; at least 3 are recommended — add more in the Product gallery box or on the variations.',
			$plural->get_message()
		);
	}

	/**
	 * Only enabled variations reach the context: `Child_Loader` drops the disabled ones
	 * (covered in `Child_Loader_Test`). The count is therefore exactly the parent's images
	 * plus those of the variations seeded here.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_disabled_variation_images_do_not_count() {
		$result = $this->check(
			array(
				'product_type'      => 'variable',
				'featured_image_id' => 10,
				'variations'        => array(
					array(
						'id'       => 101,
						'image_id' => 20,
					),
				),
			),
			3
		);

		$this->assertSame( 2, $result->get_data()['image_count'] );
		$this->assertSame( Status::FAIL, $result->get_status() );
	}

	/**
	 * Simple, grouped and external products keep the v1.0 count and copy, even when
	 * variation data is present.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_simple_unchanged() {
		foreach ( array( 'simple', 'grouped', 'external' ) as $type ) {
			$result = $this->check(
				array(
					'product_type'      => $type,
					'featured_image_id' => 10,
					'variations'        => array(
						array(
							'id'                => 101,
							'image_id'          => 20,
							'gallery_image_ids' => array( 21 ),
						),
					),
				),
				3
			);

			$this->assertSame( 1, $result->get_data()['image_count'], $type );
			$this->assertSame(
				'This product has 1 image; at least 3 are recommended — add more in the Product gallery box.',
				$result->get_message(),
				$type
			);
		}
	}

	/**
	 * The settings screen says that variation images count.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_description_text() {
		$this->assertSame(
			'Checks the total number of images on the product, counting the featured image and the gallery together. On a variable product, images on enabled variations count too.',
			( new Image_Count_Rule() )->get_description()
		);
	}
}
