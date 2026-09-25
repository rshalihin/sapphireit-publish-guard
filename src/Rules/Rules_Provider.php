<?php
/**
 * Registration of the built-in checklist rules.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rules;

use ProductPublishGuard\Engine\Rule_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the eleven shipped rules into a registry, then opens it to everyone else.
 *
 * This is the only file that knows the built-in catalogue. Adding a twelfth rule means
 * writing the rule class and adding one line here; adding one from another plugin means
 * hooking `sit_wcpg_register_rules` and touching nothing in this plugin at all.
 *
 * @since 1.0.0
 */
final class Rules_Provider {

	/**
	 * Not instantiable: the class is a single static operation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Register the built-in rules and fire the extension point.
	 *
	 * @since 1.0.0
	 *
	 * @param Rule_Registry $registry The registry to populate.
	 * @return void
	 */
	public static function populate( Rule_Registry $registry ): void {
		foreach ( self::built_in_rules() as $rule ) {
			$registry->register( $rule );
		}

		/**
		 * Fires once the built-in rules are registered, so that other code can add its own.
		 *
		 * Everything downstream is data-driven: a rule registered here gains a settings
		 * row, a checklist row and, if it is configured as required, publishing
		 * enforcement — with no further changes anywhere.
		 *
		 * @since 1.0.0
		 *
		 * @param Rule_Registry $registry The registry being populated.
		 */
		do_action( 'sit_wcpg_register_rules', $registry );
	}

	/**
	 * The eleven rules this version ships with, in display order.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function built_in_rules(): array {
		return array(
			new Title_Rule(),
			new Description_Rule(),
			new Short_Description_Rule(),
			new Featured_Image_Rule(),
			new Image_Count_Rule(),
			new Price_Rule(),
			new Sale_Price_Rule(),
			new Category_Rule(),
			new Tags_Rule(),
			new Sku_Rule(),
			new Stock_Status_Rule(),
		);
	}
}
