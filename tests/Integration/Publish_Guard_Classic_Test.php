<?php
/**
 * Layer A through the classic editor and wp_update_post().
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;

/**
 * Covers section 9.8's "re-enable the Publish button" and "wp_update_post() from another
 * plugin" rows: the server decides, whatever the browser sent.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Classic_Test extends Publish_Guard_Test_Case {

	/**
	 * A classic-editor form for a product, as `edit_post()` hands it to core.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Product id.
	 * @param array $fields  Form fields to add or replace.
	 * @return array
	 */
	private function form( int $post_id, array $fields = array() ): array {
		return array_merge(
			array(
				'action'                 => 'editpost',
				'post_ID'                => $post_id,
				'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
			),
			$fields
		);
	}

	/**
	 * A failing product stays a draft, and the user is told why.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_failing_product_is_kept_as_a_draft(): void {
		$id = $this->failing_product();

		$this->publish( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id );
		$this->assertNotNull( Plugin::instance()->publish_guard()->get_refused_result( $id ) );
	}

	/**
	 * A passing product publishes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_passing_product_publishes(): void {
		$id = $this->passing_product();

		$this->publish( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices() );
	}

	/**
	 * Manual rows 11 and 15: a missing SKU only warns at its shipped severity, and the
	 * same product is refused as soon as the merchant makes the rule required.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_configured_severity_decides_enforcement(): void {
		$warned = $this->passing_product( 'draft', array( 'sku' => '' ) );

		$this->publish( $warned );

		$this->assertSame( 'publish', $this->status_of( $warned ) );

		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['rules']['sku']['severity'] = Severity::REQUIRED;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
		Checklist_Service::flush_memo();

		$refused = $this->passing_product( 'draft', array( 'sku' => '' ) );

		$this->publish( $refused );

		$pending = $this->pending_notices();

		$this->assertSame( 'draft', $this->status_of( $refused ) );
		$this->assertArrayNotHasKey( $warned, $pending );
		$this->assertArrayHasKey( $refused, $pending );
		$this->assertSame( Notices::KIND_BLOCKED, $pending[ $refused ]['kind'] );
		$this->assertSame( array( 'sku' ), self::failed_rule_ids( $pending[ $refused ] ) );
	}

	/**
	 * A refused pending product stays pending rather than dropping to draft.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_pending_product_stays_pending(): void {
		$id = $this->failing_product( 'pending' );

		$this->publish( $id );

		$this->assertSame( 'pending', $this->status_of( $id ) );
	}

	/**
	 * The form's unsaved price is what counts, not the stale stored one.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_forms_price_is_read_before_it_is_saved(): void {
		$id = $this->failing_product();

		$this->publish( $id, $this->form( $id, array( '_regular_price' => '12.00' ) ) );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * A form that clears the price is refused, although the stored product had one.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_form_that_clears_the_price_is_refused(): void {
		$id = $this->passing_product();

		$this->publish( $id, $this->form( $id, array( '_regular_price' => '' ) ) );

		$this->assertSame( 'draft', $this->status_of( $id ) );
	}

	/**
	 * A form price without WooCommerce's nonce is not believed: WooCommerce would not
	 * save it either.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_forged_form_price_is_not_believed(): void {
		$id = $this->failing_product();

		$this->publish(
			$id,
			$this->form(
				$id,
				array(
					'_regular_price'         => '12.00',
					'woocommerce_meta_nonce' => 'forged',
				)
			)
		);

		$this->assertSame( 'draft', $this->status_of( $id ) );
	}

	/**
	 * Removing every category in the form is a required failure.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_forms_categories_are_read(): void {
		$id = $this->passing_product();

		$this->publish( $id, $this->form( $id, array( 'tax_input' => array( 'product_cat' => array( '0' ) ) ) ) );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertContains( 'category', self::failed_rule_ids( $this->pending_notices()[ $id ] ) );
	}

	/**
	 * Manual row 2: no image, price or category — three required failures, the product
	 * stays a draft, and the notice names all three.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_incomplete_product_reports_all_three_failures(): void {
		$id = $this->passing_product();

		$this->publish(
			$id,
			$this->form(
				$id,
				array(
					'_thumbnail_id'  => '-1',
					'_regular_price' => '',
					'tax_input'      => array( 'product_cat' => array( '0' ) ),
				)
			)
		);

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertEqualsCanonicalizing(
			array( 'featured_image', 'price', 'category' ),
			self::failed_rule_ids( $this->pending_notices()[ $id ] )
		);
	}

	/**
	 * A product inserted as published in one call is judged too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_new_product_inserted_as_published_is_refused(): void {
		$id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Inserted directly',
			)
		);

		$this->assertSame( 'draft', $this->status_of( $id ) );
	}

	/**
	 * A live product is never demoted, even when it no longer passes (section 6.4).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_published_product_is_never_demoted(): void {
		$id = $this->failing_product( 'publish' );

		$this->publish( $id, array( 'post_title' => 'Edited while live' ) );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * Saving a draft is not a publish attempt.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_saving_a_draft_is_not_judged(): void {
		$id = $this->failing_product();

		$this->publish( $id, array(), 'draft' );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices() );
	}

	/**
	 * The editor redirect is marked, and the "published" message is replaced.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_redirect_and_message_say_it_was_not_published(): void {
		$id = $this->failing_product();

		$this->publish( $id );

		// Called directly: the notice hooks are admin-only, and a test request is not one.
		$notices  = Plugin::instance()->notices();
		$location = $notices->filter_redirect( 'post.php?post=' . $id . '&action=edit&message=6', $id );

		$this->assertStringContainsString( Notices::QUERY_ARG . '=1', $location );
		$this->assertStringNotContainsString( Notices::QUERY_ARG, $notices->filter_redirect( 'post.php?post=1', $id + 1 ) );

		$_GET[ Notices::QUERY_ARG ] = '1';
		$messages                   = $notices->filter_messages( array( 'product' => array( 6 => 'Product published.' ) ) );
		unset( $_GET[ Notices::QUERY_ARG ] );

		$this->assertSame( 'Product saved, but not published.', $messages['product'][6] );
	}

	/**
	 * The notice is printed once on the product's own editor, then forgotten.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_notice_renders_once_on_the_editor(): void {
		$id = $this->failing_product();

		$this->publish( $id );

		set_current_screen( 'product' );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's editor sets it; the notice reads the product from it.

		ob_start();
		Plugin::instance()->notices()->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'This product was not published', $html );
		$this->assertArrayNotHasKey( $id, $this->pending_notices() );

		ob_start();
		Plugin::instance()->notices()->render();
		$this->assertSame( '', (string) ob_get_clean() );

		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
	}
}
