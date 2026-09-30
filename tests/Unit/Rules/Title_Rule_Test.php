<?php
/**
 * Tests for the product title rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Title_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers every branch of the title rule.
 *
 * @since 1.0.0
 */
final class Title_Rule_Test extends TestCase {

	/**
	 * Run the rule against a title.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Product title.
	 * @return Rule_Result
	 */
	private function check( string $title ): Rule_Result {
		return ( new Title_Rule() )->check(
			Product_Context::from_array( array( 'title' => $title ) ),
			$this->createMock( Settings::class )
		);
	}

	/**
	 * A product with no title at all is not ready.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_title_fails() {
		$result = $this->check( '' );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'Add a product title.', $result->get_message() );
	}

	/**
	 * Whitespace is not a title.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_whitespace_only_title_fails() {
		$this->assertSame( Status::FAIL, $this->check( "  \t\n " )->get_status() );
	}

	/**
	 * The placeholder WordPress writes into a new auto-draft is not a title either.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_auto_draft_placeholder_fails() {
		$this->assertSame( Status::FAIL, $this->check( 'Auto Draft' )->get_status() );
		$this->assertSame( Status::FAIL, $this->check( '  auto draft  ' )->get_status() );
	}

	/**
	 * WooCommerce overwrites a product auto-draft's title with `AUTO-DRAFT`; that is no
	 * title either, or an untouched new product would show the title check as passed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_woocommerce_auto_draft_placeholder_fails() {
		$this->assertSame( Status::FAIL, $this->check( 'AUTO-DRAFT' )->get_status() );
		$this->assertSame( Status::FAIL, $this->check( ' auto-draft ' )->get_status() );
	}

	/**
	 * A real title passes, and says nothing further.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_real_title_passes() {
		$result = $this->check( 'Hand-thrown stoneware mug' );

		$this->assertSame( Status::PASS, $result->get_status() );
		$this->assertSame( '', $result->get_message() );
	}

	/**
	 * The rule points the merchant at the title field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_fix_target_is_the_title_field() {
		$this->assertSame( '#title', $this->check( '' )->get_fix()['selector'] );
	}
}
