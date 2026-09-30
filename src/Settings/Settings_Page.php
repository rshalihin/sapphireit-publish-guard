<?php
/**
 * The `WooCommerce → Product Checklist` settings screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Settings;

use ProductPublishGuard\Engine\Rule_Interface;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Plugin;

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
	 * Construct the page.
	 *
	 * The registry is resolved on first use, so the rule list is built only on this
	 * screen and on a save — not on every other admin request that merely loads the menu.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings           $settings The merchant's configuration.
	 * @param Rule_Registry|null $registry Rules to list. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Rule_Registry $registry = null ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	/**
	 * Hook the page into the admin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'filter_capability' ) );
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
	 * @since 1.0.0
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

		$values = $this->settings->get_all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Product Checklist', 'sapphireit-publish-guard' ) . '</h1>';

		settings_errors();

		echo '<form method="post" action="options.php">';

		settings_fields( self::OPTION_GROUP );

		$this->render_master_switch( $values );
		$this->render_rules_section( $values );
		$this->render_thresholds_section( $values );
		$this->render_publishing_section( $values );

		submit_button();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the single switch that turns the whole checklist off.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_master_switch( array $values ): void {
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
		echo esc_html__( 'Checklist', 'sapphireit-publish-guard' );
		echo '</th><td>';

		$this->render_checkbox(
			array( 'enabled' ),
			(bool) $values['enabled'],
			__( 'Enable the product readiness checklist', 'sapphireit-publish-guard' ),
			__( 'When this is off, no checks run and nothing is enforced.', 'sapphireit-publish-guard' )
		);

		echo '</td></tr></tbody></table>';
	}

	/**
	 * Render the per-rule enable and severity table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_rules_section( array $values ): void {
		echo '<h2>' . esc_html__( 'Rules', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<p class="description">';
		echo esc_html__( 'A required check must pass before a product can be published. A warning is advisory and never blocks publishing.', 'sapphireit-publish-guard' );
		echo '</p>';

		$rules = $this->registry()->all();

		if ( empty( $rules ) ) {
			echo '<p>' . esc_html__( 'No checks are registered.', 'sapphireit-publish-guard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped sit-wcpg-settings-rules"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Check', 'sapphireit-publish-guard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Enabled', 'sapphireit-publish-guard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Severity', 'sapphireit-publish-guard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $rule ) {
			$this->render_rule_row( $rule, $values );
		}

		echo '</tbody></table>';
	}

	/**
	 * Render one rule's row.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Interface $rule   The rule to render.
	 * @param array          $values Current settings.
	 * @return void
	 */
	private function render_rule_row( Rule_Interface $rule, array $values ): void {
		$rule_id  = $rule->get_id();
		$stored   = $values['rules'][ $rule_id ] ?? array();
		$enabled  = (bool) ( $stored['enabled'] ?? $rule->is_enabled_by_default() );
		$severity = (string) ( $stored['severity'] ?? $rule->get_default_severity() );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $rule->get_label() ) . '</strong>';

		$description = $rule->get_description();

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td>';

		echo '<td>';
		$this->render_checkbox(
			array( 'rules', $rule_id, 'enabled' ),
			$enabled,
			/* translators: %s: the name of the check being enabled. */
			sprintf( __( 'Run the %s check', 'sapphireit-publish-guard' ), $rule->get_label() )
		);
		echo '</td>';

		echo '<td><fieldset>';

		foreach ( Severity::all() as $candidate ) {
			$this->render_radio( array( 'rules', $rule_id, 'severity' ), $candidate, $severity, Severity::label( $candidate ) );
		}

		echo '</fieldset></td>';
		echo '</tr>';
	}

	/**
	 * Render the content threshold fields.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_thresholds_section( array $values ): void {
		echo '<h2>' . esc_html__( 'Content thresholds', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$hints = array(
			'min_description_chars'       => __( 'Characters. Set to 0 to turn the length warning off.', 'sapphireit-publish-guard' ),
			'min_short_description_chars' => __( 'Characters. Set to 0 to turn the length warning off.', 'sapphireit-publish-guard' ),
			'min_images'                  => __( 'Images, counting the featured image. Set to 0 or 1 to turn this check off.', 'sapphireit-publish-guard' ),
		);

		foreach ( Settings::threshold_limits() as $key => $limits ) {
			$field_id = 'sit-wcpg-threshold-' . str_replace( '_', '-', (string) $key );

			echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">';
			echo esc_html( Settings::threshold_label( (string) $key ) );
			echo '</label></th><td>';

			printf(
				'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" />',
				esc_attr( $field_id ),
				esc_attr( $this->field_name( array( 'thresholds', (string) $key ) ) ),
				(int) ( $values['thresholds'][ $key ] ?? 0 ),
				(int) $limits['min'],
				(int) $limits['max']
			);

			echo ' <span class="description">' . esc_html( $hints[ $key ] ?? '' ) . '</span>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the publishing and products-list fields.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Current settings.
	 * @return void
	 */
	private function render_publishing_section( array $values ): void {
		$scope_labels = array(
			Settings::SCOPE_AUTHENTICATED => __( 'All signed-in requests', 'sapphireit-publish-guard' ),
			Settings::SCOPE_EDITOR        => __( 'Admin editor screens only', 'sapphireit-publish-guard' ),
		);

		echo '<h2>' . esc_html__( 'Publishing', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Enforcement', 'sapphireit-publish-guard' ) . '</th><td><fieldset>';

		$this->render_checkbox(
			array( 'publishing', 'block_on_required_failure' ),
			(bool) $values['publishing']['block_on_required_failure'],
			__( 'Prevent publishing when required checks fail', 'sapphireit-publish-guard' ),
			__( 'Enforced on the server, so it also covers quick edit, bulk edit and the REST API.', 'sapphireit-publish-guard' )
		);

		$this->render_checkbox(
			array( 'publishing', 'allow_admin_override' ),
			(bool) $values['publishing']['allow_admin_override'],
			__( 'Allow users who can manage WooCommerce to publish anyway', 'sapphireit-publish-guard' ),
			__( 'Off by default, which means nobody can publish past a failing required check.', 'sapphireit-publish-guard' )
		);

		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Enforcement applies to', 'sapphireit-publish-guard' ) . '</th><td><fieldset>';

		foreach ( Settings::scopes() as $scope ) {
			$this->render_radio(
				array( 'publishing', 'enforce_scope' ),
				$scope,
				(string) $values['publishing']['enforce_scope'],
				$scope_labels[ $scope ] ?? $scope
			);
		}

		echo '<p class="description">';
		echo esc_html__( 'Scheduled publishing run by WP-Cron and requests made through WP-CLI are always excluded.', 'sapphireit-publish-guard' );
		echo '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Products list', 'sapphireit-publish-guard' ) . '</th><td>';

		$this->render_checkbox(
			array( 'product_list', 'show_column' ),
			(bool) $values['product_list']['show_column'],
			__( 'Show the Readiness column in the products list', 'sapphireit-publish-guard' )
		);

		echo '</td></tr>';
		echo '</tbody></table>';
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
	 * Render one checkbox with its label and optional help text.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $keys        Key path inside the option, outermost first.
	 * @param bool     $checked     Whether the box is ticked.
	 * @param string   $label       Translated label.
	 * @param string   $description Optional translated help text.
	 * @return void
	 */
	private function render_checkbox( array $keys, bool $checked, string $label, string $description = '' ): void {
		echo '<label><input type="checkbox" name="' . esc_attr( $this->field_name( $keys ) ) . '" value="1"';
		checked( $checked );
		echo ' /> ' . esc_html( $label ) . '</label>';

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Render one radio button with its label.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $keys    Key path inside the option, outermost first.
	 * @param string   $value   This button's value.
	 * @param string   $current The currently stored value.
	 * @param string   $label   Translated label.
	 * @return void
	 */
	private function render_radio( array $keys, string $value, string $current, string $label ): void {
		echo '<label class="sit-wcpg-settings-choice"><input type="radio" name="' . esc_attr( $this->field_name( $keys ) ) . '"';
		echo ' value="' . esc_attr( $value ) . '"';
		checked( $value, $current );
		echo ' /> ' . esc_html( $label ) . '</label> ';
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
}
