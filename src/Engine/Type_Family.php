<?php
/**
 * Product type families.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The four shapes a product can take, as far as the rules are concerned.
 *
 * A rule asks for the family, never for the raw type, so a subscription is checked like
 * a simple product and a variable subscription like a variable one. Any type the plugin
 * does not know is treated as simple, which is the v1.0 behaviour.
 *
 * A class of constants rather than a PHP enum: the declared floor is PHP 8.0.
 *
 * @since 1.1.0
 */
final class Type_Family {

	/**
	 * A product with its own price and stock.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const SIMPLE = 'simple';

	/**
	 * A parent whose price and stock live on its variations.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const VARIABLE = 'variable';

	/**
	 * A collection of linked products, with no price or stock of its own.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const GROUPED = 'grouped';

	/**
	 * A product sold elsewhere, with a price but no stock.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const EXTERNAL = 'external';

	/**
	 * WooCommerce types that do not share their family's name.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private const ALIASES = array(
		'subscription'          => self::SIMPLE,
		'variable-subscription' => self::VARIABLE,
	);

	/**
	 * Not instantiable.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {}

	/**
	 * Every family, in display order.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::SIMPLE, self::VARIABLE, self::GROUPED, self::EXTERNAL );
	}

	/**
	 * Whether a value is one of the four families.
	 *
	 * @since 1.1.0
	 *
	 * @param string $family Candidate family.
	 * @return bool
	 */
	public static function is_valid( string $family ): bool {
		return in_array( $family, self::all(), true );
	}

	/**
	 * The family a WooCommerce product type belongs to. Pure: no filter is applied here.
	 *
	 * @since 1.1.0
	 *
	 * @param string $type WooCommerce product type.
	 * @return string One of the family constants.
	 */
	public static function from_type( string $type ): string {
		if ( isset( self::ALIASES[ $type ] ) ) {
			return self::ALIASES[ $type ];
		}

		return self::is_valid( $type ) ? $type : self::SIMPLE;
	}
}
