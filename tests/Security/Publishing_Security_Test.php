<?php
/**
 * Security coverage for publishing enforcement as a shop manager.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Tests\Integration\Publish_Guard_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The two publishing rows of coding-plan.md section 12.3.
 *
 * Both act as `shop_manager`, the most privileged role the override could apply to, so
 * a pass here shows that capability alone never unlocks publishing: only the explicit
 * `allow_admin_override` opt-in does.
 *
 * @since 1.0.0
 */
final class Publishing_Security_Test extends Publish_Guard_Test_Case {

	/**
	 * Drop the REST server again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Act as a fresh shop manager.
	 *
	 * @since 1.0.0
	 *
	 * @return int The shop manager's id.
	 */
	private function act_as_shop_manager(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * With `allow_admin_override` off, a shop manager is blocked like anyone else.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_shop_manager_is_blocked_while_the_override_is_off(): void {
		$id      = $this->failing_product();
		$manager = $this->act_as_shop_manager();

		$this->assertFalse( Plugin::instance()->settings()->allows_admin_override() );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$this->assertFalse( Plugin::instance()->publish_guard()->can_override( $manager ) );

		$this->publish( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id, Notices::KIND_BLOCKED, $manager );
	}

	/**
	 * `POST /wc/v3/products` with `status=publish` by a shop manager still yields a draft.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_shop_manager_cannot_publish_a_failing_product_over_the_woocommerce_rest_api(): void {
		global $wp_rest_server;

		$this->act_as_shop_manager();

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/wc/v3/products' );
		$request->set_body_params(
			array(
				'name'   => 'Created over REST by a shop manager',
				'type'   => 'simple',
				'status' => 'publish',
			)
		);

		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $this->status_of( (int) $response->get_data()['id'] ) );
	}
}
