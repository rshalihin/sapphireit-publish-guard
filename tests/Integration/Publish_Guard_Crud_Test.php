<?php
/**
 * Layer B: WooCommerce CRUD writes.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Covers section 9.8's `$product->save()` and `POST /wc/v3/products` rows.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Crud_Test extends Publish_Guard_Test_Case {

	/**
	 * Drop the REST server a test may have built.
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
	 * `set_status( 'publish' )` then `save()` on a failing product ends as a draft.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_failing_crud_publish_is_saved_as_a_draft(): void {
		$id      = $this->failing_product();
		$product = wc_get_product( $id );

		$product->set_status( 'publish' );
		$product->save();

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertNoticeQueued( $id );
	}

	/**
	 * A passing CRUD publish goes through.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_passing_crud_publish_goes_through(): void {
		$id      = $this->passing_product();
		$product = wc_get_product( $id );

		$product->set_status( 'publish' );
		$product->save();

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * The props set on the object are judged, not the stale stored row: a price added in
	 * the same save lets it publish.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_props_set_in_the_same_save_are_judged(): void {
		$id      = $this->failing_product();
		$product = wc_get_product( $id );

		$product->set_regular_price( '5.00' );
		$product->set_status( 'publish' );
		$product->save();

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * A product created as published is judged on its object, categories included, even
	 * though nothing is stored yet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_new_product_is_judged_on_its_object(): void {
		$term = wp_insert_term( 'sit-wcpg-crud', 'product_cat' );

		$complete = new WC_Product_Simple();
		$complete->set_props(
			array(
				'name'          => 'Created complete',
				'description'   => str_repeat( 'A sentence that describes the product. ', 8 ),
				'regular_price' => '3.00',
				'image_id'      => $this->image(),
				'category_ids'  => array( (int) $term['term_id'] ),
				'status'        => 'publish',
			)
		);
		$complete->save();

		$incomplete = new WC_Product_Simple();
		$incomplete->set_props(
			array(
				'name'   => 'Created incomplete',
				'status' => 'publish',
			)
		);
		$incomplete->save();

		$this->assertSame( 'publish', $this->status_of( $complete->get_id() ) );
		$this->assertSame( 'draft', $this->status_of( $incomplete->get_id() ) );
	}

	/**
	 * A live product saved through CRUD is never demoted (section 6.4).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_live_product_is_never_demoted(): void {
		$id      = $this->passing_product( 'publish' );
		$product = wc_get_product( $id );

		$product->set_regular_price( '' );
		$product->save();

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * `POST /wc/v3/products` with `status=publish` and no price creates a draft.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_woocommerce_rest_api_cannot_publish_a_failing_product(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/wc/v3/products' );
		$request->set_body_params(
			array(
				'name'   => 'Created over REST',
				'type'   => 'simple',
				'status' => 'publish',
			)
		);

		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $this->status_of( (int) $response->get_data()['id'] ) );
	}
}
