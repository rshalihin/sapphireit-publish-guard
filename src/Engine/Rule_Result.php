<?php
/**
 * The outcome of a single rule.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object describing one checklist row.
 *
 * A rule reports an *outcome* (pass / warn / fail / skip). The engine later stamps the
 * merchant's configured severity onto the result with `with_severity()`, which derives
 * the final status from the matrix in coding-plan.md section 5.5.
 *
 * The `data` payload is restricted to integers, floats and booleans by the constructor.
 * That is the structural mitigation for stored XSS (coding-plan.md section 9.5): no
 * product-supplied text can reach the checklist UI, the admin notices or the REST
 * response, because the value object will not carry it.
 *
 * @since 1.0.0
 */
final class Rule_Result {

	/**
	 * Identifier of the rule that produced this result.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $rule_id;

	/**
	 * What the rule reported, before severity is applied. One of the Status constants.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $outcome;

	/**
	 * The displayed status, derived from the outcome and the configured severity.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $status;

	/**
	 * The configured severity this result was evaluated under.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $severity;

	/**
	 * Translated rule label.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $label;

	/**
	 * Translated, literal message. Never contains product-supplied text.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $message;

	/**
	 * Rule group: content, media, pricing, organization or inventory.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $group;

	/**
	 * Numeric and boolean context for the UI.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $data;

	/**
	 * Where the merchant should go to fix this, or an empty array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $fix;

	/**
	 * Build a result. Use the four static factories instead.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id  Rule identifier.
	 * @param string $outcome  One of the Status constants.
	 * @param string $severity Configured severity.
	 * @param string $label    Translated rule label.
	 * @param string $group    Rule group.
	 * @param string $message  Translated, literal message.
	 * @param array  $data     Numeric and boolean context.
	 * @param array  $fix      Fix target descriptor.
	 */
	private function __construct(
		string $rule_id,
		string $outcome,
		string $severity,
		string $label,
		string $group,
		string $message,
		array $data,
		array $fix
	) {
		$this->rule_id  = $rule_id;
		$this->outcome  = Status::is_valid( $outcome ) ? $outcome : Status::SKIPPED;
		$this->severity = Severity::is_valid( $severity ) ? $severity : Severity::REQUIRED;
		$this->status   = self::derive_status( $this->outcome, $this->severity );
		$this->label    = $label;
		$this->group    = $group;
		$this->message  = $message;
		$this->data     = self::filter_data( $data );
		$this->fix      = self::filter_fix( $fix );
	}

	/**
	 * The rule's requirement is met.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @param string $label   Translated rule label.
	 * @param string $group   Rule group.
	 * @param string $message Optional translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @param array  $fix     Optional fix target descriptor.
	 * @return Rule_Result
	 */
	public static function pass( string $rule_id, string $label, string $group, string $message = '', array $data = array(), array $fix = array() ): Rule_Result {
		return new self( $rule_id, Status::PASS, Severity::REQUIRED, $label, $group, $message, $data, $fix );
	}

	/**
	 * The requirement is not met. Severity decides whether this blocks publishing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @param string $label   Translated rule label.
	 * @param string $group   Rule group.
	 * @param string $message Translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @param array  $fix     Optional fix target descriptor.
	 * @return Rule_Result
	 */
	public static function fail( string $rule_id, string $label, string $group, string $message = '', array $data = array(), array $fix = array() ): Rule_Result {
		return new self( $rule_id, Status::FAIL, Severity::REQUIRED, $label, $group, $message, $data, $fix );
	}

	/**
	 * The finding is advisory by nature and is never escalated to a failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @param string $label   Translated rule label.
	 * @param string $group   Rule group.
	 * @param string $message Translated message.
	 * @param array  $data    Optional numeric and boolean context.
	 * @param array  $fix     Optional fix target descriptor.
	 * @return Rule_Result
	 */
	public static function warn( string $rule_id, string $label, string $group, string $message = '', array $data = array(), array $fix = array() ): Rule_Result {
		return new self( $rule_id, Status::WARNING, Severity::REQUIRED, $label, $group, $message, $data, $fix );
	}

	/**
	 * The rule does not apply to this product; it is excluded from the counts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_id Rule identifier.
	 * @param string $label   Translated rule label.
	 * @param string $group   Rule group.
	 * @param string $message Translated reason.
	 * @param array  $data    Optional numeric and boolean context.
	 * @param array  $fix     Optional fix target descriptor.
	 * @return Rule_Result
	 */
	public static function skip( string $rule_id, string $label, string $group, string $message = '', array $data = array(), array $fix = array() ): Rule_Result {
		return new self( $rule_id, Status::SKIPPED, Severity::REQUIRED, $label, $group, $message, $data, $fix );
	}

	/**
	 * Return a copy evaluated under a different configured severity.
	 *
	 * This is where the status derivation matrix is applied; the original is untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param string $severity One of the Severity constants.
	 * @return Rule_Result
	 */
	public function with_severity( string $severity ): Rule_Result {
		return new self(
			$this->rule_id,
			$this->outcome,
			$severity,
			$this->label,
			$this->group,
			$this->message,
			$this->data,
			$this->fix
		);
	}

	/**
	 * Return a copy pointing at a different fix target.
	 *
	 * The target is normalized as the constructor does: one without a string selector
	 * becomes an empty array. Everything else carries over unchanged.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fix Fix target descriptor.
	 * @return Rule_Result
	 */
	public function with_fix( array $fix ): Rule_Result {
		return new self(
			$this->rule_id,
			$this->outcome,
			$this->severity,
			$this->label,
			$this->group,
			$this->message,
			$this->data,
			$fix
		);
	}

	/**
	 * Derive the displayed status from an outcome and a configured severity.
	 *
	 * Only `fail` is severity-sensitive. A `warn` outcome is advisory by nature and must
	 * never be escalated, or raising a threshold would silently start blocking products.
	 *
	 * @since 1.0.0
	 *
	 * @param string $outcome  One of the Status constants.
	 * @param string $severity One of the Severity constants.
	 * @return string
	 */
	private static function derive_status( string $outcome, string $severity ): string {
		if ( Status::FAIL === $outcome && Severity::WARNING === $severity ) {
			return Status::WARNING;
		}

		return $outcome;
	}

	/**
	 * Drop anything from the data payload that is not a number or a boolean.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Raw data payload.
	 * @return array
	 */
	private static function filter_data( array $data ): array {
		$filtered = array();

		foreach ( $data as $key => $value ) {
			if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$filtered[ (string) $key ] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Normalize a fix target to the three documented keys, or to nothing at all.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fix Raw fix descriptor.
	 * @return array
	 */
	private static function filter_fix( array $fix ): array {
		if ( empty( $fix['selector'] ) || ! is_string( $fix['selector'] ) ) {
			return array();
		}

		return array(
			'selector' => $fix['selector'],
			'label'    => isset( $fix['label'] ) && is_string( $fix['label'] ) ? $fix['label'] : '',
			'panel'    => isset( $fix['panel'] ) && is_string( $fix['panel'] ) ? $fix['panel'] : '',
		);
	}

	/**
	 * Rule identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_rule_id(): string {
		return $this->rule_id;
	}

	/**
	 * What the rule reported, before severity was applied.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_outcome(): string {
		return $this->outcome;
	}

	/**
	 * The displayed status.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * The configured severity this result was evaluated under.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_severity(): string {
		return $this->severity;
	}

	/**
	 * Translated rule label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Translated message.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Rule group.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_group(): string {
		return $this->group;
	}

	/**
	 * Numeric and boolean context.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Fix target descriptor, or an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fix(): array {
		return $this->fix;
	}

	/**
	 * JSON-safe representation, in the shape defined in coding-plan.md section 5.4.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'rule_id'  => $this->rule_id,
			'status'   => $this->status,
			'severity' => $this->severity,
			'label'    => $this->label,
			'message'  => $this->message,
			'group'    => $this->group,
			'data'     => $this->data,
			'fix'      => $this->fix,
		);
	}
}
