<?php
/**
 * Tests for the featured image rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Featured_Image_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers "no image", "image that is no longer there" and the passing case.
 *
 * @since 1.0.0
 */
final class Featured_Image_Rule_Test extends TestCase {

	/**
	 * Run the rule against a context.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Context values.
	 * @return Rule_Result
	 */
	private function check( array $data ): Rule_Result {
		return ( new Featured_Image_Rule() )->check(
			Product_Context::from_array( $data ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * No featured image is an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_featured_image_fails() {
		$result = $this->check( array( 'featured_image_id' => 0 ) );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'This product has no featured image — set one in the Product image box.', $result->get_message() );
	}

	/**
	 * Core's "no image" sentinel is normalized by the context, so the rule sees zero.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_minus_one_sentinel_fails_as_no_image() {
		$result = $this->check( array( 'featured_image_id' => -1 ) );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'This product has no featured image — set one in the Product image box.', $result->get_message() );
	}

	/**
	 * An attachment that has been deleted, or is not an image, gets its own message:
	 * the product looks fine in the editor but renders without a picture.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_attachment_that_is_not_an_image_fails() {
		$result = $this->check(
			array(
				'featured_image_id'        => 77,
				'has_valid_featured_image' => false,
			)
		);

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The featured image is no longer in the media library — choose a new one in the Product image box.', $result->get_message() );
	}

	/**
	 * A real image passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_valid_featured_image_passes() {
		$result = $this->check(
			array(
				'featured_image_id'        => 77,
				'has_valid_featured_image' => true,
			)
		);

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( '', $result->get_message() );
	}

	/**
	 * The result carries the image count, which the panel shows alongside the row.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_result_reports_the_image_count() {
		$result = $this->check(
			array(
				'featured_image_id' => 0,
				'gallery_image_ids' => array( 4, 5 ),
			)
		);

		$this->assertSame( array( 'image_count' => 2 ), $result->get_data() );
	}
}
