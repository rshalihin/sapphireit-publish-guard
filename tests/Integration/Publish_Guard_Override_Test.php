<?php
/**
 * The administrator override.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Plugin;

/**
 * Override needs the setting **and** `manage_woocommerce` (section 6.3.3, manual row 21).
 *
 * @since 1.0.0
 */
final class Publish_Guard_Override_Test extends Publish_Guard_Test_Case {

	/**
	 * Act as a fresh user with a role.
	 *
	 * @since 1.0.0
	 *
	 * @param string $role Role name.
	 * @return int User id.
	 */
	private function act_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * A shop manager publishes past a failure, and is told the override was used.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_shop_manager_can_override_when_allowed(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$id      = $this->failing_product();
		$manager = $this->act_as( 'shop_manager' );

		$this->publish( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id, Notices::KIND_OVERRIDE, $manager );
	}

	/**
	 * A user without `manage_woocommerce` cannot override, setting or not.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_user_without_manage_woocommerce_cannot_override(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$id = $this->failing_product();
		$this->act_as( 'editor' );

		$this->publish( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertFalse( Plugin::instance()->publish_guard()->can_override( get_current_user_id() ) );
	}

	/**
	 * Without the setting, even a shop manager is refused.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_override_is_off_by_default(): void {
		$id = $this->failing_product();
		$this->act_as( 'shop_manager' );

		$this->publish( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
	}

	/**
	 * The override covers CRUD saves too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_override_covers_crud_saves(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$id = $this->failing_product();
		$this->act_as( 'shop_manager' );

		$product = wc_get_product( $id );
		$product->set_status( 'publish' );
		$product->save();

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * `sit_wcpg_can_override_publish_guard` can grant or revoke the override.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_override_is_filterable(): void {
		$id = $this->failing_product();

		add_filter( 'sit_wcpg_can_override_publish_guard', '__return_true' );
		$this->publish( $id );
		remove_filter( 'sit_wcpg_can_override_publish_guard', '__return_true' );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * No custom capability is registered and no role is changed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_role_is_modified(): void {
		$manager = get_role( 'shop_manager' );
		$editor  = get_role( 'editor' );

		foreach ( array_merge( array_keys( $manager->capabilities ), array_keys( $editor->capabilities ) ) as $cap ) {
			$this->assertStringNotContainsString( 'sit_wcpg', $cap );
		}
	}
}
