<?php
/**
 * Imports are not blocked by default (Review Focus 1).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product_CSV_Importer;
use WP_REST_Request;

/**
 * REST and CSV imports create a variable or grouped parent before its children exist, so
 * `child_products` ships at warning severity and such a parent publishes (D7). The CSV
 * importer runs as the signed-in user and is guarded like any CRUD save (R7), so a product
 * that fails a required check is imported as a draft.
 *
 * @since 1.1.0
 */
final class Publish_Guard_Import_Test extends Publish_Guard_Test_Case {

	/**
	 * The image file the CSV fixtures name in their `images` column.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private const IMPORT_IMAGE = 'sit-wcpg-import.jpg';

	/**
	 * The acting shop manager.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	private int $manager_id = 0;

	/**
	 * Load the importer, provide the image the fixtures name, and act as a shop manager.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WC_Admin_Exporters', false ) ) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-exporters.php';
		}

		if ( ! class_exists( 'WC_Product_CSV_Importer', false ) ) {
			require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		}

		// Found by file name, so the importer never downloads anything.
		self::factory()->attachment->create_object(
			array(
				'file'           => self::IMPORT_IMAGE,
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			)
		);

		$this->manager_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $this->manager_id );
	}

	/**
	 * Drop the REST server a test may have built.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Store one rule's severity over the defaults.
	 *
	 * @since 1.1.0
	 *
	 * @param string $rule_id  Rule id.
	 * @param string $severity Severity::* value.
	 * @return void
	 */
	private function set_rule_severity( string $rule_id, string $severity ): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['rules'][ $rule_id ]['severity'] = $severity;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
		Checklist_Service::flush_memo();
	}

	/**
	 * `POST /wc/v3/products` with `status=publish` and an otherwise complete product of a type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $type Product type.
	 * @return int The created product id.
	 */
	private function rest_create( string $type ): int {
		$category = wp_insert_term( 'sit-wcpg-' . wp_generate_password( 6, false ), 'product_cat' );
		$request  = new WP_REST_Request( 'POST', '/wc/v3/products' );

		$request->set_body_params(
			array(
				'name'              => 'An imported ' . $type . ' product',
				'type'              => $type,
				'status'            => 'publish',
				'description'       => str_repeat( 'A sentence that describes the product. ', 8 ),
				'short_description' => str_repeat( 'A short blurb. ', 6 ),
				'images'            => array( array( 'id' => $this->image() ) ),
				'categories'        => array( array( 'id' => (int) $category['term_id'] ) ),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Run the WooCommerce CSV importer over a fixture file, recording every context the
	 * guard judges on the way.
	 *
	 * @since 1.1.0
	 *
	 * @param string $fixture File name in `tests/fixtures/`.
	 * @return array The importer's report.
	 */
	private function import_csv( string $fixture ): array {
		$this->judged_contexts = array();

		add_filter( 'sit_wcpg_should_enforce', array( $this, 'record_judged_context' ), 100, 2 );

		$importer = new WC_Product_CSV_Importer(
			dirname( __DIR__ ) . '/fixtures/' . $fixture,
			array(
				'parse'            => true,
				'prevent_timeouts' => false,
			)
		);
		$report   = $importer->import();

		remove_filter( 'sit_wcpg_should_enforce', array( $this, 'record_judged_context' ), 100 );
		Checklist_Service::flush_memo();

		$errors = array_map(
			static function ( $error ) {
				return $error->get_error_message();
			},
			array_merge( $report['failed'], $report['skipped'] )
		);

		$this->assertSame( array(), $errors, 'The importer refused rows.' );

		return $report;
	}

	/**
	 * The judged contexts of one type family.
	 *
	 * @since 1.1.0
	 *
	 * @param string $family Type_Family::* value.
	 * @return Product_Context[]
	 */
	private function judged_of_family( string $family ): array {
		return array_values(
			array_filter(
				$this->judged_contexts,
				static function ( Product_Context $context ) use ( $family ) {
					return $context->get_type_family() === $family;
				}
			)
		);
	}

	/**
	 * The stored product id for a SKU.
	 *
	 * @since 1.1.0
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	private function id_by_sku( string $sku ): int {
		$id = (int) wc_get_product_id_by_sku( $sku );

		$this->assertGreaterThan( 0, $id, 'No product with SKU ' . $sku );

		return $id;
	}

	/**
	 * A variable product created as published over REST, before any variation exists, is
	 * published: `child_products` only warns.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_rest_variable_published_without_variations_is_published(): void {
		$id = $this->rest_create( 'variable' );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $this->manager_id ) );
		$this->assertSame( Status::WARNING, self::rule_status( Plugin::instance()->checklist()->validate_post( $id ), 'child_products' ) );
	}

	/**
	 * A grouped product created as published over REST with no children is published.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_rest_grouped_published_without_children_is_published(): void {
		$id = $this->rest_create( 'grouped' );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $this->manager_id ) );
		$this->assertSame( Status::WARNING, self::rule_status( Plugin::instance()->checklist()->validate_post( $id ), 'child_products' ) );
	}

	/**
	 * A merchant who makes `child_products` required blocks both.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_raising_child_products_to_required_blocks_it(): void {
		$this->set_rule_severity( 'child_products', Severity::REQUIRED );

		foreach ( array( 'variable', 'grouped' ) as $type ) {
			$id      = $this->rest_create( $type );
			$pending = $this->pending_notices( $this->manager_id );

			$this->assertSame( 'draft', $this->status_of( $id ), $type );

			// Judged before it had an id, it is still reported under the id the save gave it.
			$this->assertArrayNotHasKey( 0, $pending, $type );
			$this->assertArrayHasKey( $id, $pending, $type );
			$this->assertSame( array( 'child_products' ), self::failed_rule_ids( $pending[ $id ] ), $type );

			delete_transient( Notices::TRANSIENT_PREFIX . $this->manager_id );
		}
	}

	/**
	 * The CSV importer saves a variable parent before its variations, so the parent is
	 * judged with none; it is published, and so are the variations that follow.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_csv_import_of_variable_parent_then_variations_is_published(): void {
		$report    = $this->import_csv( 'import-variable.csv' );
		$parent_id = $this->id_by_sku( 'SIT-WCPG-CSV-VAR' );
		$parent    = wc_get_product( $parent_id );

		$this->assertCount( 2, $report['imported_variations'] );
		$this->assertSame( 'variable', $parent->get_type() );
		$this->assertSame( 'publish', $this->status_of( $parent_id ) );
		$this->assertCount( 2, $parent->get_children() );

		foreach ( $parent->get_children() as $variation_id ) {
			$this->assertSame( 'publish', $this->status_of( $variation_id ) );
		}

		// The parent was judged, and at that moment it had no variations.
		$judged = $this->judged_of_family( Type_Family::VARIABLE );

		$this->assertNotEmpty( $judged, 'The guard never judged the variable parent.' );
		$this->assertSame( array(), $judged[0]->get_variations() );
		$this->assertArrayNotHasKey( $parent_id, $this->pending_notices( $this->manager_id ) );
	}

	/**
	 * The CSV importer creates `importing` placeholders for grouped children named before
	 * their own rows. They exist but are not published, so `child_products` warns, and the
	 * parent is published; the children then publish from their own rows.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_csv_import_of_grouped_parent_before_its_children_is_published(): void {
		$this->import_csv( 'import-grouped.csv' );

		$parent_id = $this->id_by_sku( 'SIT-WCPG-CSV-GRP' );
		$children  = array( $this->id_by_sku( 'SIT-WCPG-CSV-GRP-1' ), $this->id_by_sku( 'SIT-WCPG-CSV-GRP-2' ) );

		$this->assertSame( 'publish', $this->status_of( $parent_id ) );
		$this->assertEqualsCanonicalizing( $children, wc_get_product( $parent_id )->get_children() );

		foreach ( $children as $child_id ) {
			$this->assertSame( 'publish', $this->status_of( $child_id ) );
		}

		$judged = $this->judged_of_family( Type_Family::GROUPED );

		$this->assertNotEmpty( $judged, 'The guard never judged the grouped parent.' );
		$this->assertSame( 0, $judged[0]->get_published_child_count() );
		$this->assertCount( 2, $judged[0]->get_child_ids() );

		$result = Plugin::instance()->checklist()->validate_context( $judged[0] );

		$this->assertSame( Status::WARNING, self::rule_status( $result, 'child_products' ) );
		$this->assertArrayNotHasKey( $parent_id, $this->pending_notices( $this->manager_id ) );
	}

	/**
	 * A product that fails a required check is imported as a draft, and the importer is
	 * told why (pins the corrected FAQ, R7). Two such rows are each reported under their own
	 * id, although each was judged before it had one (§6.1).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_csv_import_of_a_product_failing_a_required_check_is_a_draft(): void {
		$this->import_csv( 'import-unpriced.csv' );

		$ids     = array( $this->id_by_sku( 'SIT-WCPG-CSV-UNPRICED' ), $this->id_by_sku( 'SIT-WCPG-CSV-UNPRICED-2' ) );
		$pending = $this->pending_notices( $this->manager_id );

		$this->assertArrayNotHasKey( 0, $pending );
		$this->assertCount( 2, $pending );

		foreach ( $ids as $id ) {
			$this->assertSame( 'draft', $this->status_of( $id ) );
			$this->assertArrayHasKey( $id, $pending );
			$this->assertSame( Notices::KIND_BLOCKED, $pending[ $id ]['kind'] );
			$this->assertSame( array( 'price' ), self::failed_rule_ids( $pending[ $id ] ) );
		}
	}
}
