<?php
/**
 * Tests for draft extras on the validate route.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Tests\Stubs\Extra_Probe_Rule;
use Spy_REST_Server;
use WC_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The browser may send `draft.extras`, but a rule only ever sees what an add-on's
 * `sit_wcpg_draft_extras` filter made of them.
 *
 * @since 1.2.0
 */
final class Rest_Validate_Extras_Test extends WP_UnitTestCase {

	/**
	 * The REST server the requests are dispatched through.
	 *
	 * @since 1.2.0
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * The arguments of each `sit_wcpg_draft_extras` call.
	 *
	 * @since 1.2.0
	 * @var array[]
	 */
	private array $calls = array();

	/**
	 * A clean cache, an administrator, a REST server and the probe rule.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();
		wp_cache_flush();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server per test.
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init', $this->server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook.

		$this->calls = array();

		Extra_Probe_Rule::activate();
	}

	/**
	 * Leave nothing behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Undoing set_up().

		Extra_Probe_Rule::deactivate();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * A saved product.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'A perfectly ordinary product' );
		$product->save();

		Checklist_Service::flush_memo();

		return $product->get_id();
	}

	/**
	 * Dispatch a validate request.
	 *
	 * @since 1.2.0
	 *
	 * @param int        $product_id Product id.
	 * @param array|null $draft      The draft, or null for a request with no draft at all.
	 * @return \WP_REST_Response
	 */
	private function dispatch( int $product_id, ?array $draft = null ) {
		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( null === $draft ? array() : array( 'draft' => $draft ) ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * The probe rule's status in a response.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_REST_Response $response The response.
	 * @return string
	 */
	private function probe_status( $response ): string {
		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['results'] as $row ) {
			if ( Extra_Probe_Rule::ID === $row['rule_id'] ) {
				return $row['status'];
			}
		}

		return '';
	}

	/**
	 * Pass the probe code through the draft filter, as an add-on does after sanitizing it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function accept_probe_code(): void {
		add_filter(
			'sit_wcpg_draft_extras',
			function ( $extras, $raw, $product ) {
				$this->calls[] = array( $extras, $raw, $product );

				if ( isset( $raw[ Extra_Probe_Rule::EXTRA ] ) && is_string( $raw[ Extra_Probe_Rule::EXTRA ] ) ) {
					$extras[ Extra_Probe_Rule::EXTRA ] = sanitize_text_field( $raw[ Extra_Probe_Rule::EXTRA ] );
				}

				return $extras;
			},
			10,
			3
		);
	}

	/**
	 * A 400 with the draft error code.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_REST_Response $response The response.
	 * @return void
	 */
	private function assertInvalidDraft( $response ): void {
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'sit_wcpg_invalid_draft', $response->get_data()['code'] );
	}

	/**
	 * What the browser sends is not trusted: only the filter's result is seeded.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_extras_reach_rules_only_through_the_filter(): void {
		$id    = $this->product();
		$draft = array( 'extras' => array( Extra_Probe_Rule::EXTRA => 'x' ) );

		$this->assertSame( Status::FAIL, $this->probe_status( $this->dispatch( $id, $draft ) ) );

		$this->accept_probe_code();

		$this->assertSame( Status::PASS, $this->probe_status( $this->dispatch( $id, $draft ) ) );
	}

	/**
	 * The filter gets the raw extras and the stored product.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_receives_raw_extras_and_product(): void {
		$id = $this->product();

		$this->accept_probe_code();

		$raw = array(
			Extra_Probe_Rule::EXTRA => 'x',
			'other'                 => array( 1, 2 ),
		);

		$this->dispatch( $id, array( 'extras' => $raw ) );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( array(), $this->calls[0][0] );
		$this->assertSame( $raw, $this->calls[0][1] );
		$this->assertInstanceOf( WC_Product::class, $this->calls[0][2] );
		$this->assertSame( $id, $this->calls[0][2]->get_id() );
	}

	/**
	 * Fifty keys are accepted, fifty-one are not.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_more_than_fifty_keys_is_rejected(): void {
		$id     = $this->product();
		$extras = array();

		for ( $i = 0; $i < 50; $i++ ) {
			$extras[ 'key_' . $i ] = 'v';
		}

		$this->assertSame( 200, $this->dispatch( $id, array( 'extras' => $extras ) )->get_status() );

		$extras['key_50'] = 'v';

		$this->assertInvalidDraft( $this->dispatch( $id, array( 'extras' => $extras ) ) );
	}

	/**
	 * The encoded extras are capped at 20 000 bytes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_extras_over_20000_bytes_is_rejected(): void {
		$id = $this->product();

		$this->assertSame( 200, $this->dispatch( $id, array( 'extras' => array( 'big' => str_repeat( 'a', 19000 ) ) ) )->get_status() );
		$this->assertInvalidDraft( $this->dispatch( $id, array( 'extras' => array( 'big' => str_repeat( 'a', 20001 ) ) ) ) );
	}

	/**
	 * Anything but an object is refused.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_extras_must_be_an_object(): void {
		$id = $this->product();

		$this->assertInvalidDraft( $this->dispatch( $id, array( 'extras' => 'a string' ) ) );
		$this->assertInvalidDraft( $this->dispatch( $id, array( 'extras' => 7 ) ) );
	}

	/**
	 * A result that depends on extras is never served from the cache.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_request_with_extras_is_not_cached(): void {
		$id = $this->product();

		$this->accept_probe_code();

		$this->assertSame( Status::PASS, $this->probe_status( $this->dispatch( $id, array( 'extras' => array( Extra_Probe_Rule::EXTRA => 'x' ) ) ) ) );
		$this->assertSame( Status::FAIL, $this->probe_status( $this->dispatch( $id, array( 'extras' => array( Extra_Probe_Rule::EXTRA => '' ) ) ) ) );
		$this->assertSame( Status::FAIL, $this->probe_status( $this->dispatch( $id ) ), 'The result with extras was not stored for a plain read.' );
	}

	/**
	 * A plain read is still memoized and cached.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_request_without_extras_or_draft_still_uses_the_cache(): void {
		$id = $this->product();

		$first = $this->dispatch( $id )->get_data();

		$this->assertInstanceOf( \ProductPublishGuard\Engine\Validation_Result::class, wp_cache_get( $this->cache_key( $id ), Checklist_Service::CACHE_GROUP ) );

		Checklist_Service::flush_memo();

		// A rule that came and went between two reads does not show in a cached result.
		Extra_Probe_Rule::deactivate();

		$second = $this->dispatch( $id )->get_data();

		$this->assertSame( $first['generated_at'], $second['generated_at'] );
		$this->assertSame( $first['results'], $second['results'] );

		// An empty extras object is a plain read too.
		$third = $this->dispatch( $id, array( 'extras' => array() ) )->get_data();

		$this->assertSame( $first['results'], $third['results'] );
	}

	/**
	 * The checklist service's object-cache key for a product.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	private function cache_key( int $id ): string {
		return implode(
			':',
			array(
				Checklist_Service::CACHE_VERSION,
				(string) $id,
				(string) get_post( $id )->post_modified_gmt,
				Plugin::instance()->settings()->get_hash(),
				determine_locale(),
			)
		);
	}
}
