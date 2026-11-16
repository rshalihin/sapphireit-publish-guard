<?php
/**
 * The `WooCommerce → Product Checklist` settings screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Settings;

use ProductPublishGuard\Admin\Admin_Shell;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Icons;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Engine\Managed_Rule_Interface;
use ProductPublishGuard\Engine\Rule_Interface;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Readiness_Rebuild;

defined( 'ABSPATH' ) || exit;

/**
 * Menu registration, setting registration and the form itself.
 *
 * The save path is core's: the form posts to `options.php`, which checks the nonce
 * emitted by `settings_fields()` and the capability supplied through the
 * `option_page_capability_sit_wcpg_settings` filter *before* the sanitize callback runs.
 * The plugin adds no endpoint of its own here.
 *
 * Rule rows are built from the registry rather than from a hard-coded list, so a rule
 * added later — by a future version or by another plugin — is configurable with no edit
 * to this file.
 *
 * Since 1.2.0 the form wears the admin shell's components (UI decisions U8, U15): a page
 * bar with the master switch and Save, a section nav, one card per rule group, a
 * Publishing card, the readiness banner and a bottom save bar. It posts exactly the same
 * fields as before and works without JavaScript.
 *
 * @since 1.0.0
 */
final class Settings_Page {

	/**
	 * Settings API option group, which also names the nonce and the capability filter.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_GROUP = 'sit_wcpg_settings';

	/**
	 * Admin page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MENU_SLUG = 'sit-wcpg-settings';

	/**
	 * The `admin_menu` priority the page is added at.
	 *
	 * Later than WooCommerce's last submenu (Extensions, priority 70), so the page sits at
	 * the end of the WooCommerce menu and a page added after it sits next to it.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MENU_PRIORITY = 100;

	/**
	 * Capability required to view and save the settings.
	 *
	 * Deliberately not `manage_options`: a shop manager has to be able to configure a
	 * WooCommerce plugin.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Threshold key => the rule whose row it renders in. A threshold whose rule is not
	 * listed renders in a "Thresholds" card after the rule groups.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	public const THRESHOLD_RULES = array(
		'min_description_chars'       => 'description',
		'min_short_description_chars' => 'short_description',
		'min_images'                  => 'image_count',
	);

	/**
	 * Prefix of each section's HTML id, which the section nav links to.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const SECTION_ID_PREFIX = 'sit-wcpg-section-';

	/**
	 * Section keys of the cards that are not rule groups. A rule group whose key matches
	 * one gets a `-group` suffix, so ids never collide.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const FIXED_SECTIONS = array( 'other', 'thresholds', 'publishing', 'readiness' );

	/**
	 * Rule id => icon name. Other rules get the check mark.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	private const RULE_ICONS = array(
		'title'             => 'title',
		'description'       => 'text',
		'short_description' => 'text',
		'featured_image'    => 'image',
		'image_count'       => 'images',
		'price'             => 'price',
		'sale_price'        => 'price',
		'category'          => 'grid',
		'tags'              => 'tag',
		'sku'               => 'barcode',
		'stock_status'      => 'bag',
		'child_products'    => 'fields',
	);

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The rules the form builds its rows from.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry;

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
	 * The registry is resolved on first use, so the rule list is built only on this
	 * screen and on a save — not on every other admin request that merely loads the menu.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added `$rebuild`.
	 *
	 * @param Settings               $settings The merchant's configuration.
	 * @param Rule_Registry|null     $registry Rules to list. Null resolves the plugin's own.
	 * @param Readiness_Rebuild|null $rebuild  The rebuild. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Rule_Registry $registry = null, ?Readiness_Rebuild $rebuild = null ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->rebuild  = $rebuild;
	}

	/**
	 * Hook the page into the admin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), self::MENU_PRIORITY );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'load-' . Screen::SETTINGS_HOOK, array( $this, 'on_load' ), 10, 0 );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'filter_capability' ) );
	}

	/**
	 * Start a rebuild when the screen opens on stale flags.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function on_load(): void {
		$this->rebuild()->ensure_current();
	}

	/**
	 * Add the submenu entry under WooCommerce.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Checklist', 'sapphireit-publish-guard' ),
			__( 'Product Checklist', 'sapphireit-publish-guard' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * Sanitized by `Settings_Sanitizer::sanitize()`, which rebuilds the option from the defaults
	 * and allowlists and clamps every field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'description'       => __( 'Product readiness checklist configuration.', 'sapphireit-publish-guard' ),
				'sanitize_callback' => array( new Settings_Sanitizer( $this->settings ), 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Require `manage_woocommerce` rather than `manage_options` to save.
	 *
	 * Core enforces this in `options.php` before anything of ours runs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $capability Capability core would otherwise require.
	 * @return string
	 */
	public function filter_capability( string $capability ): string {
		unset( $capability );

		return self::CAPABILITY;
	}

	/**
	 * Render the settings screen.
	 *
	 * DOM order is the keyboard order: section nav, page bar, sections, bottom bar.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The admin shell's layout and components (UI phase U06).
	 *
	 * @return void
	 */
	public function render_page(): void {
		/*
		 * The menu capability is not a complete guard on its own: a direct request to
		 * `admin.php?page=sit-wcpg-settings` reaches the callback in some configurations,
		 * so the check is repeated here where the decision actually matters.
		 */
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'sapphireit-publish-guard' ) );
		}

		$values    = $this->settings->get_all();
		$rules     = $this->listed_rules();
		$groups    = $this->group_rules( $rules );
		$orphans   = $this->orphan_thresholds( $rules );
		$readiness = $this->settings->is_enabled();

		Admin_Shell::render( Admin_Shell::TAB_CHECKLIST );

		echo '<div class="wrap sit-wcpg-ui sit-wcpg-settings">';

		settings_errors();

		$invalid = $this->invalid_field_id();

		echo '<form method="post" action="options.php" class="sit-wcpg-settings__form" data-sit-wcpg-settings-form';

		if ( '' !== $invalid ) {
			echo ' data-sit-wcpg-invalid="' . esc_attr( $invalid ) . '"';
		}

		echo '>';

		settings_fields( self::OPTION_GROUP );

		// The page bar spans the full width above the nav and the sections, so the title
		// starts at the left edge of the page (U15).
		$this->render_page_bar( $values );

		echo '<div class="sit-wcpg-settings__layout">';

		$this->render_nav( $groups, array() !== $orphans, $readiness );

		echo '<div class="sit-wcpg-settings__main">';

		echo '<div class="sit-wcpg-settings__sections">';

		if ( array() === $groups ) {
			echo '<div class="sit-wcpg-ui-card"><div class="sit-wcpg-ui-card__body"><p>' . esc_html__( 'No checks are registered.', 'sapphireit-publish-guard' ) . '</p></div></div>';
		}

		foreach ( $groups as $group ) {
			$this->render_group_card( $group, $values );
		}

		if ( array() !== $orphans ) {
			$this->render_thresholds_card( $orphans, $values );
		}

		$this->render_publishing_card( $values );

		if ( $readiness ) {
			$this->render_readiness_banner();
		}

		echo '</div>';

		self::render_save_actions( 'bottom' );

		echo '</div></div>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render one set of save actions (U15): status, spinner, Discard and Save.
	 *
	 * The status and Discard carry `hidden` until the settings script runs: without it
	 * there is no save state to show and nothing to discard, and Save still submits. The
	 * top status is the `aria-live` region; the bottom one is `aria-hidden`, so a screen
	 * reader hears each change once.
	 *
	 * @since 1.2.0
	 *
	 * @param string $position `top` (inside the page bar) or `bottom`.
	 * @return void
	 */
	private static function render_save_actions( string $position ): void {
		$top = 'top' === $position;

		echo '<div class="sit-wcpg-ui-savebar sit-wcpg-ui-savebar--' . ( $top ? 'inline' : 'bottom' ) . '" data-sit-wcpg-savebar="' . esc_attr( $position ) . '">';
		echo '<span class="sit-wcpg-ui-savebar__state" data-sit-wcpg-savestate hidden>';
		echo '<span class="sit-wcpg-ui-savebar__dot" aria-hidden="true"></span>';
		echo '<span class="sit-wcpg-ui-savebar__status" data-sit-wcpg-status ' . ( $top ? 'aria-live="polite"' : 'aria-hidden="true"' ) . '>' . esc_html__( 'All changes saved', 'sapphireit-publish-guard' ) . '</span>';
		echo '</span>';
		echo '<span class="spinner sit-wcpg-ui-savebar__spinner" aria-hidden="true"></span>';
		echo '<button type="button" class="button sit-wcpg-ui-savebar__discard" data-sit-wcpg-discard hidden>' . esc_html__( 'Discard', 'sapphireit-publish-guard' ) . '</button>';

		submit_button( __( 'Save changes', 'sapphireit-publish-guard' ), 'primary', 'submit', false, $top ? array( 'id' => 'sit-wcpg-save-top' ) : null );

		echo '</div>';
	}

	/**
	 * The id of the first field a settings error on this request is about, or ''.
	 *
	 * Read after `settings_errors()`, which has already pulled `options.php`'s errors out
	 * of their transient. Only a clamped threshold names a field; any other notice, such as
	 * core's "Settings saved.", names none.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function invalid_field_id(): string {
		$prefix = Settings_Sanitizer::CLAMP_ERROR_PREFIX;

		foreach ( get_settings_errors( Settings::OPTION_NAME ) as $error ) {
			$code = isset( $error['code'] ) ? (string) $error['code'] : '';

			if ( 0 !== strpos( $code, $prefix ) ) {
				continue;
			}

			$key = substr( $code, strlen( $prefix ) );

			if ( isset( Settings::threshold_limits()[ $key ] ) ) {
				return self::threshold_field_id( $key );
			}
		}

		return '';
	}

	/**
	 * The HTML id of a threshold's number field.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Threshold key.
	 * @return string
	 */
	private static function threshold_field_id( string $key ): string {
		return 'sit-wcpg-threshold-' . str_replace( '_', '-', $key );
	}

	/**
	 * The rules the merchant configures here: every registered rule but managed ones.
	 *
	 * A managed rule is switched on and off by the add-on that registered it, not here.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Interface[]
	 */
	private function listed_rules(): array {
		return array_values(
			array_filter(
				$this->registry()->all(),
				static function ( Rule_Interface $rule ): bool {
					return ! $rule instanceof Managed_Rule_Interface;
				}
			)
		);
	}

	/**
	 * Bucket rules under the editor panel's headings, in its order.
	 *
	 * A group the panel has no heading for, as a third-party rule can have, goes last
	 * under "Other".
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Interface[] $rules Rules, in display order.
	 * @return array<int, array{key: string, label: string, description: string, rules: Rule_Interface[]}> The groups that have rules.
	 */
	private function group_rules( array $rules ): array {
		$labels  = Editor_Meta_Box::group_labels();
		$buckets = array_fill_keys( array_keys( $labels ), array() );
		$other   = array();

		foreach ( $rules as $rule ) {
			if ( isset( $buckets[ $rule->get_group() ] ) ) {
				$buckets[ $rule->get_group() ][] = $rule;
			} else {
				$other[] = $rule;
			}
		}

		$groups = array();

		foreach ( array_filter( $buckets ) as $key => $bucket ) {
			$section = str_replace( '_', '-', (string) $key );

			$groups[] = array(
				'key'         => in_array( $section, self::FIXED_SECTIONS, true ) ? $section . '-group' : $section,
				'label'       => $labels[ $key ],
				'description' => $this->group_description( (string) $key ),
				'rules'       => $bucket,
			);
		}

		if ( array() !== $other ) {
			$groups[] = array(
				'key'         => 'other',
				'label'       => __( 'Other', 'sapphireit-publish-guard' ),
				'description' => $this->group_description( 'other' ),
				'rules'       => $other,
			);
		}

		return $groups;
	}

	/**
	 * The one-line description under a group's heading; empty for an add-on's group.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Group key, or `other`.
	 * @return string
	 */
	private function group_description( string $key ): string {
		switch ( $key ) {
			case 'content':
				return __( 'What shoppers and search engines read first.', 'sapphireit-publish-guard' );
			case 'media':
				return __( 'Channels disapprove products without images.', 'sapphireit-publish-guard' );
			case 'pricing':
				return __( 'A product can\'t be bought without a valid price.', 'sapphireit-publish-guard' );
			case 'organization':
				return __( 'How shoppers and channels find the product.', 'sapphireit-publish-guard' );
			case 'inventory':
				return __( 'What you can sell and how it is identified.', 'sapphireit-publish-guard' );
			case 'other':
				return __( 'Checks added by other plugins.', 'sapphireit-publish-guard' );
			default:
				return '';
		}
	}

	/**
	 * The thresholds whose rule has no row on this screen.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Interface[] $rules The listed rules.
	 * @return string[] Threshold keys.
	 */
	private function orphan_thresholds( array $rules ): array {
		$listed = array_map(
			static function ( Rule_Interface $rule ): string {
				return $rule->get_id();
			},
			$rules
		);

		$orphans = array();

		foreach ( array_keys( Settings::threshold_limits() ) as $key ) {
			if ( ! isset( self::THRESHOLD_RULES[ $key ] ) || ! in_array( self::THRESHOLD_RULES[ $key ], $listed, true ) ) {
				$orphans[] = (string) $key;
			}
		}

		return $orphans;
	}

	/**
	 * A rule's stored state, falling back to its own defaults.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Interface $rule   The rule.
	 * @param array          $values Current settings.
	 * @return array{enabled: bool, severity: string}
	 */
	private function rule_state( Rule_Interface $rule, array $values ): array {
		$stored = $values['rules'][ $rule->get_id() ] ?? array();

		return array(
			'enabled'  => (bool) ( $stored['enabled'] ?? $rule->is_enabled_by_default() ),
			'severity' => (string) ( $stored['severity'] ?? $rule->get_default_severity() ),
		);
	}

	/**
	 * The HTML id of a section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Section key.
	 * @return string
	 */
	private static function section_id( string $key ): string {
		return self::SECTION_ID_PREFIX . $key;
	}

	/**
	 * Render the section nav, and below it the "Need channel rules?" box while the upgrade
	 * prompts are on (U13).
	 *
	 * @since 1.2.0
	 *
	 * @param array $groups     The rule groups.
	 * @param bool  $thresholds Whether the Thresholds card is printed.
	 * @param bool  $readiness  Whether the readiness banner is printed.
	 * @return void
	 */
	private function render_nav( array $groups, bool $thresholds, bool $readiness ): void {
		echo '<nav class="sit-wcpg-settings__nav" data-sit-wcpg-section-nav aria-label="' . esc_attr__( 'Checklist sections', 'sapphireit-publish-guard' ) . '">';
		echo '<div class="sit-wcpg-ui-card sit-wcpg-settings__nav-card">';

		if ( array() !== $groups ) {
			echo '<p class="sit-wcpg-settings__nav-heading">' . esc_html__( 'Check groups', 'sapphireit-publish-guard' ) . '</p>';
			echo '<ul class="sit-wcpg-ui-nav">';

			foreach ( $groups as $group ) {
				self::render_nav_item( $group['key'], $group['label'], count( $group['rules'] ) );
			}

			echo '</ul>';
		}

		echo '<p class="sit-wcpg-settings__nav-heading">' . esc_html__( 'Settings', 'sapphireit-publish-guard' ) . '</p>';
		echo '<ul class="sit-wcpg-ui-nav">';

		if ( $thresholds ) {
			self::render_nav_item( 'thresholds', __( 'Thresholds', 'sapphireit-publish-guard' ) );
		}

		self::render_nav_item( 'publishing', __( 'Publishing', 'sapphireit-publish-guard' ) );

		if ( $readiness ) {
			self::render_nav_item( 'readiness', __( 'Readiness filter', 'sapphireit-publish-guard' ) );
		}

		echo '</ul>';

		if ( Admin_Shell::show_upgrade_prompts() ) {
			echo '<div class="sit-wcpg-settings__pro">';
			echo '<p class="sit-wcpg-settings__pro-title">' . esc_html__( 'Need channel rules?', 'sapphireit-publish-guard' ) . '</p>';
			echo '<p class="sit-wcpg-settings__pro-text">' . esc_html__( 'Pro adds Google, Meta and agent-feed packs and your own store rules.', 'sapphireit-publish-guard' ) . '</p>';
			echo '<a class="sit-wcpg-settings__pro-link" href="' . esc_url( Admin_Shell::PRO_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Learn about Pro', 'sapphireit-publish-guard' );
			echo wp_kses( Icons::svg( 'chevron-right', 16 ), Icons::allowed_html() );
			echo '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'sapphireit-publish-guard' ) . '</span>';
			echo '</a></div>';
		}

		echo '</div></nav>';
	}

	/**
	 * Render one nav link, with the number of checks in the section when given.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $key   Section key.
	 * @param string   $label Plain-text label.
	 * @param int|null $count Checks in the section, or null.
	 * @return void
	 */
	private static function render_nav_item( string $key, string $label, ?int $count = null ): void {
		echo '<li><a class="sit-wcpg-ui-nav__item" href="#' . esc_attr( self::section_id( $key ) ) . '">' . esc_html( $label );

		if ( null !== $count ) {
			echo '<span class="sit-wcpg-ui-nav__count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span>';
			/* translators: %s: number of checks in the group. */
			echo '<span class="screen-reader-text"> ' . esc_html( sprintf( _n( '(%s check)', '(%s checks)', $count, 'sapphireit-publish-guard' ), number_format_i18n( $count ) ) ) . '</span>';
		}

		echo '</a></li>';
	}

	/**
	 * Render the page bar: title, what Required and Warning mean, the master switch and
	 * the save actions (U15).
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_page_bar( array $values ): void {
		$id = $this->field_id( array( 'enabled' ) );

		echo '<div class="sit-wcpg-ui-pagebar">';
		echo '<div class="sit-wcpg-ui-pagebar__text">';
		echo '<h1 class="sit-wcpg-ui-pagebar__title">' . esc_html__( 'Product checklist', 'sapphireit-publish-guard' ) . '</h1>';
		echo '<p class="sit-wcpg-ui-pagebar__description">';
		printf(
			/* translators: 1: the word "Required", 2: the word "Warning". */
			esc_html__( 'A %1$s check must pass before a product can be published. A %2$s is shown in the editor but never blocks publishing.', 'sapphireit-publish-guard' ),
			'<strong class="sit-wcpg-settings__term sit-wcpg-settings__term--required">' . esc_html( Severity::label( Severity::REQUIRED ) ) . '</strong>',
			'<strong class="sit-wcpg-settings__term sit-wcpg-settings__term--warning">' . esc_html( Severity::label( Severity::WARNING ) ) . '</strong>'
		);
		echo '</p></div>';

		echo '<div class="sit-wcpg-settings__actions">';
		echo '<div class="sit-wcpg-settings__master">';
		echo '<span class="sit-wcpg-settings__master-text">';
		echo '<label class="sit-wcpg-settings__master-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Run the checklist', 'sapphireit-publish-guard' ) . '</label>';
		echo '<span class="sit-wcpg-settings__master-hint" id="' . esc_attr( $id . '-description' ) . '">' . esc_html__( 'When this is off, no checks run and nothing is enforced.', 'sapphireit-publish-guard' ) . '</span>';
		echo '</span>';

		$this->render_checkbox( array( 'enabled' ), (bool) $values['enabled'], '', $id . '-description' );

		echo '</div>';

		self::render_save_actions( 'top' );

		echo '</div></div>';
	}

	/**
	 * Open a card section with its header.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key         Section key.
	 * @param string $title       Plain-text heading.
	 * @param string $description Plain-text line under the heading, or ''.
	 * @param string $modifier    Extra class, or ''.
	 * @return void
	 */
	private static function open_card( string $key, string $title, string $description, string $modifier = '' ): void {
		$id = self::section_id( $key );

		echo '<section class="' . esc_attr( trim( 'sit-wcpg-ui-card sit-wcpg-settings__section ' . $modifier ) ) . '" id="' . esc_attr( $id ) . '" data-sit-wcpg-section aria-labelledby="' . esc_attr( $id . '-title' ) . '">';
		echo '<div class="sit-wcpg-ui-card__header"><div class="sit-wcpg-settings__heading">';
		echo '<h2 id="' . esc_attr( $id . '-title' ) . '">' . esc_html( $title ) . '</h2>';

		if ( '' !== $description ) {
			echo '<p class="sit-wcpg-settings__summary">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render one rule group: header with counts, then a row per rule.
	 *
	 * @since 1.2.0
	 *
	 * @param array $group  The group.
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_group_card( array $group, array $values ): void {
		self::open_card( $group['key'], $group['label'], $group['description'], 'sit-wcpg-settings__group' );

		$this->render_counts( $group['rules'], $values );

		echo '</div><div class="sit-wcpg-settings__rows">';

		foreach ( $group['rules'] as $rule ) {
			$this->render_rule_row( $rule, $values );
		}

		echo '</div></section>';
	}

	/**
	 * The header's counts: enabled checks by severity, and how many are off.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Interface[] $rules  The group's rules.
	 * @param array            $values Current settings.
	 * @return void
	 */
	private function render_counts( array $rules, array $values ): void {
		$required = 0;
		$warning  = 0;
		$off      = 0;

		foreach ( $rules as $rule ) {
			$state = $this->rule_state( $rule, $values );

			if ( ! $state['enabled'] ) {
				++$off;
			} elseif ( Severity::WARNING === $state['severity'] ) {
				++$warning;
			} else {
				++$required;
			}
		}

		$pills = array();

		if ( $required > 0 ) {
			/* translators: %s: number of required checks. */
			$pills['required'] = sprintf( _n( '%s required', '%s required', $required, 'sapphireit-publish-guard' ), number_format_i18n( $required ) );
		}

		if ( $warning > 0 ) {
			/* translators: %s: number of checks that warn. */
			$pills['warning'] = sprintf( _n( '%s warning', '%s warnings', $warning, 'sapphireit-publish-guard' ), number_format_i18n( $warning ) );
		}

		if ( $off > 0 ) {
			/* translators: %s: number of checks switched off. */
			$pills['neutral'] = sprintf( _n( '%s off', '%s off', $off, 'sapphireit-publish-guard' ), number_format_i18n( $off ) );
		}

		echo '<p class="sit-wcpg-settings__counts">';

		foreach ( $pills as $tone => $text ) {
			echo '<span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span> ';
		}

		echo '</p>';
	}

	/**
	 * Render one rule's row: icon, label, description, severity, switch, and any
	 * threshold that belongs to the rule.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 A card row with a segmented severity control and a switch.
	 *
	 * @param Rule_Interface $rule   The rule to render.
	 * @param array          $values Current settings.
	 * @return void
	 */
	private function render_rule_row( Rule_Interface $rule, array $values ): void {
		$rule_id = $rule->get_id();
		$state   = $this->rule_state( $rule, $values );
		$label   = $rule->get_label();

		echo '<div class="sit-wcpg-settings__row" data-rule="' . esc_attr( $rule_id ) . '">';
		echo '<span class="sit-wcpg-settings__icon">' . wp_kses( Icons::svg( self::RULE_ICONS[ $rule_id ] ?? 'check', 20 ), Icons::allowed_html() ) . '</span>';
		echo '<div class="sit-wcpg-settings__text">';
		echo '<h3 class="sit-wcpg-settings__title">' . esc_html( $label ) . '</h3>';

		$description = $rule->get_description();

		if ( '' !== $description ) {
			echo '<p class="sit-wcpg-settings__description">' . esc_html( $description ) . '</p>';
		}

		echo '</div><div class="sit-wcpg-settings__controls">';

		$severities = array();

		foreach ( Severity::all() as $candidate ) {
			$severities[ $candidate ] = Severity::label( $candidate );
		}

		$this->render_segmented(
			array( 'rules', $rule_id, 'severity' ),
			$severities,
			$state['severity'],
			/* translators: %s: the name of the check. */
			sprintf( __( 'Severity of the %s check', 'sapphireit-publish-guard' ), $label ),
			true
		);

		$this->render_checkbox(
			array( 'rules', $rule_id, 'enabled' ),
			$state['enabled'],
			/* translators: %s: the name of the check being enabled. */
			sprintf( __( 'Run the %s check', 'sapphireit-publish-guard' ), $label )
		);

		echo '</div>';

		foreach ( self::THRESHOLD_RULES as $threshold => $owner ) {
			if ( $owner === $rule_id && isset( Settings::threshold_limits()[ $threshold ] ) ) {
				$this->render_threshold_field( $threshold, $values );
			}
		}

		echo '</div>';
	}

	/**
	 * Render one threshold as an inline number field.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key    Threshold key.
	 * @param array  $values Current settings.
	 * @return void
	 */
	private function render_threshold_field( string $key, array $values ): void {
		$limits   = Settings::threshold_limits()[ $key ];
		$field_id = self::threshold_field_id( $key );
		$copy     = $this->threshold_copy( $key );
		$ids      = array();

		echo '<div class="sit-wcpg-settings__threshold">';
		echo '<label class="sit-wcpg-settings__threshold-label" for="' . esc_attr( $field_id ) . '">' . esc_html( Settings::threshold_label( $key ) ) . '</label>';
		echo '<span class="sit-wcpg-settings__number">';

		if ( '' !== $copy['unit'] ) {
			$ids[] = $field_id . '-unit';
		}

		if ( '' !== $copy['hint'] ) {
			$ids[] = $field_id . '-hint';
		}

		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"%6$s />',
			esc_attr( $field_id ),
			esc_attr( $this->field_name( array( 'thresholds', $key ) ) ),
			(int) ( $values['thresholds'][ $key ] ?? 0 ),
			(int) $limits['min'],
			(int) $limits['max'],
			array() !== $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : ''
		);

		if ( '' !== $copy['unit'] ) {
			echo '<span class="sit-wcpg-settings__unit" id="' . esc_attr( $field_id . '-unit' ) . '">' . esc_html( $copy['unit'] ) . '</span>';
		}

		echo '</span>';

		if ( '' !== $copy['hint'] ) {
			echo '<span class="sit-wcpg-settings__hint" id="' . esc_attr( $field_id . '-hint' ) . '">' . esc_html( $copy['hint'] ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * A threshold's unit and hint.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Threshold key.
	 * @return array{unit: string, hint: string}
	 */
	private function threshold_copy( string $key ): array {
		switch ( $key ) {
			case 'min_description_chars':
			case 'min_short_description_chars':
				return array(
					'unit' => __( 'characters', 'sapphireit-publish-guard' ),
					'hint' => __( 'Set to 0 to turn the length warning off.', 'sapphireit-publish-guard' ),
				);
			case 'min_images':
				return array(
					'unit' => __( 'images', 'sapphireit-publish-guard' ),
					'hint' => __( 'Counts the featured image. Set to 0 or 1 to turn this check off.', 'sapphireit-publish-guard' ),
				);
			default:
				return array(
					'unit' => '',
					'hint' => '',
				);
		}
	}

	/**
	 * Render the thresholds whose rule has no row.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $keys   Threshold keys.
	 * @param array    $values Current settings.
	 * @return void
	 */
	private function render_thresholds_card( array $keys, array $values ): void {
		self::open_card( 'thresholds', __( 'Thresholds', 'sapphireit-publish-guard' ), '' );

		echo '</div><div class="sit-wcpg-settings__rows">';

		foreach ( $keys as $key ) {
			echo '<div class="sit-wcpg-settings__row sit-wcpg-settings__row--field">';
			$this->render_threshold_field( $key, $values );
			echo '</div>';
		}

		echo '</div></section>';
	}

	/**
	 * Render the Publishing card: enforcement, its scope and the products-list column.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_publishing_card( array $values ): void {
		$scope_labels = array(
			Settings::SCOPE_AUTHENTICATED => __( 'All signed-in requests', 'sapphireit-publish-guard' ),
			Settings::SCOPE_EDITOR        => __( 'Admin editor screens only', 'sapphireit-publish-guard' ),
		);

		$scopes = array();

		foreach ( Settings::scopes() as $scope ) {
			$scopes[ $scope ] = $scope_labels[ $scope ] ?? $scope;
		}

		self::open_card( 'publishing', __( 'Publishing', 'sapphireit-publish-guard' ), __( 'What happens when a product fails a required check.', 'sapphireit-publish-guard' ) );

		echo '</div><div class="sit-wcpg-settings__rows">';

		$this->render_switch_row(
			array( 'publishing', 'block_on_required_failure' ),
			(bool) $values['publishing']['block_on_required_failure'],
			'shield',
			__( 'Prevent publishing when required checks fail', 'sapphireit-publish-guard' ),
			__( 'Enforced on the server, so it also covers quick edit, bulk edit and the REST API.', 'sapphireit-publish-guard' )
		);

		$this->render_switch_row(
			array( 'publishing', 'allow_admin_override' ),
			(bool) $values['publishing']['allow_admin_override'],
			'key',
			__( 'Allow users who can manage WooCommerce to publish anyway', 'sapphireit-publish-guard' ),
			__( 'Off by default, which means nobody can publish past a failing required check.', 'sapphireit-publish-guard' )
		);

		echo '<div class="sit-wcpg-settings__row">';
		echo '<span class="sit-wcpg-settings__icon">' . wp_kses( Icons::svg( 'store', 20 ), Icons::allowed_html() ) . '</span>';
		echo '<div class="sit-wcpg-settings__text">';
		echo '<p class="sit-wcpg-settings__title">' . esc_html__( 'Enforcement applies to', 'sapphireit-publish-guard' ) . '</p>';
		echo '<p class="sit-wcpg-settings__description">' . esc_html__( 'Scheduled publishing run by WP-Cron and requests made through WP-CLI are always excluded.', 'sapphireit-publish-guard' ) . '</p>';
		echo '</div><div class="sit-wcpg-settings__controls">';

		$this->render_segmented(
			array( 'publishing', 'enforce_scope' ),
			$scopes,
			(string) $values['publishing']['enforce_scope'],
			__( 'Enforcement applies to', 'sapphireit-publish-guard' )
		);

		echo '</div></div>';

		$this->render_switch_row(
			array( 'product_list', 'show_column' ),
			(bool) $values['product_list']['show_column'],
			'fields',
			__( 'Show the Readiness column in the products list', 'sapphireit-publish-guard' ),
			''
		);

		echo '</div></section>';
	}

	/**
	 * Render a row whose visible title labels its switch.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $keys        Key path inside the option, outermost first.
	 * @param bool     $checked     Whether the switch is on.
	 * @param string   $icon        Icon name.
	 * @param string   $title       Translated label.
	 * @param string   $description Translated help text, or ''.
	 * @return void
	 */
	private function render_switch_row( array $keys, bool $checked, string $icon, string $title, string $description ): void {
		$id = $this->field_id( $keys );

		echo '<div class="sit-wcpg-settings__row">';
		echo '<span class="sit-wcpg-settings__icon">' . wp_kses( Icons::svg( $icon, 20 ), Icons::allowed_html() ) . '</span>';
		echo '<div class="sit-wcpg-settings__text">';
		echo '<label class="sit-wcpg-settings__title" for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';

		if ( '' !== $description ) {
			echo '<p class="sit-wcpg-settings__description" id="' . esc_attr( $id . '-description' ) . '">' . esc_html( $description ) . '</p>';
		}

		echo '</div><div class="sit-wcpg-settings__controls">';

		$this->render_checkbox( $keys, $checked, '', '' !== $description ? $id . '-description' : '' );

		echo '</div></div>';
	}

	/**
	 * Say whether the products-list filter is up to date, in an info banner.
	 *
	 * Left out while the checklist is switched off: nothing is checked then, so every
	 * status line would be untrue.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_readiness_banner(): void {
		$id = self::section_id( 'readiness' );

		echo '<section class="sit-wcpg-ui-banner sit-wcpg-ui-banner--info sit-wcpg-settings__section" id="' . esc_attr( $id ) . '" data-sit-wcpg-section aria-labelledby="' . esc_attr( $id . '-title' ) . '">';
		echo wp_kses( Icons::svg( 'info', 20 ), Icons::allowed_html() );
		echo '<div class="sit-wcpg-ui-banner__body">';
		echo '<h2 class="sit-wcpg-ui-banner__title" id="' . esc_attr( $id . '-title' ) . '">' . esc_html__( 'Readiness filter', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<p>' . esc_html( $this->readiness_status_line() ) . '</p>';
		echo '</div></section>';
	}

	/**
	 * The sentence describing the stored readiness flags.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function readiness_status_line(): string {
		$status = $this->rebuild()->status();

		if ( $status['is_current'] ) {
			return sprintf(
				/* translators: %d: number of products. */
				__( 'All %d products have been checked against these settings.', 'sapphireit-publish-guard' ),
				$status['total']
			);
		}

		if ( ! $status['scheduler'] ) {
			return __( 'Background rechecks need WooCommerce\'s Action Scheduler, which isn\'t available. Run "wp sit-wcpg reindex" to update the products list filter.', 'sapphireit-publish-guard' );
		}

		if ( Readiness_Rebuild::STATE_BUILDING === $status['state'] ) {
			return sprintf(
				/* translators: 1: products rechecked so far, 2: products to recheck in total. */
				__( 'Rechecking products against these settings: %1$d of %2$d done.', 'sapphireit-publish-guard' ),
				$status['done'],
				$status['total']
			);
		}

		return __( 'Products will be rechecked against these settings in the background.', 'sapphireit-publish-guard' );
	}

	/**
	 * Build the form field name for a path inside the option.
	 *
	 * Every field on this screen posts inside `sit_wcpg_settings[…]`, which is what makes
	 * the whole form arrive at the sanitizer as one array.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $keys Key path, outermost first.
	 * @return string
	 */
	private function field_name( array $keys ): string {
		$name = Settings::OPTION_NAME;

		foreach ( $keys as $key ) {
			$name .= '[' . $key . ']';
		}

		return $name;
	}

	/**
	 * Build the HTML id for a path inside the option: `sit-wcpg-field-rules-sku-enabled`.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $keys Key path, outermost first.
	 * @return string
	 */
	private function field_id( array $keys ): string {
		$parts = array_map(
			static function ( string $key ): string {
				return str_replace( '_', '-', sanitize_key( $key ) );
			},
			$keys
		);

		return 'sit-wcpg-field-' . implode( '-', $parts );
	}

	/**
	 * Render one checkbox as a switch (`role="switch"`, U5).
	 *
	 * The old plain-checkbox form is gone: no field on the screen uses it any more.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Rendered as a switch. `$label` may be empty when the caller prints a
	 *              visible `<label for>`; `$description` became `$described_by`.
	 *
	 * @param string[] $keys         Key path inside the option, outermost first.
	 * @param bool     $checked      Whether the switch is on.
	 * @param string   $label        Translated label, visually hidden; '' when the caller
	 *                               labels the switch.
	 * @param string   $described_by Id of the element describing it, or ''.
	 * @return void
	 */
	private function render_checkbox( array $keys, bool $checked, string $label, string $described_by = '' ): void {
		$id = $this->field_id( $keys );

		echo '<span class="sit-wcpg-ui-switch">';
		echo '<input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->field_name( $keys ) ) . '" value="1"';

		if ( '' !== $described_by ) {
			echo ' aria-describedby="' . esc_attr( $described_by ) . '"';
		}

		checked( $checked );
		echo ' />';

		if ( '' !== $label ) {
			echo '<label class="sit-wcpg-ui-switch__label screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}

		echo '</span>';
	}

	/**
	 * Render a set of radios as a segmented control (U5).
	 *
	 * @since 1.2.0
	 *
	 * @param string[]              $keys    Key path inside the option, outermost first.
	 * @param array<string, string> $options Value => translated label.
	 * @param string                $current The currently stored value.
	 * @param string                $legend  Translated legend, visually hidden.
	 * @param bool                  $toned   Whether each option carries its value as a tone
	 *                                       modifier (Required / Warning colours).
	 * @return void
	 */
	private function render_segmented( array $keys, array $options, string $current, string $legend, bool $toned = false ): void {
		echo '<fieldset class="sit-wcpg-ui-segmented">';
		echo '<legend class="sit-wcpg-ui-segmented__legend">' . esc_html( $legend ) . '</legend>';

		foreach ( $options as $value => $label ) {
			$this->render_radio( $keys, (string) $value, $current, $label, $toned );
		}

		echo '</fieldset>';
	}

	/**
	 * Render one radio button as a segment, with its label.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Rendered as a segment; added `$toned`.
	 *
	 * @param string[] $keys    Key path inside the option, outermost first.
	 * @param string   $value   This button's value.
	 * @param string   $current The currently stored value.
	 * @param string   $label   Translated label.
	 * @param bool     $toned   Whether to add the value as a tone modifier.
	 * @return void
	 */
	private function render_radio( array $keys, string $value, string $current, string $label, bool $toned = false ): void {
		$id    = $this->field_id( $keys ) . '-' . sanitize_html_class( $value );
		$class = 'sit-wcpg-ui-segmented__input' . ( $toned ? ' sit-wcpg-ui-segmented__input--' . sanitize_html_class( $value ) : '' );

		echo '<input type="radio" class="' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->field_name( $keys ) ) . '"';
		echo ' value="' . esc_attr( $value ) . '"';
		checked( $value, $current );
		echo ' />';
		echo '<label class="sit-wcpg-ui-segmented__option" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	}

	/**
	 * The rules the form builds its rows from.
	 *
	 * @since 1.0.0
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
