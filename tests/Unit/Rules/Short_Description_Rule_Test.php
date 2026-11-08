<?php
/**
 * Tests for the short description rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Short_Description_Rule;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The same matrix as the description rule, against its own threshold — and proving the
 * rule reads that threshold and not the long description's.
 *
 * @since 1.0.0
 */
final class Short_Description_Rule_Test extends TestCase {

	/**
	 * A settings double that answers both content thresholds separately.
	 *
	 * @since 1.0.0
	 *
	 * @param int $minimum Value returned for `min_short_description_chars`.
	 * @return Settings
	 */
	private function settings( int $minimum ): Settings {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'threshold' )->willReturnCallback(
			static function ( $key ) use ( $minimum ) {
				// A deliberately different value, so reading the wrong key is visible.
				return 'min_short_description_chars' === $key ? $minimum : 9999;
			}
		);

		return $settings;
	}

	/**
	 * Run the rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $short_description Raw short description.
	 * @param int    $minimum           Configured threshold.
	 * @return Rule_Result
	 */
	private function check( string $short_description, int $minimum ): Rule_Result {
		return ( new Short_Description_Rule() )->check(
			Product_Context::from_array( array( 'short_description' => $short_description ) ),
			$this->settings( $minimum )
		);
	}

	/**
	 * No short description is an unmet requirement.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_empty_short_description_fails() {
		$result = $this->check( '', 50 );

		$this->assertSame( Status::FAIL, $result->get_status() );
		$this->assertSame( 'The short description is empty — write one in the Product short description box.', $result->get_message() );
	}

	/**
	 * A short one warns, and the merchant is told both numbers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_short_one_warns_with_both_numbers() {
		$result = $this->check( str_repeat( 'a', 20 ), 50 );

		$this->assertSame( Status::WARNING, $result->get_status() );
		$this->assertSame( 'The short description is 20 characters; at least 50 are recommended — expand it in the Product short description box.', $result->get_message() );
	}

	/**
	 * A one-character short description takes the singular form.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_one_character_one_uses_the_singular() {
		$this->assertSame(
			'The short description is 1 character; at least 50 are recommended — expand it in the Product short description box.',
			$this->check( 'a', 50 )->get_message()
		);
	}

	/**
	 * The threshold is inclusive.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_exactly_the_threshold_passes() {
		$this->assertSame( Status::PASS, $this->check( str_repeat( 'a', 50 ), 50 )->get_status() );
	}

	/**
	 * More than the threshold passes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_above_the_threshold_passes() {
		$this->assertSame( Status::PASS, $this->check( str_repeat( 'a', 120 ), 50 )->get_status() );
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
	 * Markup and shortcodes are stripped before counting, and multibyte text is counted
	 * in characters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_length_normalization_matches_the_long_description() {
		$this->assertSame( 11, $this->check( '<p>[shortcode]<em>Hello</em>   world</p>', 100 )->get_data()['length'] );
		$this->assertSame( 9, $this->check( 'Ünïcödé ✓', 100 )->get_data()['length'] );
	}

	/**
	 * The rule is advisory out of the box: plenty of stores never use this field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_it_ships_as_a_warning() {
		$this->assertSame( Severity::WARNING, ( new Short_Description_Rule() )->get_default_severity() );
	}
}
