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
use ProductPublishGuard\Support\Preflight;
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
	 * The shape of a group key an add-on may add.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const GROUP_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';

	/**
	 * How many groups add-ons may add in all.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_ADDED_GROUPS = 10;

	/**
	 * Per-status icon name (an `Icons` name) for the no-JS list.
	 *
	 * The React panel draws the same names (`STATUS_ICONS` in `StatusIcon.js`), so the box
	 * does not change shape when it takes over. Decorative only: every row also carries a
	 * visually hidden text label, because status must never be conveyed by a symbol or a
	 * colour alone.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Icon names instead of text glyphs (UI phase U09).
	 * @var array<string, string>
	 */
	private const STATUS_ICONS = array(
		Status::PASS    => 'check',
		Status::WARNING => 'alert',
		Status::FAIL    => 'cross',
		Status::SKIPPED => 'ban',
	);

	/**
	 * Radius of the score ring, in its 64 × 64 viewBox (`RING_RADIUS` in
	 * `ChecklistSummary.js`).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const RING_RADIUS = 27;

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

		// `sit-wcpg-ui` scopes the admin UI kit's tokens and font to the panel (UI decision U2).
		echo '<div id="' . esc_attr( self::MOUNT_ID ) . '" class="sit-wcpg-ui sit-wcpg-checklist">';

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
	 * The five core groups always come first, with their own labels. An add-on can append
	 * groups through `sit_wcpg_rule_groups`, and can do nothing else to the map.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Extended by `sit_wcpg_rule_groups`.
	 *
	 * @return array<string, string>
	 */
	public static function group_labels(): array {
		$core = array(
			'content'      => __( 'Content', 'sapphireit-publish-guard' ),
			'media'        => __( 'Media', 'sapphireit-publish-guard' ),
			'pricing'      => __( 'Pricing', 'sapphireit-publish-guard' ),
			'organization' => __( 'Organization', 'sapphireit-publish-guard' ),
			'inventory'    => __( 'Inventory', 'sapphireit-publish-guard' ),
		);

		/**
		 * Filters the checklist's group headings.
		 *
		 * Add a group for rules of your own: a key of lowercase letters, digits and
		 * underscores, starting with a letter, at most 30 characters, mapped to a
		 * translated label. Added groups follow the core ones in the order given, ten at
		 * most. The core groups keep their labels and positions whatever is returned.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, string> $labels Group labels by group key, in display order.
		 */
		$filtered = apply_filters( 'sit_wcpg_rule_groups', $core );

		if ( ! is_array( $filtered ) ) {
			return $core;
		}

		$labels = $core;
		$added  = 0;

		foreach ( $filtered as $key => $label ) {
			if ( self::MAX_ADDED_GROUPS === $added ) {
				break;
			}

			if ( ! is_string( $key ) || isset( $core[ $key ] ) || 1 !== preg_match( self::GROUP_KEY_PATTERN, $key ) ) {
				continue;
			}

			if ( ! is_string( $label ) || '' === $label ) {
				continue;
			}

			$labels[ $key ] = $label;

			++$added;
		}

		return $labels;
	}

	/**
	 * Render the server-side checklist, in the panel's order (UI phase U09).
	 *
	 * The summary and the pre-flight statement; the failing view (each group's failing
	 * and warning rows, failures first); the passed rows in one closed `<details>`; the
	 * rows that do not apply in another. The React panel renders the same order.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Failing first; passed and not applicable collapsed.
	 *
	 * @param Validation_Result $result The result to render.
	 * @return void
	 */
	private function render_fallback( Validation_Result $result ): void {
		echo '<div class="sit-wcpg-checklist__fallback">';

		$this->render_summary( $result );

		$issues  = array();
		$passed  = array();
		$skipped = array();

		foreach ( $result->get_results() as $row ) {
			$status = $row->get_status();

			if ( Status::SKIPPED === $status ) {
				$skipped[] = $row;
			} elseif ( Status::PASS === $status ) {
				$passed[ $row->get_group() ][] = $row;
			} else {
				$issues[ $row->get_group() ][] = $row;
			}
		}

		echo '<div class="sit-wcpg-checklist__body">';

		if ( array() === $issues && array() === $passed && array() === $skipped ) {
			echo '<p class="sit-wcpg-checklist__empty">' . esc_html__( 'No checks are enabled.', 'sapphireit-publish-guard' ) . '</p>';
		}

		if ( array() !== $issues ) {
			echo '<div class="sit-wcpg-checklist__failing">';

			foreach ( $this->order_groups( $issues ) as $group => $rows ) {
				$this->render_group( (string) $group, self::failures_first( $rows ), true );
			}

			echo '</div>';
		}

		$this->render_passed( $passed, array() !== $issues );
		$this->render_skipped( $skipped );

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the score ring, the readiness state line and the counts, then the pre-flight
	 * statement.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The score ring and the "3 required · 2 warnings" counts line.
	 *
	 * @param Validation_Result $result The result to summarize.
	 * @return void
	 */
	private function render_summary( Validation_Result $result ): void {
		$counts     = $result->get_counts();
		$is_ready   = $result->is_ready();
		$state      = $is_ready ? Status::PASS : Status::FAIL;
		$failed     = (int) $counts['failed'];
		$warnings   = (int) $counts['warnings'];
		$passed     = (int) $counts['passed'];
		$applicable = (int) $counts['evaluated'];

		if ( $failed > 0 ) {
			$tone = Status::FAIL;
		} elseif ( $warnings > 0 ) {
			$tone = Status::WARNING;
		} else {
			$tone = Status::PASS;
		}

		$headline = $is_ready
			? __( 'Ready to publish', 'sapphireit-publish-guard' )
			: __( 'Not ready to publish', 'sapphireit-publish-guard' );

		$circumference = 2 * M_PI * self::RING_RADIUS;
		$dash          = $applicable > 0 ? min( 1, $passed / $applicable ) * $circumference : 0;

		echo '<div class="sit-wcpg-checklist__summary sit-wcpg-checklist__summary--' . esc_attr( $state ) . '" aria-live="polite">';
		echo '<span class="sit-wcpg-checklist__ring sit-wcpg-checklist__ring--' . esc_attr( $tone ) . '">';
		echo '<svg width="64" height="64" viewBox="0 0 64 64" role="img" aria-label="' . esc_attr( $result->get_summary_label() ) . '" focusable="false">';
		echo '<circle class="sit-wcpg-checklist__ring-track" cx="32" cy="32" r="' . (int) self::RING_RADIUS . '"></circle>';

		if ( $passed > 0 ) {
			echo '<circle class="sit-wcpg-checklist__ring-bar" cx="32" cy="32" r="' . (int) self::RING_RADIUS . '" stroke-dasharray="'
				. esc_attr( number_format( $dash, 2, '.', '' ) . ' ' . number_format( $circumference, 2, '.', '' ) )
				. '" transform="rotate(-90 32 32)"></circle>';
		}

		echo '</svg>';
		echo '<span class="sit-wcpg-checklist__ring-value" aria-hidden="true">' . esc_html( $passed . '/' . $applicable ) . '</span>';
		echo '</span>';
		echo '<span class="sit-wcpg-checklist__headline">';
		echo '<strong class="sit-wcpg-checklist__state">' . esc_html( $headline ) . '</strong>';
		echo '<span class="sit-wcpg-checklist__counts">' . esc_html( self::counts_line( $failed, $warnings, $applicable ) ) . '</span>';
		echo '</span>';
		echo '</div>';

		$preflight = Preflight::for_result(
			$result,
			$this->settings->blocks_publishing(),
			Plugin::instance()->publish_guard()->can_override( get_current_user_id() ),
			(string) get_post_status( $result->get_product_id() )
		);

		echo '<p class="sit-wcpg-preflight sit-wcpg-preflight--' . esc_attr( $preflight['kind'] ) . '">' . esc_html( Preflight::message( $preflight ) ) . '</p>';
	}

	/**
	 * "3 required · 2 warnings", or "All checks passed" (`countsLine()` in
	 * `ChecklistSummary.js`).
	 *
	 * @since 1.2.0
	 *
	 * @param int $failed     Required checks that fail.
	 * @param int $warnings   Checks with a warning.
	 * @param int $applicable Checks that apply.
	 * @return string
	 */
	private static function counts_line( int $failed, int $warnings, int $applicable ): string {
		$parts = array();

		if ( $failed > 0 ) {
			/* translators: %d: number of required checks that fail. */
			$parts[] = sprintf( _n( '%d required', '%d required', $failed, 'sapphireit-publish-guard' ), $failed );
		}

		if ( $warnings > 0 ) {
			/* translators: %d: number of checks with a warning. */
			$parts[] = sprintf( _n( '%d warning', '%d warnings', $warnings, 'sapphireit-publish-guard' ), $warnings );
		}

		if ( array() === $parts ) {
			return $applicable > 0
				? __( 'All checks passed', 'sapphireit-publish-guard' )
				: __( 'No checks apply', 'sapphireit-publish-guard' );
		}

		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		/* translators: 1: e.g. "3 required", 2: e.g. "2 warnings". */
		return sprintf( __( '%1$s · %2$s', 'sapphireit-publish-guard' ), $parts[0], $parts[1] );
	}

	/**
	 * Render one group of rows.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The `$show_count` heading count ("1 failing").
	 *
	 * @param string $group      Group identifier.
	 * @param array  $rows       Rule_Result objects in that group.
	 * @param bool   $show_count Whether the heading shows how many rows fail.
	 * @return void
	 */
	private function render_group( string $group, array $rows, bool $show_count = false ): void {
		$labels = self::group_labels();

		echo '<div class="sit-wcpg-checklist__section">';
		echo '<h4 class="sit-wcpg-checklist__group"><span class="sit-wcpg-checklist__group-label">' . esc_html( $labels[ $group ] ?? $group ) . '</span>';

		if ( $show_count ) {
			self::render_group_count( $rows );
		}

		echo '</h4>';
		echo '<ul class="sit-wcpg-checklist__items">';

		foreach ( $rows as $row ) {
			$this->render_item( $row );
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * The "1 failing" or "2 warnings" count beside a failing group's heading
	 * (`groupCount()` in `ChecklistGroup.js`).
	 *
	 * @since 1.2.0
	 *
	 * @param array $rows Rule_Result objects in the group.
	 * @return void
	 */
	private static function render_group_count( array $rows ): void {
		$failed   = 0;
		$warnings = 0;

		foreach ( $rows as $row ) {
			if ( Status::FAIL === $row->get_status() ) {
				++$failed;
			} elseif ( Status::WARNING === $row->get_status() ) {
				++$warnings;
			}
		}

		if ( $failed > 0 ) {
			$tone = Status::FAIL;
			/* translators: %d: number of required checks failing in this group. */
			$text = sprintf( _n( '%d failing', '%d failing', $failed, 'sapphireit-publish-guard' ), $failed );
		} elseif ( $warnings > 0 ) {
			$tone = Status::WARNING;
			/* translators: %d: number of warnings in this group. */
			$text = sprintf( _n( '%d warning', '%d warnings', $warnings, 'sapphireit-publish-guard' ), $warnings );
		} else {
			return;
		}

		echo '<span class="sit-wcpg-checklist__group-count sit-wcpg-checklist__group-count--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Render the passed rows, by group, in one closed `<details>`.
	 *
	 * @since 1.2.0
	 *
	 * @param array $grouped    Passed Rule_Result objects keyed by group.
	 * @param bool  $has_issues Whether the failing view is shown above.
	 * @return void
	 */
	private function render_passed( array $grouped, bool $has_issues ): void {
		if ( array() === $grouped ) {
			return;
		}

		$count = (int) array_sum( array_map( 'count', $grouped ) );

		if ( $has_issues ) {
			/* translators: %d: number of checks that passed, listed below the failing ones. */
			$label = sprintf( _n( '%d more passed', '%d more passed', $count, 'sapphireit-publish-guard' ), $count );
		} else {
			/* translators: %d: number of checks that passed. */
			$label = sprintf( _n( '%d passed', '%d passed', $count, 'sapphireit-publish-guard' ), $count );
		}

		echo '<details class="sit-wcpg-checklist__passed">';
		echo '<summary class="sit-wcpg-checklist__disclosure sit-wcpg-checklist__disclosure--passed">';
		self::render_chevron();
		echo esc_html( $label ) . '</summary>';

		foreach ( $this->order_groups( $grouped ) as $group => $rows ) {
			$this->render_group( (string) $group, $rows );
		}

		echo '</details>';
	}

	/**
	 * Render the rules that did not apply to this product, last, in one closed `<details>`.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Collapsed, as in the React panel.
	 *
	 * @param array $rows Skipped Rule_Result objects.
	 * @return void
	 */
	private function render_skipped( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		echo '<details class="sit-wcpg-checklist__skipped">';
		echo '<summary class="sit-wcpg-checklist__disclosure sit-wcpg-checklist__disclosure--skipped">';
		self::render_chevron();
		echo esc_html(
			sprintf(
				/* translators: %d: number of checks that do not apply to this product. */
				_n( 'Not applicable (%d)', 'Not applicable (%d)', count( $rows ), 'sapphireit-publish-guard' ),
				count( $rows )
			)
		) . '</summary>';

		echo '<ul class="sit-wcpg-checklist__items sit-wcpg-checklist__items--skipped">';

		foreach ( $rows as $row ) {
			$this->render_item( $row );
		}

		echo '</ul>';
		echo '</details>';
	}

	/**
	 * The disclosure chevron: one icon per state; the stylesheet shows the right one.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function render_chevron(): void {
		echo '<span class="sit-wcpg-checklist__chevron sit-wcpg-checklist__chevron--closed" aria-hidden="true">' . wp_kses( Icons::svg( 'chevron-right', 14 ), Icons::allowed_html() ) . '</span>';
		echo '<span class="sit-wcpg-checklist__chevron sit-wcpg-checklist__chevron--open" aria-hidden="true">' . wp_kses( Icons::svg( 'chevron-down', 14 ), Icons::allowed_html() ) . '</span>';
	}

	/**
	 * Render one checklist row: status dot, label and, when it did not pass, the message.
	 *
	 * The message is printed only when the row did not pass: a wall of green explanations
	 * is noise, and the merchant is looking for what is missing. The fix link needs
	 * JavaScript to focus a field, so only the React panel shows it.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The status dot is a kit icon.
	 *
	 * @param Rule_Result $row The row to render.
	 * @return void
	 */
	private function render_item( Rule_Result $row ): void {
		$status = $row->get_status();
		$known  = isset( self::STATUS_ICONS[ $status ] ) ? $status : Status::SKIPPED;

		echo '<li class="sit-wcpg-checklist__item sit-wcpg-checklist__item--' . esc_attr( $status ) . '">';
		echo '<span class="sit-wcpg-checklist__icon sit-wcpg-checklist__icon--' . esc_attr( $known ) . '" aria-hidden="true">'
			. wp_kses( Icons::svg( self::STATUS_ICONS[ $known ], 12 ), Icons::allowed_html() ) . '</span>';
		echo '<span class="screen-reader-text">' . esc_html( self::status_label( $status ) ) . '</span>';
		echo '<span class="sit-wcpg-checklist__content">';
		echo '<span class="sit-wcpg-checklist__label">' . esc_html( $row->get_label() ) . '</span>';

		$message = $row->get_message();

		if ( Status::PASS !== $status && '' !== $message ) {
			echo '<span class="sit-wcpg-checklist__message">' . esc_html( $message ) . '</span>';
		}

		echo '</span>';
		echo '</li>';
	}

	/**
	 * A failing group's rows with the required failures first, otherwise in their order.
	 *
	 * @since 1.2.0
	 *
	 * @param array $rows Rule_Result objects.
	 * @return array
	 */
	private static function failures_first( array $rows ): array {
		$failures = array();
		$others   = array();

		foreach ( $rows as $row ) {
			if ( Status::FAIL === $row->get_status() ) {
				$failures[] = $row;
			} else {
				$others[] = $row;
			}
		}

		return array_merge( $failures, $others );
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

		// The labelled groups first, in their order; a group nobody labelled goes after them.
		foreach ( array_keys( self::group_labels() ) as $group ) {
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
