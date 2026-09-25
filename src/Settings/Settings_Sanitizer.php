<?php
/**
 * Turns a submitted settings payload into a trustworthy settings array.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Settings;

use ProductPublishGuard\Engine\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the `sit_wcpg_settings` option.
 *
 * Registered as `register_setting()`'s sanitize callback, which also makes it the
 * `sanitize_option_sit_wcpg_settings` filter — so it runs for every `update_option()` on
 * that key, not only for form posts.
 *
 * The output array is **built from the defaults**, never filtered down from the input.
 * Nothing the caller did not ask about can survive: unknown top-level keys, unknown rule
 * identifiers and an injected `version` are all simply never copied across.
 *
 * @since 1.0.0
 */
final class Settings_Sanitizer {

	/**
	 * Where the defaults, the rule whitelist and the threshold ranges come from.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the sanitizer.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings Settings facade supplying the defaults.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Normalize a submitted settings payload.
	 *
	 * The parameter is deliberately untyped. Core hands this callback whatever was
	 * posted under the option name, and a crafted request can make that a string; a
	 * typed parameter would turn that into a fatal error on the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Submitted value.
	 * @return array The complete, normalized settings array.
	 */
	public function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = $this->settings->get_defaults();

		return array(
			// Never taken from input: the version describes the code, not the request.
			'version'      => Settings::VERSION,
			'enabled'      => $this->flag( $raw, 'enabled' ),
			'rules'        => $this->sanitize_rules( $this->sub_array( $raw, 'rules' ), $defaults['rules'] ),
			'thresholds'   => $this->sanitize_thresholds( $this->sub_array( $raw, 'thresholds' ), $defaults['thresholds'] ),
			'publishing'   => $this->sanitize_publishing( $this->sub_array( $raw, 'publishing' ), $defaults['publishing'] ),
			'product_list' => array(
				'show_column' => $this->flag( $this->sub_array( $raw, 'product_list' ), 'show_column' ),
			),
		);
	}

	/**
	 * Normalize the per-rule settings.
	 *
	 * Iteration is over the *defaults*, which are keyed by the registry's rule
	 * identifiers. A submitted identifier the registry does not know is therefore never
	 * read and never stored.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw      Submitted rules sub-array.
	 * @param array $defaults Default rules sub-array, keyed by rule identifier.
	 * @return array
	 */
	private function sanitize_rules( array $raw, array $defaults ): array {
		$clean = array();

		foreach ( $defaults as $rule_id => $rule_defaults ) {
			$submitted = $this->sub_array( $raw, (string) $rule_id );
			$severity  = isset( $submitted['severity'] ) && is_scalar( $submitted['severity'] )
				? sanitize_key( (string) $submitted['severity'] )
				: '';

			$clean[ $rule_id ] = array(
				'enabled'  => $this->flag( $submitted, 'enabled' ),
				'severity' => Severity::is_valid( $severity ) ? $severity : $rule_defaults['severity'],
			);
		}

		return $clean;
	}

	/**
	 * Normalize and clamp the content thresholds.
	 *
	 * A value outside the accepted range is clamped rather than rejected — the merchant
	 * still gets a saved, usable setting — but a notice says what the stored value is,
	 * so the screen never silently disagrees with what was typed.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw      Submitted thresholds sub-array.
	 * @param array $defaults Default thresholds.
	 * @return array<string, int>
	 */
	private function sanitize_thresholds( array $raw, array $defaults ): array {
		$clean = array();

		foreach ( Settings::threshold_limits() as $key => $limits ) {
			$fallback = (int) ( $defaults[ $key ] ?? 0 );

			if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
				$clean[ $key ] = $fallback;

				continue;
			}

			$value   = absint( $raw[ $key ] );
			$clamped = min( $limits['max'], max( $limits['min'], $value ) );

			if ( $clamped !== $value ) {
				$this->report_clamp( (string) $key, $clamped );
			}

			$clean[ $key ] = $clamped;
		}

		return $clean;
	}

	/**
	 * Normalize the publishing settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw      Submitted publishing sub-array.
	 * @param array $defaults Default publishing settings.
	 * @return array
	 */
	private function sanitize_publishing( array $raw, array $defaults ): array {
		$scope = isset( $raw['enforce_scope'] ) && is_scalar( $raw['enforce_scope'] )
			? sanitize_key( (string) $raw['enforce_scope'] )
			: '';

		return array(
			'block_on_required_failure' => $this->flag( $raw, 'block_on_required_failure' ),
			'allow_admin_override'      => $this->flag( $raw, 'allow_admin_override' ),
			'enforce_scope'             => in_array( $scope, Settings::scopes(), true ) ? $scope : $defaults['enforce_scope'],
		);
	}

	/**
	 * Read a checkbox.
	 *
	 * An unchecked checkbox is not posted at all, so "absent" has to mean off — which
	 * is also why the form must render every checkbox the settings array contains.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $source Submitted sub-array.
	 * @param string $key    Key to read.
	 * @return bool
	 */
	private function flag( array $source, string $key ): bool {
		return isset( $source[ $key ] ) && ! in_array( $source[ $key ], array( '', '0', 0, false ), true );
	}

	/**
	 * Read a nested array from submitted data.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $source Submitted data.
	 * @param string $key    Key to read.
	 * @return array Empty array when the key is absent or is not an array.
	 */
	private function sub_array( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
	}

	/**
	 * Tell the merchant that a threshold was moved into range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Threshold key.
	 * @param int    $value The value that was stored.
	 * @return void
	 */
	private function report_clamp( string $key, int $value ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		add_settings_error(
			Settings::OPTION_NAME,
			'sit_wcpg_threshold_clamped_' . $key,
			sprintf(
				/* translators: 1: threshold setting label, 2: the value that was stored instead. */
				__( '%1$s was outside the allowed range and has been saved as %2$d.', 'product-publish-guard' ),
				Settings::threshold_label( $key ),
				$value
			),
			'warning'
		);
	}
}
