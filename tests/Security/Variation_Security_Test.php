<?php
/**
 * Security coverage for variation data.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Tests\Integration\Publish_Guard_Test_Case;
use Spy_REST_Server;
use WP_REST_Request;

/**
 * Variation data reaches the engine only from storage (D3), variation ids are not
 * products to the plugin's REST route, and a variation's own text never reaches a notice
 * or a response: messages carry integers only (v1.0.0 §9.5), on real objects too.
 *
 * @since 1.1.0
 */
final class Variation_Security_Test extends Publish_Guard_Test_Case {

	/**
	 * A hostile SKU.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private const PAYLOAD = '"><script>alert(1)</script>';

	/**
	 * Drop the REST server and restore the screen and global post.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		set_current_screen( 'front' );
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Dispatch a validate request for one product.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $product_id Product to validate.
	 * @param array $body       JSON body.
	 * @return \WP_REST_Response
	 */
	private function validate( int $product_id, array $body = array() ) {
		global $wp_rest_server;

		if ( ! $wp_rest_server instanceof Spy_REST_Server ) {
			$wp_rest_server = new Spy_REST_Server();
			do_action( 'rest_api_init', $wp_rest_server );
		}

		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $wp_rest_server->dispatch( $request );
	}

	/**
	 * The status of one rule in a REST response.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $data    Response data.
	 * @param string $rule_id Rule id.
	 * @return string
	 */
	private static function response_status( array $data, string $rule_id ): string {
		foreach ( $data['results'] as $row ) {
			if ( $rule_id === $row['rule_id'] ) {
				return $row['status'];
			}
		}

		return '';
	}

	/**
	 * A variable product whose only enabled variation has no price.
	 *
	 * @since 1.1.0
	 *
	 * @param string $sku The variation's SKU.
	 * @return int Parent id.
	 */
	private function unpriced_variable( string $sku = '' ): int {
		return $this->variable_product(
			array(
				array(
					'regular_price' => '',
					'sku'           => '' === $sku ? 'SIT-WCPG-' . wp_generate_password( 8, false ) : $sku,
				),
			)
		);
	}

	/**
	 * Assert a string carries nothing of the payload.
	 *
	 * @since 1.1.0
	 *
	 * @param string $output  Raw output.
	 * @param string $surface What was rendered, for the failure message.
	 * @return void
	 */
	private function assertNoPayload( string $output, string $surface ): void {
		$this->assertNotSame( '', $output, "The {$surface} is empty, so the assertion would prove nothing." );
		$this->assertStringNotContainsString( '<script', $output, "The {$surface} contains a script tag." );
		$this->assertStringNotContainsString( 'alert(1)', $output, "The {$surface} contains product-supplied text." );
	}

	/**
	 * No draft can carry variation data: neither variation keys inside the draft nor
	 * beside it change the answer, which stays the stored product's (D3).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_rest_draft_cannot_inject_variation_data(): void {
		$parent_id    = $this->unpriced_variable();
		$variation_id = wc_get_product( $parent_id )->get_children()[0];
		$expected     = $this->validate( $parent_id )->get_data();

		$this->assertSame( Status::FAIL, self::response_status( $expected, 'price' ) );

		$forged = array(
			array(
				'id'            => $variation_id,
				'regular_price' => '10',
				'status'        => 'publish',
			),
		);

		$response = $this->validate(
			$parent_id,
			array(
				'draft'      => array(
					'variations'    => $forged,
					'variation_ids' => array(),
					'product_type'  => 'variable',
				),
				'variations' => $forged,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected['results'], $response->get_data()['results'] );

		// Nor can the classic form, which posts no variation fields WooCommerce saves on submit.
		$this->publish(
			$parent_id,
			array_merge(
				$this->classic_form( $parent_id ),
				array(
					'variable_post_id'       => array( (string) $variation_id ),
					'variable_regular_price' => array( '10' ),
					'variable_status'        => array( 'publish' ),
				)
			)
		);

		$this->assertSame( 'draft', $this->status_of( $parent_id ) );
		$this->assertNoticeQueued( $parent_id );
	}

	/**
	 * A hostile variation SKU never reaches the queued notice, the rendered notices or the
	 * REST response, even when the variation is the one that blocks the publish.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_hostile_variation_sku_never_reaches_notice_or_rest(): void {
		$parent_id    = $this->unpriced_variable( self::PAYLOAD );
		$variation_id = wc_get_product( $parent_id )->get_children()[0];

		// Stored as given, so what follows proves the plugin keeps it out.
		$this->assertSame( self::PAYLOAD, wc_get_product( $variation_id )->get_sku( 'edit' ) );

		// Refused: the variation has no price.
		$this->publish( $parent_id );

		$this->assertSame( 'draft', $this->status_of( $parent_id ) );
		$this->assertNoticeQueued( $parent_id );
		$this->assertNoPayload( (string) wp_json_encode( $this->pending_notices() ), 'queued notice' );

		// The editor notice prints the messages.
		set_current_screen( 'product' );
		$GLOBALS['post'] = get_post( $parent_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's editor sets it; the notice reads the product from it.

		ob_start();
		Plugin::instance()->notices()->render();
		$editor_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'variation #' . $variation_id, $editor_html );
		$this->assertNoPayload( $editor_html, 'editor notice' );

		// Refused again, then the list notice prints the labels.
		$this->publish( $parent_id );
		set_current_screen( 'edit-product' );

		ob_start();
		Plugin::instance()->notices()->render();
		$list_html = (string) ob_get_clean();

		$this->assertStringContainsString( Notices::KIND_BLOCKED, $list_html );
		$this->assertNoPayload( $list_html, 'list notice' );

		// The REST response, from storage and with the payload in the draft too.
		foreach ( array( array(), array( 'draft' => array( 'sku' => self::PAYLOAD ) ) ) as $body ) {
			$response = $this->validate( $parent_id, $body );

			$this->assertSame( 200, $response->get_status() );
			$this->assertNoPayload( (string) wp_json_encode( $response->get_data() ), 'REST response' );
		}
	}

	/**
	 * A variation id is a 404 even for an administrator, who can edit it: to the route a
	 * variation is not a product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_variation_id_rest_is_404_even_for_admin(): void {
		$variation_id = wc_get_product( $this->unpriced_variable() )->get_children()[0];

		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$this->assertTrue( current_user_can( 'edit_post', $variation_id ) );

		$response = $this->validate( $variation_id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'sit_wcpg_not_found', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'results', $response->get_data() );
	}
}
