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
	 * Size of the product thumbnail, in pixels.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const THUMBNAIL_SIZE = 44;

	/**
	 * The `orderby` values a visitor may sort by, mapped to `WP_Query`'s.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	private const ORDERBY = array(
		'title' => 'title',
	);

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
	 * Variations per product on this page, by product id.
	 *
	 * @since 1.2.0
	 * @var array<int, int>
	 */
	private array $variation_counts = array();

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
			'action'  => __( 'Action', 'sapphireit-publish-guard' ),
		);
	}

	/**
	 * The columns a visitor can sort by.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{string, bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name' => array( 'title', false ),
		);
	}

	/**
	 * Load this page of products.
	 *
	 * Newest change first unless the visitor sorts by name. The page's thumbnails, product
	 * types and variation counts are read in a few queries for the whole page, not per row.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );

		list( $orderby, $order ) = $this->ordering();

		$query = new WP_Query(
			array(
				'post_type'      => Screen::POST_TYPE,
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The flag is what this screen lists.
				'meta_query'     => array( Readiness_Store::meta_query( Readiness_Store::FILTER_FAIL ) ),
				'posts_per_page' => Catalog_Page::PER_PAGE,
				'paged'          => $this->get_pagenum(),
				'orderby'        => $orderby,
				'order'          => $order,
			)
		);

		$this->items = $query->posts;

		if ( array() !== $this->items ) {
			update_post_thumbnail_cache( $query );
			$this->variation_counts = $this->count_variations( array_map( 'intval', wp_list_pluck( $this->items, 'ID' ) ) );
		}

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => Catalog_Page::PER_PAGE,
			)
		);
	}

	/**
	 * The product: thumbnail, name (linked to its checklist when the user may edit it) and a
	 * line with its SKU or type.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $item The product.
	 * @return string
	 */
	public function column_name( $item ): string {
		$id    = (int) $item->ID;
		$name  = get_the_title( $item );
		$title = esc_html( $name );

		if ( current_user_can( 'edit_post', $id ) ) {
			$title = '<a href="' . esc_url( get_edit_post_link( $id, 'raw' ) . '#' . Editor_Meta_Box::ID ) . '">' . $title . '</a>';
		}

		$thumbnail = get_the_post_thumbnail(
			$id,
			array( self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE ),
			array(
				'class' => 'sit-wcpg-catalog__thumb-image',
				'alt'   => '',
			)
		);

		if ( '' === $thumbnail ) {
			// The name is already in the cell, so the initials are decoration only.
			$media = '<span class="sit-wcpg-catalog__thumb sit-wcpg-catalog__thumb--initials" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
		} else {
			$media = '<span class="sit-wcpg-catalog__thumb">' . wp_kses_post( $thumbnail ) . '</span>';
		}

		return '<div class="sit-wcpg-catalog__product">' . $media
			. '<div class="sit-wcpg-catalog__product-text">'
			. '<strong class="sit-wcpg-catalog__product-name">' . $title . '</strong>'
			. '<span class="sit-wcpg-catalog__product-meta">' . esc_html( $this->product_line( $id ) ) . '</span>'
			. '</div></div>';
	}

	/**
	 * The labels of the required checks that fail, one required pill each.
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

		$pills = array();

		foreach ( $flag['required_failures'] as $rule_id ) {
			$label   = $this->labels[ $rule_id ] ?? __( 'A check that is no longer active', 'sapphireit-publish-guard' );
			$pills[] = '<li class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required">' . esc_html( $label ) . '</li>';
		}

		return '<ul class="sit-wcpg-catalog__checks">' . implode( '', $pills ) . '</ul>';
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
	 * "Edit product", for whoever may edit it.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $item The product.
	 * @return string
	 */
	public function column_action( $item ): string {
		$id = (int) $item->ID;

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return '';
		}

		return '<a class="sit-wcpg-catalog__edit" href="' . esc_url( (string) get_edit_post_link( $id, 'raw' ) ) . '">'
			. esc_html__( 'Edit product', 'sapphireit-publish-guard' )
			. '<span class="screen-reader-text">: ' . esc_html( get_the_title( $item ) ) . '</span></a>';
	}

	/**
	 * What an empty page means. The screen prints its own empty state when nothing fails
	 * at all; this covers a page number past the end.
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

	/**
	 * The requested order, from an allow-list; newest change first by default.
	 *
	 * @since 1.2.0
	 *
	 * @return array{string, string}
	 */
	private function ordering(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only sort, checked against an allow-list.
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( self::ORDERBY[ $orderby ] ) ) {
			return array( 'modified', 'DESC' );
		}

		return array( self::ORDERBY[ $orderby ], 'DESC' === $order ? 'DESC' : 'ASC' );
	}

	/**
	 * The line under the name: variations for a variable product, else the SKU, else the type.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Product id.
	 * @return string Plain text.
	 */
	private function product_line( int $id ): string {
		$product = wc_get_product( $id );

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) ) {
			$count = $this->variation_counts[ $id ] ?? 0;

			return sprintf(
				/* translators: %s: number of variations. */
				_n( 'Variable · %s variation', 'Variable · %s variations', $count, 'sapphireit-publish-guard' ),
				number_format_i18n( $count )
			);
		}

		$sku = (string) $product->get_sku( 'edit' );

		if ( '' !== $sku ) {
			/* translators: %s: the product's SKU. */
			return sprintf( __( 'SKU %s', 'sapphireit-publish-guard' ), $sku );
		}

		$types = array(
			'simple'   => __( 'Simple', 'sapphireit-publish-guard' ),
			'grouped'  => __( 'Grouped', 'sapphireit-publish-guard' ),
			'external' => __( 'External', 'sapphireit-publish-guard' ),
		);
		$type  = (string) $product->get_type();

		if ( isset( $types[ $type ] ) ) {
			return $types[ $type ];
		}

		$names = wc_get_product_types();

		return isset( $names[ $type ] ) ? (string) $names[ $type ] : '';
	}

	/**
	 * How many variations each product has, in one query for the page.
	 *
	 * Counts the statuses WooCommerce's own `get_children()` reads (published and private).
	 *
	 * @since 1.2.0
	 *
	 * @param int[] $ids Product ids on the page.
	 * @return array<int, int>
	 */
	private function count_variations( array $ids ): array {
		$counts = array();
		$rows   = get_posts(
			array(
				'post_type'       => 'product_variation',
				'post_status'     => array( 'publish', 'private' ),
				'post_parent__in' => $ids,
				'fields'          => 'id=>parent',
				'posts_per_page'  => -1,
			)
		);

		foreach ( $rows as $parent ) {
			$parent            = (int) $parent;
			$counts[ $parent ] = ( $counts[ $parent ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Up to two initials from a product name, for the placeholder tile.
	 *
	 * Words that start with neither a letter nor a digit (a dash, an emoji) are skipped.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name The product name.
	 * @return string
	 */
	public static function initials( string $name ): string {
		$words    = preg_split( '/\s+/u', html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		$initials = '';

		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if ( 1 !== preg_match( '/^[\p{L}\p{N}]/u', $word ) ) {
				continue;
			}

			$initials .= mb_strtoupper( mb_substr( $word, 0, 1 ) );

			if ( mb_strlen( $initials ) >= 2 ) {
				break;
			}
		}

		return $initials;
	}
}
