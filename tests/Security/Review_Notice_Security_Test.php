<?php
/**
 * Security coverage for the review request.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Rest\Review_Notice_Controller;
use ProductPublishGuard\Rest\Validate_Controller;
use Spy_REST_Server;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The plugin's only state-changing REST route: who may reach it, and what it accepts.
 *
 * @since 1.2.0
 */
final class Review_Notice_Security_Test extends WP_UnitTestCase {

	/**
	 * A role that edits products but runs nothing.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const LIMITED_ROLE = 'sit_wcpg_catalog_editor';

	/**
	 * The REST server this test dispatches through.
	 *
	 * @since 1.2.0
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * A REST server, and a request that is due and unanswered.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );

		add_role(
			self::LIMITED_ROLE,
			'SIT-WCPG catalog editor',
			array(
				'read'          => true,
				'edit_products' => true,
			)
		);

		update_option(
			Review_Notice::OPTION,
			array(
				'first_seen' => time() - 31 * DAY_IN_SECONDS,
				'blocked_at' => time() - DAY_IN_SECONDS,
				'ask_after'  => 0,
				'done'       => false,
			),
			false
		);
	}

	/**
	 * Leave no server, role or state behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		unset( $GLOBALS['wp_rest_auth_cookie'], $_REQUEST['_wpnonce'], $GLOBALS['current_screen'] );

		remove_role( self::LIMITED_ROLE );
		delete_option( Review_Notice::OPTION );

		parent::tear_down();
	}

	/**
	 * Post a body to the route.
	 *
	 * @since 1.2.0
	 *
	 * @param array $body Body parameters.
	 * @return \WP_REST_Response
	 */
	private function post( array $body ) {
		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . Review_Notice_Controller::ROUTE );

		$request->set_body_params( $body );

		return $this->server->dispatch( $request );
	}

	/**
	 * A cookie alone is not enough: no nonce, no user.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rest_route_requires_cookie_nonce(): void {
		$this->assertNotFalse(
			has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ),
			'Core cookie authentication must still gate this route.'
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$GLOBALS['wp_rest_auth_cookie'] = true;

		unset( $_REQUEST['_wpnonce'] );

		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'A missing REST nonce must log the request out.' );

		$response = $this->post( array( 'choice' => 'never' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( Review_Notice::state()['done'] );
	}

	/**
	 * Editing products does not make the answer yours to give.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rest_route_requires_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => self::LIMITED_ROLE ) ) );

		$this->assertSame( 403, $this->post( array( 'choice' => 'never' ) )->get_status() );
		$this->assertFalse( Review_Notice::state()['done'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->assertSame( 200, $this->post( array( 'choice' => 'later' ) )->get_status() );
	}

	/**
	 * Only the three answers get through, whatever else is posted.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_choice_is_enum_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$before = Review_Notice::state();

		$bodies = array(
			'missing'      => array(),
			'unknown'      => array( 'choice' => 'always' ),
			'sql'          => array( 'choice' => "never' OR 1=1 -- " ),
			'array'        => array( 'choice' => array( 'never' ) ),
			'upper case'   => array( 'choice' => 'NEVER' ),
			'empty string' => array( 'choice' => '' ),
		);

		foreach ( $bodies as $label => $body ) {
			$this->assertSame( 400, $this->post( $body )->get_status(), $label );
		}

		$this->assertSame( $before, Review_Notice::state() );
	}

	/**
	 * Extra fields cannot write into the option.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_route_writes_only_the_choice(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$before = Review_Notice::state();

		$this->post(
			array(
				'choice'     => 'never',
				'first_seen' => 1,
				'blocked_at' => 1,
				'ask_after'  => 1,
			)
		);

		$after = Review_Notice::state();

		$this->assertSame( $before['first_seen'], $after['first_seen'] );
		$this->assertSame( $before['blocked_at'], $after['blocked_at'] );
		$this->assertSame( $before['ask_after'], $after['ask_after'] );
		$this->assertArrayNotHasKey( 'choice', get_option( Review_Notice::OPTION ) );
	}

	/**
	 * A shopper's page gets neither the notice nor its hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_notice_never_renders_on_front_end(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_admin() );

		$notice = new Review_Notice();

		$notice->register();

		$this->assertFalse( has_action( 'admin_notices', array( $notice, 'render' ) ) );
		$this->assertFalse( has_action( 'current_screen', array( $notice, 'start_clock' ) ) );

		ob_start();
		$notice->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
