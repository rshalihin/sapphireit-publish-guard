<?php
/**
 * Tests for the long description rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Description_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the empty, below-threshold, at-threshold and threshold-disabled branches, plus
 * the length normalization the rule depends on.
 *
 * @since 1.0.0
 */
final class Description_Rule_Test extends TestCase {

	/**
	 * A settings double that answers one threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param int $minimum Value returned for `min_description_chars`.
	 * @return Settings
	 */
	private function settings( int $minimum ): Settings {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'threshold' )->willReturnCallback(
			static function ( $key ) use ( $minimum ) {
				return 'min_description_chars' === $key ? $minimum : 0;
			}
		);

		return $settings;
	}

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $description Raw description.
	 * @param int    $minimum     Configured threshold.
	 * @return Rule_Result
	 */
	private function check( string $description, int $minimum ): Rule_Result {
		return ( new Description_Rule() )->check(
			Product_Context::from_array( array( 'description' => $description ) ),
			$this->settings( $minimum )
		);
	}

	/**
	 * No description is an unmet requirement, not an advisory note.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_description_fails() {
		$result = $this->check( '', 150 );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The product description is empty — write one in the main editor below the title.', $result->get_message() );
		$this->assertSame(
			array(
				'length'  => 0,
				'minimum' => 150,
			),
			$result->get_data()
		);
	}

	/**
	 * Markup with no text behind it counts as empty.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_markup_with_no_text_fails() {
		$this->assertSame( Status::FAIL, $this->check( '<p></p><br />', 150 )->get_status() );
	}

	/**
	 * A short description warns, and the merchant is told both numbers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_short_description_warns_with_both_numbers() {
		$result = $this->check( str_repeat( 'a', 40 ), 150 );

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 'The product description is 40 characters; at least 150 are recommended — expand it in the main editor below the title.', $result->get_message() );
	}

	/**
	 * A one-character description takes the singular form.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_one_character_description_uses_the_singular() {
		$this->assertSame(
			'The product description is 1 character; at least 150 are recommended — expand it in the main editor below the title.',
			$this->check( 'a', 150 )->get_message()
		);
	}

	/**
	 * The threshold is inclusive: exactly the minimum is enough.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_exactly_the_threshold_passes() {
		$this->assertSame( Status::PASS, $this->check( str_repeat( 'a', 150 ), 150 )->get_status() );
	}

	/**
	 * More than the threshold passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_above_the_threshold_passes() {
		$this->assertSame( Status::PASS, $this->check( str_repeat( 'a', 400 ), 150 )->get_status() );
	}

	/**
	 * A threshold of zero switches the advisory branch off, but still requires text.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_zero_threshold_never_warns() {
		$this->assertSame( Status::PASS, $this->check( 'a', 0 )->get_status() );
		$this->assertSame( Status::FAIL, $this->check( '', 0 )->get_status() );
	}

	/**
	 * Markup and shortcodes are removed before the characters are counted, and a
	 * shortcode is never executed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_markup_and_shortcodes_are_stripped_before_counting() {
		$result = $this->check( '<p>[gallery ids="1,2"]<strong>Hello</strong>   world</p>', 100 );

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 11, $result->get_data()['length'], 'Expected "Hello world".' );
	}

	/**
	 * Multibyte text is counted in characters, not bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_multibyte_text_is_counted_in_characters() {
		$result = $this->check( 'Ünïcödé ✓', 100 );

		$this->assertSame( 9, $result->get_data()['length'] );
	}
}
