<?php
/**
 * The readiness panel in the product editor.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `side` meta box and renders the first paint of the checklist.
 *
 * The panel is complete before any network activity: the server renders the current
 * result as a plain, fully escaped list inside the mount node, and the React app
 * (phase 7) replaces those children when it mounts. With JavaScript disabled the list
 * that is already on the page is the feature — not a placeholder, not a spinner.
 *
 * Nothing rendered here carries product-supplied text. Rule labels and messages are
 * translated literals with integer-only placeholders (coding-plan.md section 9.5), so a
 * product titled `<script>` cannot reach this markup at all. The output is escaped
 * anyway, because a structural mitigation and an escaping discipline are cheap together
 * and a single third-party rule could weaken the first one.
 *
 * @since 1.0.0
 */
final class Editor_Meta_Box {

	/**
	 * Meta box id, which is also the anchor the products-list column links to.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ID = 'sit_wcpg_product_checklist';

	/**
	 * The id of the node the React app mounts into.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MOUNT_ID = 'sit-wcpg-checklist-root';

	/**
	 * Group display order. Groups a third-party rule invents are rendered after these.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const GROUP_ORDER = array( 'content', 'media', 'pricing', 'organization', 'inventory' );

	/**
	 * Per-status glyph for the no-JS list.
	 *
	 * Decorative only: every row also carries a visually hidden text label, because
	 * status must never be conveyed by a symbol or a colour alone.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private const ICONS = array(
		Status::PASS    => "\u{2713}",
		Status::WARNING => '!',
		Status::FAIL    => "\u{2717}",
		Status::SKIPPED => "\u{2013}",
	);

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
	 * Construct the meta box.
	 *
	 * The checklist service is resolved lazily so that merely registering the meta box on
	 * an admin request never builds the rule registry.
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
	 * Hook the meta box into the product editor.
	 *
	 * `add_meta_boxes_product` rather than `add_meta_boxes`, so no per-request post-type
	 * branch is needed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Screen::POST_TYPE, array( $this, 'add_meta_box' ) );
	}

	/**
	 * Register the box itself.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post The product being edited.
	 * @return void
	 */
	public function add_meta_box( WP_Post $post ): void {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			self::ID,
			__( 'Product Readiness', 'sapphireit-publish-guard' ),
			array( $this, 'render' ),
			Screen::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the mount node and the no-JS fallback inside it.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post The product being edited.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$result = $this->checklist()->validate_post( (int) $post->ID );

		echo '<div id="' . esc_attr( self::MOUNT_ID ) . '" class="sit-wcpg-checklist">';

		if ( $result instanceof Validation_Result ) {
			$this->render_fallback( $result );
		} else {
			echo '<p class="sit-wcpg-checklist__empty">'
				. esc_html__( 'The checklist will appear once this product has been saved.', 'sapphireit-publish-guard' )
				. '</p>';
		}

		echo '</div>';
	}

	/**
	 * Translated group headings, in display order.
	 *
	 * Shared with `Assets`, which ships the same map to the React panel so both renderings
	 * of the checklist label and order their groups identically.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function group_labels(): array {
		return array(
			'content'      => __( 'Content', 'sapphireit-publish-guard' ),
			'media'        => __( 'Media', 'sapphireit-publish-guard' ),
			'pricing'      => __( 'Pricing', 'sapphireit-publish-guard' ),
			'organization' => __( 'Organization', 'sapphireit-publish-guard' ),
			'inventory'    => __( 'Inventory', 'sapphireit-publish-guard' ),
		);
	}

	/**
	 * Render the server-side checklist.
	 *
	 * @since 1.0.0
	 *
	 * @param Validation_Result $result The result to render.
	 * @return void
	 */
	private function render_fallback( Validation_Result $result ): void {
		echo '<div class="sit-wcpg-checklist__fallback">';

		$this->render_summary( $result );

		$grouped = array();
		$skipped = array();

		foreach ( $result->get_results() as $row ) {
			if ( Status::SKIPPED === $row->get_status() ) {
				$skipped[] = $row;

				continue;
			}

			$grouped[ $row->get_group() ][] = $row;
		}

		foreach ( $this->order_groups( $grouped ) as $group => $rows ) {
			$this->render_group( (string) $group, $rows );
		}

		$this->render_skipped( $skipped );

		echo '</div>';
	}

	/**
	 * Render the readiness summary.
	 *
	 * @since 1.0.0
	 *
	 * @param Validation_Result $result The result to summarize.
	 * @return void
	 */
	private function render_summary( Validation_Result $result ): void {
		$counts   = $result->get_counts();
		$is_ready = $result->is_ready();
		$state    = $is_ready ? Status::PASS : Status::FAIL;

		$headline = $is_ready
			? __( 'Ready to publish', 'sapphireit-publish-guard' )
			: __( 'Not ready to publish', 'sapphireit-publish-guard' );

		echo '<p class="sit-wcpg-checklist__summary sit-wcpg-checklist__summary--' . esc_attr( $state ) . '" aria-live="polite">';
		echo '<span class="sit-wcpg-checklist__icon" aria-hidden="true">' . esc_html( self::ICONS[ $state ] ) . '</span> ';
		echo '<strong class="sit-wcpg-checklist__state">' . esc_html( $headline ) . '</strong>';
		echo '<span class="sit-wcpg-checklist__counts">' . esc_html( $result->get_summary_label() ) . '</span>';

		$issues = (int) $counts['failed'] + (int) $counts['warnings'];

		if ( $issues > 0 ) {
			echo '<span class="sit-wcpg-checklist__issues">' . esc_html(
				sprintf(
					/* translators: %d: number of checks that did not pass. */
					_n( '%d issue', '%d issues', $issues, 'sapphireit-publish-guard' ),
					$issues
				)
			) . '</span>';
		}

		echo '</p>';
	}

	/**
	 * Render one group of rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Group identifier.
	 * @param array  $rows  Rule_Result objects in that group.
	 * @return void
	 */
	private function render_group( string $group, array $rows ): void {
		$labels = self::group_labels();

		echo '<h4 class="sit-wcpg-checklist__group">' . esc_html( $labels[ $group ] ?? $group ) . '</h4>';
		echo '<ul class="sit-wcpg-checklist__items">';

		foreach ( $rows as $row ) {
			$this->render_item( $row );
		}

		echo '</ul>';
	}

	/**
	 * Render the rules that did not apply to this product.
	 *
	 * @since 1.0.0
	 *
	 * @param array $rows Skipped Rule_Result objects.
	 * @return void
	 */
	private function render_skipped( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		echo '<h4 class="sit-wcpg-checklist__group sit-wcpg-checklist__group--skipped">' . esc_html(
			sprintf(
				/* translators: %d: number of checks that do not apply to this product. */
				_n( 'Not applicable (%d)', 'Not applicable (%d)', count( $rows ), 'sapphireit-publish-guard' ),
				count( $rows )
			)
		) . '</h4>';

		echo '<ul class="sit-wcpg-checklist__items sit-wcpg-checklist__items--skipped">';

		foreach ( $rows as $row ) {
			$this->render_item( $row );
		}

		echo '</ul>';
	}

	/**
	 * Render one checklist row.
	 *
	 * The message is printed only when the row did not pass: a wall of green explanations
	 * is noise, and the merchant is looking for what is missing.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Result $row The row to render.
	 * @return void
	 */
	private function render_item( Rule_Result $row ): void {
		$status = $row->get_status();
		$icon   = self::ICONS[ $status ] ?? self::ICONS[ Status::SKIPPED ];

		echo '<li class="sit-wcpg-checklist__item sit-wcpg-checklist__item--' . esc_attr( $status ) . '">';
		echo '<span class="sit-wcpg-checklist__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
		echo '<span class="screen-reader-text">' . esc_html( self::status_label( $status ) ) . '</span> ';
		echo '<span class="sit-wcpg-checklist__label">' . esc_html( $row->get_label() ) . '</span>';

		$message = $row->get_message();

		if ( Status::PASS !== $status && '' !== $message ) {
			echo '<span class="sit-wcpg-checklist__message">' . esc_html( $message ) . '</span>';
		}

		echo '</li>';
	}

	/**
	 * Groups in display order: the known ones first, then anything a rule invented.
	 *
	 * @since 1.0.0
	 *
	 * @param array $grouped Rows keyed by group identifier.
	 * @return array
	 */
	private function order_groups( array $grouped ): array {
		$ordered = array();

		foreach ( self::GROUP_ORDER as $group ) {
			if ( isset( $grouped[ $group ] ) ) {
				$ordered[ $group ] = $grouped[ $group ];
			}
		}

		foreach ( $grouped as $group => $rows ) {
			if ( ! isset( $ordered[ $group ] ) ) {
				$ordered[ $group ] = $rows;
			}
		}

		return $ordered;
	}

	/**
	 * The visually hidden text that accompanies each status glyph.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status One of the Status constants.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case Status::PASS:
				return __( 'Passed', 'sapphireit-publish-guard' );
			case Status::WARNING:
				return __( 'Warning', 'sapphireit-publish-guard' );
			case Status::FAIL:
				return __( 'Failed', 'sapphireit-publish-guard' );
			default:
				return __( 'Not applicable', 'sapphireit-publish-guard' );
		}
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
