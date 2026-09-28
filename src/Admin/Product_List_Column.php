<?php
/**
 * The Readiness column on the products list.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * At-a-glance readiness on `edit.php?post_type=product` (coding-plan.md section 7.2).
 *
 * Three responsibilities, one per hook:
 *
 * 1. `manage_edit-product_columns` — insert the column before Date.
 * 2. `the_posts` — prime every cache a row validation reads, once, for the whole page
 *    (section 11.2), so rendering a cell performs no query of its own.
 * 3. `manage_product_posts_custom_column` — render the cell from
 *    `Checklist_Service::get_summary_for_post_id()`, the only read path section 14.4 allows.
 *
 * Drafts and published products are rendered identically: the cell describes content
 * completeness, not publication state. There is no sorting and no filtering (section 13).
 *
 * @since 1.0.0
 */
final class Product_List_Column {

	/**
	 * Column key in the list table.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const COLUMN = 'sit_wcpg_readiness';

	/**
	 * Column the Readiness column is inserted in front of.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const BEFORE_COLUMN = 'date';

	/**
	 * Capability needed to see the column at all.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CAPABILITY = 'edit_products';

	/**
	 * Post statuses that never get a readiness state.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const UNRATED_STATUSES = array( 'auto-draft', 'trash' );

	/**
	 * Post meta holding the comma-separated gallery attachment ids.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const GALLERY_META = '_product_image_gallery';

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The checklist source, resolved on first use.
	 *
	 * @since 1.0.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist;

	/**
	 * Construct the column.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings               $settings  The merchant's configuration.
	 * @param Checklist_Service|null $checklist Result source. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Checklist_Service $checklist = null ) {
		$this->settings  = $settings;
		$this->checklist = $checklist;
	}

	/**
	 * Hook the column into the products list.
	 *
	 * The column hooks are the product-specific variants, so they never fire for another
	 * post type. `the_posts` fires for every query; its callback bails on the screen check
	 * before anything else.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_edit-' . Screen::POST_TYPE . '_columns', array( $this, 'add_column' ) );
		add_filter( 'the_posts', array( $this, 'prime_caches' ), 10, 2 );
		add_action( 'manage_' . Screen::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Insert the Readiness column before Date, or last when there is no Date column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Column keys mapped to their headings.
	 * @return array
	 */
	public function add_column( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		if ( ! $this->is_visible() ) {
			return $columns;
		}

		$heading = __( 'Readiness', 'sapphireit-publish-guard' );
		$result  = array();

		foreach ( $columns as $key => $label ) {
			if ( self::BEFORE_COLUMN === $key ) {
				$result[ self::COLUMN ] = $heading;
			}

			$result[ $key ] = $label;
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = $heading;
		}

		return $result;
	}

	/**
	 * Prime every cache a row validation reads, for the whole page at once (section 11.2).
	 *
	 * Runs for the main query of the products list screen only, with the full row set in
	 * hand and before any cell renders. Core has usually primed the post, meta and term
	 * caches already, in which case the first two calls find everything cached and cost
	 * nothing; they stay because a filtered main query may have switched that off. The
	 * attachments — featured images and, when a rule counts them, gallery images — are
	 * what core never primes, and are fetched here in one query pair.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post[]     $posts The rows about to be listed.
	 * @param WP_Query|null $query The query that produced them.
	 * @return WP_Post[] The rows, unchanged.
	 */
	public function prime_caches( $posts, $query = null ) {
		if ( ! is_array( $posts ) || array() === $posts ) {
			return $posts;
		}

		if ( ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return $posts;
		}

		// The screen first: it is cheap, and reading a setting builds the rule registry.
		if ( ! Screen::is_product_list_screen() || ! $this->is_visible() ) {
			return $posts;
		}

		$products = array_values(
			array_filter(
				$posts,
				static function ( $post ) {
					return $post instanceof WP_Post && Screen::POST_TYPE === $post->post_type;
				}
			)
		);

		if ( array() === $products ) {
			return $posts;
		}

		$ids = array_map( 'intval', wp_list_pluck( $products, 'ID' ) );

		update_post_caches( $products, Screen::POST_TYPE, true, true );
		update_object_term_cache( $ids, Screen::POST_TYPE );

		$attachment_ids = $this->attachment_ids( $ids );

		if ( array() !== $attachment_ids ) {
			_prime_post_caches( $attachment_ids, false, true );
		}

		return $posts;
	}

	/**
	 * Render one Readiness cell.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Product post identifier.
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		// Checked again here, not only at registration (section 9.1): another plugin can
		// fire this action for a column list it built itself.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$post_id = absint( $post_id );

		if ( in_array( get_post_status( $post_id ), self::UNRATED_STATUSES, true ) ) {
			$this->render_empty();

			return;
		}

		$summary = $this->checklist()->get_summary_for_post_id( $post_id );

		if ( null === $summary || Status::SKIPPED === $summary['status'] ) {
			$this->render_empty();

			return;
		}

		$counts = $summary['counts'];
		$status = $summary['status'];

		switch ( $status ) {
			case Status::FAIL:
				$failed = (int) $counts['failed'];
				$glyph  = '✗';
				$label  = sprintf(
					/* translators: %d: number of failed required checks. */
					_n( '%d error', '%d errors', $failed, 'sapphireit-publish-guard' ),
					$failed
				);
				break;

			case Status::WARNING:
				$warnings = (int) $counts['warnings'];
				$glyph    = '!';
				$label    = sprintf(
					/* translators: %d: number of checks with a warning. */
					_n( '%d warning', '%d warnings', $warnings, 'sapphireit-publish-guard' ),
					$warnings
				);
				break;

			default:
				$glyph = '✓';
				$label = __( 'Ready', 'sapphireit-publish-guard' );
				break;
		}

		$link   = current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id, 'raw' ) : '';
		$linked = is_string( $link ) && '' !== $link;

		// Escaped where it is printed, never pre-built into a string: section 9.4 allows
		// no EscapeOutput exclusion anywhere.
		echo '<span class="sit-wcpg-readiness sit-wcpg-readiness--' . esc_attr( $status ) . '">';

		if ( $linked ) {
			echo '<a href="' . esc_url( $link . '#' . Editor_Meta_Box::ID ) . '">';
		}

		echo '<span class="sit-wcpg-readiness__icon" aria-hidden="true">' . esc_html( $glyph ) . '</span> ';
		echo '<span class="sit-wcpg-readiness__label">' . esc_html( $label ) . '</span>';

		if ( $linked ) {
			echo '</a>';
		}

		echo '</span>';
	}

	/**
	 * Whether the column is shown for this user at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_visible(): bool {
		return current_user_can( self::CAPABILITY )
			&& $this->settings->is_enabled()
			&& $this->settings->shows_list_column();
	}

	/**
	 * Attachment ids the page's validations will read.
	 *
	 * Featured images always (the `featured_image` rule and the image count both read
	 * them); gallery images only when the `image_count` rule is enabled, because nothing
	 * else looks at them. Both lists come from meta that is already cached.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $ids Product post identifiers.
	 * @return int[]
	 */
	private function attachment_ids( array $ids ): array {
		$with_gallery   = $this->settings->rule_is_enabled( 'image_count' );
		$attachment_ids = array();

		foreach ( $ids as $id ) {
			$attachment_ids[] = (int) get_post_meta( $id, '_thumbnail_id', true );

			if ( $with_gallery ) {
				$gallery = (string) get_post_meta( $id, self::GALLERY_META, true );

				if ( '' !== $gallery ) {
					$attachment_ids = array_merge( $attachment_ids, array_map( 'intval', explode( ',', $gallery ) ) );
				}
			}
		}

		return array_values( array_unique( array_filter( $attachment_ids ) ) );
	}

	/**
	 * Render the cell for a product that has no readiness state.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_empty(): void {
		echo '<span class="sit-wcpg-readiness sit-wcpg-readiness--none" aria-hidden="true">—</span>'
			. '<span class="screen-reader-text">' . esc_html__( 'No readiness state', 'sapphireit-publish-guard' ) . '</span>';
	}

	/**
	 * The checklist service, resolved on first use.
	 *
	 * @since 1.0.0
	 *
	 * @return Checklist_Service
	 */
	private function checklist(): Checklist_Service {
		if ( null === $this->checklist ) {
			$this->checklist = Plugin::instance()->checklist();
		}

		return $this->checklist;
	}
}
