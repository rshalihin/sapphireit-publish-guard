<?php
/**
 * A configurable managed-rule double.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Stubs;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Managed_Rule_Interface;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Settings\Settings;
use RuntimeException;

/**
 * A rule that decides its own applicability and severity, as an add-on's rule does.
 *
 * It always fails its check, so the severity the validator stamps decides the status.
 *
 * @since 1.2.0
 */
final class Managed_Fake_Rule extends Abstract_Rule implements Managed_Rule_Interface {

	/**
	 * The value that makes `supports()` or `get_severity_for()` throw.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const THROWS = 'throws';

	/**
	 * Rule identifier.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $id;

	/**
	 * What `supports()` returns, or self::THROWS.
	 *
	 * @since 1.2.0
	 * @var bool|string
	 */
	private $supported;

	/**
	 * What `get_severity_for()` returns, or self::THROWS.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $severity;

	/**
	 * Severity the rule ships with.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $default_severity;

	/**
	 * Rule label.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $label;

	/**
	 * Construct the double.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $id               Rule identifier.
	 * @param bool|string $supported        What `supports()` returns, or self::THROWS.
	 * @param string      $severity         What `get_severity_for()` returns, or self::THROWS.
	 * @param string      $default_severity Severity the rule ships with.
	 * @param string      $label            Rule label. Empty derives one from the id.
	 */
	public function __construct( string $id, $supported = true, string $severity = Severity::WARNING, string $default_severity = Severity::REQUIRED, string $label = '' ) {
		$this->id               = $id;
		$this->supported        = $supported;
		$this->severity         = $severity;
		$this->default_severity = $default_severity;
		$this->label            = '' === $label ? 'Managed ' . $id : $label;
	}

	/**
	 * Rule identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Rule label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Severity the rule ships with.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_default_severity(): string {
		return $this->default_severity;
	}

	/**
	 * Whether the rule applies.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return bool
	 * @throws RuntimeException When the double was built to throw.
	 */
	public function supports( Product_Context $context ): bool {
		unset( $context );

		if ( self::THROWS === $this->supported ) {
			throw new RuntimeException( 'Managed supports failure.' );
		}

		return (bool) $this->supported;
	}

	/**
	 * The severity for this product.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return string
	 * @throws RuntimeException When the double was built to throw.
	 */
	public function get_severity_for( Product_Context $context ): string {
		unset( $context );

		if ( self::THROWS === $this->severity ) {
			throw new RuntimeException( 'Managed severity failure.' );
		}

		return $this->severity;
	}

	/**
	 * Always fail.
	 *
	 * @since 1.2.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $context, $settings );

		return $this->fail( 'Managed failure.' );
	}
}
