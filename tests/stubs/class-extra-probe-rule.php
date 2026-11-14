<?php
/**
 * A rule that judges a context extra, for the extras tests.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Stubs;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Managed_Rule_Interface;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;

/**
 * Fails, as a required check, unless the extra `probe_code` is not empty.
 *
 * The plugin's registry cannot forget a rule, so this one lives in it for the rest of the
 * run and applies only while a test has switched it on.
 *
 * @since 1.3.0
 */
final class Extra_Probe_Rule extends Abstract_Rule implements Managed_Rule_Interface {

	/**
	 * The rule's identifier.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const ID = 'extra_probe';

	/**
	 * The extra it reads.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const EXTRA = 'probe_code';

	/**
	 * Whether the rule applies to anything at the moment.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Register the rule with the plugin, once, and switch it on.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		$registry = Plugin::instance()->registry();

		if ( ! $registry->has( self::ID ) ) {
			$registry->register( new self() );
		}

		self::$active = true;
	}

	/**
	 * Switch the rule off again.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::$active = false;
	}

	/**
	 * Rule identifier.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Rule label.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Extra probe';
	}

	/**
	 * After every shipped rule.
	 *
	 * @since 1.3.0
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 900;
	}

	/**
	 * Only while a test has switched the rule on.
	 *
	 * @since 1.3.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return bool
	 */
	public function supports( Product_Context $context ): bool {
		unset( $context );

		return self::$active;
	}

	/**
	 * Always required.
	 *
	 * @since 1.3.0
	 *
	 * @param Product_Context $context The product being validated.
	 * @return string
	 */
	public function get_severity_for( Product_Context $context ): string {
		unset( $context );

		return Severity::REQUIRED;
	}

	/**
	 * Pass when the extra has a value.
	 *
	 * @since 1.3.0
	 *
	 * @param Product_Context $context  The product being validated.
	 * @param Settings        $settings The merchant's configuration.
	 * @return Rule_Result
	 */
	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		unset( $settings );

		$code = $context->get_extra( self::EXTRA, '' );

		return is_string( $code ) && '' !== $code ? $this->pass() : $this->fail( 'The probe code is missing.' );
	}
}
