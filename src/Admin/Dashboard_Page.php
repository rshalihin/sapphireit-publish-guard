<?php
/**
 * The Dashboard: WooCommerce → Publish Guard.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Managed_Rule_Interface;
use ProductPublishGuard\Engine\Rule_Interface;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Stats;
use ProductPublishGuard\Support\Readiness_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog health, the stat cards, the most common issues, the active checks, the Pro card
 * and the help cards (UI decisions U7, U12, U13, U14).
 *
 * Everything on the screen is read from the stored flags through `Readiness_Stats`, so
 * opening it validates nothing, and the page has no form: it changes nothing. Every
 * number links to the products list filtered to what it counts. Nothing is kept over
 * time, so there is no history and no trend.
 *
 * @since 1.2.0
 */
final class Dashboard_Page {

	/**
	 * Menu slug of the page.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const MENU_SLUG = 'sit-wcpg-dashboard';

	/**
	 * Capability needed to see the page (U7).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const CAPABILITY = 'edit_products';

	/**
	 * The menu the page sits under.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const PARENT = 'woocommerce';

	/**
	 * Priority of the `admin_menu` callback: after WooCommerce's own entries (70) and
	 * before Product Checklist (100), so the entry sits right above it (V-U6).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MENU_PRIORITY = 99;

	/**
	 * The plugin's WordPress.org support forum.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const SUPPORT_URL = 'https://wordpress.org/support/plugin/sapphireit-publish-guard/';

	/**
	 * Radius of the health ring, in SVG units of its 168 × 168 view box.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const RING_RADIUS = 72;

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry;

	/**
	 * The statistics, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Readiness_Stats|null
	 */
	private ?Readiness_Stats $stats;

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Readiness_Rebuild|null
	 */
	private ?Readiness_Rebuild $rebuild;

	/**
	 * Construct the page.
	 *
	 * Construction only stores what it is given; nothing is resolved until the page renders.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings               $settings The merchant's configuration.
	 * @param Rule_Registry|null     $registry The rule registry. Null resolves the plugin's own.
	 * @param Readiness_Stats|null   $stats    The statistics. Null resolves the plugin's own.
	 * @param Readiness_Rebuild|null $rebuild  The rebuild. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Rule_Registry $registry = null, ?Readiness_Stats $stats = null, ?Readiness_Rebuild $rebuild = null ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->stats    = $stats;
		$this->rebuild  = $rebuild;
	}

	/**
	 * Hook the page into the admin.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), self::MENU_PRIORITY );
		add_action( 'load-' . Screen::DASHBOARD_HOOK, array( $this, 'on_load' ), 10, 0 );
	}

	/**
	 * Add the submenu entry under WooCommerce.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Publish Guard', 'sapphireit-publish-guard' ),
			__( 'Publish Guard', 'sapphireit-publish-guard' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Start a rebuild when the page opens on stale flags, as the Live products screen does.
	 *
	 * Takes no input: it only queues the background recheck the flags already need.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function on_load(): void {
		$this->rebuild()->ensure_current();
	}

	/**
	 * Render the page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		// Repeated here: a direct request can reach the callback in some configurations.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sapphireit-publish-guard' ) );
		}

		$show_pro = Admin_Shell::show_upgrade_prompts() && current_user_can( Settings_Page::CAPABILITY );

		Admin_Shell::render( Admin_Shell::TAB_DASHBOARD );

		echo '<div class="wrap sit-wcpg-ui sit-wcpg-dashboard">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Publish Guard dashboard', 'sapphireit-publish-guard' ) . '</h1>';

		echo '<div class="sit-wcpg-dashboard__row sit-wcpg-dashboard__row--top">';

		if ( $this->settings->is_enabled() ) {
			$stats = $this->stats()->get();

			$this->render_health( $stats, $show_pro );
		} else {
			$stats = null;

			$this->render_turned_off( $show_pro );
		}

		if ( $show_pro ) {
			$this->render_pro_card();
		}

		echo '</div>';

		if ( null !== $stats ) {
			$this->render_stat_cards( $stats );

			echo '<div class="sit-wcpg-dashboard__row sit-wcpg-dashboard__row--split">';
			$this->render_issues( $stats );
			$this->render_active_checks();
			echo '</div>';
		}

		$this->render_help_cards();

		echo '</div>';
	}

	/**
	 * The catalog health card: the ring, the sentence, the actions and the recheck line.
	 *
	 * @since 1.2.0
	 *
	 * @param array $stats    The statistics.
	 * @param bool  $show_pro Whether the Pro card shares the row.
	 * @return void
	 */
	private function render_health( array $stats, bool $show_pro ): void {
		$checked = max( 0, $stats['total'] - $stats['unchecked'] );

		echo '<section class="' . esc_attr( self::health_class( $show_pro ) ) . '" aria-labelledby="sit-wcpg-dashboard-health">';

		if ( 0 === $checked ) {
			echo '<div class="sit-wcpg-ui-empty">';
			echo '<span class="sit-wcpg-ui-empty__icon">' . wp_kses( Icons::svg( 'shield', 24 ), Icons::allowed_html() ) . '</span>';
			echo '<h2 class="sit-wcpg-ui-empty__title" id="sit-wcpg-dashboard-health">' . esc_html__( 'Catalog health', 'sapphireit-publish-guard' ) . '</h2>';
			echo '<p class="sit-wcpg-ui-empty__text">' . esc_html__( 'Nothing checked yet — products are checked when they are saved or when a recheck runs.', 'sapphireit-publish-guard' ) . '</p>';
			$this->render_recheck_line();
			echo '</div></section>';

			return;
		}

		$passing = $stats['ready'] + $stats['warning'];
		// Rounded down, so a catalog with one failure never reads as 100 %.
		$percent = (int) floor( min( 1, $passing / $checked ) * 100 );

		$this->render_ring( $percent );

		echo '<div class="sit-wcpg-dashboard__health-text">';
		echo '<p class="sit-wcpg-dashboard__overline" id="sit-wcpg-dashboard-health">' . esc_html__( 'Catalog health', 'sapphireit-publish-guard' ) . '</p>';
		echo '<h2 class="sit-wcpg-dashboard__headline">' . esc_html(
			sprintf(
				/* translators: 1: products that pass every required check, 2: products checked. */
				_n( '%1$s of %2$s product passes every required check', '%1$s of %2$s products pass every required check', $checked, 'sapphireit-publish-guard' ),
				number_format_i18n( $passing ),
				number_format_i18n( $checked )
			)
		) . '</h2>';
		echo '<p class="sit-wcpg-dashboard__summary">' . esc_html( $this->summary( $stats ) ) . '</p>';

		echo '<div class="sit-wcpg-dashboard__actions">';
		echo '<a class="sit-wcpg-ui-button sit-wcpg-ui-button--primary" href="' . esc_url( self::list_url( Readiness_Store::FILTER_FAIL ) ) . '">' . esc_html__( 'Show not-ready products', 'sapphireit-publish-guard' ) . '</a>';
		echo '<a class="sit-wcpg-ui-button sit-wcpg-ui-button--secondary" href="' . esc_url( self::catalog_url() ) . '">' . esc_html__( 'Live products report', 'sapphireit-publish-guard' ) . '</a>';
		$this->render_recheck_line();
		echo '</div>';

		echo '</div></section>';
	}

	/**
	 * The ring: an SVG image labelled with the percentage, which the visible number repeats.
	 *
	 * @since 1.2.0
	 *
	 * @param int $percent Share of checked products that pass, 0–100.
	 * @return void
	 */
	private function render_ring( int $percent ): void {
		$circumference = 2 * M_PI * self::RING_RADIUS;
		$dash          = round( $circumference * $percent / 100, 2 );
		$value         = sprintf(
			/* translators: %s: a whole-number percentage. */
			__( '%s%%', 'sapphireit-publish-guard' ),
			number_format_i18n( $percent )
		);
		$label = sprintf(
			/* translators: %s: a whole-number percentage. */
			__( '%s%% of checked products pass every required check', 'sapphireit-publish-guard' ),
			number_format_i18n( $percent )
		);

		echo '<div class="sit-wcpg-dashboard__ring">';
		echo '<svg class="sit-wcpg-dashboard__ring-svg" width="168" height="168" viewBox="0 0 168 168" role="img" aria-label="' . esc_attr( $label ) . '">';
		echo '<circle class="sit-wcpg-dashboard__ring-track" cx="84" cy="84" r="' . esc_attr( (string) self::RING_RADIUS ) . '" fill="none" stroke-width="14"></circle>';

		if ( $percent > 0 ) {
			echo '<circle class="sit-wcpg-dashboard__ring-fill" cx="84" cy="84" r="' . esc_attr( (string) self::RING_RADIUS ) . '" fill="none" stroke-width="14" stroke-linecap="round" stroke-dasharray="' . esc_attr( $dash . ' ' . round( $circumference, 2 ) ) . '" transform="rotate(-90 84 84)"></circle>';
		}

		echo '</svg>';
		echo '<p class="sit-wcpg-dashboard__ring-label" aria-hidden="true">';
		echo '<span class="sit-wcpg-dashboard__ring-value">' . esc_html( $value ) . '</span>';
		echo '<span class="sit-wcpg-dashboard__ring-caption">' . esc_html__( 'publish-ready', 'sapphireit-publish-guard' ) . '</span>';
		echo '</p></div>';
	}

	/**
	 * The line under the headline: held drafts and live failures.
	 *
	 * @since 1.2.0
	 *
	 * @param array $stats The statistics.
	 * @return string
	 */
	private function summary( array $stats ): string {
		if ( 0 === $stats['fail'] ) {
			return __( 'Every checked product passes your required checks.', 'sapphireit-publish-guard' );
		}

		$held  = max( 0, $stats['fail'] - $stats['live_fail'] );
		$parts = array();

		if ( $held > 0 && $this->settings->blocks_publishing() ) {
			$parts[] = sprintf(
				/* translators: %s: number of products. */
				_n( '%s product not yet live is held back until it passes.', '%s products not yet live are held back until they pass.', $held, 'sapphireit-publish-guard' ),
				number_format_i18n( $held )
			);
		} elseif ( $held > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of products. */
				_n( '%s product not yet live fails a required check.', '%s products not yet live fail a required check.', $held, 'sapphireit-publish-guard' ),
				number_format_i18n( $held )
			);
		}

		if ( $stats['live_fail'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of products. */
				_n( '%s live product would be refused if it were published today.', '%s live products would be refused if they were published today.', $stats['live_fail'], 'sapphireit-publish-guard' ),
				number_format_i18n( $stats['live_fail'] )
			);
			$parts[] = __( 'Nothing live is ever taken offline.', 'sapphireit-publish-guard' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * "Rechecked automatically", or how far the recheck has got when the flags are stale.
	 *
	 * The numbers count a stale product under its old status (V-U4), so they are never
	 * presented as final while a recheck is due.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_recheck_line(): void {
		$status = $this->rebuild()->status();

		if ( $status['is_current'] ) {
			$message = __( 'Rechecked automatically when settings change', 'sapphireit-publish-guard' );
		} elseif ( ! $status['scheduler'] ) {
			$message = Readiness_Filter::no_scheduler_message();
		} elseif ( Readiness_Rebuild::STATE_BUILDING === $status['state'] ) {
			$message = sprintf(
				/* translators: 1: products rechecked so far, 2: products to recheck in total. */
				__( 'Rechecking products against your current settings: %1$s of %2$s done. The numbers update as it runs.', 'sapphireit-publish-guard' ),
				number_format_i18n( $status['done'] ),
				number_format_i18n( $status['total'] )
			);
		} else {
			$message = __( 'Rechecking products against your current settings. The numbers update when it finishes.', 'sapphireit-publish-guard' );
		}

		echo '<p class="sit-wcpg-dashboard__recheck">' . wp_kses( Icons::svg( 'refresh', 16 ), Icons::allowed_html() ) . esc_html( $message ) . '</p>';
	}

	/**
	 * The card shown instead of the numbers while the checklist is switched off.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $show_pro Whether the Pro card shares the row.
	 * @return void
	 */
	private function render_turned_off( bool $show_pro ): void {
		echo '<section class="' . esc_attr( self::health_class( $show_pro ) ) . '" aria-labelledby="sit-wcpg-dashboard-health">';
		echo '<div class="sit-wcpg-ui-empty">';
		echo '<span class="sit-wcpg-ui-empty__icon">' . wp_kses( Icons::svg( 'ban', 24 ), Icons::allowed_html() ) . '</span>';
		echo '<h2 class="sit-wcpg-ui-empty__title" id="sit-wcpg-dashboard-health">' . esc_html__( 'Checklist turned off', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<p class="sit-wcpg-ui-empty__text">' . esc_html__( 'The product checklist is turned off, so nothing is being checked.', 'sapphireit-publish-guard' ) . '</p>';

		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			echo '<a class="sit-wcpg-ui-button sit-wcpg-ui-button--primary" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Turn it on in Checklist settings', 'sapphireit-publish-guard' ) . '</a>';
		}

		echo '</div></section>';
	}

	/**
	 * The navy Pro card (U13). Shown only while the prompts are on, to a user who can manage
	 * the store.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_pro_card(): void {
		$features = array(
			__( 'Google Shopping checks', 'sapphireit-publish-guard' ),
			__( 'Meta catalog checks', 'sapphireit-publish-guard' ),
			__( 'ChatGPT and agent feed checks', 'sapphireit-publish-guard' ),
			__( 'GTIN / MPN integrity and store policy rules', 'sapphireit-publish-guard' ),
		);

		echo '<section class="sit-wcpg-dashboard__pro" aria-labelledby="sit-wcpg-dashboard-pro">';
		echo '<p class="sit-wcpg-dashboard__pro-eyebrow"><span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--pro">' . esc_html__( 'PRO', 'sapphireit-publish-guard' ) . '</span>';
		echo '<span>' . esc_html__( 'Channel Readiness', 'sapphireit-publish-guard' ) . '</span></p>';
		echo '<h2 class="sit-wcpg-dashboard__pro-title" id="sit-wcpg-dashboard-pro">' . esc_html__( 'Catch what Google, Meta and AI shopping agents would reject', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<ul class="sit-wcpg-dashboard__pro-list">';

		foreach ( $features as $feature ) {
			echo '<li>' . wp_kses( Icons::svg( 'check', 16 ), Icons::allowed_html() ) . esc_html( $feature ) . '</li>';
		}

		echo '</ul>';
		echo '<a class="sit-wcpg-ui-button sit-wcpg-ui-button--gold sit-wcpg-dashboard__pro-link" href="' . esc_url( Admin_Shell::PRO_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'See what Pro adds', 'sapphireit-publish-guard' );
		self::render_new_tab_hint();
		echo '</a></section>';
	}

	/**
	 * The stat cards. "Not checked yet" joins them only while something is unchecked.
	 *
	 * @since 1.2.0
	 *
	 * @param array $stats The statistics.
	 * @return void
	 */
	private function render_stat_cards( array $stats ): void {
		echo '<div class="sit-wcpg-dashboard__stats">';

		self::render_stat( 'success', __( 'Ready to publish', 'sapphireit-publish-guard' ), $stats['ready'], self::list_url( Readiness_Store::FILTER_READY ), __( 'Pass every check', 'sapphireit-publish-guard' ) );
		self::render_stat( 'required', __( 'Not ready', 'sapphireit-publish-guard' ), $stats['fail'], self::list_url( Readiness_Store::FILTER_FAIL ), __( 'Failing a required check', 'sapphireit-publish-guard' ) );
		self::render_stat( 'warning', __( 'Warnings', 'sapphireit-publish-guard' ), $stats['warning'], self::list_url( Readiness_Store::FILTER_WARNING ), __( 'Never blocked, worth a look', 'sapphireit-publish-guard' ) );
		self::render_stat( 'info', __( 'Live, would fail today', 'sapphireit-publish-guard' ), $stats['live_fail'], self::catalog_url(), __( 'Published, and failing a required check', 'sapphireit-publish-guard' ) );

		if ( $stats['unchecked'] > 0 ) {
			self::render_stat( 'neutral', __( 'Not checked yet', 'sapphireit-publish-guard' ), $stats['unchecked'], self::list_url( Readiness_Store::FILTER_UNCHECKED ), __( 'Checked when next saved or rechecked', 'sapphireit-publish-guard' ) );
		}

		echo '</div>';
	}

	/**
	 * One stat card. Its number is the link (U12).
	 *
	 * @since 1.2.0
	 *
	 * @param string $tone    Dot modifier: success, required, warning, info or neutral.
	 * @param string $label   Plain-text label.
	 * @param int    $value   The count.
	 * @param string $url     Where the number links.
	 * @param string $caption Plain-text caption.
	 * @return void
	 */
	private static function render_stat( string $tone, string $label, int $value, string $url, string $caption ): void {
		$dot   = 'sit-wcpg-ui-stat__dot' . ( 'neutral' === $tone ? '' : ' sit-wcpg-ui-stat__dot--' . $tone );
		$class = 'sit-wcpg-ui-stat__value' . ( 'required' === $tone && $value > 0 ? ' sit-wcpg-dashboard__value--required' : '' );

		echo '<div class="sit-wcpg-ui-stat">';
		echo '<p class="sit-wcpg-ui-stat__label"><span class="' . esc_attr( $dot ) . '" aria-hidden="true"></span>' . esc_html( $label ) . '</p>';
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $value ) );
		echo '<span class="screen-reader-text"> ' . esc_html(
			sprintf(
				/* translators: %s: the stat card's label, such as "Not ready". */
				__( '%s: show these products', 'sapphireit-publish-guard' ),
				$label
			)
		) . '</span></a>';
		echo '<p class="sit-wcpg-ui-stat__caption">' . esc_html( $caption ) . '</p>';
		echo '</div>';
	}

	/**
	 * The required checks that fail on the most products, each linking to its filtered list.
	 *
	 * @since 1.2.0
	 *
	 * @param array $stats The statistics.
	 * @return void
	 */
	private function render_issues( array $stats ): void {
		$rows = array();

		foreach ( $stats['top_failures'] as $rule_id => $count ) {
			$rule = $this->registry()->get( (string) $rule_id );

			if ( null !== $rule && $count > 0 ) {
				$rows[] = array( $rule, (int) $count );
			}
		}

		echo '<section class="sit-wcpg-ui-card sit-wcpg-dashboard__issues" aria-labelledby="sit-wcpg-dashboard-issues">';
		echo '<div class="sit-wcpg-ui-card__header"><h2 id="sit-wcpg-dashboard-issues">' . esc_html__( 'Most common issues', 'sapphireit-publish-guard' ) . '</h2>';

		if ( $stats['fail'] > 0 ) {
			echo '<a class="sit-wcpg-dashboard__more" href="' . esc_url( self::list_url( Readiness_Store::FILTER_FAIL ) ) . '">' . esc_html__( 'All not-ready products', 'sapphireit-publish-guard' ) . '<span aria-hidden="true"> →</span></a>';
		}

		echo '</div><div class="sit-wcpg-ui-card__body">';

		if ( array() === $rows ) {
			echo '<p class="sit-wcpg-dashboard__none">' . wp_kses( Icons::svg( 'check', 18 ), Icons::allowed_html() ) . esc_html__( 'No product fails a required check.', 'sapphireit-publish-guard' ) . '</p>';
			echo '</div></section>';

			return;
		}

		echo '<p class="sit-wcpg-dashboard__caption">' . esc_html__( 'Required checks only: warnings are not counted per check.', 'sapphireit-publish-guard' );

		if ( $stats['capped'] ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: number of products read. */
					__( 'Based on the first %s not-ready products.', 'sapphireit-publish-guard' ),
					number_format_i18n( Readiness_Stats::SCAN_LIMIT )
				)
			);
		}

		echo '</p><ul class="sit-wcpg-dashboard__issue-list">';

		$scanned = max( 1, min( $stats['fail'], Readiness_Stats::SCAN_LIMIT ) );
		$groups  = Editor_Meta_Box::group_labels();

		foreach ( $rows as $row ) {
			$this->render_issue( $row[0], $row[1], $scanned, $groups );
		}

		echo '</ul></div></section>';
	}

	/**
	 * One row of the most common issues.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Interface        $rule    The failing check.
	 * @param int                   $count   Products failing it.
	 * @param int                   $scanned Not-ready products the count is out of.
	 * @param array<string, string> $groups  Group key to heading.
	 * @return void
	 */
	private function render_issue( Rule_Interface $rule, int $count, int $scanned, array $groups ): void {
		$id      = 'sit-wcpg-dashboard-issue-' . sanitize_html_class( $rule->get_id() );
		$share   = (int) round( min( 1, $count / $scanned ) * 100 );
		$group   = $groups[ $rule->get_group() ] ?? __( 'Other', 'sapphireit-publish-guard' );
		$caption = sprintf(
			/* translators: %s: the check's group, such as "Media". */
			__( '%s · Required', 'sapphireit-publish-guard' ),
			$group
		);

		echo '<li class="sit-wcpg-dashboard__issue">';
		echo '<span class="sit-wcpg-dashboard__issue-name"><span class="sit-wcpg-dashboard__issue-label" id="' . esc_attr( $id ) . '">' . esc_html( $rule->get_label() ) . '</span>';
		echo '<span class="sit-wcpg-dashboard__issue-meta">' . esc_html( $caption ) . '</span></span>';
		echo '<span class="sit-wcpg-ui-bar sit-wcpg-ui-bar--required sit-wcpg-dashboard__issue-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $share ) . '" aria-labelledby="' . esc_attr( $id ) . '">';
		echo '<span class="sit-wcpg-ui-bar__fill" style="' . esc_attr( 'width: ' . $share . '%' ) . '"></span></span>';
		echo '<a class="sit-wcpg-dashboard__issue-count" href="' . esc_url( self::list_url( Readiness_Store::FILTER_FAIL, $rule->get_id() ) ) . '">' . esc_html( number_format_i18n( $count ) );
		echo '<span class="screen-reader-text"> ' . esc_html(
			sprintf(
				/* translators: %s: the check's label. */
				__( 'products failing %s: show them', 'sapphireit-publish-guard' ),
				$rule->get_label()
			)
		) . '</span></a>';
		echo '</li>';
	}

	/**
	 * Every non-managed check with an on/off pill. Pills, not switches: this page saves nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_active_checks(): void {
		$rules = array_values(
			array_filter(
				$this->registry()->all(),
				static function ( Rule_Interface $rule ): bool {
					return ! $rule instanceof Managed_Rule_Interface;
				}
			)
		);
		$on    = count(
			array_filter(
				$rules,
				function ( Rule_Interface $rule ): bool {
					return $this->settings->rule_is_enabled( $rule->get_id() );
				}
			)
		);

		echo '<section class="sit-wcpg-ui-card sit-wcpg-dashboard__checks" aria-labelledby="sit-wcpg-dashboard-checks">';
		echo '<div class="sit-wcpg-ui-card__header"><h2 id="sit-wcpg-dashboard-checks">' . esc_html__( 'Active checks', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<span class="' . esc_attr( 'sit-wcpg-ui-pill sit-wcpg-ui-pill--' . ( $on > 0 ? 'success' : 'neutral' ) ) . '">' . esc_html(
			sprintf(
				/* translators: 1: checks switched on, 2: checks in total. */
				__( '%1$s of %2$s on', 'sapphireit-publish-guard' ),
				number_format_i18n( $on ),
				number_format_i18n( count( $rules ) )
			)
		) . '</span></div>';

		echo '<div class="sit-wcpg-ui-card__body">';

		if ( array() === $rules ) {
			echo '<p>' . esc_html__( 'No checks are registered.', 'sapphireit-publish-guard' ) . '</p>';
		} else {
			echo '<ul class="sit-wcpg-dashboard__check-list">';

			foreach ( $rules as $rule ) {
				$enabled = $this->settings->rule_is_enabled( $rule->get_id() );

				echo '<li class="' . esc_attr( 'sit-wcpg-dashboard__check' . ( $enabled ? '' : ' sit-wcpg-dashboard__check--off' ) ) . '">';
				echo '<span class="sit-wcpg-dashboard__check-label">' . esc_html( $rule->get_label() ) . '</span>';
				echo '<span class="' . esc_attr( 'sit-wcpg-ui-pill sit-wcpg-ui-pill--' . ( $enabled ? 'success' : 'neutral' ) . ' sit-wcpg-dashboard__check-state' ) . '">'
					. ( $enabled ? esc_html__( 'On', 'sapphireit-publish-guard' ) : esc_html__( 'Off', 'sapphireit-publish-guard' ) ) . '</span>';
				echo '</li>';
			}

			echo '</ul>';
		}

		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			echo '<p class="sit-wcpg-dashboard__manage"><a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Manage all checks', 'sapphireit-publish-guard' ) . '<span aria-hidden="true"> →</span></a></p>';
		}

		echo '</div></section>';
	}

	/**
	 * Documentation, Support and Leave a review.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_help_cards(): void {
		$cards = array(
			array( 'info', 'book', __( 'Documentation', 'sapphireit-publish-guard' ), __( 'How each check decides, and what it skips.', 'sapphireit-publish-guard' ), Admin_Shell::DOCS_URL, __( 'Read the docs', 'sapphireit-publish-guard' ) ),
			array( 'success', 'chat', __( 'Support', 'sapphireit-publish-guard' ), __( 'Ask on the WordPress.org forum.', 'sapphireit-publish-guard' ), self::SUPPORT_URL, __( 'Open a topic', 'sapphireit-publish-guard' ) ),
			array( 'warning', 'star', __( 'Enjoying Publish Guard?', 'sapphireit-publish-guard' ), __( 'A review helps other store owners find it.', 'sapphireit-publish-guard' ), Settings_Footer::REVIEW_URL, __( 'Leave a review', 'sapphireit-publish-guard' ) ),
		);

		echo '<div class="sit-wcpg-dashboard__help">';

		foreach ( $cards as $card ) {
			echo '<div class="sit-wcpg-ui-card sit-wcpg-dashboard__help-card">';
			echo '<span class="' . esc_attr( 'sit-wcpg-dashboard__help-icon sit-wcpg-dashboard__help-icon--' . $card[0] ) . '">' . wp_kses( Icons::svg( $card[1], 20 ), Icons::allowed_html() ) . '</span>';
			echo '<div><h2 class="sit-wcpg-dashboard__help-title">' . esc_html( $card[2] ) . '</h2>';
			echo '<p class="sit-wcpg-dashboard__help-text">' . esc_html( $card[3] ) . '</p>';
			echo '<a href="' . esc_url( $card[4] ) . '" target="_blank" rel="noopener">' . esc_html( $card[5] );
			self::render_new_tab_hint();
			echo '</a></div></div>';
		}

		echo '</div>';
	}

	/**
	 * Class of the health card: it spans the row when the Pro card is not beside it.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $show_pro Whether the Pro card shares the row.
	 * @return string
	 */
	private static function health_class( bool $show_pro ): string {
		return 'sit-wcpg-ui-card sit-wcpg-dashboard__health' . ( $show_pro ? '' : ' sit-wcpg-dashboard__health--wide' );
	}

	/**
	 * The products list filtered to a readiness state, and optionally to one failing check.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filter  A Readiness_Store filter.
	 * @param string $rule_id A registered rule id, or ''.
	 * @return string
	 */
	private static function list_url( string $filter, string $rule_id = '' ): string {
		$args = array(
			'post_type'                 => Screen::POST_TYPE,
			Readiness_Filter::QUERY_VAR => $filter,
		);

		if ( '' !== $rule_id ) {
			$args[ Readiness_Filter::RULE_QUERY_VAR ] = $rule_id;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * The Live products report.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private static function catalog_url(): string {
		return add_query_arg( 'page', Catalog_Page::MENU_SLUG, admin_url( Catalog_Page::PARENT ) );
	}

	/**
	 * The Checklist settings.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private static function settings_url(): string {
		return add_query_arg( 'page', Settings_Page::MENU_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Tell screen-reader users a link opens a new tab.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function render_new_tab_hint(): void {
		echo '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'sapphireit-publish-guard' ) . '</span>';
	}

	/**
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		if ( null === $this->registry ) {
			$this->registry = Plugin::instance()->registry();
		}

		return $this->registry;
	}

	/**
	 * The statistics, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Stats
	 */
	private function stats(): Readiness_Stats {
		if ( null === $this->stats ) {
			$this->stats = Plugin::instance()->readiness_stats();
		}

		return $this->stats;
	}

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Rebuild
	 */
	private function rebuild(): Readiness_Rebuild {
		if ( null === $this->rebuild ) {
			$this->rebuild = Plugin::instance()->readiness_rebuild();
		}

		return $this->rebuild;
	}
}
