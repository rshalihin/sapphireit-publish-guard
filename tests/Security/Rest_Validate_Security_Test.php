<?php
/**
 * Security coverage for the live-validation REST endpoint.
 *
 * Every row of coding-plan.md section 12.3 that names the REST route has an assertion
 * here, plus the stored-XSS row as it applies to the REST response.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use Spy_REST_Server;
use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Authorization, nonce handling, input caps and enum rejection.
 *
 * @since 1.0.0
 */
final class Rest_Validate_Security_Test extends WP_UnitTestCase {

	/**
	 * A role with product editing but no `edit_others_products`.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const LIMITED_ROLE = 'sit_wcpg_limited_editor';

	/**
	 * The REST server this test dispatches through.
	 *
	 * @since 1.0.0
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * Boot a REST server with the plugin's route on it, logged out.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		Checklist_Service::flush_memo();
		wp_cache_flush();

		add_role(
			self::LIMITED_ROLE,
			'SIT-WCPG limited editor',
			array(
				'read'                    => true,
				'upload_files'            => true,
				'edit_products'           => true,
				'edit_published_products' => true,
				'publish_products'        => true,
			)
		);

		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );

		wp_set_current_user( 0 );
	}

	/**
	 * Remove the role and the server again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		remove_role( self::LIMITED_ROLE );
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * A saved product, optionally owned by a given user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $author_id Author to assign, or 0 to leave the default.
	 * @return WC_Product_Simple
	 */
	private function product( int $author_id = 0 ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'A perfectly ordinary product' );
		$product->set_regular_price( '19.99' );
		$product->set_sku( 'SIT-WCPG-SEC-' . wp_rand( 1000, 9999 ) );
		$product->set_status( 'publish' );
		$product->save();

		if ( $author_id > 0 ) {
			wp_update_post(
				array(
					'ID'          => $product->get_id(),
					'post_author' => $author_id,
				)
			);
		}

		return $product;
	}

	/**
	 * Dispatch a validate request.
	 *
	 * @since 1.0.0
	 *
	 * @param int        $product_id Product to validate.
	 * @param array|null $draft      Draft snapshot, or null to send no `draft` key.
	 * @return \WP_REST_Response
	 */
	private function dispatch( int $product_id, ?array $draft = null ) {
		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( null === $draft ? array() : array( 'draft' => $draft ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * A logged-out request is refused, with no result in the body.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_logged_out_request_is_refused(): void {
		$product = $this->product();

		$response = $this->dispatch( $product->get_id() );
		$data     = $response->get_data();

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 'sit_wcpg_forbidden', $data['code'] );
		$this->assertArrayNotHasKey( 'results', $data );
		$this->assertArrayNotHasKey( 'counts', $data );
		$this->assertArrayNotHasKey( 'is_ready', $data );
	}

	/**
	 * A subscriber is refused.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused(): void {
		$product = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch( $product->get_id() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_wcpg_forbidden', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'results', $response->get_data() );
	}

	/**
	 * A product editor without `edit_others_products` is refused another author's product.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_editor_without_edit_others_products_is_refused(): void {
		$mine      = self::factory()->user->create( array( 'role' => self::LIMITED_ROLE ) );
		$somebody  = self::factory()->user->create( array( 'role' => self::LIMITED_ROLE ) );
		$their_one = $this->product( $somebody );
		$my_one    = $this->product( $mine );

		wp_set_current_user( $mine );

		$this->assertFalse( current_user_can( 'edit_others_products' ) );

		$refused = $this->dispatch( $their_one->get_id() );

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'sit_wcpg_forbidden', $refused->get_data()['code'] );

		// The same role on its own product is allowed, so the refusal above is about
		// ownership and not about the role lacking product capabilities altogether.
		$allowed = $this->dispatch( $my_one->get_id() );

		$this->assertSame( 200, $allowed->get_status() );
	}

	/**
	 * Core refuses a cookie-authenticated request whose REST nonce is missing or wrong,
	 * before any handler of ours runs.
	 *
	 * The route is an ordinary `register_rest_route()` registration on the shared server,
	 * so `rest_cookie_check_errors()` gates it like every other route. Core handles the two
	 * cases differently and both matter here: a **missing** nonce silently downgrades the
	 * request to unauthenticated, which this route then refuses on capability; a **wrong**
	 * nonce is a hard 403 from core.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_core_rejects_a_cookie_request_without_a_valid_rest_nonce(): void {
		$this->assertNotFalse(
			has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ),
			'Core cookie authentication must still gate this route.'
		);

		$product = $this->product();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin );

		// `rest_cookie_check_errors()` only applies when the request authenticated by
		// cookie, which core signals through this global.
		$GLOBALS['wp_rest_auth_cookie'] = true;

		unset( $_REQUEST['_wpnonce'] );

		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'A missing REST nonce must log the request out.' );

		// And the consequence for this route: an administrator's cookie without the nonce
		// gets exactly what an anonymous caller gets.
		$response = $this->dispatch( $product->get_id() );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertArrayNotHasKey( 'results', $response->get_data() );

		wp_set_current_user( $admin );

		$_REQUEST['_wpnonce'] = 'not-a-real-nonce';

		$invalid = rest_cookie_check_errors( null );

		$this->assertWPError( $invalid );
		$this->assertSame( 'rest_cookie_invalid_nonce', $invalid->get_error_code() );
		$this->assertSame( 403, $invalid->get_error_data()['status'] );

		wp_set_current_user( $admin );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_rest' );

		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( $admin, get_current_user_id(), 'A correct REST nonce leaves the user signed in.' );
		$this->assertArrayHasKey( 'X-WP-Nonce', $this->server->sent_headers, 'Core refreshes the nonce on success.' );

		unset( $_REQUEST['_wpnonce'], $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * Ten thousand category ids are capped, and the request still succeeds.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_oversized_id_list_is_capped(): void {
		$product = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->dispatch(
			$product->get_id(),
			array(
				'category_ids'      => range( 1, 10000 ),
				'tag_ids'           => range( 1, 10000 ),
				'gallery_image_ids' => range( 1, 10000 ),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['results'] as $row ) {
			if ( 'category' === $row['rule_id'] ) {
				$this->assertLessThanOrEqual( 100, $row['data']['category_count'] );
			}
		}
	}

	/**
	 * The caps are applied by the sanitizer itself, at the documented sizes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_sanitizer_applies_the_documented_caps(): void {
		$controller = new Validate_Controller();

		$draft = $controller->sanitize_draft(
			array(
				'category_ids'      => range( 1, 10000 ),
				'tag_ids'           => range( 1, 10000 ),
				'gallery_image_ids' => range( 1, 10000 ),
			)
		);

		$this->assertCount( 100, $draft['category_ids'] );
		$this->assertCount( 200, $draft['tag_ids'] );
		$this->assertCount( 100, $draft['gallery_image_ids'] );

		// A comma-separated list is capped the same way.
		$from_string = $controller->sanitize_draft(
			array( 'gallery_image_ids' => implode( ',', range( 1, 10000 ) ) )
		);

		$this->assertCount( 100, $from_string['gallery_image_ids'] );

		// Nested arrays, zero and duplicates are dropped; a negative id is absint-ed the
		// way `wp_parse_id_list()` would (section 9.3), so nothing unbounded gets through.
		$hostile = $controller->sanitize_draft(
			array( 'category_ids' => array( array( 'deeply' => array( 'nested' => 1 ) ), 5, '7', -3, 0, 5 ) )
		);

		$this->assertSame( array( 5, 7, 3 ), $hostile['category_ids'] );
	}

	/**
	 * A product type outside the registry is a 400, not a silently different checklist.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unknown_product_type_is_rejected(): void {
		$product = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->dispatch( $product->get_id(), array( 'product_type' => '<script>alert(1)</script>' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'results', $response->get_data() );
		$this->assertStringNotContainsString( '<script>', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A stock status outside the registry is a 400.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unknown_stock_status_is_rejected(): void {
		$product = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->dispatch( $product->get_id(), array( 'stock_status' => 'definitely-not-a-status' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * A draft that is not an object is a 400.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_scalar_draft_is_rejected(): void {
		$product = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product->get_id() . '/validate' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'draft' => 'a string, not an object' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A hostile title never reaches the response, from the draft or from the database.
	 *
	 * Section 9.5: no rule message or `data` value may contain product-supplied text, so
	 * the whole stored-XSS class is closed structurally rather than by escaping.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_hostile_product_text_never_reaches_the_response(): void {
		$payload = '"><script>alert(1)</script>';

		$product = new WC_Product_Simple();
		$product->set_name( $payload );
		$product->set_description( $payload );
		$product->set_short_description( $payload );
		$product->set_sku( 'SIT-WCPG-XSS-1' );
		$product->save();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->dispatch(
			$product->get_id(),
			array(
				'title'   => $payload,
				'content' => $payload,
				'excerpt' => $payload,
				'sku'     => $payload,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$raw = wp_json_encode( $response->get_data() );

		$this->assertStringNotContainsString( '<script>', $raw );
		$this->assertStringNotContainsString( 'alert(1)', $raw );

		// Every `data` value is a number or a boolean — never text of any origin.
		foreach ( $response->get_data()['results'] as $row ) {
			foreach ( $row['data'] as $value ) {
				$this->assertTrue(
					is_int( $value ) || is_float( $value ) || is_bool( $value ),
					'Rule data must carry integers, floats and booleans only.'
				);
			}
		}
	}

	/**
	 * The endpoint writes nothing even when the caller is refused.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_refused_call_writes_nothing(): void {
		$product = $this->product();
		$id      = $product->get_id();

		$post_before = get_post( $id, ARRAY_A );
		$meta_before = get_post_meta( $id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->dispatch(
			$id,
			array(
				'sku'          => 'MUTATED',
				'stock_status' => 'outofstock',
			)
		);

		wp_cache_flush();
		clean_post_cache( $id );

		$this->assertSame( $post_before, get_post( $id, ARRAY_A ) );
		$this->assertSame( $meta_before, get_post_meta( $id ) );
	}
}
