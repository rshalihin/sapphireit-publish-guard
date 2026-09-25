<?php
/**
 * Tests for the live-validation REST endpoint.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use Spy_REST_Server;
use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the route's contract: the draft wins, absent keys fall back, and nothing is
 * written.
 *
 * @since 1.0.0
 */
final class Rest_Validate_Test extends WP_UnitTestCase {

	/**
	 * The REST server this test dispatches through.
	 *
	 * @since 1.0.0
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * Boot a REST server with the plugin's route on it, as an administrator.
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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server();
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Leave no memo or server behind.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * A saved simple product that passes most of the shipped rules.
	 *
	 * @since 1.0.0
	 *
	 * @return WC_Product_Simple
	 */
	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'A perfectly ordinary product' );
		$product->set_description( str_repeat( 'Sentence about the product. ', 20 ) );
		$product->set_short_description( str_repeat( 'Short blurb. ', 10 ) );
		$product->set_regular_price( '19.99' );
		$product->set_sku( 'SIT-WCPG-REST-1' );
		$product->set_stock_status( 'instock' );
		$product->save();

		return $product;
	}

	/**
	 * Dispatch a validate request for one product.
	 *
	 * The draft travels as a JSON body, which is how the browser sends it, so core's own
	 * body parsing is part of what these tests exercise.
	 *
	 * @since 1.0.0
	 *
	 * @param int        $product_id Product to validate.
	 * @param array|null $draft      Draft snapshot, or null to send no `draft` key at all.
	 * @return \WP_REST_Response
	 */
	private function dispatch( int $product_id, ?array $draft = null ) {
		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( null === $draft ? array() : array( 'draft' => $draft ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Map a response's rows to `rule_id => status`.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	private static function statuses( array $data ): array {
		$statuses = array();

		foreach ( $data['results'] as $row ) {
			$statuses[ $row['rule_id'] ] = $row['status'];
		}

		return $statuses;
	}

	/**
	 * The route is registered under the documented namespace and path.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_route_is_registered(): void {
		$routes = $this->server->get_routes( Validate_Controller::REST_NAMESPACE );

		$route = '/' . Validate_Controller::REST_NAMESPACE . Validate_Controller::ROUTE;

		$this->assertArrayHasKey( $route, $routes );

		$handler = $routes[ $route ][0];

		$this->assertSame( array( 'POST' => true ), $handler['methods'] );
		$this->assertNotSame( '__return_true', $handler['permission_callback'] );
		$this->assertIsArray( $handler['permission_callback'] );
		$this->assertSame( 'permissions_check', $handler['permission_callback'][1] );
	}

	/**
	 * A valid draft returns 200 and the documented result shape.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_valid_draft_returns_the_documented_shape(): void {
		$product  = $this->product();
		$response = $this->dispatch( $product->get_id(), array( 'title' => 'A different title entirely' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame(
			array(
				'product_id',
				'product_type',
				'results',
				'counts',
				'is_ready',
				'required_failure_ids',
				'summary_label',
				'generated_at',
				'settings_hash',
			),
			array_keys( $data )
		);

		$this->assertSame( $product->get_id(), $data['product_id'] );
		$this->assertSame( 'simple', $data['product_type'] );
	}

	/**
	 * A key present in the draft beats the stored product.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_draft_overrides_the_stored_product(): void {
		$product = $this->product();

		$stored = self::statuses( $this->dispatch( $product->get_id() )->get_data() );

		$this->assertSame( Status::PASS, $stored['sku'] );
		$this->assertSame( Status::PASS, $stored['price'] );

		$drafted = self::statuses(
			$this->dispatch(
				$product->get_id(),
				array(
					'sku'           => '',
					'regular_price' => '',
				)
			)->get_data()
		);

		// The price rule ships as `required`, so an unmet requirement is a failure; the
		// SKU rule ships as `warning`, so the same unmet requirement is demoted (5.5).
		$this->assertSame( Status::FAIL, $drafted['price'] );
		$this->assertSame( Status::WARNING, $drafted['sku'] );
	}

	/**
	 * A key absent from the draft falls back to the stored value.
	 *
	 * This is the merge semantic the whole design rests on: a snapshot that omits the
	 * description must not be read as an empty description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_absent_keys_fall_back_to_the_stored_product(): void {
		$product = $this->product();

		$statuses = self::statuses(
			$this->dispatch( $product->get_id(), array( 'sku' => '' ) )->get_data()
		);

		$this->assertSame( Status::WARNING, $statuses['sku'] );

		// Everything the draft did not mention still comes from the saved product.
		$this->assertSame( Status::PASS, $statuses['title'] );
		$this->assertSame( Status::PASS, $statuses['description'] );
		$this->assertSame( Status::PASS, $statuses['short_description'] );
		$this->assertSame( Status::PASS, $statuses['price'] );
	}

	/**
	 * Sending no `draft` key at all validates the stored product.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_request_without_a_draft_validates_the_stored_product(): void {
		$product  = $this->product();
		$response = $this->dispatch( $product->get_id() );

		$this->assertSame( 200, $response->get_status() );

		$statuses = self::statuses( $response->get_data() );

		$this->assertSame( Status::PASS, $statuses['title'] );
		$this->assertSame( Status::FAIL, $statuses['featured_image'] );
	}

	/**
	 * A draft price is normalized before the rule sees it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_draft_price_is_normalized(): void {
		$product = $this->product();

		$statuses = self::statuses(
			$this->dispatch(
				$product->get_id(),
				array(
					'regular_price' => '20.00',
					'sale_price'    => '25.00',
				)
			)->get_data()
		);

		// A sale price above the regular price is advisory, never a failure.
		$this->assertSame( Status::WARNING, $statuses['sale_price'] );

		$statuses = self::statuses(
			$this->dispatch(
				$product->get_id(),
				array(
					'regular_price' => '20.00',
					'sale_price'    => '15.00',
				)
			)->get_data()
		);

		$this->assertSame( Status::PASS, $statuses['sale_price'] );
	}

	/**
	 * A sale window sent as bare dates is normalized so the two can be compared.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_bare_sale_dates_are_normalized_and_compared(): void {
		$product = $this->product();

		$statuses = self::statuses(
			$this->dispatch(
				$product->get_id(),
				array(
					'regular_price' => '20.00',
					'sale_price'    => '15.00',
					'sale_from'     => '2026-05-01',
					'sale_to'       => '2026-04-01',
				)
			)->get_data()
		);

		$this->assertSame( Status::WARNING, $statuses['sale_price'] );

		$statuses = self::statuses(
			$this->dispatch(
				$product->get_id(),
				array(
					'regular_price' => '20.00',
					'sale_price'    => '15.00',
					'sale_from'     => '2026-04-01',
					'sale_to'       => '2026-05-01',
				)
			)->get_data()
		);

		$this->assertSame( Status::PASS, $statuses['sale_price'] );
	}

	/**
	 * A featured image sent as core's `-1` sentinel means "no image".
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_featured_image_sentinel_means_no_image(): void {
		$product = $this->product();

		$statuses = self::statuses(
			$this->dispatch( $product->get_id(), array( 'featured_image_id' => -1 ) )->get_data()
		);

		$this->assertSame( Status::FAIL, $statuses['featured_image'] );
	}

	/**
	 * An id that is not a product is a 404.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_non_product_id_is_a_404(): void {
		$post_id = self::factory()->post->create();

		$response = $this->dispatch( $post_id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'sit_wcpg_not_found', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'results', $response->get_data() );
	}

	/**
	 * An id that exists nowhere is a 404 too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unknown_id_is_a_404(): void {
		$response = $this->dispatch( 999999 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'sit_wcpg_not_found', $response->get_data()['code'] );
	}

	/**
	 * The call writes nothing: post row, meta and terms are byte-identical afterwards.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_product_is_unchanged_after_a_call(): void {
		$product = $this->product();
		$id      = $product->get_id();

		wp_set_object_terms( $id, array( 'a-category' ), 'product_cat' );

		$post_before  = get_post( $id, ARRAY_A );
		$meta_before  = get_post_meta( $id );
		$terms_before = wp_get_object_terms( $id, array( 'product_cat', 'product_tag' ), array( 'fields' => 'ids' ) );

		$response = $this->dispatch(
			$id,
			array(
				'title'             => 'Something else',
				'content'           => 'Replaced description.',
				'excerpt'           => 'Replaced blurb.',
				'product_type'      => 'grouped',
				'regular_price'     => '999.99',
				'sale_price'        => '1.00',
				'sale_from'         => '2026-01-01',
				'sale_to'           => '2026-12-31',
				'sku'               => 'MUTATED-SKU',
				'stock_status'      => 'outofstock',
				'manage_stock'      => true,
				'stock_quantity'    => 42,
				'featured_image_id' => 12345,
				'gallery_image_ids' => array( 1, 2, 3 ),
				'category_ids'      => array( 4, 5 ),
				'tag_ids'           => array( 6, 7 ),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		wp_cache_flush();
		clean_post_cache( $id );

		$this->assertSame( $post_before, get_post( $id, ARRAY_A ) );
		$this->assertSame( $meta_before, get_post_meta( $id ) );
		$this->assertSame( $terms_before, wp_get_object_terms( $id, array( 'product_cat', 'product_tag' ), array( 'fields' => 'ids' ) ) );

		// And the product as WooCommerce reads it back.
		$reloaded = wc_get_product( $id );

		$this->assertSame( 'A perfectly ordinary product', $reloaded->get_name() );
		$this->assertSame( 'SIT-WCPG-REST-1', $reloaded->get_sku() );
		$this->assertSame( '19.99', $reloaded->get_regular_price() );
		$this->assertSame( '', $reloaded->get_sale_price() );
		$this->assertSame( 'instock', $reloaded->get_stock_status() );
		$this->assertSame( 'simple', $reloaded->get_type() );
	}

	/**
	 * Unknown draft keys are dropped rather than rejected.
	 *
	 * A newer client sending a field this version does not know about must still get a
	 * correct answer for the fields it does.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_unknown_draft_keys_are_dropped(): void {
		$product = $this->product();

		$response = $this->dispatch(
			$product->get_id(),
			array(
				'sku'                      => '',
				'weight'                   => '5',
				'post_status'              => 'publish',
				Settings::OPTION_NAME      => array( 'enabled' => false ),
				'has_valid_featured_image' => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$statuses = self::statuses( $response->get_data() );

		$this->assertSame( Status::WARNING, $statuses['sku'] );

		// `has_valid_featured_image` is an internal context key and must not be settable
		// from a request — if it were, a draft could claim an image it does not have.
		$this->assertSame( Status::FAIL, $statuses['featured_image'] );
	}

	/**
	 * A live draft is never served from, or written to, the result cache.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_drafted_result_is_not_cached(): void {
		$product = $this->product();

		$first  = self::statuses( $this->dispatch( $product->get_id(), array( 'sku' => '' ) )->get_data() );
		$second = self::statuses( $this->dispatch( $product->get_id() )->get_data() );

		$this->assertSame( Status::WARNING, $first['sku'] );
		$this->assertSame( Status::PASS, $second['sku'] );
	}
}
