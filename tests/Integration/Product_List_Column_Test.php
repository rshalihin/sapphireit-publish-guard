<?php
/**
 * Tests for the products list Readiness column.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Product_List_Column;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use WC_Product_Simple;
use WP_Query;
use WP_UnitTestCase;

/**
 * Covers column placement, the visibility switches, every cell state, and the query
 * budget of manual row 22 (coding-plan.md sections 7.2, 11.2 and 12.4).
 *
 * @since 1.0.0
 */
final class Product_List_Column_Test extends WP_UnitTestCase {

	/**
	 * Queries a 50-product page may add over the same page without the column.
	 *
	 * Manual row 22 asks for "within ~5". Priming is one query pair for the attachments
	 * whatever the row count; rendering a cell is meant to add none.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const QUERY_ALLOWANCE = 5;

	/**
	 * Rows on the measured page.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Start from an unsaved option, an empty memo and an administrator.
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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no option, memo or screen behind for the next test class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * A column running exactly the rules the test dictates.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry $registry Rules to run.
	 * @param array         $stored   Settings to store over the defaults, by top-level key.
	 * @return Product_List_Column
	 */
	private function column( Rule_Registry $registry, array $stored = array() ): Product_List_Column {
		$settings = new Settings( $registry );

		if ( array() !== $stored ) {
			update_option( Settings::OPTION_NAME, array_replace_recursive( $settings->get_defaults(), $stored ) );
			$settings->refresh();
		}

		$service = new Checklist_Service( new Validator( $registry, $settings ), $settings );

		return new Product_List_Column( $settings, $service );
	}

	/**
	 * A registry of fake rules with the given outcomes.
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$outcomes One of pass, fail, warn, skip per rule.
	 * @return Rule_Registry
	 */
	private function registry( string ...$outcomes ): Rule_Registry {
		$registry = new Rule_Registry();

		foreach ( $outcomes as $index => $outcome ) {
			$registry->register( new Fake_Rule( 'rule_' . $index, $outcome, 10 * ( $index + 1 ) ) );
		}

		return $registry;
	}

	/**
	 * A saved simple product.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Post status to save it with.
	 * @return int Product id.
	 */
	private function product( string $status = 'draft' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'A product' );
		$product->set_status( $status );
		$product->set_regular_price( '19.99' );

		return $product->save();
	}

	/**
	 * A real image attachment.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function image(): int {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'sit-wcpg-test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			)
		);
	}

	/**
	 * Capture one rendered cell.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_List_Column $column  The column to render.
	 * @param int                 $post_id Product to render it for.
	 * @param string              $key     Column key being rendered.
	 * @return string
	 */
	private function cell( Product_List_Column $column, int $post_id, string $key = Product_List_Column::COLUMN ): string {
		ob_start();
		$column->render_column( $key, $post_id );

		return (string) ob_get_clean();
	}

	/**
	 * The three hooks are registered on their product-specific names.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_hooks_are_registered(): void {
		$column = $this->column( $this->registry( 'pass' ) );

		$column->register();

		$this->assertNotFalse( has_filter( 'manage_edit-product_columns', array( $column, 'add_column' ) ) );
		$this->assertNotFalse( has_filter( 'the_posts', array( $column, 'prime_caches' ) ) );
		$this->assertNotFalse( has_action( 'manage_product_posts_custom_column', array( $column, 'render_column' ) ) );
	}

	/**
	 * The column goes immediately before Date.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_column_is_inserted_before_date(): void {
		$columns = $this->column( $this->registry( 'pass' ) )->add_column(
			array(
				'cb'   => '<input type="checkbox" />',
				'name' => 'Name',
				'date' => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'name', Product_List_Column::COLUMN, 'date' ), array_keys( $columns ) );
		$this->assertSame( 'Readiness', $columns[ Product_List_Column::COLUMN ] );
	}

	/**
	 * Without a Date column the Readiness column is appended.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_column_is_appended_when_there_is_no_date_column(): void {
		$columns = $this->column( $this->registry( 'pass' ) )->add_column( array( 'name' => 'Name' ) );

		$this->assertSame( array( 'name', Product_List_Column::COLUMN ), array_keys( $columns ) );
	}

	/**
	 * The column setting switches the column off.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_column_is_hidden_when_the_setting_is_off(): void {
		$column = $this->column( $this->registry( 'pass' ), array( 'product_list' => array( 'show_column' => false ) ) );

		$this->assertArrayNotHasKey( Product_List_Column::COLUMN, $column->add_column( array( 'date' => 'Date' ) ) );
	}

	/**
	 * Switching the whole checklist off hides the column too.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_column_is_hidden_when_the_checklist_is_disabled(): void {
		$column = $this->column( $this->registry( 'pass' ), array( 'enabled' => false ) );

		$this->assertArrayNotHasKey( Product_List_Column::COLUMN, $column->add_column( array( 'date' => 'Date' ) ) );
	}

	/**
	 * A user who cannot edit products never sees the column.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_column_is_hidden_without_edit_products(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$column = $this->column( $this->registry( 'pass' ) );

		$this->assertArrayNotHasKey( Product_List_Column::COLUMN, $column->add_column( array( 'date' => 'Date' ) ) );
	}

	/**
	 * The cell checks the capability itself, so firing the render action directly for a
	 * user who cannot edit products prints nothing (section 9.1).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_cell_renders_nothing_without_edit_products(): void {
		$product_id = $this->product();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->cell( $this->column( $this->registry( 'fail' ) ), $product_id ) );
	}

	/**
	 * Required failures render as a plural-aware error count linking to the panel.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_failures_render_an_error_count_linking_to_the_panel(): void {
		$product_id = $this->product();
		$output     = $this->cell( $this->column( $this->registry( 'fail', 'fail', 'warn', 'pass' ) ), $product_id );

		$this->assertStringContainsString( 'sit-wcpg-readiness--fail', $output );
		$this->assertStringContainsString( '✗', $output );
		$this->assertStringContainsString( '2 errors', $output );
		$this->assertStringContainsString( '#' . Editor_Meta_Box::ID, $output );
		$this->assertStringContainsString( 'post=' . $product_id, $output );
	}

	/**
	 * Warnings alone render as a warning count, singular for one.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_warnings_render_a_warning_count(): void {
		$output = $this->cell( $this->column( $this->registry( 'warn', 'pass' ) ), $this->product() );

		$this->assertStringContainsString( 'sit-wcpg-readiness--warning', $output );
		$this->assertStringContainsString( '1 warning', $output );
		$this->assertStringNotContainsString( '1 warnings', $output );
	}

	/**
	 * A product with nothing failing is Ready.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_passing_product_renders_ready(): void {
		$output = $this->cell( $this->column( $this->registry( 'pass', 'skip' ) ), $this->product() );

		$this->assertStringContainsString( 'sit-wcpg-readiness--pass', $output );
		$this->assertStringContainsString( '✓', $output );
		$this->assertStringContainsString( 'Ready', $output );
	}

	/**
	 * A published product is rated exactly like a draft: the cell describes content, not
	 * publication state.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_published_product_with_errors_still_shows_them(): void {
		$output = $this->cell( $this->column( $this->registry( 'fail' ) ), $this->product( 'publish' ) );

		$this->assertStringContainsString( '1 error', $output );
	}

	/**
	 * Auto-drafts and trashed products render a dash.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_auto_drafts_and_trashed_products_render_a_dash(): void {
		$column   = $this->column( $this->registry( 'fail' ) );
		$trashed  = $this->product();
		$auto_ids = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'auto-draft',
			)
		);

		wp_trash_post( $trashed );

		foreach ( array( $trashed, $auto_ids ) as $post_id ) {
			$output = $this->cell( $column, $post_id );

			$this->assertStringContainsString( 'sit-wcpg-readiness--none', $output );
			$this->assertStringNotContainsString( 'error', $output );
		}
	}

	/**
	 * With every rule skipped or disabled there is no state to show.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_nothing_evaluated_renders_a_dash(): void {
		$output = $this->cell( $this->column( $this->registry( 'skip' ) ), $this->product() );

		$this->assertStringContainsString( 'sit-wcpg-readiness--none', $output );
	}

	/**
	 * Other columns are left to their owners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_other_columns_render_nothing(): void {
		$this->assertSame( '', $this->cell( $this->column( $this->registry( 'fail' ) ), $this->product(), 'name' ) );
	}

	/**
	 * Priming ignores every query that is not the products list's main query.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_priming_ignores_secondary_queries(): void {
		global $wpdb;

		set_current_screen( 'edit-product' );

		$column = $this->column( $this->registry( 'pass' ) );
		$posts  = array( get_post( $this->product() ) );
		$before = $wpdb->num_queries;

		$this->assertSame( $posts, $column->prime_caches( $posts, new WP_Query() ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * Manual row 22: a 50-product page costs no more than ~5 queries over the same page
	 * without the column, with the real rules and real product data.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_full_page_stays_within_the_query_budget(): void {
		set_current_screen( 'edit-product' );

		$plugin   = Plugin::instance();
		$settings = $plugin->settings();
		$stored   = $settings->get_defaults();

		// Enabled so the gallery half of the priming is exercised too.
		$stored['rules']['image_count']['enabled'] = true;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$category_id    = self::factory()->term->create( array( 'taxonomy' => 'product_cat' ) );
		$product_ids    = array();
		$attachment_ids = array();

		for ( $i = 0; $i < self::PAGE_SIZE; $i++ ) {
			$featured = $this->image();
			$gallery  = $this->image();

			$product = new WC_Product_Simple();
			$product->set_name( 'Product ' . $i );
			$product->set_status( 0 === $i % 2 ? 'publish' : 'draft' );
			$product->set_description( str_repeat( 'Sentence about the product. ', 20 ) );
			$product->set_regular_price( '19.99' );
			$product->set_image_id( $featured );
			$product->set_gallery_image_ids( array( $gallery ) );
			$product->set_category_ids( array( $category_id ) );

			$product_ids[]    = $product->save();
			$attachment_ids[] = $featured;
			$attachment_ids[] = $gallery;
		}

		$column = new Product_List_Column( $settings, $plugin->checklist() );

		// Warm-up: one-off reads (the rule registry, options, the user's locale) land here.
		$this->list_page( $column, $product_ids, $attachment_ids );

		$baseline    = $this->list_page( null, $product_ids, $attachment_ids );
		$with_column = $this->list_page( $column, $product_ids, $attachment_ids );

		$this->assertLessThanOrEqual(
			self::QUERY_ALLOWANCE,
			$with_column - $baseline,
			sprintf( 'The column added %d queries to a %d-product page.', $with_column - $baseline, self::PAGE_SIZE )
		);
	}

	/**
	 * Run the list screen's main query cold and, with a column, render every cell.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_List_Column|null $column         The column, or null for the baseline.
	 * @param int[]                    $product_ids    Products on the page.
	 * @param int[]                    $attachment_ids Their images.
	 * @return int Queries the page ran.
	 */
	private function list_page( ?Product_List_Column $column, array $product_ids, array $attachment_ids ): int {
		global $wpdb, $wp_the_query, $wp_query;

		$service = Plugin::instance()->checklist();

		// flush_post() reads the post for its cache key, so it runs before the post caches go.
		foreach ( $product_ids as $product_id ) {
			$service->flush_post( $product_id );
		}

		foreach ( array_merge( $product_ids, $attachment_ids ) as $post_id ) {
			clean_post_cache( $post_id );
		}

		Checklist_Service::flush_memo();

		if ( null !== $column ) {
			add_filter( 'the_posts', array( $column, 'prime_caches' ), 10, 2 );
		}

		$before = $wpdb->num_queries;

		// The main query, as edit.php runs it: prime_caches() only acts on the main query.
		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Emulating the list screen's main query.
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Emulating the list screen's main query.

		$query->query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => self::PAGE_SIZE,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		if ( null !== $column ) {
			ob_start();

			foreach ( $query->posts as $post ) {
				$column->render_column( Product_List_Column::COLUMN, $post->ID );
			}

			ob_end_clean();
		}

		$count = $wpdb->num_queries - $before;

		if ( null !== $column ) {
			remove_filter( 'the_posts', array( $column, 'prime_caches' ), 10 );
		}

		return $count;
	}
}
