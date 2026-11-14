<?php
/**
 * Tests for the filter that lets an add-on's configuration take part in the settings hash.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use WP_UnitTestCase;

/**
 * The hash is in every cache key and in the readiness signature, so a salt changes them all,
 * and no salt leaves them exactly as they were before the filter existed.
 *
 * @since 1.2.0
 */
final class Settings_Hash_Salt_Test extends WP_UnitTestCase {

	/**
	 * Start from a site that has never saved the form.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Settings over one known rule.
	 *
	 * @since 1.2.0
	 *
	 * @return Settings
	 */
	private function settings(): Settings {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'title' ) );

		return new Settings( $registry );
	}

	/**
	 * The hash's input before any salt.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings $settings The settings.
	 * @return string
	 */
	private function json( Settings $settings ): string {
		return (string) wp_json_encode( $settings->get_all() );
	}

	/**
	 * Make the filter return a value.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $salt What the filter returns.
	 * @return void
	 */
	private function salt_with( $salt ): void {
		add_filter(
			'sit_wcpg_settings_hash_salt',
			static function () use ( $salt ) {
				return $salt;
			}
		);
	}

	/**
	 * With nothing on the filter the hash is the one stored signatures were written with.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_hash_without_salt_is_unchanged(): void {
		$settings = $this->settings();

		$this->assertSame( md5( $this->json( $settings ) ), $settings->get_hash() );
	}

	/**
	 * A salt is appended to the hashed text after a separator.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_salt_changes_the_hash(): void {
		$settings = $this->settings();
		$unsalted = $settings->get_hash();

		$this->salt_with( 'pro:abc' );

		$this->assertNotSame( $unsalted, $settings->get_hash() );
		$this->assertSame( md5( $this->json( $settings ) . '|pro:abc' ), $settings->get_hash() );
	}

	/**
	 * Two add-on configurations never share a hash.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_different_salts_give_different_hashes(): void {
		$settings = $this->settings();

		$this->salt_with( 'pro:abc' );
		$first = $settings->get_hash();

		remove_all_filters( 'sit_wcpg_settings_hash_salt' );
		$this->salt_with( 'pro:abd' );

		$this->assertNotSame( $first, $settings->get_hash() );
	}

	/**
	 * A filter that returns something other than a string changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_non_string_salt_is_ignored(): void {
		$settings = $this->settings();

		$this->salt_with( array( 'pro:abc' ) );

		$this->assertSame( md5( $this->json( $settings ) ), $settings->get_hash() );
	}

	/**
	 * A long salt is reduced to its own hash before it is used.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_long_salt_is_reduced(): void {
		$settings = $this->settings();
		$salt     = str_repeat( 'x', 500 );

		$this->salt_with( $salt );

		$this->assertSame( md5( $this->json( $settings ) . '|' . md5( $salt ) ), $settings->get_hash() );
	}
}
