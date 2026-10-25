<?php
/**
 * The per-product query budget (D11, Review Focus 3).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Checklist_Service;

/**
 * Validating a variable product costs a constant number of queries, however many
 * variations it has: `Child_Loader` primes their posts, meta and terms in one pass. There is
 * no variation cap, because a cap would be a silent bypass.
 *
 * @since 1.1.0
 */
final class Child_Loader_Scaling_Test extends Publish_Guard_Test_Case {

	/**
	 * Queries 100 variations may cost over 10 (D11).
	 *
	 * @since 1.1.0
	 * @var int
	 */
	private const QUERY_ALLOWANCE = 10;

	/**
	 * A variable product with a number of complete variations, each with its own image
	 * and one gallery image.
	 *
	 * @since 1.1.0
	 *
	 * @param int $count Number of enabled variations.
	 * @return int Parent id.
	 */
	private function variable_with( int $count ): int {
		$variations = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$variations[] = array(
				'regular_price'     => '10',
				'sku'               => 'SIT-WCPG-' . wp_generate_password( 10, false ),
				'stock_status'      => 'instock',
				'image_id'          => $this->image(),
				'gallery_image_ids' => array( $this->image() ),
			);
		}

		return $this->variable_product( $variations );
	}

	/**
	 * Queries one cold validation of a stored product runs.
	 *
	 * Every cache is flushed first, so neither measurement inherits the other's work.
	 *
	 * @since 1.1.0
	 *
	 * @param int $parent_id   Parent id.
	 * @param int $variations  Enabled variations it must report, so the count is not of a shortcut.
	 * @return int
	 */
	private function measure( int $parent_id, int $variations ): int {
		global $wpdb;

		wp_cache_flush();
		Checklist_Service::flush_memo();

		$before = $wpdb->num_queries;
		$result = Plugin::instance()->checklist()->validate_post( $parent_id );
		$count  = $wpdb->num_queries - $before;

		$enabled = null;

		foreach ( $result->get_results() as $row ) {
			if ( 'child_products' === $row->get_rule_id() ) {
				$enabled = $row->get_data()['enabled'];
			}
		}

		$this->assertSame( $variations, $enabled, 'The child_products row must report every enabled variation.' );
		$this->assertSame( array(), $result->get_required_failures() );

		return $count;
	}

	/**
	 * 100 variations cost at most 10 queries more than 10 variations.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_query_count_does_not_grow_with_variations(): void {
		$small = $this->variable_with( 10 );
		$large = $this->variable_with( 100 );

		// Warm-up of both, so neither measurement pays a one-off cost the other doesn't:
		// the rule registry and the user's locale, and each parent's `wc_product_children_{id}`
		// transient, which is stored in the database and survives the cache flush.
		$this->measure( $small, 10 );
		$this->measure( $large, 100 );

		$ten     = $this->measure( $small, 10 );
		$hundred = $this->measure( $large, 100 );

		$this->assertLessThanOrEqual(
			$ten + self::QUERY_ALLOWANCE,
			$hundred,
			sprintf( 'Validating 10 variations ran %1$d queries; 100 ran %2$d.', $ten, $hundred )
		);
	}
}
