<?php
/**
 * Tests for the review request.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Rest\Review_Notice_Controller;
use ProductPublishGuard\Rest\Validate_Controller;
use Spy_REST_Server;
use WP_REST_Request;

/**
 * When the notice may appear, who sees it, and how a choice is recorded.
 *
 * @since 1.2.0
 */
final class Review_Notice_Test extends Publish_Guard_Test_Case {

	/**
	 * Every screen the notice appears on, as a data provider.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_listed_screens(): array {
		return array(
			'settings'      => array( Screen::SETTINGS_HOOK ),
			'products list' => array( 'edit-' . Screen::POST_TYPE ),
			'catalog'       => array( Screen::CATALOG_HOOK ),
		);
	}

	/**
	 * Start from a site that has never been asked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Review_Notice::OPTION );
	}

	/**
	 * Leave no screen or REST server behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		unset( $GLOBALS['current_screen'] );

		delete_option( Review_Notice::OPTION );

		parent::tear_down();
	}

	/**
	 * Store the notice's state.
	 *
	 * @since 1.2.0
	 *
	 * @param int  $first_seen Seconds before now that the clock started; 0 for never.
	 * @param int  $blocked    Seconds before now of the first block; 0 for never.
	 * @param int  $ask_after  Absolute time of the next ask.
	 * @param bool $done       Whether it has stopped asking.
	 * @return void
	 */
	private function store_state( int $first_seen, int $blocked, int $ask_after = 0, bool $done = false ): void {
		update_option(
			Review_Notice::OPTION,
			array(
				'first_seen' => $first_seen > 0 ? time() - $first_seen : 0,
				'blocked_at' => $blocked > 0 ? time() - $blocked : 0,
				'ask_after'  => $ask_after,
				'done'       => $done,
			),
			false
		);
	}

	/**
	 * A state in which the notice is due.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function store_due_state(): void {
		$this->store_state( 31 * DAY_IN_SECONDS, DAY_IN_SECONDS );
	}

	/**
	 * What the notice prints on a screen.
	 *
	 * @since 1.2.0
	 *
	 * @param string $screen_id The screen.
	 * @return string
	 */
	private function render_on( string $screen_id ): string {
		set_current_screen( $screen_id );

		ob_start();
		( new Review_Notice() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A REST server with the plugin's routes.
	 *
	 * @since 1.2.0
	 *
	 * @return Spy_REST_Server
	 */
	private function server(): Spy_REST_Server {
		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		return $wp_rest_server;
	}

	/**
	 * Post a choice to the route.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $choice The choice.
	 * @return \WP_REST_Response
	 */
	private function post_choice( $choice ) {
		$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . Review_Notice_Controller::ROUTE );

		$request->set_body_params( array( 'choice' => $choice ) );

		return $this->server()->dispatch( $request );
	}

	/**
	 * Never before the gate has stopped something.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_not_due_without_a_block(): void {
		$this->store_state( 31 * DAY_IN_SECONDS, 0 );

		$this->assertFalse( Review_Notice::is_due() );
	}

	/**
	 * Never inside the first thirty days.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_not_due_before_30_days(): void {
		$this->store_state( 29 * DAY_IN_SECONDS, DAY_IN_SECONDS );

		$this->assertFalse( Review_Notice::is_due() );
	}

	/**
	 * A block with no clock started is not a month of use.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_not_due_before_the_clock_starts(): void {
		$this->store_state( 0, DAY_IN_SECONDS );

		$this->assertFalse( Review_Notice::is_due() );
	}

	/**
	 * Time and a block together make it due.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_due_after_30_days_and_a_block(): void {
		$this->store_due_state();

		$this->assertTrue( Review_Notice::is_due() );
	}

	/**
	 * The time can be injected.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_is_due_takes_a_time(): void {
		$this->store_state( 29 * DAY_IN_SECONDS, DAY_IN_SECONDS );

		$this->assertTrue( Review_Notice::is_due( time() + 2 * DAY_IN_SECONDS ) );
	}

	/**
	 * Anything the option holds comes back in the documented shape.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_state_normalizes_a_corrupt_option(): void {
		update_option( Review_Notice::OPTION, 'not an array', false );

		$this->assertSame(
			array(
				'first_seen' => 0,
				'blocked_at' => 0,
				'ask_after'  => 0,
				'done'       => false,
			),
			Review_Notice::state()
		);
		$this->assertFalse( Review_Notice::is_due() );
	}

	/**
	 * The first refusal is recorded, and no later one writes anything.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_blocked_publish_sets_blocked_at_once(): void {
		$this->store_state( DAY_IN_SECONDS, 0 );

		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};

		add_action( 'update_option_' . Review_Notice::OPTION, $count );

		$this->publish_through( 'crud', $this->failing_product() );

		$first = Review_Notice::state()['blocked_at'];

		$this->assertGreaterThan( 0, $first );

		$this->publish_through( 'crud', $this->failing_product() );

		remove_action( 'update_option_' . Review_Notice::OPTION, $count );

		$this->assertSame( $first, Review_Notice::state()['blocked_at'] );
		$this->assertSame( 1, $writes );
	}

	/**
	 * Publishing anyway is not the gate stopping something.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_override_does_not_count_as_block(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$this->publish_through( 'crud', $this->failing_product() );

		$this->assertSame( 0, Review_Notice::state()['blocked_at'] );
	}

	/**
	 * The clock starts on the first visit, and only then.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_listed_screens
	 *
	 * @param string $screen_id The screen.
	 * @return void
	 */
	public function test_clock_starts_on_first_visit_to_a_listed_screen( string $screen_id ): void {
		set_current_screen( $screen_id );

		$notice = new Review_Notice();

		$notice->start_clock( get_current_screen() );

		$first_seen = Review_Notice::state()['first_seen'];

		$this->assertGreaterThan( 0, $first_seen );

		$this->store_state( 5, 0 );

		$stored = Review_Notice::state()['first_seen'];

		$notice->start_clock( get_current_screen() );

		$this->assertSame( $stored, Review_Notice::state()['first_seen'] );
	}

	/**
	 * Any other screen leaves the clock alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_clock_not_started_on_dashboard(): void {
		set_current_screen( 'dashboard' );

		( new Review_Notice() )->start_clock( get_current_screen() );

		$this->assertFalse( get_option( Review_Notice::OPTION ) );
	}

	/**
	 * "Maybe later" waits fourteen days.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_later_snoozes_14_days(): void {
		$this->store_due_state();

		$now   = time();
		$state = ( new Review_Notice() )->record_choice( 'later', $now );

		$this->assertFalse( $state['done'] );
		$this->assertSame( $now + 14 * DAY_IN_SECONDS, $state['ask_after'] );
		$this->assertFalse( Review_Notice::is_due( $now + 14 * DAY_IN_SECONDS - 1 ) );
		$this->assertTrue( Review_Notice::is_due( $now + 14 * DAY_IN_SECONDS ) );
	}

	/**
	 * The other two answers end it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_never_and_review_stop_for_good(): void {
		foreach ( array( 'never', 'review' ) as $choice ) {
			$this->store_due_state();

			$this->assertTrue( ( new Review_Notice() )->record_choice( $choice )['done'] );
			$this->assertFalse( Review_Notice::is_due( time() + YEAR_IN_SECONDS ) );
		}
	}

	/**
	 * An answer that isn't one changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unknown_choice_changes_nothing(): void {
		$this->store_due_state();

		$before = Review_Notice::state();

		$this->assertSame( $before, ( new Review_Notice() )->record_choice( 'whatever' ) );
		$this->assertSame( $before, Review_Notice::state() );
	}

	/**
	 * Shown on the three screens to someone who can act on it.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_listed_screens
	 *
	 * @param string $screen_id The screen.
	 * @return void
	 */
	public function test_render_only_for_manage_woocommerce_on_listed_screens( string $screen_id ): void {
		$this->store_due_state();

		$this->assertStringContainsString( 'data-sit-wcpg-review', $this->render_on( $screen_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->assertStringContainsString( 'data-sit-wcpg-review', $this->render_on( $screen_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertSame( '', $this->render_on( $screen_id ) );
	}

	/**
	 * Nowhere else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_render_nothing_on_other_screens(): void {
		$this->store_due_state();

		$this->assertSame( '', $this->render_on( 'dashboard' ) );
		$this->assertSame( '', $this->render_on( 'edit-post' ) );
		$this->assertSame( '', $this->render_on( 'plugins' ) );
	}

	/**
	 * Nothing to show until it is due.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_render_nothing_when_not_due(): void {
		$this->store_state( 31 * DAY_IN_SECONDS, 0 );

		$this->assertSame( '', $this->render_on( Screen::SETTINGS_HOOK ) );
	}

	/**
	 * The exact copy, and no inline script.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_render_markup_has_no_inline_handlers_and_escaped_url(): void {
		$this->store_due_state();

		$output = $this->render_on( Screen::SETTINGS_HOOK );

		$this->assertStringNotContainsString( 'onclick', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'href="' . esc_url( Review_Notice::REVIEW_URL ) . '"', $output );
		$this->assertMatchesRegularExpression( '/target="_blank"\s+rel="[^"]*noopener[^"]*"\s+data-choice="review"/', $output );
		$this->assertStringContainsString( 'Publish Guard has been stopping incomplete products from going live on this store. If it has helped, a short review on WordPress.org helps other store owners find it.', $output );
		$this->assertStringContainsString( '>Leave a review<', $output );
		$this->assertMatchesRegularExpression( '#<button type="button" class="button-link" data-choice="later">Maybe later</button>#', $output );
		$this->assertMatchesRegularExpression( '#<button type="button" class="button-link" data-choice="never">Never ask again</button>#', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
	}

	/**
	 * Read on three screens, so never loaded on every page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_option_not_autoloaded(): void {
		( new Review_Notice() )->record_choice( 'later' );

		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayNotHasKey( Review_Notice::OPTION, wp_load_alloptions() );
		$this->assertIsArray( get_option( Review_Notice::OPTION ) );
	}

	/**
	 * Registered from `boot()`, so a refusal over REST or cron counts.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_listener_is_registered_outside_the_admin(): void {
		$this->assertFalse( is_admin() );

		$this->store_state( DAY_IN_SECONDS, 0 );

		do_action( 'sit_wcpg_publish_blocked', 1, 1, array( 'price' ), 'rest' );

		$this->assertGreaterThan( 0, Review_Notice::state()['blocked_at'] );
	}

	/**
	 * An administrator can answer.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_route_records_never(): void {
		$this->store_due_state();

		$response = $this->post_choice( 'never' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['done'] );
		$this->assertIsInt( $response->get_data()['ask_after'] );
		$this->assertTrue( Review_Notice::state()['done'] );
	}

	/**
	 * Nobody else can.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_route_rejects_shop_customer(): void {
		$this->store_due_state();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$response = $this->post_choice( 'never' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_wcpg_forbidden', $response->get_data()['code'] );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->post_choice( 'never' )->get_status() );
		$this->assertFalse( Review_Notice::state()['done'] );
	}

	/**
	 * Only the three answers are accepted.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_route_rejects_unknown_choice(): void {
		$this->assertSame( 400, $this->post_choice( 'sometime' )->get_status() );
	}

	/**
	 * The route takes no reads.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_get_is_not_allowed(): void {
		$request  = new WP_REST_Request( 'GET', '/' . Validate_Controller::REST_NAMESPACE . Review_Notice_Controller::ROUTE );
		$response = $this->server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 404, 405 ) );
	}
}
