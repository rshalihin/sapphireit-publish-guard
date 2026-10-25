<?php
/**
 * Shared fixtures for the publishing-guard integration tests.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product;
use WC_Product_External;
use WC_Product_Grouped;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Products that pass or fail the shipped required rules, and settings helpers.
 *
 * The shipped required rules are title, description, featured image, price and
 * category (section 5.6). A "passing" product satisfies all five; a "failing" one is the
 * same product with no regular price, so exactly one required rule fails.
 *
 * Not a test class itself: the name does not end in `Test.php`, so PHPUnit does not
 * collect it, and the bootstrap loads it.
 *
 * @since 1.0.0
 */
abstract class Publish_Guard_Test_Case extends WP_UnitTestCase {

	/**
	 * The guard layers `publish_through()` drives: the classic editor, Quick Edit and
	 * Bulk Edit (Layer A), a CRUD save and the WooCommerce REST API (Layer B), and the
	 * scheduled-publish cron (Layer C).
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	protected const LAYERS = array( 'classic', 'quick_edit', 'bulk_edit', 'crud', 'rest', 'scheduled' );

	/**
	 * The acting administrator.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected int $admin_id = 0;

	/**
	 * Contexts judged during the last `publish_through()`.
	 *
	 * @since 1.1.0
	 * @var Product_Context[]
	 */
	protected array $judged_contexts = array();

	/**
	 * Reset settings, memo and caches, and act as an administrator.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();
		wp_cache_flush();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Leave no settings or memo behind.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * Store publishing settings over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param array $publishing Keys of the `publishing` group to change.
	 * @return void
	 */
	protected function set_publishing( array $publishing ): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['publishing'] = array_merge( $stored['publishing'], $publishing );

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
	}

	/**
	 * A real image attachment, so the featured-image rule passes.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	protected function image(): int {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'sit-wcpg-test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			)
		);
	}

	/**
	 * The props of a product that passes every shipped required rule.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status Post status to save it with.
	 * @param array  $props  Props to change.
	 * @return array
	 */
	protected function passing_props( string $status = 'draft', array $props = array() ): array {
		$term = wp_insert_term( 'sit-wcpg-' . wp_generate_password( 6, false ), 'product_cat' );

		return array_merge(
			array(
				'name'              => 'A complete product',
				'description'       => str_repeat( 'A sentence that describes the product. ', 8 ),
				'short_description' => str_repeat( 'A short blurb. ', 6 ),
				'regular_price'     => '19.99',
				'sku'               => 'SIT-WCPG-' . wp_generate_password( 8, false ),
				'stock_status'      => 'instock',
				'image_id'          => $this->image(),
				'category_ids'      => array( (int) $term['term_id'] ),
				'status'            => $status,
			),
			$props
		);
	}

	/**
	 * A saved simple product that passes every shipped required rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Post status to save it with.
	 * @param array  $props  Props to change before saving.
	 * @return int Product id.
	 */
	protected function passing_product( string $status = 'draft', array $props = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_props( $this->passing_props( $status, $props ) );

		return $this->save_unguarded( $product );
	}

	/**
	 * A saved variable product with stored variations.
	 *
	 * The parent takes the passing props without a regular price, which a variable
	 * product doesn't have. Each item of `$variations` holds that variation's props, plus
	 * an optional `'status' => 'private'` for a disabled variation (default `publish`,
	 * enabled). The parent is then synced with its children, which WooCommerce otherwise
	 * defers to shutdown, so its price and stock status are already stored.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $variations One props array per variation, in menu order.
	 * @param string $status     Post status of the parent.
	 * @param array  $props      Parent props to change before saving.
	 * @return int Parent product id.
	 */
	protected function variable_product( array $variations, string $status = 'draft', array $props = array() ): int {
		$parent_props = $this->passing_props( $status, $props );
		unset( $parent_props['regular_price'] );

		$product = new WC_Product_Variable();
		$product->set_props( $parent_props );
		$parent_id = $this->save_unguarded( $product );

		foreach ( array_values( $variations ) as $menu_order => $variation_props ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_props(
				array_merge(
					array(
						'status'     => 'publish',
						'menu_order' => $menu_order,
					),
					$variation_props
				)
			);
			$this->save_unguarded( $variation );
		}

		return $this->save_unguarded( WC_Product_Variable::sync( $parent_id, false ) );
	}

	/**
	 * A saved grouped product linking the given children.
	 *
	 * @since 1.1.0
	 *
	 * @param int[]  $child_ids Linked product ids, in order.
	 * @param string $status    Post status to save it with.
	 * @return int Product id.
	 */
	protected function grouped_product( array $child_ids, string $status = 'draft' ): int {
		$props = $this->passing_props( $status, array( 'children' => array_map( 'intval', $child_ids ) ) );
		unset( $props['regular_price'] );

		$product = new WC_Product_Grouped();
		$product->set_props( $props );

		return $this->save_unguarded( $product );
	}

	/**
	 * A saved external product: the passing props plus a product URL.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status Post status to save it with.
	 * @param array  $props  Props to change before saving.
	 * @return int Product id.
	 */
	protected function external_product( string $status = 'draft', array $props = array() ): int {
		$product = new WC_Product_External();
		$product->set_props(
			$this->passing_props(
				$status,
				array_merge(
					array(
						'product_url' => 'https://example.org/sit-wcpg-external',
						'button_text' => 'Buy elsewhere',
					),
					$props
				)
			)
		);

		return $this->save_unguarded( $product );
	}

	/**
	 * A saved simple product whose only required failure is the missing price.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Post status to save it with.
	 * @return int Product id.
	 */
	protected function failing_product( string $status = 'draft' ): int {
		return $this->passing_product( $status, array( 'regular_price' => '' ) );
	}

	/**
	 * Save a fixture with the guard switched off, so a failing fixture can be live.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Takes any product, variations included.
	 *
	 * @param WC_Product $product Product to save.
	 * @return int Product id.
	 */
	protected function save_unguarded( WC_Product $product ): int {
		add_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );
		$product->save();
		remove_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );

		Checklist_Service::flush_memo();

		return $product->get_id();
	}

	/**
	 * The stored status, read past every cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	protected function status_of( int $post_id ): string {
		clean_post_cache( $post_id );

		return (string) get_post_status( $post_id );
	}

	/**
	 * Publish through `wp_update_post()`, as another plugin or the editor would.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id Post id.
	 * @param array  $extra   Extra `$postarr` fields, such as a form payload.
	 * @param string $status  Status to ask for.
	 * @return void
	 */
	protected function publish( int $post_id, array $extra = array(), string $status = 'publish' ): void {
		wp_update_post(
			wp_slash(
				array_merge(
					array(
						'ID'          => $post_id,
						'post_status' => $status,
					),
					$extra
				)
			)
		);
	}

	/**
	 * Every guard layer a publish can arrive through, as a data provider.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function layer_provider(): array {
		$layers = array();

		foreach ( self::LAYERS as $layer ) {
			$layers[ $layer ] = array( $layer );
		}

		return $layers;
	}

	/**
	 * Ask to publish a stored product through one guard layer.
	 *
	 * The classic and Quick Edit payloads carry the stored product's own values, the way
	 * the browser posts them: an absent `_manage_stock` reads as unticked, and a grouped
	 * form without `grouped_products` as an emptied group (R12). Every context a layer
	 * judges is recorded in `$judged_contexts`.
	 *
	 * @since 1.1.0
	 *
	 * @param string $layer   One of `LAYERS`.
	 * @param int    $post_id Stored product id.
	 * @return int The user a refusal notice goes to: the author for the scheduled layer.
	 */
	protected function publish_through( string $layer, int $post_id ): int {
		$user_id = $this->admin_id;

		$this->judged_contexts = array();

		if ( 'scheduled' === $layer ) {
			$this->schedule_unguarded( $post_id );
			$user_id = (int) get_post_field( 'post_author', $post_id );
		}

		add_filter( 'sit_wcpg_should_enforce', array( $this, 'record_judged_context' ), 100, 2 );

		switch ( $layer ) {
			case 'classic':
				$this->publish( $post_id, $this->classic_form( $post_id ) );
				break;

			case 'quick_edit':
				$this->publish( $post_id, $this->quick_edit_payload( $post_id ) );
				break;

			case 'bulk_edit':
				require_once ABSPATH . 'wp-admin/includes/post.php';

				bulk_edit_posts(
					array(
						'post_type'                    => 'product',
						'bulk_edit'                    => 'Update',
						'post'                         => array( (string) $post_id ),
						'_status'                      => 'publish',
						'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
						'change_regular_price'         => '',
						'_regular_price'               => '',
					)
				);
				break;

			case 'crud':
				$product = wc_get_product( $post_id );
				$product->set_status( 'publish' );
				$product->save();
				break;

			case 'rest':
				$this->rest_publish( $post_id );
				break;

			case 'scheduled':
				// The cron runs with no user.
				wp_set_current_user( 0 );
				wp_publish_post( $post_id );
				wp_set_current_user( $this->admin_id );
				break;

			default:
				$this->fail( 'Unknown layer ' . $layer );
		}

		remove_filter( 'sit_wcpg_should_enforce', array( $this, 'record_judged_context' ), 100 );
		Checklist_Service::flush_memo();

		return $user_id;
	}

	/**
	 * Record a context a layer judged. Hooked on `sit_wcpg_should_enforce`.
	 *
	 * @since 1.1.0
	 *
	 * @param bool            $enforce The decision so far, returned unchanged.
	 * @param Product_Context $context The product as the layer sees it.
	 * @return bool
	 */
	public function record_judged_context( $enforce, $context ) {
		if ( $context instanceof Product_Context ) {
			$this->judged_contexts[] = $context;
		}

		return $enforce;
	}

	/**
	 * Schedule a product for tomorrow with the guard switched off, so the scheduled layer
	 * is the only one that judges it.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	protected function schedule_unguarded( int $post_id ): void {
		$date = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		add_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );
		$this->publish(
			$post_id,
			array(
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
				'edit_date'     => true,
			)
		);
		remove_filter( 'sit_wcpg_should_enforce', '__return_false', 99 );

		Checklist_Service::flush_memo();

		$this->assertSame( 'future', $this->status_of( $post_id ) );
	}

	/**
	 * A classic-editor form carrying the stored product's values.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Product id.
	 * @return array
	 */
	protected function classic_form( int $post_id ): array {
		$product = wc_get_product( $post_id );
		$form    = array_merge(
			array(
				'action'                 => 'editpost',
				'post_ID'                => $post_id,
				'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
				'product-type'           => $product->get_type(),
				'product_image_gallery'  => implode( ',', $product->get_gallery_image_ids( 'edit' ) ),
			),
			self::price_and_stock_fields( $product )
		);

		if ( $product instanceof WC_Product_Grouped ) {
			$form['grouped_products'] = array_map( 'strval', $product->get_children( 'edit' ) );
		}

		return $form;
	}

	/**
	 * A Quick Edit payload carrying the stored product's values.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Product id.
	 * @return array
	 */
	protected function quick_edit_payload( int $post_id ): array {
		return array_merge(
			array(
				'action'                       => 'inline-save',
				'post_ID'                      => $post_id,
				'woocommerce_quick_edit'       => '1',
				'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
			),
			self::price_and_stock_fields( wc_get_product( $post_id ) )
		);
	}

	/**
	 * The price, SKU and stock fields both editors post, from the stored product.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Product $product Stored product.
	 * @return array
	 */
	private static function price_and_stock_fields( WC_Product $product ): array {
		$fields = array(
			'_regular_price' => $product->get_regular_price( 'edit' ),
			'_sale_price'    => $product->get_sale_price( 'edit' ),
			'_sku'           => $product->get_sku( 'edit' ),
			'_stock_status'  => $product->get_stock_status( 'edit' ),
		);

		if ( $product->get_manage_stock( 'edit' ) ) {
			$fields['_manage_stock'] = 'yes';
			$fields['_stock']        = (string) $product->get_stock_quantity( 'edit' );
		}

		return $fields;
	}

	/**
	 * `PUT /wc/v3/products/<id>` with `status=publish`.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	private function rest_publish( int $post_id ): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $post_id );
		$request->set_body_params( array( 'status' => 'publish' ) );

		$response = $wp_rest_server->dispatch( $request );

		$wp_rest_server = null;

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The validation result of each context the last `publish_through()` judged.
	 *
	 * @since 1.1.0
	 *
	 * @return Validation_Result[]
	 */
	protected function judged_results(): array {
		$checklist = Plugin::instance()->checklist();

		return array_map(
			static function ( Product_Context $context ) use ( $checklist ) {
				return $checklist->validate_context( $context );
			},
			$this->judged_contexts
		);
	}

	/**
	 * The status of one rule in a result.
	 *
	 * @since 1.1.0
	 *
	 * @param Validation_Result $result  The result.
	 * @param string            $rule_id Rule id.
	 * @return string The status, or '' when the rule did not run.
	 */
	protected static function rule_status( Validation_Result $result, string $rule_id ): string {
		foreach ( $result->get_results() as $row ) {
			if ( $row->get_rule_id() === $rule_id ) {
				return $row->get_status();
			}
		}

		return '';
	}

	/**
	 * The notice entries waiting for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id; defaults to the acting administrator.
	 * @return array
	 */
	protected function pending_notices( int $user_id = 0 ): array {
		return Plugin::instance()->notices()->pending( $user_id > 0 ? $user_id : $this->admin_id );
	}

	/**
	 * The rule ids a queued entry names.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Queued entry.
	 * @return array
	 */
	protected static function failed_rule_ids( array $entry ): array {
		return array_column( $entry['failures'], 'rule_id' );
	}

	/**
	 * Assert one entry of a kind was queued for a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id Product id.
	 * @param string $kind    Notices::KIND_* value.
	 * @param int    $user_id User id; defaults to the acting administrator.
	 * @return void
	 */
	protected function assertNoticeQueued( int $post_id, string $kind = Notices::KIND_BLOCKED, int $user_id = 0 ): void {
		$pending = $this->pending_notices( $user_id );

		$this->assertArrayHasKey( $post_id, $pending );
		$this->assertSame( $kind, $pending[ $post_id ]['kind'] );
		$this->assertContains( 'price', self::failed_rule_ids( $pending[ $post_id ] ) );
	}
}
