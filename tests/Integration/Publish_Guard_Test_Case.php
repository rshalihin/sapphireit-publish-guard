<?php
/**
 * Shared fixtures for the publishing-guard integration tests.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WC_Product_Simple;
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
	 * The acting administrator.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected int $admin_id = 0;

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
	 * A saved simple product that passes every shipped required rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Post status to save it with.
	 * @param array  $props  Props to change before saving.
	 * @return int Product id.
	 */
	protected function passing_product( string $status = 'draft', array $props = array() ): int {
		$term = wp_insert_term( 'sit-wcpg-' . wp_generate_password( 6, false ), 'product_cat' );

		$product = new WC_Product_Simple();
		$product->set_props(
			array_merge(
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
	 *
	 * @param WC_Product_Simple $product Product to save.
	 * @return int Product id.
	 */
	protected function save_unguarded( WC_Product_Simple $product ): int {
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
