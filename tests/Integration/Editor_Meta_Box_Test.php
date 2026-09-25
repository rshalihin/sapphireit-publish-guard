<?php
/**
 * Tests for the product editor's readiness panel.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use WC_Product_Simple;
use WP_Post;
use WP_UnitTestCase;

/**
 * Covers registration on the product screen, the mount node, the escaped no-JS list and
 * the capability guard.
 *
 * @since 1.0.0
 */
final class Editor_Meta_Box_Test extends WP_UnitTestCase {

	/**
	 * Start from an unsaved option, an empty memo and no registered meta boxes.
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

		unset( $GLOBALS['wp_meta_boxes'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no memo or meta box behind for the next test class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		Checklist_Service::flush_memo();

		unset( $GLOBALS['wp_meta_boxes'] );

		parent::tear_down();
	}

	/**
	 * A saved product with a known shape.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Product title.
	 * @return WP_Post
	 */
	private function product( string $title = 'A product' ): WP_Post {
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_description( str_repeat( 'Sentence about the product. ', 20 ) );
		$product->set_regular_price( '19.99' );
		$product->save();

		return get_post( $product->get_id() );
	}

	/**
	 * A meta box running exactly the rules the test dictates.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry $registry Rules to run.
	 * @return Editor_Meta_Box
	 */
	private function box( Rule_Registry $registry ): Editor_Meta_Box {
		$settings = new Settings( $registry );
		$service  = new Checklist_Service( new Validator( $registry, $settings ), $settings );

		return new Editor_Meta_Box( $settings, $service );
	}

	/**
	 * A registry with one row of each interesting status.
	 *
	 * @since 1.0.0
	 *
	 * @return Rule_Registry
	 */
	private function mixed_registry(): Rule_Registry {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'alpha', 'pass', 10 ) );
		$registry->register( new Fake_Rule( 'beta', 'fail', 20 ) );
		$registry->register( new Fake_Rule( 'gamma', 'warn', 30 ) );
		$registry->register( new Fake_Rule( 'delta', 'skip', 40 ) );

		return $registry;
	}

	/**
	 * Capture the box's rendered output.
	 *
	 * @since 1.0.0
	 *
	 * @param Editor_Meta_Box $box  The box to render.
	 * @param WP_Post         $post The product to render it for.
	 * @return string
	 */
	private function render( Editor_Meta_Box $box, WP_Post $post ): string {
		ob_start();
		$box->render( $post );

		return (string) ob_get_clean();
	}

	/**
	 * The box is registered in the side column of the product screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_box_is_registered_on_the_product_screen(): void {
		$post = $this->product();
		$box  = $this->box( $this->mixed_registry() );

		$box->register();

		/*
		 * The hook is the product-specific variant, so no per-request post-type branch is
		 * needed. Core fires it; the test calls the callback directly rather than firing a
		 * core hook itself, which would drag every other plugin's boxes in with it.
		 */
		$this->assertNotFalse( has_action( 'add_meta_boxes_product', array( $box, 'add_meta_box' ) ) );

		$box->add_meta_box( $post );

		$this->assertArrayHasKey(
			Editor_Meta_Box::ID,
			$GLOBALS['wp_meta_boxes']['product']['side']['high']
		);
	}

	/**
	 * The box is not registered when the checklist is switched off entirely.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_box_is_not_registered_when_the_checklist_is_disabled(): void {
		$registry = $this->mixed_registry();
		$settings = new Settings( $registry );
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$service = new Checklist_Service( new Validator( $registry, $settings ), $settings );
		$post    = $this->product();

		( new Editor_Meta_Box( $settings, $service ) )->add_meta_box( $post );

		$this->assertArrayNotHasKey( 'product', (array) ( $GLOBALS['wp_meta_boxes'] ?? array() ) );
	}

	/**
	 * The rendered panel is a mount node wrapping a complete, static checklist.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_render_contains_the_mount_node_and_the_fallback_list(): void {
		$output = $this->render( $this->box( $this->mixed_registry() ), $this->product() );

		$this->assertStringContainsString( 'id="' . Editor_Meta_Box::MOUNT_ID . '"', $output );
		$this->assertStringContainsString( 'sit-wcpg-checklist__fallback', $output );

		// One row per rule, each carrying its status as a class.
		$this->assertStringContainsString( 'sit-wcpg-checklist__item--pass', $output );
		$this->assertStringContainsString( 'sit-wcpg-checklist__item--fail', $output );
		$this->assertStringContainsString( 'sit-wcpg-checklist__item--warning', $output );
		$this->assertStringContainsString( 'sit-wcpg-checklist__item--skipped', $output );

		// Labels for every rule, and messages only for the rows that did not pass.
		$this->assertStringContainsString( 'Fake alpha', $output );
		$this->assertStringContainsString( 'Fake beta', $output );
		$this->assertStringContainsString( 'Fake failure.', $output );
		$this->assertStringContainsString( 'Fake warning.', $output );
	}

	/**
	 * The summary reports the readiness state and the counts.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_summary_reports_readiness(): void {
		$failing = $this->render( $this->box( $this->mixed_registry() ), $this->product() );

		$this->assertStringContainsString( 'Not ready to publish', $failing );
		$this->assertStringContainsString( '1 of 3 checks passed', $failing );
		$this->assertStringContainsString( '2 issues', $failing );

		Checklist_Service::flush_memo();
		wp_cache_flush();

		$passing_registry = new Rule_Registry();
		$passing_registry->register( new Fake_Rule( 'alpha', 'pass', 10 ) );

		$passing = $this->render( $this->box( $passing_registry ), $this->product( 'Another product' ) );

		$this->assertStringContainsString( 'Ready to publish', $passing );
	}

	/**
	 * Rules that do not apply are rendered last, under their own heading.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_skipped_rules_are_collected_under_their_own_heading(): void {
		$output = $this->render( $this->box( $this->mixed_registry() ), $this->product() );

		$this->assertStringContainsString( 'Not applicable (1)', $output );
		$this->assertLessThan(
			strpos( $output, 'sit-wcpg-checklist__items--skipped' ),
			strpos( $output, 'sit-wcpg-checklist__item--fail' ),
			'Skipped rows must come after the rows that were evaluated.'
		);
	}

	/**
	 * Group headings are rendered from the shared, translated map.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_group_headings_come_from_the_shared_map(): void {
		$output = $this->render( new Editor_Meta_Box( new Settings() ), $this->product() );

		foreach ( array( 'Content', 'Media', 'Pricing', 'Organization', 'Inventory' ) as $heading ) {
			$this->assertStringContainsString( '<h4 class="sit-wcpg-checklist__group">' . $heading . '</h4>', $output );
		}
	}

	/**
	 * Status is never conveyed by a glyph alone.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_every_status_glyph_is_accompanied_by_text(): void {
		$output = $this->render( $this->box( $this->mixed_registry() ), $this->product() );

		$this->assertSame(
			substr_count( $output, '<li class="sit-wcpg-checklist__item ' ),
			substr_count( $output, '<span class="screen-reader-text">' ),
			'Every row carries exactly one visually hidden status label.'
		);
		$this->assertStringContainsString( '<span class="screen-reader-text">Passed</span>', $output );
		$this->assertStringContainsString( '<span class="screen-reader-text">Failed</span>', $output );
		$this->assertStringContainsString( '<span class="screen-reader-text">Warning</span>', $output );
		$this->assertStringContainsString( '<span class="screen-reader-text">Not applicable</span>', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
	}

	/**
	 * A hostile product title cannot reach the rendered panel.
	 *
	 * The structural mitigation in section 9.5 means it never gets into a message in the
	 * first place; this asserts the outcome rather than the mechanism, using the shipped
	 * rules so the title rule really runs against the hostile value.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_hostile_product_title_never_reaches_the_markup(): void {
		$post   = $this->product( '"><script>alert(1)</script>' );
		$output = $this->render( new Editor_Meta_Box( new Settings() ), $post );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'alert(1)', $output );
	}

	/**
	 * A user who cannot edit the product gets no checklist at all.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_user_without_edit_post_gets_nothing(): void {
		$post = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render( $this->box( $this->mixed_registry() ), $post ) );
	}

	/**
	 * The shipped panel renders a row for every enabled rule.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_shipped_rules_all_render(): void {
		$post   = $this->product();
		$output = $this->render( new Editor_Meta_Box( new Settings() ), $post );

		$result = Plugin::instance()->checklist()->validate_post( $post->ID );

		foreach ( $result->get_results() as $row ) {
			$this->assertStringContainsString( esc_html( $row->get_label() ), $output );
		}
	}
}
