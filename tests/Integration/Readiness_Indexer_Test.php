<?php
/**
 * Tests for the readiness indexer.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use RuntimeException;
use WC_Admin_Duplicate_Product;

/**
 * Every save leaves an accurate flag, once, without ever slowing the storefront or
 * breaking the save (D9, D10, Review Focus 2–4).
 *
 * @since 1.2.0
 */
final class Readiness_Indexer_Test extends Publish_Guard_Test_Case {

	/**
	 * Validations seen since `count_validations()`.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private int $validations = 0;

	/**
	 * The plugin's own indexer.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Indexer
	 */
	private function indexer(): Readiness_Indexer {
		return Plugin::instance()->readiness_indexer();
	}

	/**
	 * The stored flag of a product.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Product id.
	 * @return array|null
	 */
	private function flag( int $id ): ?array {
		return Plugin::instance()->readiness_store()->read( $id );
	}

	/**
	 * Start with nothing pending, on an admin screen, where saves validate.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Readiness_Indexer::reset();
		set_current_screen( 'edit-product' );
	}

	/**
	 * Leave no screen behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * Count every validation from now on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function count_validations(): void {
		$this->validations = 0;

		add_filter(
			'sit_wcpg_validation_result',
			function ( $result ) {
				++$this->validations;

				return $result;
			}
		);
	}

	/**
	 * Store settings with the checklist switched on or off.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $enabled The master switch.
	 * @return void
	 */
	private function set_enabled( bool $enabled ): void {
		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = $enabled;

		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();
	}

	/**
	 * A classic-editor save leaves the flag, and validates once in doing so.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_classic_editor_save_writes_flag_once(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$id = $this->failing_product();

		Readiness_Indexer::reset();
		$this->count_validations();

		edit_post(
			wp_slash(
				array_merge(
					$this->classic_form( $id ),
					array(
						'post_type'   => 'product',
						'post_status' => 'draft',
						'post_title'  => 'A complete product',
					)
				)
			)
		);

		$this->assertArrayHasKey( $id, array_flip( Readiness_Indexer::pending() ) );
		$this->assertSame( 1, $this->indexer()->flush_pending() );
		$this->assertSame( 'fail', $this->flag( $id )['status'] );
		$this->assertSame( array( 'price' ), $this->flag( $id )['required_failures'] );
		$this->assertSame( 1, $this->validations );
	}

	/**
	 * A CRUD save leaves the flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_crud_save_writes_flag(): void {
		$id = $this->spotless_product();

		$this->indexer()->flush_pending();

		$this->assertSame( 'pass', $this->flag( $id )['status'] );
	}

	/**
	 * The flag carries the signature it was computed under.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_flag_carries_the_current_signature(): void {
		$id = $this->passing_product();

		$this->indexer()->flush_pending();

		$this->assertSame( Readiness_Store::signature( Plugin::instance()->settings() ), $this->flag( $id )['signature'] );
	}

	/**
	 * Quick Edit and the REST API both mark the product.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_marking_layers
	 *
	 * @param string $layer A `publish_through()` layer.
	 * @return void
	 */
	public function test_quick_edit_and_rest_saves_are_marked( string $layer ): void {
		$id = $this->passing_product();

		Readiness_Indexer::reset();
		$this->publish_through( $layer, $id );

		$this->assertContains( $id, Readiness_Indexer::pending() );
	}

	/**
	 * The save paths R5 names.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_marking_layers(): array {
		return array(
			'quick edit' => array( 'quick_edit' ),
			'bulk edit'  => array( 'bulk_edit' ),
			'rest'       => array( 'rest' ),
		);
	}

	/**
	 * A variation's write marks its parent, and never the variation itself.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_variation_change_marks_parent(): void {
		$parent_id = $this->variable_product( array( array( 'regular_price' => '10' ) ) );
		$child_id  = wc_get_product( $parent_id )->get_children()[0];

		Readiness_Indexer::reset();

		$variation = wc_get_product( $child_id );
		$variation->set_regular_price( '12' );
		$variation->save();

		$this->assertSame( array( $parent_id ), Readiness_Indexer::pending() );
	}

	/**
	 * Trashing clears the flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_trash_clears_flag(): void {
		$id = $this->failing_product();

		$this->indexer()->flush_pending();
		$this->assertNotNull( $this->flag( $id ) );

		wp_trash_post( $id );
		$this->indexer()->flush_pending();

		$this->assertNull( $this->flag( $id ) );
	}

	/**
	 * Fixing the product changes its flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_flag_follows_a_fix(): void {
		$id = $this->failing_product();

		$this->indexer()->flush_pending();
		$this->assertSame( 'fail', $this->flag( $id )['status'] );

		$product = wc_get_product( $id );
		$product->set_regular_price( '9.99' );
		$product->save();

		$this->indexer()->flush_pending();
		$this->assertSame( 'warning', $this->flag( $id )['status'], 'The price was its only required failure; the fixture still warns.' );
		$this->assertSame( array(), $this->flag( $id )['required_failures'] );
	}

	/**
	 * With the master switch off, nothing is written and the set is emptied.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disabled_checklist_writes_nothing(): void {
		$id = $this->failing_product();

		$this->set_enabled( false );

		$this->assertSame( 0, $this->indexer()->flush_pending() );
		$this->assertNull( $this->flag( $id ) );
		$this->assertSame( array(), Readiness_Indexer::pending() );
	}

	/**
	 * Review Focus 3: whatever goes wrong while indexing, the save stands.
	 *
	 * The validator already isolates a rule that throws, so the exception that reaches the
	 * indexer is one from the `sit_wcpg_validation_result` filter, which runs outside it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_indexer_failure_never_breaks_the_save(): void {
		$id = $this->passing_product();

		$this->indexer()->flush_pending();

		$before = $this->flag( $id );

		add_filter(
			'sit_wcpg_validation_result',
			static function () {
				throw new RuntimeException( 'Indexing went wrong.' );
			}
		);

		$saved = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'A renamed product',
			)
		);

		$this->assertSame( $id, $saved );
		$this->assertSame( 0, $this->indexer()->flush_pending() );
		$this->assertSame( 'A renamed product', get_post( $id )->post_title );
		$this->assertSame( $before, $this->flag( $id ) );
	}

	/**
	 * Review Focus 4: a shopper's request never validates.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_storefront_request_enqueues_instead_of_validating(): void {
		$parent_id = $this->variable_product(
			array(
				array(
					'regular_price'  => '10',
					'manage_stock'   => true,
					'stock_quantity' => 5,
				),
			)
		);
		$child_id  = wc_get_product( $parent_id )->get_children()[0];

		set_current_screen( 'front' );
		Readiness_Indexer::reset();
		$this->count_validations();

		wc_update_product_stock( $child_id, 4 );

		$this->assertContains( $parent_id, Readiness_Indexer::pending() );
		$this->assertSame( 0, $this->indexer()->flush_pending() );
		$this->assertSame( 0, $this->validations );
		$this->assertSame( array(), Readiness_Indexer::pending() );

		$queued = $this->queued_reindexes();

		$this->assertCount( 1, $queued );
		$this->assertSame( array( 'ids' => array( $parent_id ) ), $queued[0] );
	}

	/**
	 * The args of each queued product reindex.
	 *
	 * @since 1.2.0
	 *
	 * @return array[]
	 */
	private function queued_reindexes(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Readiness_Rebuild::PRODUCTS_HOOK,
				'group'    => Readiness_Rebuild::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			)
		);

		return array_values(
			array_map(
				static function ( $action ) {
					return $action->get_args();
				},
				$actions
			)
		);
	}

	/**
	 * The block checkout is REST, and it is still a shopper's request (R16).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_shopper_rest_request_does_not_validate(): void {
		$id = $this->passing_product();

		set_current_screen( 'front' );
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		wp_set_current_user( 0 );
		$this->count_validations();

		$this->assertSame( 0, $this->indexer()->flush_pending() );
		$this->assertSame( 0, $this->validations );
		$this->assertNull( $this->flag( $id ) );
		$this->assertSame( array( array( 'ids' => array( $id ) ) ), $this->queued_reindexes() );
	}

	/**
	 * A merchant's REST save does validate, in its own request.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_merchant_rest_request_validates(): void {
		$id = $this->spotless_product();

		set_current_screen( 'front' );
		add_filter( 'wp_is_rest_endpoint', '__return_true' );

		$this->assertSame( 1, $this->indexer()->flush_pending() );
		$this->assertSame( 'pass', $this->flag( $id )['status'] );
	}

	/**
	 * Review Focus 2: a copy earns its own flag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_duplicate_does_not_copy_readiness_meta(): void {
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';

		$id = $this->failing_product();

		$this->indexer()->flush_pending();
		$this->assertSame( 'fail', $this->flag( $id )['status'] );

		$duplicate = ( new WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $id ) );

		$this->assertNull( $this->flag( $duplicate->get_id() ) );

		$this->indexer()->flush_pending();

		$this->assertSame( 'fail', $this->flag( $duplicate->get_id() )['status'] );
	}

	/**
	 * Other post types are none of the indexer's business.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_non_product_posts_are_ignored(): void {
		Readiness_Indexer::reset();

		$post_id = self::factory()->post->create();

		$this->assertSame( array(), Readiness_Indexer::pending() );
		$this->assertSame( 0, $this->indexer()->flush_pending() );
		$this->assertFalse( metadata_exists( 'post', $post_id, Readiness_Store::META_STATUS ) );
	}
}
