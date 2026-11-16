<?php
/**
 * The branded header above every Publish Guard admin screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Settings\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * The admin shell (UI decisions U6, U7) and the seams an add-on joins it through: extra
 * tabs (S12, U9), header pills (S13, U10), the shell on its own page (S14, U11) and the
 * switch for the upgrade prompts (S15, U13).
 *
 * Everything an add-on passes in is validated after its filter runs and escaped on
 * output; anything malformed is dropped (and logged under `WP_DEBUG`). The core tabs are
 * rebuilt from Free's own definitions, so a filter cannot remove, replace or relabel them.
 *
 * An add-on page uses it like this:
 *
 *     Admin_Shell::render( 'my-tab' );
 *     echo '<div class="wrap sit-wcpg-ui">…</div>';
 *
 * and enqueues the `Admin_Shell::STYLE_HANDLE` stylesheet on its screen.
 *
 * @since 1.2.0
 */
final class Admin_Shell {

	/**
	 * Handle of the shell stylesheet: registered on every admin request, enqueued on Free's
	 * shell screens. An add-on enqueues it, or lists it as a dependency, on its own page.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const STYLE_HANDLE = 'sit-wcpg-shell';

	/**
	 * Filter that adds tabs to the header (S12).
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const TABS_FILTER = 'sit_wcpg_admin_tabs';

	/**
	 * Filter that adds pills to the right of the header (S13).
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const ITEMS_FILTER = 'sit_wcpg_admin_header_items';

	/**
	 * Filter that turns the Pro prompts on Free's screens off when it returns false (S15).
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const PROMPTS_FILTER = 'sit_wcpg_show_upgrade_prompts';

	/**
	 * The Pro sales page, for "learn more" links. No tracking parameters (U13).
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const PRO_URL = 'https://sapphireitlabs.com/publish-guard/';

	/**
	 * The pricing section of the sales page, for the header's "Upgrade to Pro" button.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const PRO_PRICING_URL = self::PRO_URL . '#pricing';

	/**
	 * The brand mark in the header's logo tile: the filled shield and tick of
	 * `assets/images/icon.svg`, drawn on the tile's navy background (U6). Static markup.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const LOGO_SVG = '<svg width="36" height="36" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><path d="M128 38 L200 64 V124 C200 170 170 204 128 220 C86 204 56 170 56 124 V64 Z" fill="#00A32A"/><path d="M94 128 L119 153 L166 104" fill="none" stroke="#fff" stroke-width="20" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	/**
	 * The plugin's documentation: its WordPress.org page.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const DOCS_URL = 'https://wordpress.org/plugins/sapphireit-publish-guard/';

	/**
	 * Tab id of the Dashboard.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const TAB_DASHBOARD = 'dashboard';

	/**
	 * Tab id of the Product Checklist settings.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const TAB_CHECKLIST = 'checklist';

	/**
	 * Tab id of the Live products report.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const TAB_LIVE_PRODUCTS = 'live-products';

	/**
	 * Tab id of the link to the Pro sales page.
	 *
	 * @api
	 * @since 1.2.0
	 * @var string
	 */
	public const TAB_UPGRADE = 'upgrade';

	/**
	 * What an add-on's tab or item id must look like (U9).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const ID_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

	/**
	 * Most add-on tabs printed.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_ADDON_TABS = 4;

	/**
	 * Most header items printed.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_ITEMS = 3;

	/**
	 * Longest label, in characters.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_LABEL = 40;

	/**
	 * Longest badge, in characters.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_BADGE = 12;

	/**
	 * Header item tone => pill modifier (U5).
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	private const TONES = array(
		'neutral' => 'neutral',
		'success' => 'success',
		'warning' => 'warning',
		'danger'  => 'required',
	);

	/**
	 * Print the header, then the `wp-header-end` marker WordPress moves notices below (S14).
	 *
	 * @api
	 * @since 1.2.0
	 *
	 * @param string $active Id of the tab to mark as the current page.
	 * @return void
	 */
	public static function render( string $active ): void {
		$tabs  = self::tabs( $active );
		$items = self::header_items( $active );

		echo '<div class="sit-wcpg-ui sit-wcpg-ui-header">';
		echo '<div class="sit-wcpg-ui-header__bar">';

		echo '<p class="sit-wcpg-ui-header__brand">';
		echo '<span class="sit-wcpg-ui-header__logo">' . wp_kses( self::LOGO_SVG, self::logo_allowed_html() ) . '</span>';
		echo '<span class="sit-wcpg-ui-header__name">' . esc_html__( 'Publish Guard', 'sapphireit-publish-guard' ) . '</span>';
		echo '<span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--neutral sit-wcpg-ui-header__version">' . esc_html( 'v' . SIT_WCPG_VERSION ) . '</span>';
		echo '</p>';

		echo '<nav class="sit-wcpg-ui-header__nav" aria-label="' . esc_attr__( 'Publish Guard', 'sapphireit-publish-guard' ) . '">';
		echo '<ul class="sit-wcpg-ui-header__tabs">';

		foreach ( $tabs as $tab ) {
			self::render_tab( $tab );
		}

		echo '</ul></nav>';

		echo '<ul class="sit-wcpg-ui-header__items">';

		echo '<li>';
		self::render_external_link( 'sit-wcpg-ui-header__link', self::DOCS_URL, 'book', __( 'Docs', 'sapphireit-publish-guard' ) );
		echo '</li>';

		foreach ( $items as $item ) {
			self::render_item( $item );
		}

		if ( self::show_upgrade_prompts() && current_user_can( Settings_Page::CAPABILITY ) ) {
			echo '<li>';
			self::render_external_link( 'sit-wcpg-ui-button sit-wcpg-ui-button--primary sit-wcpg-ui-header__upgrade', self::PRO_PRICING_URL, 'crown', __( 'Upgrade to Pro', 'sapphireit-publish-guard' ) );
			echo '</li>';
		}

		echo '</ul>';
		echo '</div></div>';
		echo '<hr class="wp-header-end" />';
	}

	/**
	 * The tabs this user may open, core first, then add-on tabs, then the upgrade tab.
	 *
	 * Each entry is `array{ id: string, label: string, url: string, badge: string,
	 * badge_class: string, external: bool, current: bool }`.
	 *
	 * @api
	 * @since 1.2.0
	 *
	 * @param string $active Id of the tab to mark as the current page.
	 * @return array<int, array<string, mixed>>
	 */
	public static function tabs( string $active ): array {
		$core = self::core_tabs();

		/**
		 * Filters the tabs of the Publish Guard admin header.
		 *
		 * Append an entry `array{ id: string, label: string, url: string, capability: string,
		 * badge?: string }`. `id` matches `^[a-z][a-z0-9-]{0,39}$` and is unique; `label`
		 * (≤ 40 characters) and `badge` (≤ 12) are plain text; `url` is an absolute URL inside
		 * the admin; `capability` is checked for the current user. At most four add-on tabs
		 * are printed, in the order added. The core tabs cannot be removed or changed.
		 *
		 * @api
		 * @since 1.2.0
		 *
		 * @param array[] $tabs   The core tabs.
		 * @param string  $active Id of the current tab.
		 */
		$filtered = apply_filters(
			'sit_wcpg_admin_tabs',
			array_values( $core ),
			$active
		);

		$addons = is_array( $filtered ) ? self::addon_tabs( $filtered ) : array();
		$tabs   = array();

		foreach ( $core as $id => $tab ) {
			if ( self::TAB_UPGRADE !== $id && current_user_can( $tab['capability'] ) ) {
				$tabs[] = $tab;
			}
		}

		$tabs = array_merge( $tabs, $addons );

		if ( isset( $core[ self::TAB_UPGRADE ] ) && current_user_can( $core[ self::TAB_UPGRADE ]['capability'] ) ) {
			$tabs[] = $core[ self::TAB_UPGRADE ];
		}

		return array_map(
			static function ( array $tab ) use ( $active ): array {
				unset( $tab['capability'] );

				$tab['current'] = $active === $tab['id'];

				return $tab;
			},
			$tabs
		);
	}

	/**
	 * The validated header pills, `array{ id: string, label: string, url: string, tone: string }`.
	 *
	 * @api
	 * @since 1.2.0
	 *
	 * @param string $active Id of the current tab.
	 * @return array<int, array{id: string, label: string, url: string, tone: string}>
	 */
	public static function header_items( string $active ): array {
		/**
		 * Filters the pills on the right of the Publish Guard admin header.
		 *
		 * Append an entry `array{ id: string, label: string, url?: string, tone?: string }`,
		 * where `tone` is `neutral` (the default), `success`, `warning` or `danger` and `url`,
		 * when given, is an absolute URL inside the admin. At most three are printed. The
		 * same validation as `sit_wcpg_admin_tabs` applies.
		 *
		 * @api
		 * @since 1.2.0
		 *
		 * @param array[] $items  Header items. Empty in Free.
		 * @param string  $active Id of the current tab.
		 */
		$filtered = apply_filters(
			'sit_wcpg_admin_header_items',
			array(),
			$active
		);

		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$items = array();
		$seen  = array();

		foreach ( $filtered as $entry ) {
			$item = self::validate_item( $entry, $seen );

			if ( null === $item ) {
				continue;
			}

			$seen[]  = $item['id'];
			$items[] = $item;

			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Whether Free's own screens show the Pro prompts (S15, U13).
	 *
	 * @api
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function show_upgrade_prompts(): bool {
		/**
		 * Filters whether Publish Guard's screens mention the Pro add-on.
		 *
		 * Covers the "Upgrade to Pro" header button, the upgrade tab, the Dashboard's Pro
		 * card and the Checklist's "Need channel rules?" box. Pro returns false whenever
		 * it is active.
		 *
		 * @api
		 * @since 1.2.0
		 *
		 * @param bool $show Whether to show them. Default true.
		 */
		return (bool) apply_filters(
			'sit_wcpg_show_upgrade_prompts',
			true
		);
	}

	/**
	 * Print a page bar: the screen's title and an optional description (U15).
	 *
	 * The Checklist screen prints its own page bar, because it holds the save actions inside
	 * its form. Not part of the add-on API yet.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title       The page title, plain text.
	 * @param string $description Optional plain-text description.
	 * @param array  $link        Optional secondary button: `label` (plain text) and `url`. Printed
	 *                            only when both are non-empty strings.
	 * @return void
	 */
	public static function render_page_bar( string $title, string $description = '', array $link = array() ): void {
		echo '<div class="sit-wcpg-ui-pagebar"><div class="sit-wcpg-ui-pagebar__text">';
		echo '<h1 class="sit-wcpg-ui-pagebar__title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $description ) {
			echo '<p class="sit-wcpg-ui-pagebar__description">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';

		$label = isset( $link['label'] ) && is_string( $link['label'] ) ? $link['label'] : '';
		$url   = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';

		if ( '' !== $label && '' !== $url ) {
			echo '<div class="sit-wcpg-ui-pagebar__actions">';
			echo '<a class="sit-wcpg-ui-button sit-wcpg-ui-button--secondary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Free's tabs, keyed by id, before capability checks.
	 *
	 * The Dashboard tab exists only once its page is registered for this user
	 * (`add_submenu_page()` records it in `$_parent_pages`), so a request that never built
	 * the menu prints no dead tab.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function core_tabs(): array {
		$tabs = array();

		if ( isset( $GLOBALS['_parent_pages'][ Dashboard_Page::MENU_SLUG ] ) ) {
			$tabs[ self::TAB_DASHBOARD ] = self::tab(
				self::TAB_DASHBOARD,
				__( 'Dashboard', 'sapphireit-publish-guard' ),
				add_query_arg( 'page', Dashboard_Page::MENU_SLUG, admin_url( 'admin.php' ) ),
				Dashboard_Page::CAPABILITY
			);
		}

		$tabs[ self::TAB_CHECKLIST ] = self::tab(
			self::TAB_CHECKLIST,
			__( 'Checklist', 'sapphireit-publish-guard' ),
			add_query_arg( 'page', Settings_Page::MENU_SLUG, admin_url( 'admin.php' ) ),
			Settings_Page::CAPABILITY
		);

		$tabs[ self::TAB_LIVE_PRODUCTS ] = self::tab(
			self::TAB_LIVE_PRODUCTS,
			__( 'Live products', 'sapphireit-publish-guard' ),
			add_query_arg( 'page', Catalog_Page::MENU_SLUG, admin_url( Catalog_Page::PARENT ) ),
			Catalog_Page::CAPABILITY
		);

		if ( self::show_upgrade_prompts() ) {
			$tabs[ self::TAB_UPGRADE ] = self::tab(
				self::TAB_UPGRADE,
				__( 'Channel Readiness', 'sapphireit-publish-guard' ),
				self::PRO_URL,
				Settings_Page::CAPABILITY,
				__( 'PRO', 'sapphireit-publish-guard' ),
				'pro',
				true
			);
		}

		return $tabs;
	}

	/**
	 * One tab entry.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id         Tab id.
	 * @param string $label      Plain-text label.
	 * @param string $url        Destination.
	 * @param string $capability Capability needed to see it.
	 * @param string $badge      Plain-text badge, or ''.
	 * @param string $badge_tone Pill modifier of the badge.
	 * @param bool   $external   Whether it leaves the admin.
	 * @return array<string, mixed>
	 */
	private static function tab( string $id, string $label, string $url, string $capability, string $badge = '', string $badge_tone = 'info', bool $external = false ): array {
		return array(
			'id'          => $id,
			'label'       => $label,
			'url'         => $url,
			'capability'  => $capability,
			'badge'       => $badge,
			'badge_class' => 'sit-wcpg-ui-pill--' . $badge_tone,
			'external'    => $external,
		);
	}

	/**
	 * The valid add-on tabs the current user may open, at most four.
	 *
	 * @since 1.2.0
	 *
	 * @param array $filtered What the filter returned.
	 * @return array<int, array<string, mixed>>
	 */
	private static function addon_tabs( array $filtered ): array {
		$reserved = array( self::TAB_DASHBOARD, self::TAB_CHECKLIST, self::TAB_LIVE_PRODUCTS, self::TAB_UPGRADE );
		$seen     = array();
		$tabs     = array();

		foreach ( $filtered as $entry ) {
			// Free's own entries come back through the filter, and their ids stay Free's.
			if ( is_array( $entry ) && isset( $entry['id'] ) && in_array( $entry['id'], $reserved, true ) ) {
				continue;
			}

			$tab = self::validate_tab( $entry, $seen );

			if ( null === $tab ) {
				continue;
			}

			$seen[] = $tab['id'];

			if ( ! current_user_can( $tab['capability'] ) ) {
				continue;
			}

			$tabs[] = $tab;

			if ( count( $tabs ) >= self::MAX_ADDON_TABS ) {
				break;
			}
		}

		return $tabs;
	}

	/**
	 * An add-on's tab entry, or null when it is malformed.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed    $entry One entry from the filter.
	 * @param string[] $seen  Ids already taken.
	 * @return array<string, mixed>|null
	 */
	private static function validate_tab( $entry, array $seen ): ?array {
		if ( ! is_array( $entry ) ) {
			return self::reject( 'tab', 'is not an array' );
		}

		$id = self::valid_id( $entry['id'] ?? null, $seen );

		if ( '' === $id ) {
			return self::reject( 'tab', 'has a missing, malformed or duplicate id' );
		}

		$label = self::plain_text( $entry['label'] ?? null, self::MAX_LABEL );
		$url   = self::admin_url_or_empty( $entry['url'] ?? null );
		$cap   = $entry['capability'] ?? null;
		$badge = array_key_exists( 'badge', $entry ) ? self::plain_text( $entry['badge'], self::MAX_BADGE ) : '';

		if ( '' === $label || '' === $url || ! is_string( $cap ) || '' === $cap || ( array_key_exists( 'badge', $entry ) && '' === $badge ) ) {
			return self::reject( 'tab', '"' . $id . '" has a bad label, url, capability or badge' );
		}

		return self::tab( $id, $label, $url, $cap, $badge );
	}

	/**
	 * An add-on's header item, or null when it is malformed.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed    $entry One entry from the filter.
	 * @param string[] $seen  Ids already taken.
	 * @return array{id: string, label: string, url: string, tone: string}|null
	 */
	private static function validate_item( $entry, array $seen ): ?array {
		if ( ! is_array( $entry ) ) {
			return self::reject( 'header item', 'is not an array' );
		}

		$id = self::valid_id( $entry['id'] ?? null, $seen );

		if ( '' === $id ) {
			return self::reject( 'header item', 'has a missing, malformed or duplicate id' );
		}

		$label = self::plain_text( $entry['label'] ?? null, self::MAX_LABEL );
		$tone  = $entry['tone'] ?? 'neutral';
		$url   = '';

		if ( isset( $entry['url'] ) && '' !== $entry['url'] ) {
			$url = self::admin_url_or_empty( $entry['url'] );

			if ( '' === $url ) {
				return self::reject( 'header item', '"' . $id . '" has a url outside the admin' );
			}
		}

		if ( '' === $label || ! is_string( $tone ) || ! isset( self::TONES[ $tone ] ) ) {
			return self::reject( 'header item', '"' . $id . '" has a bad label or tone' );
		}

		return array(
			'id'    => $id,
			'label' => $label,
			'url'   => $url,
			'tone'  => $tone,
		);
	}

	/**
	 * The id when it is well formed and not taken, else ''.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed    $id   Candidate id.
	 * @param string[] $seen Ids already taken.
	 * @return string
	 */
	private static function valid_id( $id, array $seen ): string {
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || in_array( $id, $seen, true ) ) {
			return '';
		}

		return $id;
	}

	/**
	 * The trimmed text when it is a non-empty string within the limit, else ''.
	 *
	 * Escaped on output, never here.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $text  Candidate text.
	 * @param int   $limit Longest allowed, in characters.
	 * @return string
	 */
	private static function plain_text( $text, int $limit ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = trim( $text );

		return mb_strlen( $text ) <= $limit ? $text : '';
	}

	/**
	 * The URL when it is an absolute URL inside this site's admin, else ''.
	 *
	 * Checked against `admin_url()` first: `wp_validate_redirect()` alone accepts a
	 * host-less string, which is how a `javascript:` URL would get through.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $url Candidate URL.
	 * @return string
	 */
	private static function admin_url_or_empty( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$admin = admin_url();

		if ( 0 !== strpos( $url, $admin ) ) {
			return '';
		}

		$valid = wp_validate_redirect( $url, '' );

		return is_string( $valid ) && 0 === strpos( $valid, $admin ) ? $valid : '';
	}

	/**
	 * Drop a malformed entry, saying why under `WP_DEBUG`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $kind   What was dropped.
	 * @param string $reason Why.
	 * @return null
	 */
	private static function reject( string $kind, string $reason ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Developer-facing diagnostics only; the merchant sees a missing tab, not this.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'SapphireIT Publish Guard: dropped an admin header %1$s that %2$s.', $kind, $reason ) );
		}

		return null;
	}

	/**
	 * Print one tab.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $tab A validated tab.
	 * @return void
	 */
	private static function render_tab( array $tab ): void {
		$class = 'sit-wcpg-ui-header__tab' . ( $tab['current'] ? ' sit-wcpg-ui-header__tab--current' : '' );

		echo '<li><a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab['url'] ) . '"';

		if ( $tab['current'] ) {
			echo ' aria-current="page"';
		}

		if ( $tab['external'] ) {
			echo ' target="_blank" rel="noopener"';
		}

		echo '>' . esc_html( $tab['label'] );

		if ( '' !== $tab['badge'] ) {
			echo ' <span class="' . esc_attr( 'sit-wcpg-ui-pill ' . $tab['badge_class'] ) . '">' . esc_html( $tab['badge'] ) . '</span>';
		}

		if ( $tab['external'] ) {
			self::render_new_tab_hint();
		}

		echo '</a></li>';
	}

	/**
	 * Print one header pill.
	 *
	 * @since 1.2.0
	 *
	 * @param array{id: string, label: string, url: string, tone: string} $item A validated item.
	 * @return void
	 */
	private static function render_item( array $item ): void {
		$class = 'sit-wcpg-ui-pill sit-wcpg-ui-pill--' . self::TONES[ $item['tone'] ];

		echo '<li>';

		if ( '' !== $item['url'] ) {
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		} else {
			echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $item['label'] ) . '</span>';
		}

		echo '</li>';
	}

	/**
	 * Print a link that opens a new tab without access to this one.
	 *
	 * @since 1.2.0
	 *
	 * @param string $css_class Class attribute.
	 * @param string $url       Destination.
	 * @param string $icon      Icon name.
	 * @param string $label     Plain-text label.
	 * @return void
	 */
	private static function render_external_link( string $css_class, string $url, string $icon, string $label ): void {
		echo '<a class="' . esc_attr( $css_class ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">';
		echo wp_kses( Icons::svg( $icon, 16 ), Icons::allowed_html() ) . esc_html( $label );
		self::render_new_tab_hint();
		echo '</a>';
	}

	/**
	 * The `wp_kses()` allow-list for {@see Admin_Shell::LOGO_SVG}.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function logo_allowed_html(): array {
		$paint = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);

		return array(
			'svg'  => array(
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'focusable'   => true,
			) + $paint,
			'path' => array( 'd' => true ) + $paint,
		);
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
}
