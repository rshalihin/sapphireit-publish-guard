<?php
/**
 * Tests for the action an add-on fires after changing what the rules report.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;

/**
 * `sit_wcpg_rules_changed` does what a settings change does: it re-reads the settings, drops
 * what was memoized and queues a rebuild of the stored flags.
 *
 * @since 1.2.0
 */
final class Rules_Changed_Test extends Publish_Guard_Test_Case {

	/**
	 * Start with no index and an empty queue.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Readiness_Rebuild::OPTION );
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
	}

	/**
	 * Change the add-on's part of the settings hash, as a saved add-on configuration does.
	 *
	 * @since 1.2.0
	 *
	 * @param string $salt The salt.
	 * @return void
	 */
	private function salt_with( string $salt ): void {
		add_filter(
			'sit_wcpg_settings_hash_salt',
			static function () use ( $salt ): string {
				return $salt;
			}
		);
	}

	/**
	 * The stored flags are rebuilt for the signature the add-on's change produced.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rules_changed_starts_a_rebuild(): void {
		$this->passing_product();

		$before = Readiness_Store::signature( Plugin::instance()->settings() );

		$this->salt_with( 'addon:1' );

		$after = Readiness_Store::signature( Plugin::instance()->settings() );

		$this->assertNotSame( $before, $after );

		do_action( 'sit_wcpg_rules_changed' );

		$index = get_option( Readiness_Rebuild::OPTION );

		$this->assertIsArray( $index );
		$this->assertSame( Readiness_Rebuild::STATE_BUILDING, $index['state'] );
		$this->assertSame( $after, $index['signature'] );
		$this->assertSame( Readiness_Rebuild::STATE_BUILDING, Plugin::instance()->readiness_rebuild()->status()['state'] );

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => Readiness_Rebuild::HOOK,
				'group'    => Readiness_Rebuild::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			)
		);

		$this->assertCount( 1, $pending );
	}

	/**
	 * A result validated before the change is not served after it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rules_changed_drops_the_checklist_memo(): void {
		$product_id = $this->passing_product();
		$checklist  = Plugin::instance()->checklist();

		$first = $checklist->validate_post( $product_id );

		$this->salt_with( 'addon:2' );

		do_action( 'sit_wcpg_rules_changed' );

		$second = $checklist->validate_post( $product_id );

		$this->assertNotSame( $first->get_settings_hash(), $second->get_settings_hash() );
		$this->assertSame( Plugin::instance()->settings()->get_hash(), $second->get_settings_hash() );
	}

	/**
	 * Settings written behind the facade's back are read again.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rules_changed_refreshes_settings(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$this->assertSame( 2, $settings->get_all()['thresholds']['min_images'] );

		$stored['thresholds']['min_images'] = 5;

		// Without the option hooks, nothing but the action under test refreshes the facade.
		remove_all_actions( 'update_option_' . Settings::OPTION_NAME );
		remove_all_actions( 'add_option_' . Settings::OPTION_NAME );

		update_option( Settings::OPTION_NAME, $stored );

		$this->assertSame( 2, $settings->get_all()['thresholds']['min_images'], 'The facade still holds its memo.' );

		do_action( 'sit_wcpg_rules_changed' );

		$this->assertSame( 5, $settings->get_all()['thresholds']['min_images'] );
	}
}
