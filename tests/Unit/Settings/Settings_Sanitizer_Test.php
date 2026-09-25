<?php
/**
 * Tests for the settings sanitizer.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Settings;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Sanitizer;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the whitelist, the enum checks, the clamping and the version lock.
 *
 * @since 1.0.0
 */
final class Settings_Sanitizer_Test extends TestCase {

	/**
	 * The sanitizer under test.
	 *
	 * @since 1.0.0
	 * @var Settings_Sanitizer
	 */
	private Settings_Sanitizer $sanitizer;

	/**
	 * Build a sanitizer over two rules with different shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function set_up() {
		$GLOBALS['sit_wcpg_test_options']         = array();
		$GLOBALS['sit_wcpg_test_settings_errors'] = array();

		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'title', 'pass', 10, true, false, Severity::REQUIRED, true ) );
		$registry->register( new Fake_Rule( 'sku', 'pass', 20, true, false, Severity::WARNING, false ) );

		$this->sanitizer = new Settings_Sanitizer( new Settings( $registry ) );
	}

	/**
	 * Clear the globals the stubs write to.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_options'], $GLOBALS['sit_wcpg_test_settings_errors'] );
	}

	/**
	 * A payload that saves everything, used as the base for the focused cases.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function full_payload(): array {
		return array(
			'enabled'      => '1',
			'rules'        => array(
				'title' => array(
					'enabled'  => '1',
					'severity' => Severity::REQUIRED,
				),
				'sku'   => array(
					'enabled'  => '1',
					'severity' => Severity::WARNING,
				),
			),
			'thresholds'   => array(
				'min_description_chars'       => '150',
				'min_short_description_chars' => '50',
				'min_images'                  => '2',
			),
			'publishing'   => array(
				'block_on_required_failure' => '1',
				'allow_admin_override'      => '1',
				'enforce_scope'             => Settings::SCOPE_EDITOR,
			),
			'product_list' => array(
				'show_column' => '1',
			),
		);
	}

	/**
	 * A well-formed payload survives intact.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_valid_payload_is_stored_as_submitted(): void {
		$clean = $this->sanitizer->sanitize( $this->full_payload() );

		$this->assertTrue( $clean['enabled'] );
		$this->assertTrue( $clean['rules']['title']['enabled'] );
		$this->assertSame( Severity::WARNING, $clean['rules']['sku']['severity'] );
		$this->assertSame( 150, $clean['thresholds']['min_description_chars'] );
		$this->assertTrue( $clean['publishing']['allow_admin_override'] );
		$this->assertSame( Settings::SCOPE_EDITOR, $clean['publishing']['enforce_scope'] );
		$this->assertTrue( $clean['product_list']['show_column'] );
	}

	/**
	 * A rule identifier the registry does not know is never stored.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_unknown_rule_ids_are_dropped(): void {
		$payload                              = $this->full_payload();
		$payload['rules']['evil_rule']        = array(
			'enabled'  => '1',
			'severity' => Severity::REQUIRED,
		);
		$payload['rules']['../../etc/passwd'] = array( 'enabled' => '1' );

		$clean = $this->sanitizer->sanitize( $payload );

		$this->assertSame( array( 'title', 'sku' ), array_keys( $clean['rules'] ) );
	}

	/**
	 * Only the six documented top-level keys can ever be stored.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_unknown_top_level_keys_are_dropped(): void {
		$payload                             = $this->full_payload();
		$payload['injected']                 = 'anything';
		$payload['license_key']              = array( 'nested' => true );
		$payload['product_list']['injected'] = '1';

		$clean = $this->sanitizer->sanitize( $payload );

		$this->assertSame(
			array( 'version', 'enabled', 'rules', 'thresholds', 'publishing', 'product_list' ),
			array_keys( $clean )
		);
		$this->assertSame( array( 'show_column' ), array_keys( $clean['product_list'] ) );
	}

	/**
	 * The schema version comes from the code, whatever the request says.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_version_cannot_be_injected(): void {
		$payload            = $this->full_payload();
		$payload['version'] = 99;

		$this->assertSame( Settings::VERSION, $this->sanitizer->sanitize( $payload )['version'] );
	}

	/**
	 * An unrecognised severity falls back to the one the rule ships with.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_invalid_severity_falls_back_to_the_rule_default(): void {
		$payload                               = $this->full_payload();
		$payload['rules']['title']['severity'] = 'catastrophic';
		$payload['rules']['sku']['severity']   = array( 'not', 'a', 'string' );

		$clean = $this->sanitizer->sanitize( $payload );

		$this->assertSame( Severity::REQUIRED, $clean['rules']['title']['severity'] );
		$this->assertSame( Severity::WARNING, $clean['rules']['sku']['severity'] );
	}

	/**
	 * An unchecked box is not posted, and that has to mean off.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_absent_checkboxes_are_stored_as_off(): void {
		$payload = $this->full_payload();
		unset( $payload['enabled'], $payload['rules']['sku']['enabled'], $payload['product_list']['show_column'] );
		$payload['publishing']['allow_admin_override'] = '0';

		$clean = $this->sanitizer->sanitize( $payload );

		$this->assertFalse( $clean['enabled'] );
		$this->assertFalse( $clean['rules']['sku']['enabled'] );
		$this->assertFalse( $clean['product_list']['show_column'] );
		$this->assertFalse( $clean['publishing']['allow_admin_override'] );
	}

	/**
	 * Every threshold is held inside its documented range.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider data_threshold_clamping
	 *
	 * @param string $key      Threshold key.
	 * @param mixed  $input    Submitted value.
	 * @param int    $expected Value that should be stored.
	 * @return void
	 */
	public function test_thresholds_are_clamped( string $key, $input, int $expected ): void {
		$payload                       = $this->full_payload();
		$payload['thresholds'][ $key ] = $input;

		$this->assertSame( $expected, $this->sanitizer->sanitize( $payload )['thresholds'][ $key ] );
	}

	/**
	 * Upper and lower bound cases for each threshold.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function data_threshold_clamping(): array {
		return array(
			'description above max'       => array( 'min_description_chars', '999999', 10000 ),
			'description at max'          => array( 'min_description_chars', '10000', 10000 ),
			'description at min'          => array( 'min_description_chars', '0', 0 ),
			'short description above max' => array( 'min_short_description_chars', '6000', 5000 ),
			'images above max'            => array( 'min_images', '400', 20 ),
			'images at min'               => array( 'min_images', '0', 0 ),
		);
	}

	/**
	 * Clamping is reported, so the screen never disagrees with what was typed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_clamping_adds_a_settings_notice(): void {
		$payload                             = $this->full_payload();
		$payload['thresholds']['min_images'] = '400';

		$this->sanitizer->sanitize( $payload );

		$this->assertCount( 1, $GLOBALS['sit_wcpg_test_settings_errors'] );
		$this->assertSame( Settings::OPTION_NAME, $GLOBALS['sit_wcpg_test_settings_errors'][0]['setting'] );
		$this->assertStringContainsString( '20', $GLOBALS['sit_wcpg_test_settings_errors'][0]['message'] );
	}

	/**
	 * A value already inside the range is stored silently.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_in_range_threshold_reports_nothing(): void {
		$this->sanitizer->sanitize( $this->full_payload() );

		$this->assertSame( array(), $GLOBALS['sit_wcpg_test_settings_errors'] );
	}

	/**
	 * Anything that is not a number falls back to the shipped default.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_non_numeric_thresholds_fall_back_to_the_default(): void {
		$payload               = $this->full_payload();
		$payload['thresholds'] = array(
			'min_description_chars'       => 'lots',
			'min_short_description_chars' => '',
			'min_images'                  => array( 3 ),
		);

		$clean = $this->sanitizer->sanitize( $payload );

		$this->assertSame( 150, $clean['thresholds']['min_description_chars'] );
		$this->assertSame( 50, $clean['thresholds']['min_short_description_chars'] );
		$this->assertSame( 2, $clean['thresholds']['min_images'] );
	}

	/**
	 * The enforcement scope is an enum, not free text.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_invalid_enforcement_scope_falls_back_to_the_default(): void {
		$payload                                = $this->full_payload();
		$payload['publishing']['enforce_scope'] = 'everything_everywhere';

		$this->assertSame(
			Settings::SCOPE_AUTHENTICATED,
			$this->sanitizer->sanitize( $payload )['publishing']['enforce_scope']
		);
	}

	/**
	 * A payload that is not an array at all produces the defaults, not a fatal.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_non_array_payload_produces_a_complete_array(): void {
		$clean = $this->sanitizer->sanitize( 'sit_wcpg_settings=pwned' );

		$this->assertSame( Settings::VERSION, $clean['version'] );
		$this->assertFalse( $clean['enabled'] );
		$this->assertSame( array( 'title', 'sku' ), array_keys( $clean['rules'] ) );
		$this->assertSame( 150, $clean['thresholds']['min_description_chars'] );
		$this->assertSame( Settings::SCOPE_AUTHENTICATED, $clean['publishing']['enforce_scope'] );
	}

	/**
	 * A rule registered later appears in the stored array with its shipped severity.
	 *
	 * Its `enabled` flag follows checkbox semantics like every other rule's: the form
	 * that saved this payload did not know about it, so it is stored as off until the
	 * merchant saves the form again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_newly_registered_rule_is_whitelisted_with_its_shipped_severity(): void {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'title', 'pass', 10, true, false, Severity::REQUIRED, true ) );
		$registry->register( new Fake_Rule( 'alt_text', 'pass', 15, true, false, Severity::WARNING, true ) );

		$sanitizer = new Settings_Sanitizer( new Settings( $registry ) );
		$clean     = $sanitizer->sanitize( $this->full_payload() );

		$this->assertArrayHasKey( 'alt_text', $clean['rules'] );
		$this->assertSame( Severity::WARNING, $clean['rules']['alt_text']['severity'] );
	}
}
