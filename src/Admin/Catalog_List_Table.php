<?php
/**
 * The list on the catalog screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Readiness_Store;
use WP_List_Table;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Published products whose stored flag says a required check fails.
 *
 * Nothing here validates: each cell reads what the indexer last stored, and the "Last
 * checked" column says when that was.
 *
 * @since 1.2.0
 */
final class Catalog_List_Table extends WP_List_Table {

	/**
	 * The signature a flag must carry to be current.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $current_signature;

	/**
	 * Translated rule labels, by rule id.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	private array $labels;

	/**
	 * Where the flags are read from.
	 *
	 * @since 1.2.0
	 * @var Readiness_Store
	 */
	private Readiness_Store $store;

	/**
	 * Construct the table.
	 *
	 * @since 1.2.0
	 *
	 * @param string $current_signature The signature a flag must carry to be current.
	 * @param array  $labels            Translated rule labels, by rule id.
	 */
	public function __construct( string $current_signature, array $labels ) {
		parent::__construct(
			array(
				'singular' => 'sit-wcpg-catalog-product',
				'plural'   => 'sit-wcpg-catalog-products',
				'ajax'     => false,
			)
		);

		$this->current_signature = $current_signature;
		$this->labels            = $labels;
		$this->store             = Plugin::instance()->readiness_store();
	}

	/**
	 * The columns.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'name'    => __( 'Product', 'sapphireit-publish-guard' ),
			'checks'  => __( 'Failing required checks', 'sapphireit-publish-guard' ),
			'checked' => __( 'Last checked', 'sapphireit-publish-guard' ),
		);
	}

	/**
	 * Load this page of products.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );

		$query = new WP_Query(
			array(
				'post_type'              => Screen::POST_TYPE,
				'post_status'            => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The flag is what this screen lists.
				'meta_query'             => array( Readiness_Store::meta_query( Readiness_Store::FILTER_FAIL ) ),
				'posts_per_page'         => Catalog_Page::PER_PAGE,
				'paged'                  => $this->get_pagenum(),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
			)
		);

		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => Catalog_Page::PER_PAGE,
			)
		);
	}

	/**
	 * The product's name, linked to its checklist when the user may edit it.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $item The product.
	 * @return string
	 */
	public function column_name( $item ): string {
		$title = esc_html( get_the_title( $item ) );

		if ( ! current_user_can( 'edit_post', $item->ID ) ) {
			return $title;
		}

		return '<a href="' . esc_url( get_edit_post_link( $item->ID, 'raw' ) . '#' . Editor_Meta_Box::ID ) . '">' . $title . '</a>';
	}

	/**
	 * The labels of the required checks that fail.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $item The product.
	 * @return string
	 */
	public function column_checks( $item ): string {
		$flag = $this->store->read( (int) $item->ID );

		if ( null === $flag ) {
			return '';
		}

		$labels = array();

		foreach ( $flag['required_failures'] as $rule_id ) {
			$labels[] = esc_html( $this->labels[ $rule_id ] ?? __( 'A check that is no longer active', 'sapphireit-publish-guard' ) );
		}

		return '<span class="sit-wcpg-catalog__checks">' . implode( ', ', $labels ) . '</span>';
	}

	/**
	 * When the flag was written, and whether it predates the current settings.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $item The product.
	 * @return string
	 */
	public function column_checked( $item ): string {
		$flag = $this->store->read( (int) $item->ID );

		if ( null === $flag || 0 === $flag['checked_at'] ) {
			return '';
		}

		$cell = esc_html(
			sprintf(
				/* translators: %s: a human-readable length of time, such as "2 hours". */
				__( '%s ago', 'sapphireit-publish-guard' ),
				human_time_diff( $flag['checked_at'] )
			)
		);

		if ( $flag['signature'] !== $this->current_signature ) {
			$cell .= '<span class="sit-wcpg-catalog__stale">' . esc_html__( ' (not rechecked yet)', 'sapphireit-publish-guard' ) . '</span>';
		}

		return $cell;
	}

	/**
	 * What an empty list means, which depends on whether checking has finished.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function no_items(): void {
		if ( Plugin::instance()->readiness_rebuild()->status()['is_current'] ) {
			esc_html_e( 'Every live product passes your required checks.', 'sapphireit-publish-guard' );

			return;
		}

		esc_html_e( 'No failing live products found yet. Rechecking is still running, so this list may grow.', 'sapphireit-publish-guard' );
	}
}
