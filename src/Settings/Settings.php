<?php
/**
 * Typed, cached access to the plugin's single option.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Settings;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the merchant has configured, read once per request.
 *
 * The whole configuration lives in one autoloaded option, so reading it costs nothing
 * beyond the options cache. Stored values are laid over `get_defaults()` rather than
 * trusted wholesale: the rule list comes from the registry, so a rule registered by a
 * later version — or by another plugin — is configured correctly before the merchant
 * has ever opened the settings screen.
 *
 * Deliberately not final: the engine's tests build mocks of this class.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * The one option this plugin stores.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_NAME = 'sit_wcpg_settings';

	/**
	 * Schema version of the stored array. Written by the code, never read from input.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Enforcement scope: every request made by a signed-in user.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SCOPE_AUTHENTICATED = 'authenticated';

	/**
	 * Enforcement scope: only recognised admin save payloads.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SCOPE_EDITOR = 'editor';

	/**
	 * Content thresholds before the merchant changes them.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	private const THRESHOLD_DEFAULTS = array(
		'min_description_chars'       => 150,
		'min_short_description_chars' => 50,
		'min_images'                  => 2,
	);

	/**
	 * Accepted range for each threshold.
	 *
	 * The upper bounds exist so a mistyped value cannot turn an entire catalogue red,
	 * and so the checklist never advertises a target no real product could meet.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, int>>
	 */
	private const THRESHOLD_LIMITS = array(
		'min_description_chars'       => array(
			'min' => 0,
			'max' => 10000,
		),
		'min_short_description_chars' => array(
			'min' => 0,
			'max' => 5000,
		),
		'min_images'                  => array(
			'min' => 0,
			'max' => 20,
		),
	);

	/**
	 * Publishing behaviour before the merchant changes it.
	 *
	 * @since 1.0.0
	 * @var array<string, bool|string>
	 */
	private const PUBLISHING_DEFAULTS = array(
		'block_on_required_failure' => true,
		'allow_admin_override'      => false,
		'enforce_scope'             => self::SCOPE_AUTHENTICATED,
	);

	/**
	 * Products-list behaviour before the merchant changes it.
	 *
	 * @since 1.0.0
	 * @var array<string, bool>
	 */
	private const PRODUCT_LIST_DEFAULTS = array(
		'show_column' => true,
	);

	/**
	 * The rules the defaults are derived from.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry;

	/**
	 * The normalized settings, or null while they have not been read yet.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private ?array $values = null;

	/**
	 * Construct the facade.
	 *
	 * The registry is resolved on first use rather than injected eagerly, so a request
	 * that only asks whether publishing is blocked never builds the rule list.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry|null $registry Rules to derive defaults from. Null resolves the plugin's own.
	 */
	public function __construct( ?Rule_Registry $registry = null ) {
		$this->registry = $registry;
	}

	/**
	 * Every valid enforcement scope.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		return array( self::SCOPE_AUTHENTICATED, self::SCOPE_EDITOR );
	}

	/**
	 * Accepted range for each threshold, keyed by threshold name.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function threshold_limits(): array {
		return self::THRESHOLD_LIMITS;
	}

	/**
	 * Translated label for a threshold, used on the settings screen and in its notices.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Threshold key.
	 * @return string Empty string for an unknown key.
	 */
	public static function threshold_label( string $key ): string {
		switch ( $key ) {
			case 'min_description_chars':
				return __( 'Minimum description length', 'sapphireit-publish-guard' );
			case 'min_short_description_chars':
				return __( 'Minimum short description length', 'sapphireit-publish-guard' );
			case 'min_images':
				return __( 'Minimum number of images', 'sapphireit-publish-guard' );
			default:
				return '';
		}
	}

	/**
	 * The settings a site gets before it has ever saved the form.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_defaults(): array {
		$rules = array();

		foreach ( $this->registry()->all() as $rule ) {
			$severity = $rule->get_default_severity();

			$rules[ $rule->get_id() ] = array(
				'enabled'  => $rule->is_enabled_by_default(),
				'severity' => Severity::is_valid( $severity ) ? $severity : Severity::REQUIRED,
			);
		}

		return array(
			'version'      => self::VERSION,
			'enabled'      => true,
			'rules'        => $rules,
			'thresholds'   => self::THRESHOLD_DEFAULTS,
			'publishing'   => self::PUBLISHING_DEFAULTS,
			'product_list' => self::PRODUCT_LIST_DEFAULTS,
		);
	}

	/**
	 * The whole normalized settings array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_all(): array {
		if ( null === $this->values ) {
			$this->values = $this->build();
		}

		return $this->values;
	}

	/**
	 * Drop the memo so the next read goes back to the option.
	 *
	 * Only useful when the option is written inside the same request, which happens on
	 * the settings screen and in the tests.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->values = null;
	}

	/**
	 * Whether the checklist is enabled at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->get_all()['enabled'];
	}

	/**
	 * Whether a rule is enabled.
	 *
	 * An unknown identifier is disabled: a rule that is not registered cannot run, and
	 * answering false here stops a stale stored key from resurrecting anything.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @return bool
	 */
	public function rule_is_enabled( string $rule_id ): bool {
		return (bool) ( $this->get_all()['rules'][ $rule_id ]['enabled'] ?? false );
	}

	/**
	 * Configured severity for a rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @return string One of the Severity constants.
	 */
	public function rule_severity( string $rule_id ): string {
		$severity = $this->get_all()['rules'][ $rule_id ]['severity'] ?? '';

		return is_string( $severity ) && Severity::is_valid( $severity ) ? $severity : Severity::REQUIRED;
	}

	/**
	 * Configured content threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Threshold key.
	 * @return int Zero for an unknown key, which every rule reads as "no threshold".
	 */
	public function threshold( string $key ): int {
		return (int) ( $this->get_all()['thresholds'][ $key ] ?? 0 );
	}

	/**
	 * Whether publishing is blocked on a required failure.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function blocks_publishing(): bool {
		return (bool) $this->get_all()['publishing']['block_on_required_failure'];
	}

	/**
	 * Whether a capable user may publish past a required failure.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function allows_admin_override(): bool {
		return (bool) $this->get_all()['publishing']['allow_admin_override'];
	}

	/**
	 * Which requests enforcement applies to.
	 *
	 * @since 1.0.0
	 *
	 * @return string One of the SCOPE_* constants.
	 */
	public function enforcement_scope(): string {
		return (string) $this->get_all()['publishing']['enforce_scope'];
	}

	/**
	 * Whether the products list shows the readiness column.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function shows_list_column(): bool {
		return (bool) $this->get_all()['product_list']['show_column'];
	}

	/**
	 * Hash of the normalized settings, used in cache keys.
	 *
	 * Any change to any value changes the hash, which is what makes a cached checklist
	 * expire the moment the merchant changes a threshold or a severity.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_hash(): string {
		return md5( (string) wp_json_encode( $this->get_all() ) );
	}

	/**
	 * The rules the defaults are derived from.
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
	 * Lay the stored option over the defaults.
	 *
	 * The option is sanitized on write, so this is the second line of defence rather
	 * than the first: it protects against an option written before a rule existed, one
	 * written directly by another plugin, and one edited by hand in the database.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function build(): array {
		$defaults = $this->get_defaults();
		$stored   = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$values = $defaults;

		$values['enabled'] = $this->stored_bool( $stored, 'enabled', $defaults['enabled'] );

		foreach ( $defaults['rules'] as $rule_id => $rule_defaults ) {
			$rule_values = $stored['rules'][ $rule_id ] ?? array();

			if ( ! is_array( $rule_values ) ) {
				$rule_values = array();
			}

			$severity = $rule_values['severity'] ?? '';

			$values['rules'][ $rule_id ] = array(
				'enabled'  => $this->stored_bool( $rule_values, 'enabled', $rule_defaults['enabled'] ),
				'severity' => is_string( $severity ) && Severity::is_valid( $severity ) ? $severity : $rule_defaults['severity'],
			);
		}

		$thresholds = is_array( $stored['thresholds'] ?? null ) ? $stored['thresholds'] : array();

		foreach ( $defaults['thresholds'] as $key => $threshold_default ) {
			$values['thresholds'][ $key ] = isset( $thresholds[ $key ] ) && is_numeric( $thresholds[ $key ] )
				? max( 0, (int) $thresholds[ $key ] )
				: $threshold_default;
		}

		$publishing = is_array( $stored['publishing'] ?? null ) ? $stored['publishing'] : array();
		$scope      = $publishing['enforce_scope'] ?? '';

		$values['publishing'] = array(
			'block_on_required_failure' => $this->stored_bool( $publishing, 'block_on_required_failure', $defaults['publishing']['block_on_required_failure'] ),
			'allow_admin_override'      => $this->stored_bool( $publishing, 'allow_admin_override', $defaults['publishing']['allow_admin_override'] ),
			'enforce_scope'             => is_string( $scope ) && in_array( $scope, self::scopes(), true ) ? $scope : $defaults['publishing']['enforce_scope'],
		);

		$product_list = is_array( $stored['product_list'] ?? null ) ? $stored['product_list'] : array();

		$values['product_list'] = array(
			'show_column' => $this->stored_bool( $product_list, 'show_column', $defaults['product_list']['show_column'] ),
		);

		// The schema version describes the code that reads the array, so it is never taken from storage.
		$values['version'] = self::VERSION;

		return $values;
	}

	/**
	 * Read a boolean from stored data, falling back when the key is absent.
	 *
	 * Absent means "never configured" and takes the default; present means the merchant
	 * has made a choice, including the choice to switch something off.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $source   Stored sub-array.
	 * @param string $key      Key to read.
	 * @param bool   $fallback Value to use when the key is absent.
	 * @return bool
	 */
	private function stored_bool( array $source, string $key, bool $fallback ): bool {
		return array_key_exists( $key, $source ) ? (bool) $source[ $key ] : $fallback;
	}
}
