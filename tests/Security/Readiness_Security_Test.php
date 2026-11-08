<?php
/**
 * Security coverage for the readiness filter, catalog screen, override log and CLI output.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Catalog_List_Table;
use ProductPublishGuard\Admin\Catalog_Page;
use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Cli\Gate_Runner;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Support\Override_Log;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Integration\Publish_Guard_Test_Case;
use Spy_REST_Server;
use stdClass;
use WP_Query;
use WP_REST_Request;
use WPDieException;

/**
 * Each surface 1.2.0 adds, attacked once on purpose.
 *
 * @since 1.2.0
 */
final class Readiness_Security_Test extends Publish_Guard_Test_Case {

	/**
	 * A role that can edit its own products and nobody else's.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const LIMITED_ROLE = 'sit_wcpg_limited_editor';

	/**
	 * The main query before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var WP_Query|null
	 */
	private ?WP_Query $main_query = null;

	/**
	 * Load the list-table code and remember the main query.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->main_query = $GLOBALS['wp_the_query'] ?? null;

		add_role(
			self::LIMITED_ROLE,
			'SIT-WCPG limited editor',
			array(
				'read'          => true,
				'edit_products' => true,
			)
		);
	}

	/**
	 * Leave no filter, screen, role or main query behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		unset( $_GET[ Readiness_Filter::QUERY_VAR ], $GLOBALS['current_screen'], $GLOBALS['submenu'] );

		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores what set_up() saved.

		remove_role( self::LIMITED_ROLE );
		delete_option( Override_Log::OPTION );

		parent::tear_down();
	}

	/**
	 * The meta query the filter leaves on the products list for a submitted value.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value The value of the query var.
	 * @return mixed
	 */
	private function meta_query_for( $value ) {
		set_current_screen( 'edit-product' );

		$_GET[ Readiness_Filter::QUERY_VAR ] = $value;

		$query = new WP_Query();

		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The query under test is the main one.

		$query->set( 'post_type', Screen::POST_TYPE );

		( new Readiness_Filter( Plugin::instance()->settings() ) )->apply( $query );

		return $query->get( 'meta_query' );
	}

	/**
	 * A list table holding nothing yet.
	 *
	 * @since 1.2.0
	 *
	 * @param array $labels Rule labels, by rule id.
	 * @return Catalog_List_Table
	 */
	private function table( array $labels = array() ): Catalog_List_Table {
		set_current_screen( Screen::CATALOG_HOOK );

		return new Catalog_List_Table( 'current-signature', $labels );
	}

	/**
	 * The fixtures themselves prove the filter works when the value is valid.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_whitelisted_value_does_filter(): void {
		$this->assertNotSame( '', $this->meta_query_for( 'fail' ) );
	}

	/**
	 * Anything off the whitelist leaves the query alone, quietly.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_hostile_filter_values
	 *
	 * @param mixed $value The submitted value.
	 * @return void
	 */
	public function test_filter_value_cannot_inject_meta_query( $value ): void {
		$this->assertSame( '', $this->meta_query_for( $value ) );
	}

	/**
	 * Values a crafted link could submit.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_hostile_filter_values(): array {
		return array(
			'sql fragment'     => array( "fail' OR 1=1 -- " ),
			'union'            => array( 'x UNION SELECT user_pass FROM wp_users' ),
			'array'            => array( array( 'fail' ) ),
			'nested array'     => array( array( 'key' => array( 'compare' => 'EXISTS' ) ) ),
			'very long string' => array( str_repeat( 'fail', 25000 ) ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * A user without product access gets the plain list.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_ignored_for_contributor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( '', $this->meta_query_for( 'fail' ) );
	}

	/**
	 * No menu entry, and a direct request dies.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_catalog_page_requires_edit_products(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		set_current_screen( Screen::CATALOG_HOOK );

		$page = new Catalog_Page( Plugin::instance()->settings() );

		$page->add_menu();

		$this->assertNotContains( Catalog_Page::MENU_SLUG, array_column( $submenu[ Catalog_Page::PARENT ] ?? array(), 2 ) );

		$this->expectException( WPDieException::class );

		$page->render_page();
	}

	/**
	 * A product the user may not edit is named but not linked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_catalog_hides_edit_link_for_products_user_cannot_edit(): void {
		$post = get_post( $this->passing_product( 'publish', array( 'name' => 'Somebody elses product' ) ) );

		$table = $this->table();

		$this->assertStringContainsString( '<a href=', $table->column_name( $post ), 'The administrator can edit it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => self::LIMITED_ROLE ) ) );

		$cell = $this->table()->column_name( $post );

		$this->assertStringNotContainsString( '<a', $cell );
		$this->assertStringContainsString( 'Somebody elses product', $cell );
	}

	/**
	 * Post meta can be edited by anything, and none of it reaches the page as markup.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_tampered_meta_cannot_inject_markup(): void {
		$id = $this->passing_product( 'publish' );

		update_post_meta( $id, Readiness_Store::META_STATUS, 'fail' );
		update_post_meta( $id, Readiness_Store::META_DATA, array( 'required_failures' => array( '<script>' ) ) );

		$cell = $this->table( array( 'price' => 'Regular price' ) )->column_checks( get_post( $id ) );

		$this->assertStringContainsString( 'A check that is no longer active', $cell );
		$this->assertStringNotContainsString( '<script', $cell );
	}

	/**
	 * A flag whose shape is wrong renders as nothing at all.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_tampered_status_renders_nothing(): void {
		$id = $this->passing_product( 'publish' );

		update_post_meta( $id, Readiness_Store::META_STATUS, '<img src=x onerror=alert(1)>' );

		$table = $this->table();

		$this->assertSame( '', $table->column_checks( get_post( $id ) ) );
		$this->assertSame( '', $table->column_checked( get_post( $id ) ) );
	}

	/**
	 * A corrupted log reads as an empty one.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_tampered_logs
	 *
	 * @param mixed $stored What the option holds.
	 * @return void
	 */
	public function test_override_log_option_tampering_is_neutralised( $stored ): void {
		update_option( Override_Log::OPTION, $stored, false );

		$this->assertSame( array(), ( new Override_Log() )->entries() );
	}

	/**
	 * Values a corrupted or tampered option could hold.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_tampered_logs(): array {
		$object = new stdClass();

		$object->time       = 1700000000;
		$object->product_id = 5;

		return array(
			'an object'         => array( $object ),
			'a list of objects' => array( array( $object, $object ) ),
			'nested arrays'     => array( array( array( array( array( 'time' => 1 ) ) ) ) ),
			'a string'          => array( 'not a log' ),
			'array-typed ids'   => array(
				array(
					array(
						'time'       => array( 1700000000 ),
						'product_id' => array( 5 ),
					),
				),
			),
			'object-typed ids'  => array(
				array(
					array(
						'time'       => $object,
						'product_id' => $object,
					),
				),
			),
		);
	}

	/**
	 * Tampered fields around a valid entry are normalized, never echoed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_override_log_entry_fields_are_normalized(): void {
		update_option(
			Override_Log::OPTION,
			array(
				array(
					'time'       => time(),
					'product_id' => 5,
					'user_id'    => '<b>1</b>',
					'rule_ids'   => array( '<script>', array( 'nested' ), new stdClass() ),
					'save_path'  => '<i>rest</i>',
					'title'      => 'Stored product text',
				),
			),
			false
		);

		$entries = ( new Override_Log() )->entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 0, $entries[0]['user_id'] );
		$this->assertSame( array( 'script' ), $entries[0]['rule_ids'] );
		$this->assertSame( 'none', $entries[0]['save_path'] );
		$this->assertArrayNotHasKey( 'title', $entries[0] );
	}

	/**
	 * Hostile product data appears in no machine-readable output.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_cli_and_rest_outputs_hold_no_product_text(): void {
		global $wp_rest_server;

		$sku = 'HOSTILE-SKU-7731';
		$id  = $this->passing_product(
			'draft',
			array(
				'name'          => '<img src=x onerror=alert(1)>',
				'sku'           => $sku,
				'regular_price' => '',
			)
		);

		$plugin = Plugin::instance();
		$runner = new Gate_Runner( $plugin->settings(), $plugin->checklist(), $plugin->readiness_indexer() );
		$rows   = (string) wp_json_encode( iterator_to_array( $runner->check( array( $id ) ), false ) );

		$this->assertStringContainsString( '"required_failures":"price"', $rows, 'The row is for the hostile product.' );
		$this->assertStringNotContainsString( '<', $rows );
		$this->assertStringNotContainsString( $sku, $rows );

		$wp_rest_server = new Spy_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		$response = $wp_rest_server->dispatch( new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $id . '/validate' ) );

		$this->assertSame( 200, $response->get_status() );

		$preflight = (string) wp_json_encode( $response->get_data()['preflight'] );

		$this->assertStringNotContainsString( '<', $preflight );
		$this->assertStringNotContainsString( $sku, $preflight );
	}
}
