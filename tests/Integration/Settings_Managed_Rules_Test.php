<?php
/**
 * Tests that managed rules stay out of the settings screen and the stored option.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Settings\Settings_Sanitizer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Tests\Stubs\Managed_Fake_Rule;
use WP_UnitTestCase;

/**
 * A managed rule is configured by the add-on that registered it, so the merchant is never
 * offered a switch for it and nothing about it is stored.
 *
 * @since 1.3.0
 */
final class Settings_Managed_Rules_Test extends WP_UnitTestCase {

	/**
	 * Start from a site that has never saved the form.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * The shipped rules plus one managed rule.
	 *
	 * @since 1.3.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		$registry = new Rule_Registry();

		Rules_Provider::populate( $registry );
		$registry->register( new Managed_Fake_Rule( 'probe_managed', true, Severity::WARNING, Severity::REQUIRED, 'Probe managed' ) );

		return $registry;
	}

	/**
	 * The rules table lists the shipped checks and not the managed one.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_settings_screen_does_not_list_managed_rules(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$registry = $this->registry();

		ob_start();
		( new Settings_Page( new Settings( $registry ), $registry ) )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Probe managed', $output );
		$this->assertStringNotContainsString( 'probe_managed', $output );
		$this->assertStringContainsString( 'SKU', $output );
	}

	/**
	 * A submitted key for a managed rule is dropped by the sanitizer.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_saving_settings_stores_no_managed_key(): void {
		$settings = new Settings( $this->registry() );

		update_option(
			Settings::OPTION_NAME,
			( new Settings_Sanitizer( $settings ) )->sanitize(
				array(
					'enabled' => '1',
					'rules'   => array(
						'sku'           => array(
							'enabled'  => '1',
							'severity' => Severity::REQUIRED,
						),
						'probe_managed' => array(
							'enabled'  => '1',
							'severity' => Severity::REQUIRED,
						),
					),
				)
			)
		);

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertArrayHasKey( 'sku', $stored['rules'] );
		$this->assertArrayNotHasKey( 'probe_managed', $stored['rules'] );

		$settings->refresh();

		$this->assertArrayNotHasKey( 'probe_managed', $settings->get_all()['rules'] );
	}
}
