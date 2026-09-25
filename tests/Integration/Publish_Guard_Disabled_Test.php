<?php
/**
 * The guard switched off.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use WC_Product_Simple;

/**
 * With `block_on_required_failure` off, nothing is blocked and the checklist still
 * reports (section 12.2, manual row 20).
 *
 * @since 1.0.0
 */
final class Publish_Guard_Disabled_Test extends Publish_Guard_Test_Case {

	/**
	 * Every path publishes a failing product, and the checklist still names the failure.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_nothing_is_blocked_and_the_checklist_still_reports(): void {
		$this->set_publishing( array( 'block_on_required_failure' => false ) );

		$updated = $this->failing_product();
		$this->publish( $updated );

		$saved   = $this->failing_product();
		$product = wc_get_product( $saved );
		$product->set_status( 'publish' );
		$product->save();

		$this->assertSame( 'publish', $this->status_of( $updated ) );
		$this->assertSame( 'publish', $this->status_of( $saved ) );
		$this->assertSame( array(), $this->pending_notices() );

		$result = Plugin::instance()->checklist()->validate_post( $updated );

		$this->assertContains( 'price', $result->get_required_failures() );
	}

	/**
	 * Switching the whole plugin off disables the guard too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_disabled_plugin_blocks_nothing(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$id = $this->failing_product();
		$this->publish( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * Manual row 16: with every rule disabled there is nothing to fail, so an empty
	 * product publishes on every path and the checklist evaluates nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_all_rules_disabled_blocks_nothing(): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		foreach ( array_keys( $stored['rules'] ) as $rule_id ) {
			$stored['rules'][ $rule_id ]['enabled'] = false;
		}

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$empty = new WC_Product_Simple();
		$empty->save();

		$this->publish( $empty->get_id() );

		$crud = new WC_Product_Simple();
		$crud->set_status( 'publish' );
		$crud->save();

		$this->assertSame( 'publish', $this->status_of( $empty->get_id() ) );
		$this->assertSame( 'publish', $this->status_of( $crud->get_id() ) );
		$this->assertSame( array(), $this->pending_notices() );

		$result = Plugin::instance()->checklist()->validate_post( $empty->get_id() );

		$this->assertSame( array(), $result->get_results() );
		$this->assertSame( 0, $result->get_counts()['evaluated'] );
		$this->assertTrue( $result->is_ready() );
	}
}
